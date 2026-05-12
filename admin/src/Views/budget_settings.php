<?php
/**
 * @var array<string, string> $budgets
 * @var list<array<string, mixed>> $prices
 * @var list<array<string, mixed>> $prompts
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<header class="page-head">
	<h1>Budgets &amp; Pricing</h1>
	<p class="muted">Tageslimits, Modellpreise (in EUR pro 1 Mio Tokens) und Prompt-Tokenlimits.</p>
</header>

<section class="panel">
	<h2>Tagesbudgets (Output-Tokens)</h2>
	<form method="post" action="/admin/settings/budgets" class="form-grid">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">

		<label>
			<span>Global pro Tag</span>
			<input type="number" name="budget.global.daily_tokens" min="0" value="<?= $h($budgets['budget.global.daily_tokens']) ?>">
			<small class="muted">System-weit, über alle Mandanten</small>
		</label>

		<label>
			<span>Mandant pro Tag</span>
			<input type="number" name="budget.tenant.daily_tokens" min="0" value="<?= $h($budgets['budget.tenant.daily_tokens']) ?>">
			<small class="muted">Pro Mandant, Default-Wert</small>
		</label>

		<label>
			<span>User pro Tag</span>
			<input type="number" name="budget.user.daily_tokens" min="0" value="<?= $h($budgets['budget.user.daily_tokens']) ?>">
			<small class="muted">Pro Benutzer, Default-Wert</small>
		</label>

		<label>
			<span>Enforcement-Modus</span>
			<select name="budget.enforcement_mode">
				<?php $m = $budgets['budget.enforcement_mode']; ?>
				<option value="enforce"  <?= $m === 'enforce'  ? 'selected' : '' ?>>enforce — blockiert bei Überschreitung</option>
				<option value="log_only" <?= $m === 'log_only' ? 'selected' : '' ?>>log_only — nur loggen, kein Block</option>
			</select>
			<small class="muted">Soft-Rollout: erst log_only, dann enforce.</small>
		</label>

		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Budgets speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>Modell-Preise (EUR pro 1 Mio Tokens)</h2>
	<form method="post" action="/admin/settings/budgets/pricing">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<table class="data-table">
			<thead>
				<tr>
					<th>Modell</th>
					<th>Input €/M</th>
					<th>Output €/M</th>
					<th>Cache-Read €/M</th>
					<th>Cache-Creation €/M</th>
					<th>Stand</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($prices as $i => $p): ?>
					<tr>
						<td>
							<code><?= $h((string)$p['model']) ?></code>
							<input type="hidden" name="pricing[<?= $i ?>][model]" value="<?= $h((string)$p['model']) ?>">
						</td>
						<td><input type="text" inputmode="decimal" name="pricing[<?= $i ?>][input]"          value="<?= $h(number_format((float)$p['input_eur_per_1m'],  4, '.', '')) ?>"></td>
						<td><input type="text" inputmode="decimal" name="pricing[<?= $i ?>][output]"         value="<?= $h(number_format((float)$p['output_eur_per_1m'], 4, '.', '')) ?>"></td>
						<td><input type="text" inputmode="decimal" name="pricing[<?= $i ?>][cache_read]"     value="<?= $p['cache_read_eur_per_1m']     !== null ? $h(number_format((float)$p['cache_read_eur_per_1m'],     4, '.', '')) : '' ?>"></td>
						<td><input type="text" inputmode="decimal" name="pricing[<?= $i ?>][cache_creation]" value="<?= $p['cache_creation_eur_per_1m'] !== null ? $h(number_format((float)$p['cache_creation_eur_per_1m'], 4, '.', '')) : '' ?>"></td>
						<td class="muted"><?= $h(substr((string)$p['updated_at'], 0, 10)) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Preise speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>Prompt-Tokenlimits</h2>
	<p class="muted">Override pro Prompt-Version. <code>0</code> = Default (dynamisch aus Code).</p>
	<form method="post" action="/admin/settings/budgets/prompt-tokens">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<table class="data-table">
			<thead><tr><th>Prompt</th><th>Version</th><th>Modell</th><th>max_tokens</th><th>Aktiv</th></tr></thead>
			<tbody>
				<?php foreach ($prompts as $p): ?>
					<tr>
						<td><code><?= $h((string)$p['key_name']) ?></code></td>
						<td><?= $h((string)$p['version']) ?></td>
						<td class="muted"><?= $h((string)$p['model']) ?></td>
						<td><input type="number" min="0" name="prompt_max_tokens[<?= $h((string)$p['id']) ?>]" value="<?= (int)$p['max_tokens'] ?>"></td>
						<td><?= (int)$p['active'] === 1 ? '✓' : '—' ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Prompt-Limits speichern</button>
		</div>
	</form>
</section>
