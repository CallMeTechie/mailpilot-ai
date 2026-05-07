<?php /** @var array $user */ /** @var array $mailboxes */ /** @var array $vips */ /** @var array $keywords */ ?>
<div class="breadcrumb"><a href="/admin/users">← Benutzer</a></div>
<h1><?= htmlspecialchars($user['email']) ?></h1>
<p class="muted"><code><?= htmlspecialchars($user['id']) ?></code></p>

<section class="card">
	<h2>Profil</h2>
	<dl>
		<dt>Name</dt><dd><?= htmlspecialchars($user['display_name'] ?? '—') ?></dd>
		<dt>Sprache</dt><dd><?= htmlspecialchars($user['language']) ?></dd>
		<dt>Zeitzone</dt><dd><?= htmlspecialchars($user['timezone']) ?></dd>
		<dt>Briefing-Stunde</dt><dd><?= htmlspecialchars($user['briefing_hour']) ?></dd>
		<dt>Erstellt</dt><dd><?= htmlspecialchars($user['created_at']) ?></dd>
		<dt>Letzter Login</dt><dd><?= htmlspecialchars($user['last_login_at'] ?? 'nie') ?></dd>
	</dl>
</section>

<section class="card">
	<h2>Mailboxen</h2>
	<?php if ($mailboxes === []): ?>
		<p class="muted">Keine Mailboxen verbunden.</p>
	<?php else: ?>
		<ul>
			<?php foreach ($mailboxes as $m): ?>
				<li><?= htmlspecialchars($m['email']) ?> — letzter Sync: <?= htmlspecialchars($m['last_sync_at'] ?? 'nie') ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="card">
	<h2>VIP-Absender (<?= count($vips) ?>)</h2>
	<?php if ($vips === []): ?>
		<p class="muted">Keine VIPs.</p>
	<?php else: ?>
		<ul>
			<?php foreach ($vips as $v): ?>
				<li><?= htmlspecialchars($v['email']) ?> <?= $v['display_name'] ? '(' . htmlspecialchars($v['display_name']) . ')' : '' ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="card">
	<h2>Projekt-Stichworte</h2>
	<p><?= implode(', ', array_map('htmlspecialchars', $keywords)) ?: '<span class="muted">keine</span>' ?></p>
</section>

<section class="card danger">
	<h2>Gefahrenzone</h2>
	<form method="POST" action="/admin/users/<?= htmlspecialchars($user['id']) ?>/delete"
		  onsubmit="return confirm('User wirklich soft-löschen?')">
		<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">
		<button type="submit" class="btn btn-danger">User soft-löschen</button>
	</form>
</section>
