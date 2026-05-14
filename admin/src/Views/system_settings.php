<?php
/**
 * @var list<array{key:string,value:string,type:string,description:string}> $snippets
 * @var list<array{key:string,value:string,type:string,description:string}> $tuning
 * @var list<array{key:string,value:string,type:string,description:string}> $folders
 * @var string $csrfToken
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<header class="page-head">
	<h1>System-Einstellungen</h1>
	<p class="muted">Prompt-Hilfsblöcke, Tuning-Konstanten und Default-Folder. Alle Werte stehen in <code>system_settings</code> und werden ohne Code-Deploy live.</p>
</header>

<section class="panel">
	<h2>Prompt-Hilfsblöcke</h2>
	<p class="muted">Werden vom Score-Prompt (<code>P-SCORE</code>) zur Laufzeit zusammengesetzt. <code>\n</code> im Discovery-Note wird beim Render zu echten Zeilenumbrüchen.</p>
	<form method="post" action="/admin/settings/system/snippets" class="form-stack">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<?php foreach ($snippets as $s):
			$isMulti = $s['key'] === 'prompt.topic_discovery_note';
		?>
			<label class="settings-field">
				<span class="settings-key"><code><?= $h($s['key']) ?></code></span>
				<?php if ($isMulti): ?>
					<textarea name="<?= $h($s['key']) ?>" rows="8" spellcheck="false"><?= $h($s['value']) ?></textarea>
				<?php else: ?>
					<input type="text" name="<?= $h($s['key']) ?>" value="<?= $h($s['value']) ?>" spellcheck="false">
				<?php endif; ?>
				<small class="muted"><?= $h($s['description']) ?></small>
			</label>
		<?php endforeach; ?>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Snippets speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>Tuning-Konstanten</h2>
	<p class="muted">Ganzzahlige Schwellen für Worker, AutoSort und Topic-Discovery.</p>
	<form method="post" action="/admin/settings/system/tuning" class="form-grid">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<?php foreach ($tuning as $t): ?>
			<label class="settings-field">
				<span class="settings-key"><code><?= $h($t['key']) ?></code></span>
				<input type="number" min="0" name="<?= $h($t['key']) ?>" value="<?= $h($t['value']) ?>">
				<small class="muted"><?= $h($t['description']) ?></small>
			</label>
		<?php endforeach; ?>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Tuning speichern</button>
		</div>
	</form>
</section>

<section class="panel">
	<h2>Default-Folder pro Primary-Label</h2>
	<p class="muted">Werden in den AutoSort-Catch-All-Rules verwendet, wenn der User noch keinen eigenen Pfad gesetzt hat. Slashes erzeugen Outlook-Unterordner.</p>
	<form method="post" action="/admin/settings/system/folders" class="form-grid">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<?php foreach ($folders as $f): ?>
			<label class="settings-field">
				<span class="settings-key"><code><?= $h($f['key']) ?></code></span>
				<input type="text" name="<?= $h($f['key']) ?>" value="<?= $h($f['value']) ?>" spellcheck="false">
				<small class="muted"><?= $h($f['description']) ?></small>
			</label>
		<?php endforeach; ?>
		<div class="form-actions">
			<button type="submit" class="btn btn-primary">Folder-Defaults speichern</button>
		</div>
	</form>
</section>
