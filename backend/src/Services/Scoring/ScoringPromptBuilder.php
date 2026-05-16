<?php
declare(strict_types=1);

namespace MailPilot\Services\Scoring;

use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\SettingsRepository;

/**
 * Rendert Score-Prompts (User-Template + cached System-Segmente +
 * Korrekturen-Block). Frueher in MailScoringService eingewachsen; hier
 * extrahiert weil rein deterministisch und unabhaengig vom Claude-Call.
 *
 * Drei Public-Methoden:
 *   - renderUserTemplate   → fuellt den DB-User-Template-Body
 *   - buildSystemSegments  → list<text-Bloecke> mit cache_control (1h)
 *   - stripCodeFences      → entfernt ```json … ``` Wrapper aus Claude-Output
 */
final class ScoringPromptBuilder
{
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly CorrectionRepository $corrections,
		private readonly ?AutoSortCorrectionRepository $autoSortCorrections = null,
	) {
	}

	/**
	 * Rendert das DB-User-Template (Admin-Panel-editierbar) mit den
	 * dynamischen Platzhaltern. Werte werden zur Laufzeit generiert.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array<string,mixed>> $mails  Bereits redigierte Mails
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	public function renderUserTemplate(string $template, array $userProfile, array $mails, array $subLabelMap): string
	{
		$vip = implode(', ', $userProfile['vip_senders'] ?? []);
		$kw  = implode(', ', $userProfile['project_keywords'] ?? []);
		$mailsJson = json_encode(
			$mails,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
		);

		$correctionsBlock = ''; // ab Sprint 6e im System-Segment 4
		$subLabelsBlock = '';   // ab Sprint 6b im System-Segment 3

		$schemaSubLabel = $subLabelMap !== []
			? $this->settings->getString('prompt.schema_sublabel_with_pool',
				'"sub_label":"<a topic name OR null>","sub_label_is_new":true|false')
			: $this->settings->getString('prompt.schema_sublabel_empty_pool',
				'"sub_label":null,"sub_label_is_new":false');

		$discoveryNote = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.topic_discovery_note',
			'TOPIC_DISCOVERY: propose new topics if no existing bucket fits.',
		)) . "\n";

		$aliases = is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [];
		$displayName = (string)($userProfile['display_name'] ?? '');
		$identityHeader = $this->settings->getString('prompt.user_identity_header', 'USER_IDENTITY:');
		$identityLines = [''];
		$identityLines[] = $identityHeader;
		if ($displayName !== '') {
			$identityLines[] = '- name: ' . $displayName;
		}
		if ($aliases !== []) {
			$identityLines[] = '- aliases: [' . implode(', ', array_map(static fn($a) => (string)$a, $aliases)) . ']';
		}
		$identityBlock = implode("\n", $identityLines) . "\n";

		$actionOwnerRules = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.action_owner_rules',
			'ACTION_OWNER_RULES: bei Anrede-Ambiguität immer action_owner=unsure.',
		)) . "\n";

		return str_replace([
			'{{user_email}}',
			'{{user_language}}',
			'{{vip_senders_csv}}',
			'{{project_keywords_csv}}',
			'{{user_identity_block}}',
			'{{action_owner_rules_block}}',
			'{{corrections_block}}',
			'{{user_sublabels_block}}',
			'{{topic_discovery_note}}',
			'{{mails_json}}',
			'{{output_schema_sub_label}}',
		], [
			(string)($userProfile['email'] ?? ''),
			(string)($userProfile['language'] ?? 'de'),
			$vip,
			$kw,
			$identityBlock,
			$actionOwnerRules,
			$correctionsBlock,
			$subLabelsBlock,
			$discoveryNote,
			(string)$mailsJson,
			$schemaSubLabel,
		], $template);
	}

	/**
	 * Baut die system-message als list von text-Bloecken mit cache_control
	 * (1h-Extended-TTL). Bis zu vier Segmente:
	 *   1. admin-editierbarer System-Prompt
	 *   2. USER_IDENTITY (Aliases + Display-Name)
	 *   3. USER_TOPICS (sub-label-pool) — nur wenn nicht leer
	 *   4. PRIOR_CORRECTIONS (Score + AutoSort, 30d) — nur wenn vorhanden
	 *
	 * Mini-Call laesst $subLabelMap=[] weg → teilt Segment 1+2 als
	 * Cache-Prefix mit dem Score-Call.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 * @return list<array<string,mixed>>
	 */
	public function buildSystemSegments(string $systemPrompt, array $userProfile, array $subLabelMap = []): array
	{
		$aliases = is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [];
		$displayName = (string)($userProfile['display_name'] ?? '');

		$identity = "USER_IDENTITY:\n- name: " . ($displayName !== '' ? $displayName : '(unbekannt)');
		if ($aliases !== []) {
			$identity .= "\n- aliases: [" . implode(', ', array_map(static fn($a) => (string)$a, $aliases)) . ']';
		}

		$segments = [
			[
				'type' => 'text',
				'text' => $systemPrompt,
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			],
			[
				'type' => 'text',
				'text' => $identity,
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			],
		];

		// DA-Finding 1: Reihenfolge stabilisieren via ksort/usort, sonst
		// hat Discovery-In-Batch einen anderen Hash als der naechste
		// DB-Reload → cache_creation jedes Mal.
		if ($subLabelMap !== []) {
			ksort($subLabelMap, SORT_STRING);
			$header = $this->settings->getString('prompt.sublabels_header', 'USER_SUBLABELS:');
			$lines = [$header];
			foreach ($subLabelMap as $parent => $entries) {
				usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
				foreach ($entries as $entry) {
					$line = '- ' . $parent . ' / ' . $entry['name'];
					if (!empty($entry['description'])) {
						$line .= ' — ' . substr((string)$entry['description'], 0, 200);
					}
					$lines[] = $line;
				}
			}
			$segments[] = [
				'type' => 'text',
				'text' => implode("\n", $lines),
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			];
		}

		$tenantId = (string)($userProfile['tenant_id'] ?? '');
		$userId   = (string)($userProfile['user_id']   ?? '');
		if ($tenantId !== '' && $userId !== '') {
			$corrLines = $this->renderCorrectionsBlockLines($tenantId, $userId);
			if ($corrLines !== []) {
				$segments[] = [
					'type' => 'text',
					'text' => implode("\n", $corrLines),
					'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
				];
			}
		}

		return $segments;
	}

	/**
	 * Sprint 6e DA-Finding 2: rendert beide Korrektur-Pools (Score-
	 * Korrekturen aus Sprint 3e + Move-Korrekturen aus Sprint 6d) als
	 * deterministisch sortierten Few-Shot-Block.
	 *
	 * @return list<string>
	 */
	private function renderCorrectionsBlockLines(string $tenantId, string $userId): array
	{
		$scoreLimit    = max(0, $this->settings->getInt('learning.score_corrections_limit', 10));
		$autosortLimit = max(0, $this->settings->getInt('learning.autosort_corrections_limit', 10));
		$out = [];

		if ($scoreLimit > 0) {
			$score = $this->corrections->forFewShotPrompt($tenantId, $userId, $scoreLimit, 30);
			if ($score !== []) {
				$out[] = $this->settings->getString('prompt.corrections_header', 'PRIOR_USER_CORRECTIONS:');
				foreach ($score as $c) {
					$from = substr($c['from_email'], 0, 60);
					$subj = substr($c['subject'],    0, 60);
					$ki   = ($c['original_label']   ?? '?') . '/' . ($c['original_priority'] ?? '?');
					$hum  = $c['corrected_label'] . '/' . $c['corrected_priority']
						. ($c['corrected_action'] ? ' (action)' : '');
					$rsn  = ($c['reasoning'] ?? '') !== '' ? ' — Grund: ' . substr((string)$c['reasoning'], 0, 200) : '';
					$out[] = "- From: {$from} | Subject: {$subj} | KI: {$ki} → Human: {$hum}{$rsn}";
				}
			}
		}

		if ($autosortLimit > 0 && $this->autoSortCorrections !== null) {
			$moves = $this->autoSortCorrections->forFewShotPrompt($tenantId, $userId, $autosortLimit, 30);
			if ($moves !== []) {
				if ($out !== []) $out[] = '';
				$out[] = $this->settings->getString(
					'prompt.autosort_corrections_header',
					'PRIOR_AUTOSORT_CORRECTIONS:',
				);
				foreach ($moves as $m) {
					$from = $m['original_sub_label']  ?? '(catch-all)';
					$to   = $m['suggested_sub_label'] ?? '(catch-all)';
					$rsn  = ($m['user_reason'] ?? '') !== '' ? ' — Grund: ' . substr((string)$m['user_reason'], 0, 200) : '';
					$out[] = "- {$from} → {$to}{$rsn}";
				}
			}
		}
		return $out;
	}

	/**
	 * Entfernt ```json … ``` Wrapper, die Claude manchmal um JSON-Outputs
	 * setzt. Wenn keine Fences erkannt, trim() only.
	 */
	public static function stripCodeFences(string $text): string
	{
		$text = trim($text);
		if (str_starts_with($text, '```')) {
			$text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
		}
		return trim($text);
	}
}
