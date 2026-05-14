<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Repositories\SettingsRepository;
use PDO;

/**
 * Editor für die 14 system_settings-Keys aus Migration 0014:
 *
 *   prompt.*           — Hilfsblöcke, die der Score-Prompt zusammensetzt
 *   worker.*           — Heartbeat-Schwelle
 *   autosort.*         — Retry-Cap fürs Move-Routing
 *   topics.*           — Fuzzy-Merge-Threshold für Topic-Discovery
 *   folder_default.*   — Default-Folder pro Primary-Label
 *
 * Jede der drei Sektionen hat einen eigenen POST-Endpoint — eine
 * teilweise befüllte Form überschreibt niemals die anderen Sektionen.
 */
final class SystemSettingsController extends BaseController
{
	/** @var list<string> */
	private const SNIPPET_KEYS = [
		'prompt.corrections_header',
		'prompt.sublabels_header',
		'prompt.topic_discovery_note',
		'prompt.schema_sublabel_with_pool',
		'prompt.schema_sublabel_empty_pool',
	];

	/** @var list<string> */
	private const TUNING_KEYS = [
		'worker.heartbeat_threshold_seconds',
		'autosort.retry_cap',
		'topics.fuzzy_merge_levenshtein_max',
	];

	/** @var list<string> */
	private const FOLDER_KEYS = [
		'folder_default.direct',
		'folder_default.action',
		'folder_default.cc',
		'folder_default.newsletter',
		'folder_default.auto',
		'folder_default.noise',
	];

	public function show(array $params): void
	{
		$pdo  = $this->kernel->get(PDO::class);
		$keys = array_merge(self::SNIPPET_KEYS, self::TUNING_KEYS, self::FOLDER_KEYS);

		$placeholders = implode(',', array_fill(0, count($keys), '?'));
		$stmt = $pdo->prepare("SELECT `key`, `value`, `type`, description
			FROM system_settings WHERE `key` IN ({$placeholders})");
		$stmt->execute($keys);

		$rows = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$rows[(string)$r['key']] = $r;
		}

		$this->render('system_settings', [
			'snippets'  => array_map(static fn(string $k): array => $rows[$k] ?? ['key' => $k, 'value' => '', 'type' => 'string', 'description' => ''], self::SNIPPET_KEYS),
			'tuning'    => array_map(static fn(string $k): array => $rows[$k] ?? ['key' => $k, 'value' => '', 'type' => 'int',    'description' => ''], self::TUNING_KEYS),
			'folders'   => array_map(static fn(string $k): array => $rows[$k] ?? ['key' => $k, 'value' => '', 'type' => 'string', 'description' => ''], self::FOLDER_KEYS),
			'csrfToken' => $this->csrfToken(),
		]);
	}

	public function saveSnippets(array $params): void
	{
		$this->saveSection(self::SNIPPET_KEYS, 'string', 'admin.system_settings.snippets');
	}

	public function saveTuning(array $params): void
	{
		$this->saveSection(self::TUNING_KEYS, 'int', 'admin.system_settings.tuning');
	}

	public function saveFolders(array $params): void
	{
		$this->saveSection(self::FOLDER_KEYS, 'string', 'admin.system_settings.folders');
	}

	/**
	 * @param list<string> $allowed
	 */
	private function saveSection(array $allowed, string $coerce, string $auditEvent): void
	{
		$this->verifyCsrf();
		$settings = $this->kernel->get(SettingsRepository::class);

		$changed = [];
		foreach ($allowed as $k) {
			if (!array_key_exists($k, $_POST)) continue;
			$raw = (string)$_POST[$k];
			if ($coerce === 'int') {
				$raw = (string)max(0, (int)$raw);
			} else {
				$raw = rtrim($raw);
			}
			$settings->set($k, $raw);
			$changed[$k] = mb_strlen($raw);
		}

		$this->kernel->get(PDO::class)
			->prepare('INSERT INTO audit_log (event, entity, meta_json) VALUES (:e, "system_settings", :m)')
			->execute([':e' => $auditEvent, ':m' => json_encode($changed)]);

		$this->flash('success', count($changed) . ' Einstellungen aktualisiert');
		$this->redirect('/admin/settings/system');
	}
}
