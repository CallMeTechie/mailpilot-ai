<?php /** @var array $rows */ /** @var array $summary */ ?>
<h1>Claude Cache</h1>

<section class="kpi-grid">
	<div class="kpi">
		<div class="kpi-label">Einträge</div>
		<div class="kpi-value"><?= $summary['entries'] ?? 0 ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Gesamt-Hits</div>
		<div class="kpi-value"><?= $summary['total_hits'] ?? 0 ?></div>
	</div>
</section>

<section class="card">
	<h2>Cache leeren</h2>
	<form method="POST" action="/admin/cache/purge" class="inline-form"
		  onsubmit="return confirm('Cache wirklich leeren?')">
		<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">
		<select name="scope">
			<option value="expired">Nur abgelaufene (> 30 Tage)</option>
			<option value="all">Komplett leeren</option>
		</select>
		<button type="submit" class="btn btn-danger">Leeren</button>
	</form>
</section>

<section class="card">
	<h2>Top Einträge (nach letztem Zugriff)</h2>
	<table>
		<thead>
			<tr><th>Hash</th><th>Prompt</th><th>Modell</th><th>Hits</th><th>Erstellt</th><th>Letzter Hit</th></tr>
		</thead>
		<tbody>
		<?php foreach ($rows as $r): ?>
			<tr>
				<td><code><?= htmlspecialchars(substr($r['content_hash'], 0, 12)) ?>…</code></td>
				<td><?= htmlspecialchars($r['prompt_version']) ?></td>
				<td><code class="muted"><?= htmlspecialchars($r['model']) ?></code></td>
				<td><?= $r['hits'] ?></td>
				<td><?= htmlspecialchars(substr($r['created_at'], 0, 19)) ?></td>
				<td><?= htmlspecialchars(substr($r['last_hit_at'], 0, 19)) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
