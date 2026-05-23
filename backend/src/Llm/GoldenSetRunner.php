<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmGoldenRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Phase 9q-H (Marc 2026-05-23) — Quality-Test-Runner gegen das Golden-Set.
 *
 * Pro Aufruf von runForModel(providerId, role): iteriert die aktiven
 * Golden-Mails durch, baut fuer jede einen kompakten Score-Request,
 * ruft den Provider DIREKT (nicht ueber LlmRouter — Failover wuerde
 * den Quality-Vergleich verfaelschen), parst JSON, vergleicht label +
 * priority gegen das Ground-Truth.
 *
 * Synchron — bei 10 Mails × 1-2s ≈ 15s im Browser akzeptabel. Bei
 * groesserem Set sollten wir spaeter einen Worker-Tick einbauen.
 *
 * Synthetic System-Prompt — wir nutzen NICHT P-SCORE@1.6 weil das
 * volle Prompt User-Profile/Aliases/Sub-Labels enthaelt die fuer den
 * Quality-Test irrelevant sind. Stattdessen: minimaler Score-Prompt
 * der nur label + priority + JSON-Output verlangt.
 */
final class GoldenSetRunner
{
	private const SYSTEM_PROMPT = <<<'TEXT'
		Du bist ein E-Mail-Triage-Assistent. Klassifiziere die untenstehende
		Mail. Antworte AUSSCHLIESSLICH mit einem JSON-Objekt:
		  {"label":"direct|action|cc|newsletter|auto|noise","priority":1-5}

		Labels:
		- direct:    persoenliche Mail an den Nutzer
		- action:    erwartet Antwort/Entscheidung/Handlung
		- cc:        nur info, kein Action
		- newsletter: Marketing/Abo
		- auto:      automatisiert (CI, Versand, Quittung)
		- noise:     Spam/irrelevant

		Priority 1-5: 5=sofort, 4=heute, 3=Woche, 2=wann passt, 1=ignorierbar.
		Behoerden/Mahnung/Sicherheit → mindestens 4. Newsletter/Spam → 1.
		TEXT;

	/**
	 * @param array<string, LlmProvider> $providersByKind
	 */
	public function __construct(
		private readonly LlmGoldenRepository $golden,
		private readonly LlmProviderRepository $providers,
		private readonly LlmModelRepository $models,
		private readonly array $providersByKind,
		private readonly LoggerInterface $log,
		// Phase 9q B3 (Marc 2026-05-23): Logger optional. Wenn gesetzt,
		// landen auch Test-Calls im llm_call_log → Usage-Dashboard zeigt
		// die Cost von Golden-Runs.
		private readonly ?LlmCallLogger $callLogger = null,
	) {
	}

	/**
	 * Startet einen Run pro (providerId, role). Returnt die Run-ID.
	 */
	public function runForModel(string $providerId, string $role = 'score'): string
	{
		$providerRow = $this->providers->findById($providerId);
		if ($providerRow === null) {
			throw new \RuntimeException("Provider {$providerId} nicht gefunden");
		}
		$kind = (string)$providerRow['kind'];
		$provider = $this->providersByKind[$kind] ?? null;
		if ($provider === null) {
			throw new \RuntimeException("Unbekannter Provider-Kind \"{$kind}\"");
		}
		$modelRow = $this->models->findForProviderAndRole($providerId, $role);
		if ($modelRow === null) {
			throw new \RuntimeException("Kein aktives Model fuer Provider {$providerId} + Rolle {$role}");
		}
		$modelIdStr = (string)$modelRow['model_id'];

		$runId = $this->golden->insertRun($providerId, $modelIdStr, $role);
		$set   = $this->golden->listSet(includeDisabled: false);

		$correctLabel    = 0;
		$correctPriority = 0;
		$latencySum      = 0;
		$processed       = 0;
		$totalCost       = 0.0;
		$cin  = $modelRow['cost_per_mtok_in']  !== null ? (float)$modelRow['cost_per_mtok_in']  : 0.0;
		$cout = $modelRow['cost_per_mtok_out'] !== null ? (float)$modelRow['cost_per_mtok_out'] : 0.0;

		foreach ($set as $goldenMail) {
			$processed++;
			$userText = $this->buildUserText($goldenMail);
			$req = new NormalizedRequest(
				systemPrompt:   self::SYSTEM_PROMPT,
				messages:       [['role' => 'user', 'content' => $userText]],
				maxTokens:      150,
				temperature:    0.0,
				modelHint:      $modelIdStr,
				responseFormat: 'json_object',
			);

			$start = microtime(true);
			try {
				$resp = $provider->complete($req);
				$lat  = (int)((microtime(true) - $start) * 1000);

				$parsed = $this->parseResponse($resp->content);
				$predLabel    = $parsed['label']    ?? null;
				$predPriority = $parsed['priority'] ?? null;

				$labelOk    = $predLabel    === $goldenMail['expected_label'];
				$priorityOk = $predPriority !== null && $predPriority === (int)$goldenMail['expected_priority'];

				if ($labelOk)    { $correctLabel++; }
				if ($priorityOk) { $correctPriority++; }
				$latencySum += $lat;

				$inTok  = (int)($resp->usage['inputTokens']  ?? 0);
				$outTok = (int)($resp->usage['outputTokens'] ?? 0);
				$totalCost += ($inTok / 1_000_000.0) * $cin + ($outTok / 1_000_000.0) * $cout;

				$this->golden->insertDetail(
					$runId, (string)$goldenMail['id'],
					$predLabel, $predPriority, $lat, $labelOk, $priorityOk,
				);
				// Phase 9q B3: auch im llm_call_log persistieren, damit
				// Test-Runs im Usage/Cost-Dashboard auftauchen.
				$this->callLogger?->logSuccess($providerId, $role, $resp, $lat);
			} catch (Throwable $e) {
				$lat = (int)((microtime(true) - $start) * 1000);
				$this->golden->insertDetail(
					$runId, (string)$goldenMail['id'],
					null, null, $lat, false, false,
					substr($e->getMessage(), 0, 500),
				);
				$this->log->warning('golden_set.mail_failed', [
					'run_id' => $runId, 'golden_id' => $goldenMail['id'], 'err' => $e->getMessage(),
				]);
				$this->callLogger?->logFailure(
					$providerId, $role, $modelIdStr,
					'error', $e->getMessage(), $lat,
				);
			}
		}

		$avgLat = $processed > 0 ? (int)round($latencySum / $processed) : 0;
		$this->golden->updateRunDone($runId, [
			'total_mails'      => $processed,
			'correct_label'    => $correctLabel,
			'correct_priority' => $correctPriority,
			'avg_latency_ms'   => $avgLat,
			'total_cost_usd'   => $totalCost,
			'status'           => 'done',
		]);
		$this->log->info('golden_set.run_done', [
			'run_id' => $runId,
			'provider_id' => $providerId,
			'model_id' => $modelIdStr,
			'total' => $processed,
			'label_acc' => $processed > 0 ? round($correctLabel / $processed, 3) : 0,
			'prio_acc'  => $processed > 0 ? round($correctPriority / $processed, 3) : 0,
		]);
		return $runId;
	}

	/**
	 * @param array<string,mixed> $mail
	 */
	private function buildUserText(array $mail): string
	{
		return sprintf(
			"From: %s\nSubject: %s\n\n%s",
			(string)$mail['from_email'],
			(string)$mail['subject'],
			(string)$mail['body_preview'],
		);
	}

	/**
	 * Parsed JSON-Response. Toleriert ```json ... ``` Fences (manche Modelle
	 * antworten so trotz response_format=json_object).
	 *
	 * @return array{label:?string, priority:?int}
	 */
	private function parseResponse(string $content): array
	{
		$text = trim($content);
		if (str_starts_with($text, '```')) {
			$text = (string)preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
			$text = trim($text);
		}
		try {
			$decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['label' => null, 'priority' => null];
		}
		if (!is_array($decoded)) {
			return ['label' => null, 'priority' => null];
		}
		$label = isset($decoded['label']) && is_string($decoded['label']) ? $decoded['label'] : null;
		$prio  = isset($decoded['priority']) && is_numeric($decoded['priority']) ? (int)$decoded['priority'] : null;
		return ['label' => $label, 'priority' => $prio];
	}
}
