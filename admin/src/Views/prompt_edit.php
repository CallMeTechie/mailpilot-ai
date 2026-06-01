<?php /** @var ?array $prompt */ ?>
<div class="breadcrumb"><a href="/admin/prompts">← Prompts</a></div>
<h1><?= $prompt ? 'Prompt ansehen' : 'Neue Prompt-Version' ?></h1>

<?php /* Phase 9q B9 (Marc 2026-05-23): Field-Hints für jedes Eingabefeld. */ ?>
<form method="POST" action="/admin/prompts" class="form-stack">
	<input type="hidden" name="_csrf" value="<?= $this->csrfToken() ?>">

	<label class="settings-field">
		<span>Key</span>
		<select name="key_name" <?= $prompt ? 'disabled' : '' ?>>
			<option value="P-SCORE" <?= ($prompt['key_name'] ?? '') === 'P-SCORE' ? 'selected' : '' ?>>P-SCORE</option>
			<option value="P-SUMMARY" <?= ($prompt['key_name'] ?? '') === 'P-SUMMARY' ? 'selected' : '' ?>>P-SUMMARY</option>
			<option value="P-REPLY" <?= ($prompt['key_name'] ?? '') === 'P-REPLY' ? 'selected' : '' ?>>P-REPLY</option>
		</select>
		<small class="muted">
			<strong>P-SCORE</strong>: Triage-Batch (Haiku, Label + Priority).
			<strong>P-SUMMARY</strong>: Ein-Mail-Zusammenfassung (Opus).
			<strong>P-REPLY</strong>: Reply-Draft (Opus).
			Jeder Key kann genau eine aktive Version haben.
		</small>
	</label>

	<label class="settings-field">
		<span>Version</span>
		<input type="text" name="version" value="<?= htmlspecialchars($prompt['version'] ?? 'v1.1') ?>" required <?= $prompt ? 'disabled' : '' ?>>
		<small class="muted">Freitext, z. B. <code>v1.7</code> oder <code>v2.0-experimental</code>. UNIQUE pro (key_name, version) — Duplikat wird vom DB-Index abgewiesen.</small>
	</label>

	<p class="muted">
		<strong>Modell &amp; Effort</strong> werden pro Rolle in der
		<a href="/admin/llm">LLM-Provider-Ansicht</a> gesetzt (dynamisch aus dem
		Provider-Katalog). Dieser Editor steuert nur Prompt-Text, max_tokens und temperature.
	</p>

	<div class="field-row">
		<label class="settings-field">
			<span>Max Tokens</span>
			<input type="number" name="max_tokens" value="<?= $prompt['max_tokens'] ?? 2000 ?>" required <?= $prompt ? 'disabled' : '' ?>>
			<small class="muted">Obergrenze für Output-Tokens pro Call. P-SCORE: 2000 (Batch). P-SUMMARY: 400. P-REPLY: 800. Höher = teurer + langsamer.</small>
		</label>
		<label class="settings-field">
			<span>Temperature</span>
			<input type="number" name="temperature" step="0.01" min="0" max="1" value="<?= $prompt['temperature'] ?? 0.1 ?>" required <?= $prompt ? 'disabled' : '' ?>>
			<small class="muted">Determinismus: 0.0 = identisch, 1.0 = kreativ. Scoring: 0.0-0.1, Summary: 0.1-0.2, Reply: 0.3-0.5. Werte &gt; 0.7 verfälschen JSON-Antworten.</small>
		</label>
	</div>

	<label class="settings-field">
		<span>System Prompt</span>
		<textarea name="system_prompt" rows="12" required <?= $prompt ? 'disabled' : '' ?>><?= htmlspecialchars($prompt['system_prompt'] ?? '') ?></textarea>
		<small class="muted">Rolle/Persona/Regeln des Modells — wird bei jedem Call vorangestellt. Platzhalter <code>{{korrekturen}}</code>, <code>{{sublabels}}</code>, <code>{{topic_note}}</code> werden zur Laufzeit ersetzt (siehe ScoringPromptBuilder).</small>
	</label>

	<label class="settings-field">
		<span>User Template</span>
		<textarea name="user_template" rows="8" <?= $prompt ? 'disabled' : '' ?>><?= htmlspecialchars($prompt['user_template'] ?? '') ?></textarea>
		<small class="muted">Optional. Falls leer wird der Default-Render (From/Subject/Body) verwendet. Platzhalter <code>{{from}}</code>, <code>{{subject}}</code>, <code>{{body}}</code> verfügbar.</small>
	</label>

	<?php if (!$prompt): ?>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Anlegen (inaktiv)</button>
		</div>
		<p class="muted"><small>Neue Versionen werden <strong>inaktiv</strong> erstellt. Aktivierung erfolgt aus der Liste; dabei wird der Cache für den Key invalidiert.</small></p>
	<?php endif; ?>
</form>
