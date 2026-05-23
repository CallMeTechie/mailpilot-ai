<?php
/**
 * Phase 9q-F (Marc 2026-05-23) — LLM-Provider Liste.
 *
 * @var list<array<string,mixed>> $providers
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<header class="page-head">
	<h1>LLM-Provider</h1>
	<p class="muted">Multi-Provider-Inferenz mit Failover-Chain. Cloud + lokale Modelle (Ollama/LM-Studio). Privacy-Mode steuert ob Mails das Netz verlassen duerfen.</p>
	<div class="form-actions">
		<a class="btn btn-secondary" href="/admin/llm/routing">Routing &amp; Privacy-Mode →</a>
	</div>
</header>

<section class="panel">
	<table class="data">
		<thead>
			<tr>
				<th>Name</th>
				<th>Kind</th>
				<th>Base-URL</th>
				<th>Lokal?</th>
				<th>API-Key</th>
				<th>Modelle</th>
				<th>Status</th>
				<th class="actions">Aktionen</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($providers as $p): ?>
			<tr>
				<td><strong><?= $h((string)$p['name']) ?></strong></td>
				<td><code><?= $h((string)$p['kind']) ?></code></td>
				<td class="truncate"><code><?= $h((string)$p['base_url']) ?></code></td>
				<td><?= ((int)$p['is_local'] === 1) ? '✓ ja' : '–' ?></td>
				<td>
					<?php if ($p['has_api_key']): ?>
						<span class="badge badge-ok">verschluesselt</span>
					<?php elseif (!empty($p['api_key_env_fallback'])): ?>
						<span class="badge">env: <?= $h((string)$p['api_key_env_fallback']) ?></span>
					<?php else: ?>
						<span class="badge badge-warn">fehlt</span>
					<?php endif; ?>
				</td>
				<td><?= count((array)$p['models']) ?></td>
				<td>
					<?php if ((int)$p['enabled'] === 1): ?>
						<span class="badge badge-ok">aktiv</span>
					<?php else: ?>
						<span class="badge badge-muted">inaktiv</span>
					<?php endif; ?>
				</td>
				<td class="actions">
					<a class="btn btn-secondary btn-sm" href="/admin/llm/<?= $h((string)$p['id']) ?>">Bearbeiten</a>
					<form method="post" action="/admin/llm/<?= $h((string)$p['id']) ?>/toggle" style="display:inline">
						<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
						<button class="btn btn-sm" type="submit">
							<?= (int)$p['enabled'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
						</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
