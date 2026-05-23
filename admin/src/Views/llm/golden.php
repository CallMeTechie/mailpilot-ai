<?php
/**
 * Phase 9q-H (Marc 2026-05-23) — Golden-Set + Run-Historie.
 *
 * @var list<array<string,mixed>> $set
 * @var list<array<string,mixed>> $recentRuns
 * @var list<array{provider_id:string, provider_name:string, model_id_str:string, role:string}> $runnableTargets
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pct = fn(int $correct, int $total): string => $total > 0
	? number_format($correct / $total * 100, 1) . ' %'
	: '–';
?>

<header class="page-head">
	<h1>Golden-Set Quality-Test</h1>
	<p class="muted">Pro Provider/Model laeuft das Golden-Set durch und liefert eine Accuracy-Zahl gegen Ground-Truth. Damit ist Provider-Auswahl datengetrieben.</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← LLM-Provider</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/usage">Usage &amp; Kosten</a>
	</div>
</header>

<section class="panel">
	<h2>Test starten</h2>
	<p class="muted">Synchroner Lauf — bei <?= count($set) ?> Mails × ~1-2s pro Call dauert es einige Sekunden.</p>
	<?php if ($runnableTargets === []): ?>
		<p class="muted">Keine aktiven Provider+Models gefunden. Aktiviere zuerst Provider in <a href="/admin/llm">LLM-Provider</a>.</p>
	<?php else: ?>
		<table class="data">
			<thead>
				<tr>
					<th>Provider</th>
					<th>Model</th>
					<th>Rolle</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($runnableTargets as $t): ?>
				<tr>
					<td><strong><?= $h((string)$t['provider_name']) ?></strong></td>
					<td><code><?= $h((string)$t['model_id_str']) ?></code></td>
					<td><span class="badge"><?= $h((string)$t['role']) ?></span></td>
					<td>
						<form method="post" action="/admin/llm/golden/run" style="display:inline" onsubmit="return mpGoldenRunSubmit(this);">
							<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
							<input type="hidden" name="provider_id" value="<?= $h((string)$t['provider_id']) ?>">
							<input type="hidden" name="role" value="<?= $h((string)$t['role']) ?>">
							<button type="submit" class="btn btn-secondary btn-sm">Test starten</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="panel">
	<h2>Run-Historie</h2>
	<?php if ($recentRuns === []): ?>
		<p class="muted">Noch keine Runs.</p>
	<?php else: ?>
		<table class="data">
			<thead>
				<tr>
					<th>Provider</th>
					<th>Model</th>
					<th>Rolle</th>
					<th>Mails</th>
					<th>Label-Accuracy</th>
					<th>Priority-Accuracy</th>
					<th>Avg-Latenz</th>
					<th>Cost</th>
					<th>Started</th>
					<th>Status</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($recentRuns as $r): ?>
				<tr>
					<td><?= $h((string)($r['provider_name'] ?? '–')) ?></td>
					<td><code><?= $h((string)$r['model_id_str']) ?></code></td>
					<td><span class="badge"><?= $h((string)$r['role']) ?></span></td>
					<td><?= number_format((int)$r['total_mails']) ?></td>
					<td><strong><?= $pct((int)$r['correct_label'], (int)$r['total_mails']) ?></strong>
						(<?= (int)$r['correct_label'] ?>/<?= (int)$r['total_mails'] ?>)</td>
					<td><?= $pct((int)$r['correct_priority'], (int)$r['total_mails']) ?>
						(<?= (int)$r['correct_priority'] ?>/<?= (int)$r['total_mails'] ?>)</td>
					<td><?= number_format((int)$r['avg_latency_ms']) ?> ms</td>
					<td>$<?= number_format((float)$r['total_cost_usd'], 4) ?></td>
					<td><small><?= $h((string)$r['started_at']) ?></small></td>
					<td>
						<?php if ($r['status'] === 'done'): ?>
							<span class="badge badge-ok">done</span>
						<?php elseif ($r['status'] === 'error'): ?>
							<span class="badge badge-warn">error</span>
						<?php else: ?>
							<span class="badge">running</span>
						<?php endif; ?>
					</td>
					<td>
						<form method="post" action="/admin/llm/golden/<?= $h((string)$r['id']) ?>/delete" style="display:inline" onsubmit="return confirm('Run wirklich loeschen?');">
							<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
							<button type="submit" class="btn btn-sm" title="Run loeschen">×</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Bulk-Purge (Phase 9q B2) -->
		<form method="post" action="/admin/llm/golden/purge" onsubmit="return confirm('Alle Runs aelter als ' + this.days.value + ' Tagen loeschen?');" class="form-inline" style="margin-top: var(--mp-sp-3)">
			<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
			<label>Aelter als
				<input type="number" name="days" value="30" min="1" max="365" style="width:5em">
				Tage loeschen
			</label>
			<button type="submit" class="btn btn-secondary btn-sm">Bulk-Cleanup</button>
		</form>
	<?php endif; ?>
</section>

<script>
// Phase 9q B1 (Marc 2026-05-23): Loading-State fuer Golden-Test-Submit.
// Run dauert 10-30s — ohne Feedback denkt User es passiert nichts.
function mpGoldenRunSubmit(form) {
	const allButtons = document.querySelectorAll('form[action="/admin/llm/golden/run"] button[type=submit]');
	allButtons.forEach(b => { b.disabled = true; b.style.opacity = '0.5'; });
	const btn = form.querySelector('button[type=submit]');
	if (btn) {
		btn.dataset.original = btn.textContent;
		btn.textContent = '⏳ Test läuft… (~15s)';
		btn.style.opacity = '1';
	}
	return true;
}
</script>

<section class="panel">
	<h2>Test-Mail-Set</h2>
	<p class="muted"><?= count($set) ?> manuell gelabelte „Truth"-Mails. Synthetisch — keine echten Personen oder Marken.</p>
	<table class="data">
		<thead>
			<tr>
				<th>Subject</th>
				<th>From</th>
				<th>Erwartetes Label</th>
				<th>Erw. Prio</th>
				<th>Notiz</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($set as $m): ?>
			<tr>
				<td><strong><?= $h((string)$m['subject']) ?></strong></td>
				<td><code><?= $h((string)$m['from_email']) ?></code></td>
				<td><span class="badge"><?= $h((string)$m['expected_label']) ?></span></td>
				<td><?= (int)$m['expected_priority'] ?></td>
				<td><small class="muted"><?= $h((string)($m['notes'] ?? '')) ?></small></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
