<?php /** @var string $content */ /** @var ?array $currentUser */ /** @var string $path */ ?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>MailPilot Admin</title>
	<link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
<?php if ($currentUser !== null): ?>
<nav class="sidebar">
	<div class="brand">
		<strong>MailPilot</strong>
		<span class="badge">Admin</span>
	</div>
	<ul class="nav">
		<li class="<?= str_ends_with($path, '/admin/') || $path === '/admin' ? 'active' : '' ?>"><a href="/admin/">Dashboard</a></li>
		<li class="<?= str_contains($path, '/admin/tenants') ? 'active' : '' ?>"><a href="/admin/tenants">Mandanten</a></li>
		<li class="<?= str_contains($path, '/admin/users') ? 'active' : '' ?>"><a href="/admin/users">Benutzer</a></li>
		<li class="<?= str_contains($path, '/admin/prompts') ? 'active' : '' ?>"><a href="/admin/prompts">Prompts</a></li>
		<li class="<?= str_contains($path, '/admin/cache') ? 'active' : '' ?>"><a href="/admin/cache">Cache</a></li>
		<li class="<?= str_contains($path, '/admin/usage') ? 'active' : '' ?>"><a href="/admin/usage">Token-Nutzung</a></li>
		<li class="<?= str_contains($path, '/admin/settings/budgets') ? 'active' : '' ?>"><a href="/admin/settings/budgets">Budgets</a></li>
		<li class="<?= str_contains($path, '/admin/settings/system')  ? 'active' : '' ?>"><a href="/admin/settings/system">System</a></li>
		<li class="<?= str_contains($path, '/admin/sync-jobs') ? 'active' : '' ?>"><a href="/admin/sync-jobs">Sync-Jobs</a></li>
		<li class="<?= str_contains($path, '/admin/audit') ? 'active' : '' ?>"><a href="/admin/audit">Audit Log</a></li>
	</ul>
	<div class="sidebar-foot">
		<span class="muted"><?= htmlspecialchars($currentUser['name']) ?></span>
		<a href="/admin/logout" class="logout">Abmelden</a>
	</div>
</nav>
<?php endif; ?>

<main class="main <?= $currentUser === null ? 'main-bare' : '' ?>">
	<?php if (!empty($_SESSION['flash'])): ?>
		<div class="flash flash-<?= htmlspecialchars($_SESSION['flash']['kind']) ?>">
			<?= htmlspecialchars($_SESSION['flash']['message']) ?>
		</div>
		<?php unset($_SESSION['flash']); ?>
	<?php endif; ?>

	<?= $content ?>
</main>
</body>
</html>
