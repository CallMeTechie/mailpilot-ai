<?php /** @var ?array $prompt */ ?>
<div class="breadcrumb"><a href="/admin/prompts">← Prompts</a></div>
<h1><?= $prompt ? 'Prompt ansehen' : 'Neue Prompt-Version' ?></h1>

<form method="POST" action="/admin/prompts">
	<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">

	<label>
		<span>Key</span>
		<select name="key_name" <?= $prompt ? 'disabled' : '' ?>>
			<option value="P-SCORE" <?= ($prompt['key_name'] ?? '') === 'P-SCORE' ? 'selected' : '' ?>>P-SCORE</option>
			<option value="P-SUMMARY" <?= ($prompt['key_name'] ?? '') === 'P-SUMMARY' ? 'selected' : '' ?>>P-SUMMARY</option>
			<option value="P-REPLY" <?= ($prompt['key_name'] ?? '') === 'P-REPLY' ? 'selected' : '' ?>>P-REPLY</option>
		</select>
	</label>

	<label>
		<span>Version</span>
		<input type="text" name="version" value="<?= htmlspecialchars($prompt['version'] ?? 'v1.1') ?>" required <?= $prompt ? 'disabled' : '' ?>>
	</label>

	<label>
		<span>Modell</span>
		<input type="text" name="model" list="model-suggestions"
			value="<?= htmlspecialchars($prompt['model'] ?? 'claude-haiku-4-5-20251001') ?>"
			required <?= $prompt ? 'disabled' : '' ?>>
		<datalist id="model-suggestions">
			<option value="claude-haiku-4-5-20251001">Schnell + günstig — Scoring (P-SCORE)</option>
			<option value="claude-sonnet-4-6">Mittlerer Tier — Allgemein</option>
			<option value="claude-opus-4-7">Höchste Qualität — Summary / Reply</option>
		</datalist>
		<small class="muted">Vorschläge sind die im Code referenzierten Modelle. Freitext erlaubt für zukünftige Versionen.</small>
	</label>

	<div class="field-row">
		<label>
			<span>Max Tokens</span>
			<input type="number" name="max_tokens" value="<?= $prompt['max_tokens'] ?? 2000 ?>" required <?= $prompt ? 'disabled' : '' ?>>
		</label>
		<label>
			<span>Temperature</span>
			<input type="number" name="temperature" step="0.01" min="0" max="1" value="<?= $prompt['temperature'] ?? 0.1 ?>" required <?= $prompt ? 'disabled' : '' ?>>
		</label>
	</div>

	<label>
		<span>System Prompt</span>
		<textarea name="system_prompt" rows="12" required <?= $prompt ? 'disabled' : '' ?>><?= htmlspecialchars($prompt['system_prompt'] ?? '') ?></textarea>
	</label>

	<label>
		<span>User Template</span>
		<textarea name="user_template" rows="8" <?= $prompt ? 'disabled' : '' ?>><?= htmlspecialchars($prompt['user_template'] ?? '') ?></textarea>
	</label>

	<?php if (!$prompt): ?>
		<button type="submit" class="btn btn-primary">Anlegen (inaktiv)</button>
	<?php endif; ?>
</form>
