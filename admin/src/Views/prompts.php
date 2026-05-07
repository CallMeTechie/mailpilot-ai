<?php /** @var array $prompts */ ?>
<h1>Prompt-Versionen</h1>
<p class="muted">Verwaltet die versionierten Prompts für Scoring, Summary und Reply-Drafting.</p>

<p><a href="/admin/prompts/new" class="btn btn-primary">+ Neue Version</a></p>

<table>
	<thead>
		<tr><th>Key</th><th>Version</th><th>Modell</th><th>Tokens</th><th>Temp</th><th>Aktiv</th><th>Erstellt</th><th></th></tr>
	</thead>
	<tbody>
	<?php foreach ($prompts as $p): ?>
		<tr>
			<td><strong><?= htmlspecialchars($p['key_name']) ?></strong></td>
			<td><?= htmlspecialchars($p['version']) ?></td>
			<td><code><?= htmlspecialchars($p['model']) ?></code></td>
			<td><?= $p['max_tokens'] ?></td>
			<td><?= $p['temperature'] ?></td>
			<td><?= $p['active'] ? '<span class="badge badge-active">aktiv</span>' : '—' ?></td>
			<td><?= htmlspecialchars(substr($p['created_at'], 0, 10)) ?></td>
			<td>
				<a href="/admin/prompts/<?= htmlspecialchars($p['id']) ?>" class="btn btn-ghost">Ansehen</a>
				<?php if (!$p['active']): ?>
					<form method="POST" action="/admin/prompts/<?= htmlspecialchars($p['id']) ?>/activate" style="display:inline">
						<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">
						<button type="submit" class="btn btn-primary">Aktivieren</button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
