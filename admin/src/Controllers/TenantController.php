<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class TenantController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$rows = $pdo->query('SELECT t.id, t.name, t.plan, t.created_at,
				(SELECT COUNT(*) FROM tenant_user tu WHERE tu.tenant_id = t.id) AS user_count,
				(SELECT COUNT(*) FROM mailboxes m WHERE m.tenant_id = t.id AND m.deleted_at IS NULL) AS mailbox_count
			FROM tenants t
			WHERE t.deleted_at IS NULL
			ORDER BY t.created_at DESC')->fetchAll();

		$this->render('tenants', ['tenants' => $rows]);
	}

	public function show(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$id = (string)$params['id'];

		$stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = :id');
		$stmt->execute([':id' => $id]);
		$tenant = $stmt->fetch();
		if ($tenant === false) {
			http_response_code(404);
			echo '<h1>Tenant not found</h1>';
			return;
		}

		$users = $pdo->prepare('SELECT u.*, tu.role
			FROM users u
			INNER JOIN tenant_user tu ON tu.user_id = u.id
			WHERE tu.tenant_id = :t');
		$users->execute([':t' => $id]);

		$mailboxes = $pdo->prepare('SELECT id, email, last_sync_at, sync_enabled, created_at
			FROM mailboxes WHERE tenant_id = :t AND deleted_at IS NULL');
		$mailboxes->execute([':t' => $id]);

		$scoreStats = $pdo->prepare('SELECT label, COUNT(*) AS n
			FROM mail_scores WHERE tenant_id = :t
			  AND scored_at >= (UTC_TIMESTAMP(3) - INTERVAL 7 DAY)
			GROUP BY label');
		$scoreStats->execute([':t' => $id]);

		$this->render('tenant_show', [
			'tenant' => $tenant,
			'users' => $users->fetchAll(),
			'mailboxes' => $mailboxes->fetchAll(),
			'scoreStats' => $scoreStats->fetchAll(PDO::FETCH_KEY_PAIR),
		]);
	}

	public function updatePlan(array $params): void
	{
		$this->verifyCsrf();
		$plan = (string)($_POST['plan'] ?? '');
		if (!in_array($plan, ['free', 'pro', 'team', 'enterprise'], true)) {
			$this->flash('error', 'Ungültiger Plan');
			$this->redirect('/admin/tenants/' . $params['id']);
			return;
		}
		$this->kernel->get(PDO::class)->prepare('UPDATE tenants SET plan = :p WHERE id = :id')
			->execute([':p' => $plan, ':id' => $params['id']]);
		$this->flash('success', "Plan geändert auf: {$plan}");
		$this->redirect('/admin/tenants/' . $params['id']);
	}

	public function delete(array $params): void
	{
		$this->verifyCsrf();
		$pdo = $this->kernel->get(PDO::class);
		$pdo->prepare('UPDATE tenants SET deleted_at = UTC_TIMESTAMP(3) WHERE id = :id')
			->execute([':id' => $params['id']]);
		$pdo->prepare('INSERT INTO audit_log (event, entity, entity_id, created_at)
			VALUES ("admin.tenant.soft_delete", "tenant", :id, UTC_TIMESTAMP(3))')
			->execute([':id' => $params['id']]);
		$this->flash('success', 'Tenant soft-deleted');
		$this->redirect('/admin/tenants');
	}
}
