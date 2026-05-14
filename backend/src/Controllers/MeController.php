<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Response;
use MailPilot\Repositories\UserRepository;

/**
 * DSGVO endpoints: export (Art. 15) and delete (Art. 17).
 *
 * Sprint 6a: Tabellen-Coverage ist jetzt mechanisch nachvollziehbar.
 * exportTableList() / deleteTableList() / deliberateNonUserTables() bilden
 * die einzige Wahrheit darüber, welche user_id-Tabellen Export/Delete
 * adressiert und welche bewusst ausgeschlossen sind. Der
 * MeControllerCoverageTest scannt INFORMATION_SCHEMA und vergleicht gegen
 * diese Listen — eine neue user_id-Tabelle ohne Eintrag macht den Test rot.
 */
final class MeController extends BaseController
{
	/**
	 * Tabellen, deren Inhalt im /me/export-JSON erscheint.
	 *
	 * @return list<string>
	 */
	public static function exportTableList(): array
	{
		return [
			'users',
			'mailboxes',
			'vip_senders',
			'project_keywords',
			'redaction_rules',
			'user_sublabels',
			'auto_sort_rules',
			'mail_score_corrections',
			'mail_scores',
			'mail_summaries',
			'reply_drafts',
			'api_usage',
			'usage_daily',
		];
	}

	/**
	 * Tabellen, die der Soft-Delete in /me/delete anfasst (deleted_at-Stamp
	 * oder Hard-Delete). mail_scores / mail_summaries / reply_drafts haben
	 * kein deleted_at und gehen über den FK-Cascade von mails — daher nicht
	 * hier gelistet, sondern indirekt via mails-Soft-Delete + Worker-Cleanup.
	 *
	 * @return list<string>
	 */
	public static function deleteTableList(): array
	{
		return [
			'users',
			'mailboxes',
			'vip_senders',
			'project_keywords',
			'redaction_rules',
			'user_sublabels',
			'auto_sort_rules',
			'mail_score_corrections',
			'api_usage',
			'usage_daily',
		];
	}

	/**
	 * Tabellen mit user_id-Spalte, die BEWUSST nicht exportiert / gelöscht
	 * werden. Einzige Whitelist: jeder neue user_id-Eintrag muss entweder
	 * in exportTableList() ODER hier landen, sonst schlägt der Coverage-
	 * Test Alarm.
	 *
	 * Begründungen:
	 *   tenant_user → Junction, kein PII pro se (nur Membership)
	 *   audit_log   → Compliance-Audit, darf laut Art. 17 zur Beweissicherung
	 *                  retained werden; user_id ist Schlüssel, kein Body
	 *
	 * @return list<string>
	 */
	public static function deliberateNonUserTables(): array
	{
		return ['tenant_user', 'audit_log'];
	}

	public function export(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$fetch = function (string $sql, array $p) use ($pdo): array {
			$stmt = $pdo->prepare($sql);
			$stmt->execute($p);
			return $stmt->fetchAll(\PDO::FETCH_ASSOC);
		};

		$u = $ctx['user_id'];
		$t = $ctx['tenant_id'];

		$export = [
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'users'        => $fetch('SELECT id, email, display_name, aliases, privacy_acknowledged_at,
					language, timezone, briefing_hour, created_at
				FROM users WHERE id = :id', [':id' => $u]),
			'mailboxes'    => $fetch('SELECT id, email, display_name, last_sync_at, created_at
				FROM mailboxes WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'vip_senders'  => $fetch('SELECT email, display_name, created_at
				FROM vip_senders WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'project_keywords' => $fetch('SELECT keyword, created_at
				FROM project_keywords WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'redaction_rules'  => $fetch('SELECT pattern, description, enabled, created_at
				FROM redaction_rules WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'user_sublabels'   => $fetch('SELECT parent_label, name, description, created_by, created_at
				FROM user_sublabels WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'auto_sort_rules'  => $fetch('SELECT label, sub_label, folder_name, enabled, created_at
				FROM auto_sort_rules WHERE user_id = :u', [':u' => $u]),
			'mail_score_corrections' => $fetch('SELECT mail_id, original_label, corrected_label,
					user_reason, created_at
				FROM mail_score_corrections WHERE user_id = :u AND deleted_at IS NULL', [':u' => $u]),
			'mail_scores_last_30d' => $fetch('SELECT m.subject, m.from_email, m.received_at,
					s.label, s.sub_label, s.action_required, s.action_owner, s.action_owner_confidence,
					s.priority, s.summary, s.scored_at
				FROM mail_scores s
				INNER JOIN mails m ON m.id = s.mail_id
				WHERE m.tenant_id = :t
				  AND s.scored_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
				ORDER BY s.scored_at DESC', [':t' => $t]),
			'mail_summaries_last_30d' => $fetch('SELECT m.subject, m.received_at, s.summary_text, s.generated_at
				FROM mail_summaries s
				INNER JOIN mails m ON m.id = s.mail_id
				WHERE m.tenant_id = :t
				  AND s.generated_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
				ORDER BY s.generated_at DESC', [':t' => $t]),
			'reply_drafts_last_30d' => $fetch('SELECT m.subject, d.draft_text, d.created_at
				FROM reply_drafts d
				INNER JOIN mails m ON m.id = d.mail_id
				WHERE m.tenant_id = :t
				  AND d.created_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
				ORDER BY d.created_at DESC', [':t' => $t]),
			'api_usage_last_30d' => $fetch('SELECT model, prompt_version, input_tokens, output_tokens,
					cache_read_tokens, cache_creation_tokens, cost_eur, status, created_at
				FROM api_usage
				WHERE user_id = :u AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
				ORDER BY created_at DESC', [':u' => $u]),
			'usage_daily' => $fetch('SELECT `date`, model, prompt_version, calls,
					input_tokens, output_tokens, cache_read_tokens, cache_creation_tokens, cost_eur
				FROM usage_daily WHERE user_id = :u
				ORDER BY `date` DESC LIMIT 365', [':u' => $u]),
		];

		Response::json($export);
	}

	public function delete(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$pdo->beginTransaction();
		try {
			$u = $ctx['user_id'];
			$now = 'UTC_TIMESTAMP(3)';

			$pdo->prepare("UPDATE users                  SET deleted_at = {$now} WHERE id      = :id")->execute([':id' => $u]);
			$pdo->prepare("UPDATE mailboxes              SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			$pdo->prepare("UPDATE vip_senders            SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			$pdo->prepare("UPDATE project_keywords       SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			$pdo->prepare("UPDATE redaction_rules        SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			$pdo->prepare("UPDATE user_sublabels         SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			$pdo->prepare("UPDATE mail_score_corrections SET deleted_at = {$now} WHERE user_id = :u")->execute([':u' => $u]);
			// auto_sort_rules hat kein deleted_at — Hard-Delete der Regeln ist
			// OK, weil keine fremden FKs daran hängen und der User die Regeln
			// jederzeit neu anlegen kann.
			$pdo->prepare('DELETE FROM auto_sort_rules WHERE user_id = :u')->execute([':u' => $u]);
			// api_usage + usage_daily: Token-Cost-Aggregate. DSGVO Art. 17
			// verlangt Löschung; Compliance-Forensik bleibt über audit_log +
			// Backups erhalten. Hard-Delete weil keine deleted_at-Spalte.
			$pdo->prepare('DELETE FROM api_usage   WHERE user_id = :u')->execute([':u' => $u]);
			$pdo->prepare('DELETE FROM usage_daily WHERE user_id = :u')->execute([':u' => $u]);

			$pdo->prepare('INSERT INTO audit_log (tenant_id, user_id, event, entity, entity_id, meta_json)
				VALUES (:t, :u, "user.delete_request", "user", :id, NULL)')
				->execute([':t' => $ctx['tenant_id'], ':u' => $u, ':id' => $u]);

			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		Response::noContent();
	}

	/**
	 * GET /me/profile — liefert das User-Profil inkl. Aliases und
	 * privacy_acknowledged_at. Add-in liest das beim Öffnen der Settings.
	 */
	public function profile(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$row = $this->kernel->get(UserRepository::class)->findById($ctx['user_id']);
		Response::json([
			'user' => $row ?? ['id' => $ctx['user_id'], 'aliases' => [], 'privacy_acknowledged_at' => null],
		]);
	}

	/**
	 * POST /me/aliases/scan — scannt die letzten 200 eingehenden Mails
	 * des Users nach Anrede-Mustern und liefert eine Vorschlagsliste.
	 *
	 * Persistiert NICHTS: der User bestätigt im Add-in welche Vorschläge
	 * er übernimmt, dann ruft das Add-in POST /me/aliases mit der finalen
	 * Liste auf. Trennung ist bewusst — verhindert dass ein Initial-Scan
	 * versehentlich Aliase überschreibt, die der User schon gepflegt hat.
	 */
	public function scanAliases(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		// Hole letzte 200 eingegangene Mails. JOIN über mailboxes um
		// nur Postfächer dieses Users zu treffen — verhindert dass
		// Tenant-Member-Mailboxen aufgeführt werden.
		$stmt = $pdo->prepare('SELECT m.body_text, m.body_preview
			FROM mails m
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id
			WHERE m.tenant_id = :t
			  AND mb.user_id = :u
			  AND m.deleted_at IS NULL
			ORDER BY m.received_at DESC
			LIMIT 200');
		$stmt->execute([':t' => $ctx['tenant_id'], ':u' => $ctx['user_id']]);

		// Bekannte deutsche/englische Anrede-Präfixe. Optional „Herr/Frau",
		// danach ein bis drei Großbuchstaben-Tokens.
		$pattern = '/(?:Hallo|Hi|Sehr\s+geehrte[rs]?|Lieber|Liebe|Moin|Servus|Hey|Guten\s+Tag|Dear)\s+(?:Herr\s+|Frau\s+)?([A-ZÄÖÜ][\wäöüß\-]+(?:\s+[A-ZÄÖÜ][\wäöüß\-]+){0,2})\b/u';

		$counts = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$body = (string)($row['body_text'] ?? $row['body_preview'] ?? '');
			// Cap body scan to first 2k chars — Anrede steht praktisch immer
			// oben in der Mail, voller Body-Scan ist Verschwendung.
			$head = mb_substr($body, 0, 2000);
			if (preg_match_all($pattern, $head, $matches)) {
				foreach ($matches[1] as $candidate) {
					$candidate = trim($candidate);
					if ($candidate === '' || mb_strlen($candidate) > 50) continue;
					$key = mb_strtolower($candidate);
					$counts[$key] = ($counts[$key] ?? ['name' => $candidate, 'count' => 0]);
					$counts[$key]['count']++;
				}
			}
		}

		// Filter: mindestens 2 Vorkommen (Single-Hits sind Rauschen).
		// Sortiert nach Häufigkeit desc.
		$suggestions = array_values(array_filter($counts, static fn(array $c): bool => $c['count'] >= 2));
		usort($suggestions, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

		// Bereits gepflegte Aliase ausblenden — der Scan ist additiv,
		// nicht ersetzend.
		$existing = $this->kernel->get(UserRepository::class)->findById($ctx['user_id']);
		$haveLower = [];
		foreach (($existing['aliases'] ?? []) as $a) {
			$haveLower[mb_strtolower((string)$a)] = true;
		}
		$suggestions = array_values(array_filter(
			$suggestions,
			static fn(array $c): bool => !isset($haveLower[mb_strtolower($c['name'])]),
		));

		Response::json([
			'suggestions'     => array_slice($suggestions, 0, 20),
			'existing'        => $existing['aliases'] ?? [],
			'scanned_mails'   => $stmt->rowCount(),
		]);
	}

	/**
	 * POST /me/aliases — speichert die User-bestätigte Alias-Liste.
	 * Body: { "aliases": ["Marc", "MB", "Backes"] }
	 */
	public function saveAliases(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$aliases = is_array($body['aliases'] ?? null) ? $body['aliases'] : [];
		$this->kernel->get(UserRepository::class)->saveAliases($ctx['user_id'], $aliases);
		Response::json(['ok' => true, 'count' => min(count($aliases), 30)]);
	}

	/**
	 * POST /me/privacy-acknowledge — DSGVO-Disclaimer-Akzept (PRD §10.3).
	 * Setzt privacy_acknowledged_at auf jetzt; idempotent.
	 */
	public function acknowledgePrivacy(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$this->kernel->get(UserRepository::class)->acknowledgePrivacy($ctx['user_id']);
		Response::json(['ok' => true]);
	}
}
