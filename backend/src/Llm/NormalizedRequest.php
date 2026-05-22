<?php
declare(strict_types=1);

namespace MailPilot\Llm;

/**
 * Phase 9q-A (Marc 2026-05-22) — Provider-agnostische Eingabe fuer
 * Inferenz-Calls. Jeder Provider uebersetzt das in seine native Payload-
 * Shape (Anthropic /v1/messages, OpenAI /v1/chat/completions etc.).
 *
 * cacheSegments: optionale Hints fuer Anthropic-Prompt-Caching. Liste von
 * Indizes (oder Markern) die Anthropic-spezifisch als cache_control
 * gerendert werden. Bei Providern ohne natives Cache-Control wird das
 * Feld ignoriert — der DB-Layer-Cache (Phase 9q-C) macht den Rest
 * provider-agnostic.
 *
 * modelHint: model_id aus llm_models-Tabelle, z.B. "claude-haiku-4-5-
 * 20251001", "gpt-4o-mini", "qwen3:32b". Der Provider validiert den
 * Wert selbst (jeder kennt nur seine eigenen Modelle).
 */
final class NormalizedRequest
{
	/**
	 * @param list<array{role:string, content:string}> $messages
	 * @param list<int>                                $cacheSegments
	 */
	public function __construct(
		public readonly string $systemPrompt,
		public readonly array  $messages,
		public readonly int    $maxTokens,
		public readonly float  $temperature,
		public readonly string $modelHint,
		public readonly ?string $responseFormat = null,
		public readonly array  $cacheSegments  = [],
	) {
	}
}
