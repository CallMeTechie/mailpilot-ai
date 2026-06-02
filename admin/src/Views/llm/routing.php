<?php
/**
 * Phase 9q-F (Marc 2026-05-23) — LLM Routing-Mode + Privacy-Mode + Fallback-Chain pro Rolle.
 *
 * @var string $privacyMode
 * @var string $routingMode
 * @var array<string, array{configured:list<string>, available:list<array<string,mixed>>}> $chains
 * @var list<string> $roles
 * @var int $scoringBatchSize
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$routingModeLabels = ['direct' => 'Direkt (kein Failover)', 'router' => 'Router (Failover aktiv)'];
$privacyLabels     = ['cloud_allowed' => 'Cloud erlaubt', 'local_preferred' => 'Lokal bevorzugt', 'local_only' => 'Nur lokal'];
$pl = fn(string $k): string => $privacyLabels[$k] ?? $k;
$rl = fn(string $k): string => $routingModeLabels[$k] ?? $k;
?>

<header class="page-head">
	<h1>Routing &amp; Privacy</h1>
	<p class="muted">Globaler Routing-Modus + Privacy-Mode + Fallback-Chain pro Rolle (score/summary/draft/inference).</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← LLM-Provider</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/usage">Usage &amp; Kosten</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/golden">Golden-Set Quality</a>
	</div>
</header>

<?php if ($routingMode === 'direct'): ?>
<div class="flash flash-warn" style="margin-bottom: var(--mp-sp-3)">
	<strong>Aktueller Routing-Modus: <?= $h($rl('direct')) ?></strong> — die Failover-Chain gilt nur für <code>score</code> (und <code>inference</code>) und ist im direct-Modus <strong>inaktiv</strong>: Scoring ruft den konfigurierten Claude-Provider (Anthropic oder Bedrock) direkt mit dem Modell aus dem P-SCORE-Prompt. <strong>Summary und Draft laufen unabhängig vom Modus immer über den Router.</strong> Für Failover beim Scoring unten „<?= $h($rl('router')) ?>" wählen.
</div>
<?php else: ?>
<div class="flash flash-success" style="margin-bottom: var(--mp-sp-3)">
	<strong>Aktueller Routing-Modus: <?= $h($rl('router')) ?></strong> — Failover-Chain aktiv. Privacy-Mode: <strong><?= $h($pl($privacyMode)) ?></strong>.
</div>
<?php endif; ?>

<form method="post" action="/admin/llm/routing" class="form-stack">
	<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">

	<section class="panel">
		<h2>Routing-Modus</h2>
		<p class="muted">
			<strong><?= $h($rl('direct')) ?></strong> = Scoring ruft den konfigurierten Claude-Provider (Anthropic oder Bedrock) direkt, ohne Failover.
			<strong><?= $h($rl('router')) ?></strong> = Scoring läuft über den LlmRouter mit Fallback-Chain.
			Summary/Draft nutzen den Router ohnehin immer.
		</p>
		<label><input type="radio" name="routing_mode" value="direct" <?= $routingMode === 'direct' ? 'checked' : '' ?>> <?= $h($rl('direct')) ?></label><br>
		<label><input type="radio" name="routing_mode" value="router" <?= $routingMode === 'router' ? 'checked' : '' ?>> <?= $h($rl('router')) ?></label>
	</section>

	<section class="panel">
		<h2>Privacy-Mode</h2>
		<p class="muted">Steuert ob Cloud-Provider in der Fallback-Chain genutzt werden duerfen.</p>
		<label><input type="radio" name="privacy_mode" value="cloud_allowed" <?= $privacyMode === 'cloud_allowed' ? 'checked' : '' ?>> <strong><?= $h($pl('cloud_allowed')) ?></strong> — alle Provider in Reihenfolge</label><br>
		<label><input type="radio" name="privacy_mode" value="local_preferred" <?= $privacyMode === 'local_preferred' ? 'checked' : '' ?>> <strong><?= $h($pl('local_preferred')) ?></strong> — lokale Modelle zuerst, Cloud nur als Fallback</label><br>
		<label><input type="radio" name="privacy_mode" value="local_only" <?= $privacyMode === 'local_only' ? 'checked' : '' ?>> <strong><?= $h($pl('local_only')) ?></strong> — nur lokale Modelle, Mails verlassen das Netz nie</label>
	</section>

	<section class="panel">
		<h2>Scoring</h2>
		<label class="settings-field">
			<span class="settings-key">Batch-Größe (Mails pro Scoring-Call)</span>
			<input type="number" name="scoring_batch_size" min="1" max="100" value="<?= $h((string)$scoringBatchSize) ?>" style="width:6em">
			<small class="muted">1 = kein Batching. Größer = günstiger/schneller, aber an die P-SCORE-<code>max_tokens</code> gekoppelt — zu groß ⇒ JSON-Truncation ⇒ ganzer Chunk fällt aus. Empfehlung: 20.</small>
		</label>
	</section>

	<section class="panel">
		<h2>Fallback-Chain pro Rolle</h2>
		<p class="muted">Reihenfolge = Priorität. Pro Slot einen Provider wählen; Primary zuerst, Fallbacks danach.</p>
		<?php foreach ($roles as $role): ?>
			<?php
			// Provider deduplizieren (eine Rolle kann pro Provider mehrere Modell-Rows haben)
			$provs = [];
			foreach ($chains[$role]['available'] as $a) {
				$pid = (string)$a['provider_id'];
				if (!isset($provs[$pid])) {
					$provs[$pid] = [
						'name'     => (string)($a['provider_name'] ?? $pid),
						'kind'     => (string)($a['provider_kind'] ?? ''),
						'is_local' => (int)($a['is_local'] ?? 0),
					];
				}
			}
			$configured = $chains[$role]['configured'];                 // geordnete provider_ids
			// konfigurierte-aber-nicht-verfügbare IDs erhalten (nicht still verlieren)
			foreach ($configured as $cid) {
				if (!isset($provs[$cid])) { $provs[$cid] = ['name' => $cid . ' (nicht verfügbar)', 'kind' => '', 'is_local' => 0]; }
			}
			$slotCount = max(count($provs), count($configured), 1);
			?>
			<details <?= $role === 'score' ? 'open' : '' ?>>
				<summary><strong><?= $h($role) ?></strong> — <?= count($configured) ?> Provider in der Chain</summary>
				<p class="muted">Reihenfolge = Priorität: Slot 1 = Primary, danach Fallbacks. „— (leer)" lässt den Slot weg.</p>
				<?php for ($i = 0; $i < $slotCount; $i++): $sel = $configured[$i] ?? ''; ?>
					<label class="settings-field">
						<span class="settings-key"><?= $i === 0 ? 'Primary' : 'Fallback ' . $i ?></span>
						<select name="chain_<?= $h($role) ?>[]">
							<option value="">— (leer)</option>
							<?php foreach ($provs as $pid => $p): ?>
								<option value="<?= $h($pid) ?>" <?= $pid === $sel ? 'selected' : '' ?>><?= $h($p['name']) ?><?= $p['kind'] !== '' ? ' (' . $h($p['kind']) . ')' : '' ?> <?= $p['is_local'] === 1 ? '🏠' : '☁' ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endfor; ?>
			</details>
		<?php endforeach; ?>
	</section>

	<div class="form-actions">
		<button type="submit" class="btn btn-primary">Routing speichern</button>
	</div>
</form>
