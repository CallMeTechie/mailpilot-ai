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
	 * Phase 9e Hotfix #5 (Marc 2026-05-19) — Topic-Pfad-Vorschlaege.
	 * Liefert distinct volle Pfade aus mail_scores.folder_segments ALLER
	 * Mails des Tenants, sortiert nach Haeufigkeit. Datalist im Add-in
	 * filtert browser-native waehrend des Tippens.
	 *
	 * `from`-Parameter wird ignoriert (war Phase-9e-Initial-Variante mit
	 * Per-Sender-Filter) — der User soll auch Pfade vorschlagen koennen,
	 * die bisher fuer ANDERE Sender genutzt wurden (z.B. /Familie/Mama).
	 */
	public function topicSuggestions(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$stmt = $this->kernel->get(\PDO::class)->prepare(
			"SELECT folder_segments, COUNT(*) AS n
			FROM mail_scores
			WHERE tenant_id = :t AND folder_segments IS NOT NULL
			GROUP BY folder_segments
			ORDER BY n DESC
			LIMIT 200"
		);
		$stmt->execute([':t' => $ctx['tenant_id']]);
		$paths = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$arr = json_decode((string)$row['folder_segments'], true);
			if (!is_array($arr) || $arr === []) continue;
			$clean = [];
			foreach ($arr as $seg) {
				$s = trim((string)$seg);
				if ($s !== '') $clean[] = $s;
			}
			if ($clean === []) continue;
			$path = implode('/', $clean);
			$paths[$path] = ($paths[$path] ?? 0) + (int)$row['n'];
		}
		arsort($paths);
		Response::json(['items' => array_keys($paths)]);
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
