<?php /** @var array $stats */ /** @var array $labelDist */ /** @var int $errorJobs */ ?>
<h1>Dashboard</h1>

<?php if ($errorJobs > 0): ?>
<div class="flash flash-error">
	⚠ <?= $errorJobs ?> Sync-Jobs mit Fehlern in den letzten 24h — <a href="/admin/sync-jobs?status=error">Details anzeigen</a>
</div>
<?php endif; ?>

<section class="kpi-grid">
	<div class="kpi">
		<div class="kpi-label">Mandanten</div>
		<div class="kpi-value"><?= $stats['tenants'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Benutzer</div>
		<div class="kpi-value"><?= $stats['users'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Mailboxen</div>
		<div class="kpi-value"><?= $stats['mailboxes'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Mails (24h)</div>
		<div class="kpi-value"><?= $stats['mails_24h'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Scorings (24h)</div>
		<div class="kpi-value"><?= $stats['scores_24h'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Cache Einträge</div>
		<div class="kpi-value"><?= $stats['cache_entries'] ?></div>
	</div>
	<div class="kpi">
		<div class="kpi-label">Cache Hit-Ratio</div>
		<div class="kpi-value"><?= $stats['cache_hit_ratio'] ?>%</div>
	</div>
</section>

<section class="card">
	<h2>Label-Verteilung (7d)</h2>
	<table>
		<thead><tr><th>Label</th><th>Anzahl</th></tr></thead>
		<tbody>
			<?php foreach (['direct','action','cc','newsletter','auto','noise'] as $l): ?>
				<tr>
					<td><span class="badge badge-<?= $l ?>"><?= $l ?></span></td>
					<td><?= $labelDist[$l] ?? 0 ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
