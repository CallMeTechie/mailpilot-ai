<?php
/**
 * @var array{today:array, d7:array, d30:array} $cards
 * @var list<array{date:string, calls:int, input_tokens:int, output_tokens:int, cost_eur:float}> $trend
 * @var list<array{prompt_version:string, calls:int, output_tokens:int, cost_eur:float}> $byPrompt
 * @var list<array{user_id:string, email:string, calls:int, output_tokens:int, cost_eur:float}> $topUsers
 * @var list<array<string, mixed>> $recent
 */

$h   = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$num = fn(int|float $n): string => number_format($n, 0, ',', '.');
$eur = fn(float $n): string => '€ ' . number_format($n, 4, ',', '.');
$ms  = fn(int $n): string => $n < 1000 ? $n . ' ms' : number_format($n / 1000, 1, ',', '.') . ' s';

$maxCost = 0.0;
foreach ($trend as $t) { if ($t['cost_eur'] > $maxCost) $maxCost = (float)$t['cost_eur']; }
$maxCost = max($maxCost, 0.0001);
?>

<header class="page-head">
	<h1>Token-Nutzung &amp; Kosten</h1>
	<p class="muted">Live-Daten aus <code>api_usage</code> und <code>usage_daily</code> (UTC-Tage).</p>
</header>

<section class="cards-row">
	<?php foreach (['today' => 'Heute', 'd7' => '7 Tage', 'd30' => '30 Tage'] as $k => $label):
		$c = $cards[$k]; ?>
		<article class="usage-card">
			<header class="usage-card-title"><?= $h($label) ?></header>
			<div class="usage-card-primary"><?= $eur((float)$c['cost_eur']) ?></div>
			<dl class="usage-card-stats">
				<div><dt>Calls</dt><dd><?= $num((int)$c['calls']) ?></dd></div>
				<div><dt>Output-Tokens</dt><dd><?= $num((int)$c['output_tokens']) ?></dd></div>
				<div><dt>Input-Tokens</dt><dd><?= $num((int)$c['input_tokens']) ?></dd></div>
				<?php if ((int)$c['blocked'] > 0): ?>
					<div class="blocked"><dt>Blockiert</dt><dd><?= $num((int)$c['blocked']) ?></dd></div>
				<?php endif; ?>
			</dl>
		</article>
	<?php endforeach; ?>
</section>

<section class="panel">
	<h2>Kosten-Verlauf (30 Tage)</h2>
	<?php if ($trend === []): ?>
		<p class="muted">Noch keine Aktivität.</p>
	<?php else: ?>
		<div class="chart-trend">
			<?php foreach ($trend as $t): $hpct = (int)round(((float)$t['cost_eur'] / $maxCost) * 100); ?>
				<div class="chart-bar" title="<?= $h($t['date']) ?> — <?= $eur((float)$t['cost_eur']) ?> · <?= $num((int)$t['calls']) ?> Calls">
					<div class="chart-bar-fill" style="height: <?= $hpct ?>%"></div>
					<div class="chart-bar-label"><?= $h(substr($t['date'], 5)) ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<section class="panel two-col">
	<div>
		<h2>Pro Prompt (30 Tage)</h2>
		<?php if ($byPrompt === []): ?>
			<p class="muted">Keine Daten.</p>
		<?php else: ?>
			<table class="data-table">
				<thead><tr><th>Prompt</th><th>Calls</th><th>Output</th><th>Kosten</th></tr></thead>
				<tbody>
					<?php foreach ($byPrompt as $p): ?>
						<tr>
							<td><code><?= $h((string)$p['prompt_version']) ?></code></td>
							<td><?= $num((int)$p['calls']) ?></td>
							<td><?= $num((int)$p['output_tokens']) ?></td>
							<td><?= $eur((float)$p['cost_eur']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div>
		<h2>Top-User (30 Tage)</h2>
		<?php if ($topUsers === []): ?>
			<p class="muted">Keine User-zugeordneten Calls.</p>
		<?php else: ?>
			<table class="data-table">
				<thead><tr><th>User</th><th>Calls</th><th>Output</th><th>Kosten</th></tr></thead>
				<tbody>
					<?php foreach ($topUsers as $u): ?>
						<tr>
							<td><?= $h((string)$u['email']) ?></td>
							<td><?= $num((int)$u['calls']) ?></td>
							<td><?= $num((int)$u['output_tokens']) ?></td>
							<td><?= $eur((float)$u['cost_eur']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</section>

<section class="panel">
	<h2>Letzte 50 Calls</h2>
	<?php if ($recent === []): ?>
		<p class="muted">Noch keine Calls.</p>
	<?php else: ?>
		<table class="data-table dense">
			<thead>
				<tr>
					<th>Zeit (UTC)</th>
					<th>User</th>
					<th>Prompt</th>
					<th>Modell</th>
					<th>In</th>
					<th>Out</th>
					<th>Dauer</th>
					<th>Kosten</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($recent as $r): ?>
					<tr class="status-<?= $h((string)$r['status']) ?>">
						<td><?= $h(substr((string)$r['created_at'], 0, 19)) ?></td>
						<td><?= $h((string)($r['user_email'] ?? '—')) ?></td>
						<td><code><?= $h((string)$r['prompt_version']) ?></code></td>
						<td class="muted"><?= $h((string)$r['model']) ?></td>
						<td><?= $num((int)$r['input_tokens']) ?></td>
						<td><?= $num((int)$r['output_tokens']) ?></td>
						<td><?= $ms((int)$r['duration_ms']) ?></td>
						<td><?= $eur((float)$r['cost_eur']) ?></td>
						<td>
							<?php if ($r['status'] === 'success'): ?>OK
							<?php elseif ($r['status'] === 'blocked'): ?><span class="pill pill-warn">Blockiert</span>
							<?php else: ?><span class="pill pill-error" title="<?= $h((string)($r['error_text'] ?? '')) ?>">Fehler</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
