<?php /** @var array $tenants */ ?>
<h1>Mandanten</h1>

<table>
	<thead>
		<tr><th>Name</th><th>Plan</th><th>Benutzer</th><th>Mailboxen</th><th>Erstellt</th><th></th></tr>
	</thead>
	<tbody>
	<?php foreach ($tenants as $t): ?>
		<tr>
			<td><strong><?= htmlspecialchars($t['name']) ?></strong><br><code class="muted"><?= htmlspecialchars($t['id']) ?></code></td>
			<td><span class="badge badge-plan"><?= htmlspecialchars($t['plan']) ?></span></td>
			<td><?= $t['user_count'] ?></td>
			<td><?= $t['mailbox_count'] ?></td>
			<td><?= htmlspecialchars(substr($t['created_at'], 0, 10)) ?></td>
			<td><a href="/admin/tenants/<?= htmlspecialchars($t['id']) ?>" class="btn btn-ghost">Anzeigen</a></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
