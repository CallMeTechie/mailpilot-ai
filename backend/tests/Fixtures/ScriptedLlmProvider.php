<?php
declare(strict_types=1);

namespace MailPilot\Tests\Fixtures;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;

/**
 * Spec 1 (2026-06-02) — Recording/Scripted LlmProvider fuer die Score-Tests.
 *
 * Ersetzt den frueheren FakeClaudeClient-Score-Pfad: seit Scoring IMMER ueber
 * den LlmRouter laeuft, faengt dieser Provider den (nach Router-Resolution)
 * NormalizedRequest ein und liefert eine kanonische {"results":[...]}-Antwort.
 * Kein Mock — ein echtes LlmProvider-Objekt, das die Tests deterministisch
 * mit vorab gescripteten Ergebnis-Listen fuettern.
 *
 * Mehrere Score-Calls (z.B. Batch-Split) werden per FIFO-Queue bedient: jeder
 * complete() shiftet die naechste gescriptete Ergebnis-Liste.
 */
final class ScriptedLlmProvider implements LlmProvider
{
	/** Letzter empfangener Request — fuer Prompt-/Modell-Assertions. */
	public ?NormalizedRequest $seen = null;

	/** @var list<NormalizedRequest> Alle empfangenen Requests in Reihenfolge. */
	public array $requests = [];

	/** @var list<string> FIFO-Queue gescripteter Response-Content-JSON-Strings. */
	private array $scripted = [];

	/**
	 * @param list<array<string,mixed>> $results eine results-Liste fuer EINEN Call.
	 * Wird als kanonische {"results":[...]}-Antwort (Score-Shape) ausgeliefert.
	 */
	public function scriptResults(array $results): void
	{
		$this->scripted[] = json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Task 7 (2026-06-02) — scriptet ein rohes JSON-Objekt als Response-Content
	 * (Inferenz-Shape: RuleInferenceService::parseClaudeResponse erwartet das
	 * Extraktions-JSON direkt, NICHT in {"results":[...]} gewrappt).
	 *
	 * @param array<string,mixed> $data
	 */
	public function scriptRawJson(array $data): void
	{
		$this->scripted[] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	public function callCount(): int
	{
		return count($this->requests);
	}

	public function kind(): string
	{
		return 'anthropic';
	}

	public function isHealthy(): bool
	{
		return true;
	}

	public function complete(NormalizedRequest $request): NormalizedResponse
	{
		$this->seen       = $request;
		$this->requests[] = $request;

		if ($this->scripted === []) {
			throw new \RuntimeException(
				'ScriptedLlmProvider: keine gescriptete Antwort fuer Call #' . count($this->requests)
			);
		}
		$json = array_shift($this->scripted);

		return new NormalizedResponse(
			$json,
			['inputTokens' => 10, 'outputTokens' => 5],
			'end_turn',
			$request->modelHint,
			'anthropic',
		);
	}

	public function listModels(): array
	{
		return [];
	}
}
