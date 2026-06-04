<?php
/**
 * Spec 2 (Task 9) — Review der offenen score_suggestion-Vorschläge aus dem
 * Lern-Loop (PendingAction kind=score_suggestion), tenant-übergreifend.
 *
 * Annehmen/Verwerfen (Marc-Entscheidung #1 + D4): der Admin handelt
 * ADMINISTRATIV im Owner-Kontext der jeweiligen Zeile (tenant_id + user_id der
 * pending-Action) — kein Cross-User-Eingriff. Der Ownership-Guard bleibt
 * erhalten (die Regel muss dem User der Zeile gehören). User-seitig bleibt der
 * Add-in-Pfad (POST /api/v1/pending/{id}/approve|reject) zusätzlich bestehen.
 *
 * @var list<array{
 *   id:string, tenant_name:string, user_email:string,
 *   mail_subject:?string, mail_from:?string,
 *   match_score:?int, match_mode:?string,
 *   proposed:array<string,mixed>, created_at:string
 * }> $suggestions
 * @var int $disabledUserDerived  Task 10 (D8): aktuell deaktivierte user-derived Regeln.
 * @var string $csrfToken
 */
$disabledUserDerived = $disabledUserDerived ?? 0;
$h = fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Vorschlags-Felder lesbar zusammenfassen (priority/action_required/label/folder_segments).
// Gibt Klartext zurück — kein HTML, kein $h() intern. Der Template-Code escaped am Output.
$fmtProposed = static function (array $p): string {
	$parts = [];
	if (isset($p['priority'])) {
		$parts[] = 'Priorität → ' . (string)(int)$p['priority'];
	}
	if (isset($p['action_required'])) {
		$parts[] = 'Aktion erforderlich → ' . ((int)$p['action_required'] === 1 ? 'ja' : 'nein');
	}
	if (isset($p['label'])) {
		$parts[] = 'Label → ' . (string)$p['label'];
	}
	if (isset($p['folder_segments']) && is_array($p['folder_segments']) && $p['folder_segments'] !== []) {
		$segs = array_map(static fn($s): string => (string)$s, $p['folder_segments']);
		$parts[] = 'Ordner → ' . implode(' / ', $segs);
	}
	return implode(', ', $parts);
};
?>

<header class="page-head">
	<h1>Score-Vorschläge (Lern-Loop)</h1>
	<p class="muted">Offene <code>score_suggestion</code>-Vorschläge aus <code>pending_actions</code> — tenant-übergreifend. Annehmen/Verwerfen wirkt im Owner-Kontext der Zeile.</p>
	<div class="form-actions">
		<a class="btn btn-secondary btn-sm" href="/admin/llm">← LLM-Provider</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/routing">Routing &amp; Privacy-Mode →</a>
		<a class="btn btn-secondary btn-sm" href="/admin/llm/usage">Usage &amp; Kosten →</a>
	</div>
</header>

<section class="panel">
	<h2>Lern-Loop-Status</h2>
	<p class="muted">
		Aktuell <strong><?= $h((string)$disabledUserDerived) ?></strong> deaktivierte
		gelernte Regel(n) (<code>origin_correction_id</code> gesetzt, <code>enabled = 0</code>) —
		z.&nbsp;B. per LRU-Soft-Cap (<code>score_override.lru_disabled</code>) abgeschaltet
		oder vom Owner verworfen. Stille Degradationen (Budget-Fallback, fehlendes
		Match-Modell) erscheinen als Marker im Log
		(<code>rule_match.budget_exceeded_fallback</code>,
		<code>rule_match.unavailable_fallback_deterministic</code>).
	</p>
</section>

<section class="panel">
	<p class="muted">
		Annehmen (re-)aktiviert die zugehörige Score-Override-Regel des jeweiligen Users
		und zählt einen Apply-Hit; Verwerfen deaktiviert sie (kein Löschen). Beides läuft
		mit Ownership-Guard im Owner-Kontext der Zeile — gehört die Regel nicht (mehr) zum
		User, wird der Vorschlag nur geschlossen, ohne fremde Regeln zu verändern.
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
				<th>Aktionen</th>
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
				<td><?php $proposedText = $fmtProposed($s['proposed']); ?>
					<?php if ($proposedText === '') : ?>
						<span class="muted">—</span>
					<?php else : ?>
						<?= $h($proposedText) ?>
					<?php endif; ?></td>
				<td><small class="muted"><?= $h($s['created_at']) ?></small></td>
				<td class="actions">
					<form method="post" action="/admin/llm/suggestions/<?= $h($s['id']) ?>/approve" style="display:inline">
						<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
						<button class="btn btn-sm" type="submit" title="Annehmen — Regel aktivieren" aria-label="Vorschlag annehmen">✓</button>
					</form>
					<form method="post" action="/admin/llm/suggestions/<?= $h($s['id']) ?>/reject" style="display:inline">
						<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
						<button class="btn btn-sm" type="submit" title="Verwerfen — Regel deaktivieren" aria-label="Vorschlag verwerfen">✗</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
