<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Llm\LlmAllProvidersDownException;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Services\Scoring\ScoringPromptBuilder;
use Psr\Log\LoggerInterface;

/**
 * Spec 2 — LLM-gestützter Per-Mail-Match (Rolle 'match'). Nur im match_mode
 * 'llm'/'hybrid' und NUR bei Cache-Miss aufgerufen. Payload redacted; bei
 * leerer/ausgefallener Chain (z.B. local_only ohne lokales match-Modell)
 * liefert er null → Caller fällt auf den deterministischen MatchScorer zurück.
 */
final class RuleMatchService
{
	public function __construct(
		private readonly LlmRouter $router,
		private readonly RedactionService $redactor,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $mail
	 * @return int|null  0..100 oder null (→ deterministic-Fallback)
	 */
	public function scoreMatch(array $rule, array $mail): ?int
	{
		// RedactionService::reduceFromToDomain() gibt '*@domain.tld' zurück — das
		// '@' im Präfix würde die Domain-Assertion im Test brechen und ist für den
		// LLM-Payload hier unnötig. Wir extrahieren daher manuell nur den Domain-Teil.
		$domain = ltrim((string)(strstr((string)($mail['from_email'] ?? ''), '@') ?: ''), '@');
		if ($domain === '') {
			$domain = '(unknown)';
		}
		// Betreff vorab redacted; finaler redact()-Pass unten deckt zusätzlich die Regel-Felder ab.
		$subject  = $this->redactor->redact((string)($mail['subject'] ?? ''));
		$ruleDesc = $this->describeRule($rule);

		$system = 'Du bewertest, wie gut eine E-Mail zu einer gelernten Sortier-Regel passt. '
			. 'Antworte AUSSCHLIESSLICH mit JSON {"score": <0-100>}. 0 = passt nicht, 100 = passt perfekt. '
			. 'Keine Begründung, kein weiterer Text, keine Markdown-Codefences.';
		$user = $this->redactor->redact(
			"REGEL:\n$ruleDesc\n\nMAIL:\nAbsender-Domain: $domain\nBetreff: $subject"
		);

		$req = new NormalizedRequest(
			systemPrompt: $system,
			messages:     [['role' => 'user', 'content' => $user]],
			maxTokens:    50,
			temperature:  0.0,
			modelHint:    '',
			responseFormat: 'json_object',
		);
		try {
			$resp = $this->router->complete($req, 'match');
		} catch (LlmAllProvidersDownException | \RuntimeException $e) {
			$this->logger->info('rule_match.unavailable_fallback_deterministic', ['err' => $e->getMessage()]);
			return null;
		}
		$score = $this->extractScore($resp->content);
		if ($score === null) {
			// Erfolgreicher Call, aber kein Score extrahierbar → stille Degradation
			// sichtbar machen (D8), dann deterministischer Fallback.
			$this->logger->info('rule_match.unparseable_fallback_deterministic', [
				'raw' => mb_substr($resp->content, 0, 200),
			]);
			return null;
		}
		return max(0, min(100, $score));
	}

	/**
	 * Extrahiert den Score robust aus der Modell-Antwort. Reale Modelle (z.B.
	 * Anthropic Haiku) wrappen JSON in ```json … ``` Fences UND hängen teils
	 * Prosa-Begründungen an (kein nativer json_object-Mode). Strategie:
	 *   1) Fences strippen + json_decode (wie scoring/inference) — der saubere Fall.
	 *   2) Fällt das aus (z.B. Trailing-Prosa nach dem JSON), den Score per Regex
	 *      aus dem Roh-Content ziehen.
	 * Liefert null, wenn gar kein Score gefunden wird.
	 */
	private function extractScore(string $raw): ?int
	{
		$parsed = json_decode(ScoringPromptBuilder::stripCodeFences($raw), true);
		if (is_array($parsed) && isset($parsed['score']) && is_numeric($parsed['score'])) {
			return (int)$parsed['score'];
		}
		if (preg_match('/"score"\s*:\s*(\d{1,3})/', $raw, $m) === 1) {
			return (int)$m[1];
		}
		return null;
	}

	/** @param array<string,mixed> $rule */
	private function describeRule(array $rule): string
	{
		$parts = [];
		if (($rule['match_sender_key'] ?? null) !== null)    { $parts[] = 'Absender=' . $rule['match_sender_key']; }
		if (($rule['match_from_local'] ?? null) !== null)    { $parts[] = 'from_local=' . $rule['match_from_local']; }
		if (($rule['match_subject_regex'] ?? null) !== null) { $parts[] = 'Betreff-Muster=' . $rule['match_subject_regex']; }
		if (($rule['set_label'] ?? null) !== null)           { $parts[] = '→ Label=' . $rule['set_label']; }
		if (($rule['set_priority'] ?? null) !== null)        { $parts[] = '→ Priority=' . $rule['set_priority']; }
		return $parts === [] ? '(leer)' : implode(', ', $parts);
	}
}
