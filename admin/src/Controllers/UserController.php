<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class UserController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$q = trim((string)($_GET['q'] ?? ''));

		if ($q !== '') {
			$stmt = $pdo->prepare('SELECT u.*, tu.tenant_id, t.name AS tenant_name
				FROM users u
				LEFT JOIN tenant_user tu ON tu.user_id = u.id
				LEFT JOIN tenants t ON t.id = tu.tenant_id
				WHERE u.deleted_at IS NULL AND u.email LIKE :q
				ORDER BY u.last_login_at DESC LIMIT 100');
			$stmt->execute([':q' => '%' . $q . '%']);
		} else {
			$stmt = $pdo->query('SELECT u.*, tu.tenant_id, t.name AS tenant_name
				FROM users u
				LEFT JOIN tenant_user tu ON tu.user_id = u.id
				LEFT JOIN tenants t ON t.id = tu.tenant_id
				WHERE u.deleted_at IS NULL
				ORDER BY u.last_login_at DESC LIMIT 100');
		}

		$this->render('users', ['users' => $stmt->fetchAll(), 'q' => $q]);
	}

	public function show(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$id = (string)$params['id'];

		$user = $pdo->prepare('SELECT * FROM users WHERE id = :id');
		$user->execute([':id' => $id]);
		$user = $user->fetch();
		if ($user === false) {
			http_response_code(404);
			echo '<h1>User not found</h1>';
			return;
		}

		$mailboxes = $pdo->prepare('SELECT id, email, last_sync_at, sync_enabled
			FROM mailboxes WHERE user_id = :u AND deleted_at IS NULL');
		$mailboxes->execute([':u' => $id]);

		$vips = $pdo->prepare('SELECT email, display_name FROM vip_senders
			WHERE user_id = :u AND deleted_at IS NULL');
		$vips->execute([':u' => $id]);

		$keywords = $pdo->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL');
		$keywords->execute([':u' => $id]);

		$this->render('user_show', [
			'user' => $user,
			'mailboxes' => $mailboxes->fetchAll(),
			'vips' => $vips->fetchAll(),
			'keywords' => array_column($keywords->fetchAll(), 'keyword'),
		]);
	}

	public function delete(array $params): void
	{
		$this->verifyCsrf();
		$pdo = $this->kernel->get(PDO::class);
		$id = (string)$params['id'];

		$pdo->beginTransaction();
		try {
			$pdo->prepare('UPDATE users SET deleted_at = UTC_TIMESTAMP(3) WHERE id = :id')->execute([':id' => $id]);
			$pdo->prepare('UPDATE mailboxes SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')->execute([':u' => $id]);
			$pdo->prepare('UPDATE vip_senders SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')->execute([':u' => $id]);
			$pdo->prepare('UPDATE project_keywords SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')->execute([':u' => $id]);
			$pdo->prepare('INSERT INTO audit_log (event, entity, entity_id) VALUES ("admin.user.delete", "user", :id)')
				->execute([':id' => $id]);
			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		$this->flash('success', 'User soft-deleted');
		$this->redirect('/admin/users');
	}
}
