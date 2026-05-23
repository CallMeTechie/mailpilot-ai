<?php
/**
 * Phase 9q-G (Marc 2026-05-23) — Usage- und Cost-Dashboard.
 *
 * @var int $days
 * @var list<array<string,mixed>> $perProvider
 * @var list<array<string,mixed>> $perModel
 * @var int   $totalCalls
 * @var int   $totalErrors
 * @var float $totalUsd
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<header class="page-head">
	<h1>LLM Usage &amp; Kosten</h1>
	<p class="muted">Aus <code>llm_call_log</code>. Aggregation pro Provider/Model fuer den gewaehlten Zeitraum.</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← LLM-Provider</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/golden">Golden-Set Quality →</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/routing">Routing &amp; Privacy-Mode →</a>
	</div>
	<form method="get" action="/admin/llm/usage" class="form-inline">
		<label>Zeitraum (Tage):
			<select name="days" onchange="this.form.submit()">
				<?php foreach ([1, 7, 30, 90, 365] as $opt): ?>
					<option value="<?= $opt ?>" <?= $days === $opt ? 'selected' : '' ?>><?= $opt ?>d</option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>
</header>

<section class="panel">
	<h2>Summary (<?= $h((string)$days) ?>d)</h2>
	<dl class="kv-grid">
		<dt>Calls gesamt</dt>          <dd><?= number_format($totalCalls) ?></dd>
		<dt>Errors</dt>                 <dd><?= number_format($totalErrors) ?> (<?= $totalCalls ? number_format($totalErrors / $totalCalls * 100, 1) : '0' ?> %)</dd>
		<dt>Token-Kosten</dt>           <dd>$<?= number_format($totalUsd, 4) ?> <small class="muted">USD</small></dd>
	</dl>
	<p class="muted" style="margin-top: var(--mp-sp-2); font-size: 11px">
		<strong>Hinweis Waehrung:</strong> Alle Kosten in USD — LLM-APIs (Anthropic/OpenAI/Gemini/Mistral)
		rechnen ausschliesslich in USD. EUR-Konversion bewusst weggelassen, weil Wechselkurs-Schwankungen
		Kosten-Trends verfaelschen wuerden. Faustregel: 1 USD ≈ 0,92 EUR (Stand Q2/2026).
	</p>
</section>

<section class="panel">
	<h2>Pro Provider</h2>
	<?php if ($perProvider === []): ?>
		<p class="muted">Keine Calls im gewaehlten Zeitraum.</p>
	<?php else: ?>
	<table class="data">
		<thead>
			<tr>
				<th>Provider</th>
				<th>Calls</th>
				<th>Errors</th>
				<th>Input-Tokens</th>
				<th>Output-Tokens</th>
				<th>Cached</th>
				<th>Cost (USD)</th>
				<th>Avg-Latenz</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($perProvider as $r): ?>
			<tr>
				<td><strong><?= $h((string)$r['provider_name']) ?></strong></td>
				<td><?= number_format((int)$r['calls']) ?></td>
				<td>
					<?php if ((int)$r['errors'] > 0): ?>
						<span class="badge badge-warn"><?= number_format((int)$r['errors']) ?></span>
					<?php else: ?>
						0
					<?php endif; ?>
				</td>
				<td><?= number_format((int)$r['input_tokens']) ?></td>
				<td><?= number_format((int)$r['output_tokens']) ?></td>
				<td><?= number_format((int)$r['cached_tokens']) ?></td>
				<td>$<?= number_format((float)$r['total_usd'], 4) ?></td>
				<td><?= number_format((int)$r['avg_latency_ms']) ?> ms</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>

<section class="panel">
	<h2>Pro Provider × Model × Rolle</h2>
	<?php if ($perModel === []): ?>
		<p class="muted">Keine Calls im gewaehlten Zeitraum.</p>
	<?php else: ?>
	<table class="data">
		<thead>
			<tr>
				<th>Provider</th>
				<th>Model</th>
				<th>Rolle</th>
				<th>Calls</th>
				<th>Input</th>
				<th>Output</th>
				<th>Cost (USD)</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($perModel as $r): ?>
			<tr>
				<td><?= $h((string)$r['provider_name']) ?></td>
				<td><code><?= $h((string)$r['model_id_str']) ?></code></td>
				<td><span class="badge"><?= $h((string)$r['role']) ?></span></td>
				<td><?= number_format((int)$r['calls']) ?></td>
				<td><?= number_format((int)$r['input_tokens']) ?></td>
				<td><?= number_format((int)$r['output_tokens']) ?></td>
				<td>$<?= number_format((float)$r['total_usd'], 4) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
