<?php
declare(strict_types=1);

namespace MailPilot\Llm;

/**
 * Ein vom Provider entdecktes Modell (Discovery-Layer). Reines DTO.
 * effortLevels = Teilmenge von low|medium|high|xhigh|max ([] = kein Effort).
 * Token-Felder/releasedAt sind optional (Provider liefert sie nicht immer).
 */
final class ModelDescriptor
{
	/**
	 * @param list<string> $effortLevels
	 */
	public function __construct(
		public readonly string  $modelId,
		public readonly string  $displayName,
		public readonly array   $effortLevels = [],
		public readonly ?int    $maxOutputTokens = null,
		public readonly ?int    $maxContextTokens = null,
		public readonly ?string $releasedAt = null,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toRow(): array
	{
		return [
			'model_id'           => $this->modelId,
			'display_name'       => $this->displayName,
			'effort_levels'      => json_encode($this->effortLevels, JSON_UNESCAPED_UNICODE),
			'max_output_tokens'  => $this->maxOutputTokens,
			'max_context_tokens' => $this->maxContextTokens,
			'released_at'        => $this->releasedAt,
		];
	}
}
