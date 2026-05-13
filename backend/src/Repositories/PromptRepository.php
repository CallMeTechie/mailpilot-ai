<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;
use RuntimeException;

/**
 * Reads the currently-active version of a prompt from prompt_versions.
 * Schema seit Migration 0001 + Seed 0003 + 0012 (P-SCORE@1.2).
 *
 * Wird vom MailScoringService / MailSummaryService / ReplyDraftService
 * verwendet — Source-of-Truth ist die DB, das Admin-Panel
 * (/admin/prompts) ist der Editor, der Code rendert das Template mit
 * den dynamischen Platzhaltern.
 */
final class PromptRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return array{
	 *   id:string,
	 *   key_name:string,
	 *   version:string,
	 *   system_prompt:string,
	 *   user_template:string,
	 *   model:string,
	 *   max_tokens:int,
	 *   temperature:float,
	 * }
	 */
	public function getActive(string $keyName): array
	{
		$stmt = $this->db->prepare('SELECT id, key_name, version, system_prompt, user_template,
				model, max_tokens, temperature
			FROM prompt_versions
			WHERE key_name = :k AND active = 1
			ORDER BY created_at DESC
			LIMIT 1');
		$stmt->execute([':k' => $keyName]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			throw new RuntimeException(
				"No active prompt_versions row for key_name '{$keyName}'. "
				. 'Migrations 0003 (initial seeds) and 0012 (P-SCORE@1.2) sollten beim Container-Start gelaufen sein.'
			);
		}
		return [
			'id'            => (string)$row['id'],
			'key_name'      => (string)$row['key_name'],
			'version'       => (string)$row['version'],
			'system_prompt' => (string)$row['system_prompt'],
			'user_template' => (string)$row['user_template'],
			'model'         => (string)$row['model'],
			'max_tokens'    => (int)$row['max_tokens'],
			'temperature'   => (float)$row['temperature'],
		];
	}

	/**
	 * Combined cache-version-string for {keyName, version}. Used as the
	 * prompt_version column in claude_cache so cache rows are scoped to
	 * exactly the active prompt; activating a new version invalidates
	 * the cache via key_name (CacheRepository::purgeByPromptKey).
	 */
	public function cacheVersionTag(string $keyName, string $version): string
	{
		return $keyName . '@' . $version;
	}
}
