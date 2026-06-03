<?php
/**
 * Spec 2 (Task 9, Step 2) — Read-only-Review der offenen score_suggestion-
 * Vorschläge aus dem Lern-Loop (PendingAction kind=score_suggestion).
 *
 * BEWUSST ohne Annehmen/Verwerfen-Buttons: die Bestätigung läuft user-scoped
 * über das Add-in (POST /api/v1/pending/{id}/approve|reject), weil sie
 * user-gebundene Regel-Mutationen mit Ownership-Guard auslöst. Der Admin
 * sieht hier nur den Überblick.
 *
 * @var list<array{
 *   id:string, tenant_name:string, user_email:string,
 *   mail_subject:?string, mail_from:?string,
 *   match_score:?int, match_mode:?string,
 *   proposed:array<string,mixed>, created_at:string
 * }> $suggestions
 */
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Vorschlags-Felder lesbar zusammenfassen (priority/action_required/label/folder_segments).
$fmtProposed = static function (array $p) use ($h): string {
	$parts = [];
	if (isset($p['priority'])) {
		$parts[] = 'Priorität → ' . $h((string)(int)$p['priority']);
	}
	if (isset($p['action_required'])) {
		$parts[] = 'Aktion erforderlich → ' . ((int)$p['action_required'] === 1 ? 'ja' : 'nein');
	}
	if (isset($p['label'])) {
		$parts[] = 'Label → ' . $h((string)$p['label']);
	}
	if (isset($p['folder_segments']) && is_array($p['folder_segments']) && $p['folder_segments'] !== []) {
		$segs = array_map(static fn($s): string => (string)$s, $p['folder_segments']);
		$parts[] = 'Ordner → ' . $h(implode(' / ', $segs));
	}
	return $parts === [] ? '<span class="muted">—</span>' : implode(', ', $parts);
};
?>

<header class="page-head">
	<h1>Score-Vorschläge (Lern-Loop)</h1>
	<p class="muted">Offene <code>score_suggestion</code>-Vorschläge aus <code>pending_actions</code> — tenant-übergreifender Überblick (read-only).</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← LLM-Provider</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/routing">Routing &amp; Privacy-Mode →</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/usage">Usage &amp; Kosten →</a>
	</div>
</header>

<section class="panel">
	<p class="muted">
		Annehmen/Verwerfen erfolgt user-seitig im Add-in (Pending-Tab) — die Bestätigung
		(re-)aktiviert bzw. deaktiviert die zugehörige Score-Override-Regel des jeweiligen Users
		mit Ownership-Guard. Diese Sicht dient nur der Beobachtung.
	</p>
	<?php if ($suggestions === []): ?>
		<p class="muted">Keine offenen Score-Vorschläge.</p>
	<?php else: ?>
	<table class="data">
		<thead>
			<tr>
				<th>Tenant</th>
				<th>User</th>
				<th>Mail</th>
				<th>Match</th>
				<th>Vorgeschlagene Änderung</th>
				<th>Erstellt</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($suggestions as $s): ?>
			<tr>
				<td><strong><?= $h($s['tenant_name']) ?></strong></td>
				<td><?= $h($s['user_email']) ?></td>
				<td>
					<?php if ($s['mail_subject'] !== null): ?>
						<div><?= $h($s['mail_subject']) ?></div>
						<?php if ($s['mail_from'] !== null): ?>
							<small class="muted"><?= $h($s['mail_from']) ?></small>
						<?php endif; ?>
					<?php else: ?>
						<span class="muted">(Mail nicht mehr vorhanden)</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ($s['match_score'] !== null): ?>
						<span class="badge"><?= $h((string)$s['match_score']) ?></span>
					<?php endif; ?>
					<?php if ($s['match_mode'] !== null): ?>
						<small class="muted"><?= $h($s['match_mode']) ?></small>
					<?php endif; ?>
				</td>
				<td><?= $fmtProposed($s['proposed']) ?></td>
				<td><small class="muted"><?= $h($s['created_at']) ?></small></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
