<?php
declare(strict_types=1);

namespace MailPilot\Llm;

/**
 * Phase 9q-A (Marc 2026-05-22) — Provider-agnostische LLM-Abstraktion.
 *
 * Implementiert pro Anbieter (Anthropic, OpenAI, Gemini, Mistral, Qwen,
 * Ollama/LM-Studio/...). Der LlmRouter (Phase 9q-B) wuerfelt die Provider
 * in einer Fallback-Chain durch und faengt LlmOverloadedException +
 * LlmUnavailableException ab, um den naechsten Provider zu probieren.
 *
 * NormalizedRequest/-Response sind provider-agnostisch — jeder Provider
 * uebersetzt sie in seine native API-Shape (chat/completions vs. messages
 * vs. generateContent).
 */
interface LlmProvider
{
	/**
	 * Eindeutige Kennung des Provider-Typs ('anthropic', 'openai',
	 * 'gemini', 'mistral', 'openai_compatible'). Nicht der DB-Row-Name.
	 */
	public function kind(): string;

	/**
	 * Health-Check: kann dieser Provider gerade Requests verarbeiten?
	 * Implementierungen koennen ein In-Memory-Cooldown-Fenster halten
	 * (z.B. nach Overload-Exception 60s als unhealthy markieren).
	 */
	public function isHealthy(): bool;

	/**
	 * Schickt einen Inferenz-Request. Wirft:
	 *   - LlmOverloadedException bei status=529 oder overloaded_error
	 *   - LlmUnavailableException bei Connect-Fehler / 5xx-Loop
	 *   - RuntimeException bei anderen Fehlern (Auth/4xx/Bad-Request)
	 */
	public function complete(NormalizedRequest $request): NormalizedResponse;

	/**
	 * Entdeckt die beim Provider verfuegbaren (Chat-faehigen) Modelle live.
	 * Wirft LlmUnavailableException bei Transport-/Auth-Fehlern.
	 *
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array;
}
