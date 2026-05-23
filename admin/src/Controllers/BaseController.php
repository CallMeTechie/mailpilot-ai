<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Admin\Kernel;

abstract class BaseController
{
	public function __construct(protected readonly Kernel $kernel)
	{
	}

	/**
	 * Render a PHP view file with extracted variables.
	 * Views live in admin/src/Views/*.php and use $this as helper.
	 */
	protected function render(string $view, array $vars = []): void
	{
		$vars['currentUser'] = $_SESSION['admin_user'] ?? null;
		$vars['path']        = $_SERVER['REQUEST_URI'] ?? '/';
		extract($vars, EXTR_SKIP);

		$viewFile = __DIR__ . '/../Views/' . $view . '.php';
		if (!is_file($viewFile)) {
			throw new \RuntimeException("View not found: {$view}");
		}

		ob_start();
		include $viewFile;
		$content = ob_get_clean();

		include __DIR__ . '/../Views/_layout.php';
	}

	/**
	 * Phase 9q-F (Marc 2026-05-23) — Open-Redirect-Schutz (CWE-601).
	 * Nur same-origin-Pfade erlaubt: muessen mit / beginnen, duerfen NICHT
	 * mit // anfangen (cross-protocol-Redirect wie //evil.com/...) und
	 * keine Newlines enthalten (Header-Injection). Bei Verstoss faellt
	 * der Redirect auf /admin/ zurueck statt zur Angreifer-URL.
	 */
	protected function redirect(string $path): never
	{
		if (
			$path === ''
			|| !str_starts_with($path, '/')
			|| str_starts_with($path, '//')
			|| str_contains($path, "\n")
			|| str_contains($path, "\r")
		) {
			$path = '/admin/';
		}
		header('Location: ' . $path);
		exit;
	}

	protected function flash(string $kind, string $message): void
	{
		$_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
	}

	protected function csrfToken(): string
	{
		if (empty($_SESSION['csrf'])) {
			$_SESSION['csrf'] = bin2hex(random_bytes(16));
		}
		return $_SESSION['csrf'];
	}

	protected function verifyCsrf(): void
	{
		$token = $_POST['_csrf'] ?? '';
		if (!is_string($token) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
			http_response_code(403);
			echo 'CSRF validation failed';
			exit;
		}
	}

	protected function h(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
