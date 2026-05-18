-- Sort-Refactor Phase 1 — Absender-Bucket pro Tenant.
--
-- Loest die Hauptlabel-Ordner-Annahme (`/MailPilot/<Label>/...`) ab.
-- Statt Label entscheidet kuenftig der ABSENDER ueber den Top-Level-Ordner:
--   Marc-Beispiel (2026-05-18): `ebay.de` + `info.ebay.com` + `ebay.co.uk`
--   sind alle EIN Bucket „Ebay" (sender_key='ebay'); `ebay-mails.com` ist es
--   nicht (lookalike, trust_status='suspected_spoof').
--
-- Sender-Key wird per Public Suffix List in Phase 2 berechnet
-- (`jeremykendall/php-domain-parser`). Diese Migration legt nur das Schema
-- — kein Backend-Code referenziert die Tabelle bis Phase 2.
--
-- registrable_domains: Liste aller bekannten Schreibweisen pro Sender,
-- damit ein Match auch dann greift wenn die KI/PSL die Domain auf einen
-- bereits gespeicherten Sender hinten dranhaengt.
--
-- trust_status:
--   'trusted'         — bestaetigte Bekannte (Default fuer User-curated)
--   'unknown'         — neu eingetroffen, noch nicht klassifiziert
--   'suspected_spoof' — Lookalike-Domain einer bekannten Marke
--                       (Levenshtein-Treffer in Phase 2). Mail bleibt in
--                       Inbox + Toast/Badge/Banner statt Auto-Move.

CREATE TABLE senders (
	id                  CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id           CHAR(36) NOT NULL,
	sender_key          VARCHAR(64) NOT NULL,                    -- normalisierter Stem, lowercase, z.B. „ebay"
	registrable_domains JSON NOT NULL,                            -- ["ebay.de","ebay.com","ebay.co.uk"]
	display_name        VARCHAR(120) NOT NULL,                   -- User-editierbar, Default = capitalize(sender_key)
	root_folder_name    VARCHAR(120) NOT NULL,                   -- User-editierbar, Default = display_name
	trust_status        ENUM('trusted','unknown','suspected_spoof') NOT NULL DEFAULT 'unknown',
	spoof_of_sender_id  CHAR(36) NULL,                            -- bei spoof: auf welchen echten Sender es zielt
	created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at          DATETIME(3) NULL,
	UNIQUE KEY uq_senders_tenant_key (tenant_id, sender_key),
	KEY idx_senders_trust (tenant_id, trust_status),
	CONSTRAINT fk_senders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	-- Self-FK: ON DELETE SET NULL, damit ein geloeschter Echt-Sender den
	-- Spoof-Verweis nicht mitloescht (Audit-Trail bleibt erhalten).
	CONSTRAINT fk_senders_spoof_of FOREIGN KEY (spoof_of_sender_id) REFERENCES senders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
