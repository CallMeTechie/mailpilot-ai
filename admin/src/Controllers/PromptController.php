<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class PromptController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$rows = $pdo->query('SELECT id, key_name, version, model, max_tokens, temperature, active, created_at
			FROM prompt_versions
			ORDER BY key_name, created_at DESC')->fetchAll();

		$this->render('prompts', ['prompts' => $rows]);
	}

	public function create(array $params): void
	{
		$this->render('prompt_edit', ['prompt' => null]);
	}

	public function store(array $params): void
	{
		$this->verifyCsrf();
		$pdo = $this->kernel->get(PDO::class);

		$id = $this->uuid();
		$stmt = $pdo->prepare('INSERT INTO prompt_versions
			(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
			VALUES (:id, :kn, :v, :sp, :ut, :m, :mt, :t, 0)');
		$stmt->execute([
			':id' => $id,
			':kn' => (string)($_POST['key_name'] ?? ''),
			':v'  => (string)($_POST['version'] ?? ''),
			':sp' => (string)($_POST['system_prompt'] ?? ''),
			':ut' => (string)($_POST['user_template'] ?? ''),
			':m'  => (string)($_POST['model'] ?? ''),
			':mt' => (int)($_POST['max_tokens'] ?? 1000),
			':t'  => (float)($_POST['temperature'] ?? 0.2),
		]);

		$this->flash('success', 'Prompt-Version angelegt (inaktiv)');
		$this->redirect('/admin/prompts');
	}

	public function show(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$stmt = $pdo->prepare('SELECT * FROM prompt_versions WHERE id = :id');
		$stmt->execute([':id' => $params['id']]);
		$prompt = $stmt->fetch();
		if ($prompt === false) {
			http_response_code(404);
			echo '<h1>Prompt not found</h1>';
			return;
		}
		$this->render('prompt_edit', ['prompt' => $prompt]);
	}

	public function activate(array $params): void
	{
		$this->verifyCsrf();
		$pdo = $this->kernel->get(PDO::class);

		// Fetch key_name first
		$stmt = $pdo->prepare('SELECT key_name FROM prompt_versions WHERE id = :id');
		$stmt->execute([':id' => $params['id']]);
		$row = $stmt->fetch();
		if ($row === false) {
			$this->redirect('/admin/prompts');
			return;
		}

		$pdo->beginTransaction();
		try {
			$pdo->prepare('UPDATE prompt_versions SET active = 0 WHERE key_name = :kn')
				->execute([':kn' => $row['key_name']]);
			$pdo->prepare('UPDATE prompt_versions SET active = 1 WHERE id = :id')
				->execute([':id' => $params['id']]);
			$pdo->prepare('INSERT INTO audit_log (event, entity, entity_id)
				VALUES ("admin.prompt.activate", "prompt", :id)')
				->execute([':id' => $params['id']]);
			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		$this->flash('success', 'Prompt aktiviert. Cache wird mit nächster Version invalidiert.');
		$this->redirect('/admin/prompts');
	}

	private function uuid(): string
	{
		$d = random_bytes(16);
		$d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
		$d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
	}
}
