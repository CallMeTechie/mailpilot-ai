<?php
/**
 * Phase 9q-F (Marc 2026-05-23) — LLM Routing-Mode + Privacy-Mode + Fallback-Chain pro Rolle.
 *
 * @var string $privacyMode
 * @var string $routingMode
 * @var array<string, array{configured:list<string>, available:list<array<string,mixed>>}> $chains
 * @var list<string> $roles
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<header class="page-head">
	<h1>Routing &amp; Privacy</h1>
	<p class="muted">Globaler Routing-Modus + Privacy-Mode + Fallback-Chain pro Rolle (score/summary/draft/inference).</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← Provider</a>
	</div>
</header>

<form method="post" action="/admin/llm/routing" class="form-stack">
	<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">

	<section class="panel">
		<h2>Routing-Modus</h2>
		<p class="muted">
			<strong>direct</strong> = bestehender Code-Pfad (AnthropicClient direkt, kein Failover).
			<strong>router</strong> = LlmRouter mit Fallback-Chain.
		</p>
		<label><input type="radio" name="routing_mode" value="direct"  <?= $routingMode === 'direct' ? 'checked' : '' ?>> direct (kein Failover)</label><br>
		<label><input type="radio" name="routing_mode" value="router"  <?= $routingMode === 'router' ? 'checked' : '' ?>> router (Failover aktiv)</label>
	</section>

	<section class="panel">
		<h2>Privacy-Mode</h2>
		<p class="muted">Steuert ob Cloud-Provider in der Fallback-Chain genutzt werden duerfen.</p>
		<label><input type="radio" name="privacy_mode" value="cloud_allowed"   <?= $privacyMode === 'cloud_allowed'   ? 'checked' : '' ?>> <strong>cloud_allowed</strong> — alle Provider in Reihenfolge</label><br>
		<label><input type="radio" name="privacy_mode" value="local_preferred" <?= $privacyMode === 'local_preferred' ? 'checked' : '' ?>> <strong>local_preferred</strong> — lokale Modelle zuerst, Cloud nur wenn lokal down</label><br>
		<label><input type="radio" name="privacy_mode" value="local_only"      <?= $privacyMode === 'local_only'      ? 'checked' : '' ?>> <strong>local_only</strong> — nur lokale Modelle, Mails verlassen niemals das Netz</label>
	</section>

	<section class="panel">
		<h2>Fallback-Chain pro Rolle</h2>
		<p class="muted">Kommagetrennte Provider-IDs in Reihenfolge. Primary zuerst, Fallback danach.</p>
		<?php foreach ($roles as $role): ?>
			<details <?= $role === 'score' ? 'open' : '' ?>>
				<summary><strong><?= $h($role) ?></strong> — <?= count($chains[$role]['configured']) ?> Provider in Chain</summary>
				<div class="form-grid">
					<label class="settings-field">
						<span class="settings-key">Chain (komma-getrennt, Provider-UUIDs)</span>
						<input type="text" name="chain_<?= $h($role) ?>" value="<?= $h(implode(',', $chains[$role]['configured'])) ?>" style="width:100%; font-family:monospace; font-size:11px">
					</label>
					<div>
						<small class="muted">Verfuegbare Modelle fuer diese Rolle:</small>
						<ul style="font-size:12px">
						<?php foreach ($chains[$role]['available'] as $a): ?>
							<li>
								<code><?= $h((string)$a['provider_id']) ?></code>
								— <?= $h((string)$a['provider_name']) ?>
								(<?= $h((string)$a['model_id']) ?>)
								<?= (int)$a['is_local'] === 1 ? '🏠' : '☁' ?>
							</li>
						<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</details>
		<?php endforeach; ?>
	</section>

	<div class="form-actions">
		<button type="submit" class="btn btn-primary">Routing speichern</button>
	</div>
</form>
