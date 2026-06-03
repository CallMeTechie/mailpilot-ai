<?php
/**
 * Phase 9q-F (Marc 2026-05-23) — LLM-Provider Edit-Form.
 *
 * @var array<string,mixed> $provider
 * @var list<array<string,mixed>> $models
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pid = (string)$provider['id'];
?>

<header class="page-head">
	<h1><?= $h((string)$provider['name']) ?> <small class="muted">(<?= $h((string)$provider['kind']) ?>)</small></h1>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← Zurueck</a>
		<form method="post" action="/admin/llm/<?= $h($pid) ?>/test" style="display:inline">
			<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
			<button class="btn btn-secondary btn-sm" type="submit">Verbindung testen</button>
		</form>
	</div>
</header>

<section class="panel">
	<h2>Grunddaten</h2>
	<form method="post" action="/admin/llm/<?= $h($pid) ?>" class="form-grid">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">

		<label class="settings-field">
			<span class="settings-key">Anzeige-Name</span>
			<input type="text" name="name" value="<?= $h((string)$provider['name']) ?>" required>
		</label>

		<label class="settings-field">
			<span class="settings-key">Base-URL</span>
			<input type="url" name="base_url" value="<?= $h((string)$provider['base_url']) ?>" required>
		</label>

		<label class="settings-field">
			<span class="settings-key">API-Key Env-Fallback</span>
			<input type="text" name="api_key_env_fallback" value="<?= $h((string)($provider['api_key_env_fallback'] ?? '')) ?>" placeholder="z. B. OPENAI_API_KEY">
			<small class="muted">Genutzt wenn DB-Key fehlt (Migrations-Pfad). Leer = nur DB.</small>
		</label>

		<label class="settings-field">
			<span class="settings-key">Priority</span>
			<input type="number" name="priority" min="0" max="1000" value="<?= $h((string)$provider['priority']) ?>">
			<small class="muted">Niedriger = hoeher in der Fallback-Chain.</small>
		</label>

		<label class="settings-field">
			<input type="checkbox" name="enabled" <?= (int)$provider['enabled'] === 1 ? 'checked' : '' ?>>
			Aktiv (in Routing-Chain einbeziehbar)
		</label>

		<label class="settings-field">
			<input type="checkbox" name="is_local" <?= (int)$provider['is_local'] === 1 ? 'checked' : '' ?>>
			Lokaler Provider (Mails verlassen das Netzwerk nicht)
		</label>

		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>API-Key</h2>
	<?php if ($provider['has_api_key']): ?>
		<p class="muted">Aktuell <strong>verschluesselt in der DB hinterlegt</strong> (libsodium secretbox). Neu eintragen ueberschreibt.</p>
	<?php else: ?>
		<p class="muted">Kein Key in DB. Wenn env-Fallback (<code><?= $h((string)($provider['api_key_env_fallback'] ?? '–')) ?></code>) gesetzt ist, wird der genutzt.</p>
	<?php endif; ?>
	<form method="post" action="/admin/llm/<?= $h($pid) ?>/api-key" class="form-grid">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<label class="settings-field">
			<span class="settings-key">Neuer API-Key</span>
			<input type="password" name="api_key" placeholder="sk-… / AIza… / mistral-…" autocomplete="off">
			<small class="muted">Wird sofort mit dem LLM_MASTER_KEY verschluesselt. Niemals im Klartext geloggt.</small>
		</label>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Key speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>Modelle</h2>
	<p class="muted">Pro Rolle (score/summary/draft/inference) kann der Provider verschiedene Modelle anbieten. Pricing in USD pro Million Token.</p>
	<form method="post" action="/admin/llm/<?= $h($pid) ?>/models/refresh" style="margin-bottom:1rem">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<button type="submit" class="btn btn-sm">Modelle aktualisieren</button>
		<span class="muted">Fragt die Models-API dieses Providers live ab.</span>
	</form>
	<?php
	$catalogJs = [];
	foreach (($catalog ?? []) as $c) {
		$catalogJs[(string)$c['model_id']] = $c['effort_levels'] !== null
			? (array)json_decode((string)$c['effort_levels'], true) : [];
	}
	?>
	<script>window.MP_CATALOG = <?= json_encode($catalogJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
	<table class="data">
		<thead>
			<tr>
				<th>Model-ID</th>
				<th>Rolle</th>
				<th>Effort</th>
				<th>Aktiv</th>
				<th>Priority</th>
				<th>$/Mtok in</th>
				<th>$/Mtok out</th>
				<th>Caching</th>
				<th>Max Context</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($models as $m): ?>
			<tr>
				<form method="post" action="/admin/llm/models/<?= $h((string)$m['id']) ?>">
					<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
					<input type="hidden" name="provider_id" value="<?= $h($pid) ?>">
					<td>
						<select name="model_id" class="mp-model">
							<?php
							$current = (string)$m['model_id'];
							$ids = array_keys($catalogJs);
							if (!in_array($current, $ids, true)) { array_unshift($ids, $current); }
							foreach ($ids as $mid): ?>
								<option value="<?= $h($mid) ?>" <?= $mid === $current ? 'selected' : '' ?>><?= $h($mid) ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><span class="badge"><?= $h((string)$m['role']) ?></span></td>
					<td>
						<select name="effort" class="mp-effort">
							<option value="">— (Default)</option>
							<?php foreach (['low', 'medium', 'high', 'xhigh', 'max'] as $lvl): ?>
								<option value="<?= $lvl ?>" <?= (string)($m['effort'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="checkbox" name="enabled" <?= (int)$m['enabled'] === 1 ? 'checked' : '' ?>></td>
					<td><input type="number" name="priority" min="0" max="1000" value="<?= $h((string)$m['priority']) ?>" style="width:5em"></td>
					<td><input type="number" step="0.0001" name="cost_in"  value="<?= $h($m['cost_per_mtok_in']  !== null ? (string)$m['cost_per_mtok_in']  : '') ?>" style="width:6em"></td>
					<td><input type="number" step="0.0001" name="cost_out" value="<?= $h($m['cost_per_mtok_out'] !== null ? (string)$m['cost_per_mtok_out'] : '') ?>" style="width:6em"></td>
					<td><?= (int)$m['supports_caching'] === 1 ? '✓' : '–' ?></td>
					<td><?= number_format((int)$m['max_context']) ?></td>
					<td><button type="submit" class="btn btn-sm">Speichern</button></td>
				</form>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
<script>
document.querySelectorAll('.mp-model').forEach(function (sel) {
	function sync() {
		var levels = (window.MP_CATALOG[sel.value] || []);
		var eff = sel.closest('tr').querySelector('.mp-effort');
		var cur = eff.value;
		eff.replaceChildren();
		var def = document.createElement('option');
		def.value = ''; def.textContent = '— (Default)';
		eff.appendChild(def);
		levels.forEach(function (l) {
			var o = document.createElement('option');
			o.value = l; o.textContent = l; if (l === cur) o.selected = true;
			eff.appendChild(o);
		});
		eff.disabled = levels.length === 0;
	}
	sel.addEventListener('change', sync);
	sync();
});
</script>
