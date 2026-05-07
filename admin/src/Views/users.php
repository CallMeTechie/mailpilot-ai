<?php /** @var array $users */ /** @var string $q */ ?>
<h1>Benutzer</h1>

<form method="GET" action="/admin/users" class="inline-form">
	<input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="E-Mail suchen…">
	<button type="submit" class="btn btn-ghost">Suchen</button>
</form>

<table>
	<thead>
		<tr><th>E-Mail</th><th>Mandant</th><th>Sprache</th><th>Letzter Login</th><th></th></tr>
	</thead>
	<tbody>
	<?php foreach ($users as $u): ?>
		<tr>
			<td><?= htmlspecialchars($u['email']) ?></td>
			<td><?= htmlspecialchars($u['tenant_name'] ?? '—') ?></td>
			<td><?= htmlspecialchars($u['language']) ?></td>
			<td><?= htmlspecialchars($u['last_login_at'] ?? 'nie') ?></td>
			<td><a href="/admin/users/<?= htmlspecialchars($u['id']) ?>" class="btn btn-ghost">Details</a></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
