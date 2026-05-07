<?php /** @var array $entries */ /** @var string $filter */ ?>
<h1>Audit Log</h1>

<form method="GET" action="/admin/audit" class="inline-form">
	<input type="text" name="event" value="<?= htmlspecialchars($filter) ?>" placeholder="Event-Filter (z.B. admin.)">
	<button type="submit" class="btn btn-ghost">Filtern</button>
</form>

<table>
	<thead>
		<tr><th>Zeit</th><th>Event</th><th>Entity</th><th>IP</th><th>Meta</th></tr>
	</thead>
	<tbody>
	<?php foreach ($entries as $e): ?>
		<tr>
			<td><code><?= htmlspecialchars($e['created_at']) ?></code></td>
			<td><strong><?= htmlspecialchars($e['event']) ?></strong></td>
			<td><?= htmlspecialchars($e['entity'] ?? '') ?> <code class="muted"><?= htmlspecialchars(substr($e['entity_id'] ?? '', 0, 8)) ?></code></td>
			<td><?= htmlspecialchars($e['ip'] ?? '') ?></td>
			<td><code class="muted"><?= htmlspecialchars((string)$e['meta_json']) ?></code></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
