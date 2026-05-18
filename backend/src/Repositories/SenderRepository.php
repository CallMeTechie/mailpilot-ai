<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Sort-Refactor Phase 2 — Repository für die Tabellen aus Migration 0031/0032.
 *
 * senders         : ein Bucket pro Tenant + sender_key (registrable-domain-Stem).
 * sender_projects : N Projekte pro Sender (z.B. GitHub → GateControl, MailPilot-AI).
 *
 * Multi-Tenant: jede Query filtert tenant_id (CLAUDE.md §6). Soft-Delete via
 * deleted_at — list*-Reads filtern es weg, find* erlauben es bewusst nicht
 * (gelöschte Sender sind für den SenderResolver unsichtbar).
 *
 * Routing-Vertrag (vom SenderResolver / LookalikeDetector aufgerufen):
 *   1. findByRegistrableDomain($tenantId, 'ebay.de') liefert Bucket falls
 *      irgendeine Schreibweise bekannt ist.
 *   2. findByKey($tenantId, 'ebay') liefert Bucket via normalisiertem Stem.
 *   3. create() legt neuen Bucket an, Default trust_status='unknown'.
 *   4. addRegistrableDomain() haengt eine neue Schreibweise an einen bekannten
 *      Bucket — verwendet JSON_ARRAY_APPEND + Dedup-Check.
 */
final class SenderRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function findByKey(string $tenantId, string $senderKey): ?array
	{
		$stmt = $this->db->prepare('SELECT id, sender_key, registrable_domains, display_name,
				root_folder_name, trust_status, spoof_of_sender_id, created_at, updated_at
			FROM senders
			WHERE tenant_id = :t AND sender_key = :k AND deleted_at IS NULL
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':k' => strtolower($senderKey)]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $this->hydrate($row);
	}

	/**
	 * Findet den Bucket fuer eine konkrete registrable domain wie
	 * 'ebay.de'. Sucht via JSON_CONTAINS — ein Bucket kann mehrere
	 * Schreibweisen enthalten.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findByRegistrableDomain(string $tenantId, string $domain): ?array
	{
		$needle = json_encode(strtolower($domain), JSON_THROW_ON_ERROR);
		$stmt = $this->db->prepare('SELECT id, sender_key, registrable_domains, display_name,
				root_folder_name, trust_status, spoof_of_sender_id, created_at, updated_at
			FROM senders
			WHERE tenant_id = :t AND deleted_at IS NULL
			  AND JSON_CONTAINS(registrable_domains, :needle)
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':needle' => $needle]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $this->hydrate($row);
	}

	/**
	 * Erzeugt einen neuen Bucket. Display-Name + Folder-Name werden vom
	 * Aufrufer aus dem sender_key abgeleitet (kapitalisiert).
	 *
	 * @param list<string> $registrableDomains  e.g. ['ebay.de']
	 */
	public function create(
		string $tenantId,
		string $senderKey,
		array $registrableDomains,
		string $displayName,
		string $rootFolderName,
		string $trustStatus = 'unknown',
		?string $spoofOfSenderId = null,
	): string {
		$id = Uuid::v4();
		// Domains immer lowercase + unique — beugt Duplikat-Hits auf
		// ['eBay.de','EBAY.de'] vor.
		$domains = array_values(array_unique(array_map('strtolower', $registrableDomains)));
		$stmt = $this->db->prepare('INSERT INTO senders
			(id, tenant_id, sender_key, registrable_domains, display_name, root_folder_name,
			 trust_status, spoof_of_sender_id)
			VALUES (:id, :t, :k, :d, :dn, :fn, :ts, :so)');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':k'  => strtolower($senderKey),
			':d'  => json_encode($domains, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
			':dn' => $displayName,
			':fn' => $rootFolderName,
			':ts' => $trustStatus,
			':so' => $spoofOfSenderId,
		]);
		return $id;
	}

	/**
	 * Idempotent: wenn die Domain schon im Array steht, no-op. Sonst
	 * append + updated_at refresh.
	 */
	public function addRegistrableDomain(string $tenantId, string $senderId, string $domain): void
	{
		$d = strtolower($domain);
		// JSON_ARRAY_APPEND wuerde duplizieren. Erst pruefen, dann anhaengen.
		$check = $this->db->prepare('SELECT JSON_CONTAINS(registrable_domains, :needle) AS has_it
			FROM senders WHERE id = :id AND tenant_id = :t');
		$check->execute([
			':needle' => json_encode($d, JSON_THROW_ON_ERROR),
			':id'     => $senderId,
			':t'      => $tenantId,
		]);
		$row = $check->fetch(PDO::FETCH_ASSOC);
		if ($row === false || (int)$row['has_it'] === 1) {
			return;
		}
		$update = $this->db->prepare('UPDATE senders
			SET registrable_domains = JSON_ARRAY_APPEND(registrable_domains, "$", :d)
			WHERE id = :id AND tenant_id = :t');
		$update->execute([':d' => $d, ':id' => $senderId, ':t' => $tenantId]);
	}

	public function updateTrustStatus(
		string $tenantId,
		string $senderId,
		string $trustStatus,
		?string $spoofOfSenderId = null,
	): void {
		$stmt = $this->db->prepare('UPDATE senders
			SET trust_status = :ts, spoof_of_sender_id = :so
			WHERE id = :id AND tenant_id = :t');
		$stmt->execute([
			':ts' => $trustStatus,
			':so' => $spoofOfSenderId,
			':id' => $senderId,
			':t'  => $tenantId,
		]);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForTenant(string $tenantId, ?string $trustStatusFilter = null): array
	{
		$sql = 'SELECT id, sender_key, registrable_domains, display_name, root_folder_name,
				trust_status, spoof_of_sender_id, created_at, updated_at
			FROM senders
			WHERE tenant_id = :t AND deleted_at IS NULL';
		$params = [':t' => $tenantId];
		if ($trustStatusFilter !== null) {
			$sql .= ' AND trust_status = :ts';
			$params[':ts'] = $trustStatusFilter;
		}
		$sql .= ' ORDER BY display_name ASC';
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return array_map(fn(array $r): array => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function hydrate(array $r): array
	{
		$domains = [];
		if (isset($r['registrable_domains'])) {
			$decoded = json_decode((string)$r['registrable_domains'], true);
			if (is_array($decoded)) {
				$domains = array_values(array_map('strval', $decoded));
			}
		}
		return [
			'id'                  => (string)$r['id'],
			'sender_key'          => (string)$r['sender_key'],
			'registrable_domains' => $domains,
			'display_name'        => (string)$r['display_name'],
			'root_folder_name'    => (string)$r['root_folder_name'],
			'trust_status'        => (string)$r['trust_status'],
			'spoof_of_sender_id'  => $r['spoof_of_sender_id'] !== null ? (string)$r['spoof_of_sender_id'] : null,
			'created_at'          => (string)($r['created_at'] ?? ''),
			'updated_at'          => (string)($r['updated_at'] ?? ''),
		];
	}
}
