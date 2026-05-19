<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\SenderRepository;

/**
 * Phase 6a — /api/v1/settings/senders.
 *
 * Liest die in `senders` registrierten Buckets (vom SenderResolver in
 * Phase 3a automatisch befuellt) und erlaubt dem User Display-Name,
 * Root-Folder und Trust-Status zu editieren.
 *
 * Marc-Wunsch (2026-05-18): „User hat keine Moeglichkeit das Hauptverzeichnis
 * zu waehlen in dem die Ordner angelegt werden sollen". → `root_folder_name`
 * editierbar. Plus User-Override fuer Spoof-False-Positives via trust_status.
 */
final class SenderController extends BaseController
{
	private const TRUST_VALUES = ['trusted', 'unknown', 'suspected_spoof'];

	public function listSenders(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$senders = $this->kernel->get(SenderRepository::class)
			->listForTenant($ctx['tenant_id']);
		Response::json(['items' => $senders]);
	}

	/**
	 * Phase 9e (Marc 2026-05-19) — Topic-Vorschlaege fuer die
	 * „Klassifikation korrigieren"-Form. Liefert distinct letzte Segmente
	 * aus mail_scores.folder_segments aller Mails dieses Senders, sortiert
	 * nach Haeufigkeit. Datalist im Add-in nutzt das fuer Auto-Suggest.
	 */
	public function topicSuggestions(array $params, array $body): void
	{
		$ctx       = $this->requireAuth();
		$fromEmail = (string)($_GET['from'] ?? '');
		if ($fromEmail === '') {
			throw HttpException::badRequest('VALIDATION', 'from-Parameter fehlt');
		}
		$bucket = $this->kernel->get(\MailPilot\Services\Sender\SenderResolver::class)
			->resolve($ctx['tenant_id'], $fromEmail);
		if ($bucket === null) {
			Response::json(['items' => []]);
			return;
		}
		$domains = array_values($bucket['registrable_domains'] ?? []);
		if ($domains === []) {
			Response::json(['items' => []]);
			return;
		}
		// Festes Prepared-Statement pro Domain, dann PHP-merge. Bewusst KEIN
		// dynamic IN(?,?,?,...) — auch wenn die Werte hier intern sind, der
		// dynamische Platzhalter-Build ist ein Footgun fuer spaetere Aenderungen.
		$stmt = $this->kernel->get(\PDO::class)->prepare(
			"SELECT s.folder_segments, COUNT(*) AS n
			FROM mail_scores s
			INNER JOIN mails m ON m.id = s.mail_id
			WHERE s.tenant_id = :t
			  AND s.folder_segments IS NOT NULL
			  AND SUBSTRING_INDEX(m.from_email, '@', -1) = :d
			GROUP BY s.folder_segments
			ORDER BY n DESC
			LIMIT 100"
		);
		$counts = [];
		foreach ($domains as $domain) {
			$stmt->execute([':t' => $ctx['tenant_id'], ':d' => $domain]);
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$arr = json_decode((string)$row['folder_segments'], true);
				if (!is_array($arr) || $arr === []) continue;
				$topic = trim((string)end($arr));
				if ($topic === '') continue;
				$counts[$topic] = ($counts[$topic] ?? 0) + (int)$row['n'];
			}
		}
		arsort($counts);
		Response::json(['items' => array_keys($counts)]);
	}

	public function updateSender(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id  = (string)($params['id'] ?? '');
		if ($id === '') {
			throw HttpException::badRequest('VALIDATION', 'Sender-ID fehlt');
		}

		$repo    = $this->kernel->get(SenderRepository::class);
		$senders = $repo->listForTenant($ctx['tenant_id']);
		$existing = null;
		foreach ($senders as $s) {
			if ($s['id'] === $id) { $existing = $s; break; }
		}
		if ($existing === null) {
			throw HttpException::notFound('NOT_FOUND', 'Sender nicht gefunden');
		}

		// Display-Name + Folder-Name optional. Wenn beide oder eines vorhanden:
		// validieren + speichern. Leerstring resets NICHT auf Default (User
		// hat dann explizit nichts mehr) — wir verlangen mindestens ein Zeichen.
		$dn = isset($body['display_name'])     ? trim((string)$body['display_name'])     : null;
		$fn = isset($body['root_folder_name']) ? trim((string)$body['root_folder_name']) : null;

		if ($dn !== null || $fn !== null) {
			$dnFinal = $dn !== null && $dn !== '' ? $dn : $existing['display_name'];
			$fnFinal = $fn !== null && $fn !== '' ? $fn : $existing['root_folder_name'];
			if (mb_strlen($dnFinal) > 120 || mb_strlen($fnFinal) > 120) {
				throw HttpException::badRequest('VALIDATION', 'Display-Name + Folder max 120 Zeichen');
			}
			$repo->updateDisplayAndFolder($ctx['tenant_id'], $id, $dnFinal, $fnFinal);
		}

		// Trust-Status optional. ENUM-Validation. Bei trusted koennen wir
		// spoof_of_sender_id auf null setzen (User sagt: ist legitim).
		if (isset($body['trust_status'])) {
			$ts = (string)$body['trust_status'];
			if (!in_array($ts, self::TRUST_VALUES, true)) {
				throw HttpException::badRequest('VALIDATION',
					'trust_status muss trusted|unknown|suspected_spoof sein');
			}
			$repo->updateTrustStatus(
				$ctx['tenant_id'],
				$id,
				$ts,
				$ts === 'trusted' ? null : $existing['spoof_of_sender_id'],
			);
		}

		// Aktuellen Stand zurueckliefern, damit das Add-in die Karte aktualisieren kann.
		$updated = null;
		foreach ($repo->listForTenant($ctx['tenant_id']) as $s) {
			if ($s['id'] === $id) { $updated = $s; break; }
		}
		Response::json(['ok' => true, 'sender' => $updated]);
	}
}
