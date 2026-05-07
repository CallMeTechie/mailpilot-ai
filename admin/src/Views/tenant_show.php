<?php /** @var array $tenant */ /** @var array $users */ /** @var array $mailboxes */ /** @var array $scoreStats */ ?>
<div class="breadcrumb"><a href="/admin/tenants">← Mandanten</a></div>
<h1><?= htmlspecialchars($tenant['name']) ?></h1>
<p class="muted"><code><?= htmlspecialchars($tenant['id']) ?></code> · Plan: <?= htmlspecialchars($tenant['plan']) ?></p>

<section class="card">
	<h2>Plan ändern</h2>
	<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id']) ?>/plan" class="inline-form">
		<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">
		<select name="plan">
			<?php foreach (['free','pro','team','enterprise'] as $p): ?>
				<option value="<?= $p ?>" <?= $tenant['plan'] === $p ? 'selected' : '' ?>><?= $p ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="btn btn-primary">Speichern</button>
	</form>
</section>

<section class="card">
	<h2>Benutzer (<?= count($users) ?>)</h2>
	<table>
		<thead><tr><th>E-Mail</th><th>Rolle</th><th>Sprache</th><th>Letzter Login</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($users as $u): ?>
			<tr>
				<td><?= htmlspecialchars($u['email']) ?></td>
				<td><span class="badge"><?= htmlspecialchars($u['role']) ?></span></td>
				<td><?= htmlspecialchars($u['language']) ?></td>
				<td><?= htmlspecialchars($u['last_login_at'] ?? '—') ?></td>
				<td><a href="/admin/users/<?= htmlspecialchars($u['id']) ?>" class="btn btn-ghost">Details</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="card">
	<h2>Mailboxen (<?= count($mailboxes) ?>)</h2>
	<table>
		<thead><tr><th>E-Mail</th><th>Letzter Sync</th><th>Aktiv</th></tr></thead>
		<tbody>
		<?php foreach ($mailboxes as $m): ?>
			<tr>
				<td><?= htmlspecialchars($m['email']) ?></td>
				<td><?= htmlspecialchars($m['last_sync_at'] ?? 'nie') ?></td>
				<td><?= $m['sync_enabled'] ? '✓' : '✗' ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="card">
	<h2>Label-Verteilung (7d)</h2>
	<table>
		<thead><tr><th>Label</th><th>Anzahl</th></tr></thead>
		<tbody>
			<?php foreach (['direct','action','cc','newsletter','auto','noise'] as $l): ?>
				<tr>
					<td><span class="badge badge-<?= $l ?>"><?= $l ?></span></td>
					<td><?= $scoreStats[$l] ?? 0 ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="card danger">
	<h2>Gefahrenzone</h2>
	<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id']) ?>/delete"
		  onsubmit="return confirm('Mandant wirklich soft-löschen? Alle zugehörigen Mails, Scores und Tokens werden unzugänglich.')">
		<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">
		<button type="submit" class="btn btn-danger">Mandant soft-löschen</button>
	</form>
</section>
