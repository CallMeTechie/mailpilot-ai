<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Admin\Security\AdminLoginAttemptRepository;

final class AuthController extends BaseController
{
	public function showLogin(array $params): void
	{
		$this->render('login', ['error' => $_GET['error'] ?? null]);
	}

	public function doLogin(array $params): void
	{
		$user = (string)($_POST['username'] ?? '');
		$pass = (string)($_POST['password'] ?? '');
		$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');

		// Phase-H5 — Brute-Force-Schutz: 5 fails/15min pro IP → Lockout.
		$attempts = new AdminLoginAttemptRepository($this->kernel->get(\PDO::class));
		if ($attempts->isLocked($ip)) {
			$retryAfter = max(1, $attempts->secondsUntilUnlock($ip));
			header('Retry-After: ' . $retryAfter);
			$this->redirect('/admin/login?error=locked');
			return;
		}

		$admins = (array)$this->kernel->config['admin']['admins'];
		$hash = $admins[$user] ?? null;
		$success = $hash !== null && password_verify($pass, (string)$hash);

		// Versuch IMMER protokollieren (success und fail). Bei success
		// loescht der Repo die alten failures fuer diese IP.
		$attempts->record($ip, $user, $success);

		if (!$success) {
			// Constant-ish delay
			usleep(random_int(100_000, 400_000));
			$this->redirect('/admin/login?error=invalid');
			return;
		}

		if (!$this->kernel->isIpAllowed($ip)) {
			$this->redirect('/admin/login?error=ip_blocked');
			return;
		}

		session_regenerate_id(true);
		$_SESSION['admin_user_id'] = $user;
		$_SESSION['admin_user']    = ['name' => $user, 'login_at' => time()];

		$this->kernel->get(\PDO::class)->prepare('INSERT INTO audit_log
			(event, entity, meta_json, ip, created_at)
			VALUES ("admin.login", "admin", :meta, :ip, UTC_TIMESTAMP(3))')
			->execute([
				':meta' => json_encode(['user' => $user]),
				':ip'   => $ip,
			]);

		$this->redirect('/admin/');
	}

	public function logout(array $params): void
	{
		$_SESSION = [];
		session_destroy();
		$this->redirect('/admin/login');
	}
}
