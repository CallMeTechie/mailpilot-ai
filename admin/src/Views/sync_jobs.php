<?php
/**
 * @var list<array<string, mixed>> $rows
 * @var string $filterStatus
 * @var array<string, int> $counts24h
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$badge = static function (string $status): string {
	return match ($status) {
		'queued'  => '<span class="pill pill-info">queued</span>',
		'running' => '<span class="pill pill-warn">running</span>',
		'done'    => '<span class="pill pill-ok">done</span>',
		'error'   => '<span class="pill pill-error">error</span>',
		default   => '<span class="pill">'. htmlspecialchars($status) .'</span>',
	};
};
?>

<header class="page-head">
	<h1>Sync-Jobs</h1>
	<p class="muted">Worker-Job-Historie der letzten 100 Einträge. Status-Counts beziehen sich auf die letzten 24 Stunden.</p>
</header>

<section class="cards-row">
	<?php foreach (['queued','running','done','error'] as $s):
		$n = (int)($counts24h[$s] ?? 0); ?>
		<article class="usage-card">
			<header class="usage-card-title"><?= $h(ucfirst($s)) ?> (24h)</header>
			<div class="usage-card-primary"><?= $n ?></div>
			<dl class="usage-card-stats">
				<div><dt>Filter</dt><dd><a href="/admin/sync-jobs?status=<?= $h($s) ?>">anzeigen</a></dd></div>
			</dl>
		</article>
	<?php endforeach; ?>
</section>

<section class="panel">
	<h2>
		<?php if ($filterStatus !== ''): ?>
			Letzte Jobs mit Status „<?= $h($filterStatus) ?>"
			<small class="muted">(<a href="/admin/sync-jobs">Filter aufheben</a>)</small>
		<?php else: ?>
			Alle Sync-Jobs (letzte 100)
		<?php endif; ?>
	</h2>
	<?php if ($rows === []): ?>
		<p class="muted">Keine Sync-Jobs<?= $filterStatus !== '' ? ' mit diesem Status' : '' ?>.</p>
	<?php else: ?>
		<table class="data-table dense">
			<thead>
				<tr>
					<th>Status</th>
					<th>Mailbox</th>
					<th>Mandant</th>
					<th>Total / Processed</th>
					<th>Start</th>
					<th>Ende</th>
					<th>Fehler</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $r): ?>
					<tr class="status-<?= $h((string)$r['status']) ?>">
						<td><?= $badge((string)$r['status']) ?></td>
						<td><?= $h((string)($r['mailbox_email'] ?? '—')) ?></td>
						<td class="muted"><?= $h((string)($r['tenant_name'] ?? '')) ?></td>
						<td><?= (int)($r['total'] ?? 0) ?> / <?= (int)($r['processed'] ?? 0) ?></td>
						<td><?= $h(substr((string)($r['started_at'] ?? ''), 0, 19) ?: '—') ?></td>
						<td><?= $h(substr((string)($r['finished_at'] ?? ''), 0, 19) ?: '—') ?></td>
						<td>
							<?php if (!empty($r['error_text'])): ?>
								<code class="error-text" title="<?= $h((string)$r['error_text']) ?>"><?= $h(substr((string)$r['error_text'], 0, 80)) ?><?= strlen((string)$r['error_text']) > 80 ? '…' : '' ?></code>
							<?php else: ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
