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

	/** @var list<list<array<string,mixed>>> FIFO-Queue gescripteter results-Listen. */
	private array $scripted = [];

	/**
	 * @param list<array<string,mixed>> $results eine results-Liste fuer EINEN Call.
	 */
	public function scriptResults(array $results): void
	{
		$this->scripted[] = $results;
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
		$results = array_shift($this->scripted);
		$json    = json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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
