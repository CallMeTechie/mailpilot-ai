-- Phase 9q-H (Marc 2026-05-23) — Golden-Set-Test-Harness fuer Quality-
-- Vergleich pro LLM-Provider/Model.
--
-- Idee: Marc hat 10+ manuell gelabelte „Truth"-Mails. Pro Provider und
-- Score-Model laufen sie durch → man sieht objektiv welcher Provider
-- wie zuverlaessig klassifiziert. Damit ist Provider-Auswahl datengetrieben
-- statt Bauchgefuehl.
--
-- 3 Tabellen:
--   llm_golden_set        — die Truth-Mails (synthetisch, kein PII)
--   llm_golden_run        — Aggregat pro Test-Lauf (1 Row pro Provider×Model)
--   llm_golden_run_detail — per-Mail Predicted-vs-Expected (Drilldown)
--
-- Seed: 10 Beispiel-Mails die typische Mail-Klassen abdecken.

CREATE TABLE llm_golden_set (
	id                CHAR(36) NOT NULL PRIMARY KEY,
	subject           VARCHAR(200) NOT NULL,
	body_preview      TEXT NOT NULL,
	from_email        VARCHAR(120) NOT NULL,
	expected_label    ENUM('direct','action','cc','newsletter','auto','noise') NOT NULL,
	expected_priority TINYINT UNSIGNED NOT NULL,
	notes             VARCHAR(500) NULL,
	enabled           TINYINT(1) NOT NULL DEFAULT 1,
	created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_golden_set_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE llm_golden_run (
	id                 CHAR(36) NOT NULL PRIMARY KEY,
	provider_id        CHAR(36) NOT NULL,
	model_id_str       VARCHAR(120) NOT NULL,
	role               ENUM('score','summary','draft','inference') NOT NULL DEFAULT 'score',
	started_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	finished_at        DATETIME(3) NULL,
	total_mails        INT UNSIGNED NOT NULL DEFAULT 0,
	correct_label      INT UNSIGNED NOT NULL DEFAULT 0,
	correct_priority   INT UNSIGNED NOT NULL DEFAULT 0,
	avg_latency_ms     INT UNSIGNED NOT NULL DEFAULT 0,
	total_cost_usd     DECIMAL(10, 6) NOT NULL DEFAULT 0,
	status             ENUM('running','done','error') NOT NULL DEFAULT 'running',
	error_msg          VARCHAR(500) NULL,
	KEY idx_golden_run_provider_time (provider_id, started_at),
	CONSTRAINT fk_golden_run_provider FOREIGN KEY (provider_id)
		REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE llm_golden_run_detail (
	id                  CHAR(36) NOT NULL PRIMARY KEY,
	run_id              CHAR(36) NOT NULL,
	golden_id           CHAR(36) NOT NULL,
	predicted_label     VARCHAR(20) NULL,
	predicted_priority  TINYINT UNSIGNED NULL,
	latency_ms          INT UNSIGNED NOT NULL DEFAULT 0,
	label_ok            TINYINT(1) NOT NULL DEFAULT 0,
	priority_ok         TINYINT(1) NOT NULL DEFAULT 0,
	error_msg           VARCHAR(500) NULL,
	KEY idx_golden_detail_run (run_id),
	CONSTRAINT fk_golden_detail_run    FOREIGN KEY (run_id)    REFERENCES llm_golden_run(id) ON DELETE CASCADE,
	CONSTRAINT fk_golden_detail_golden FOREIGN KEY (golden_id) REFERENCES llm_golden_set(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed 10 Beispiel-Mails. Komplett synthetisch — keine echten Marken,
-- keine echten Personen. Decken die 6 Score-Labels + Wichtigkeits-Marker
-- aus P-SCORE@1.6 ab.

INSERT INTO llm_golden_set
	(id, subject, body_preview, from_email, expected_label, expected_priority, notes)
VALUES
	('00000000-0000-4000-8002-000000000001',
	 'Bescheid zur Einkommensteuer 2025 — Aktenzeichen XY-12345',
	 'Sehr geehrter Herr Beispiel, anbei der Steuerbescheid. Widerspruchsfrist 4 Wochen.',
	 'noreply@finanzamt-musterstadt.de',
	 'action', 5,
	 'Behoerden + Aktenzeichen + Frist → KLASSE A, prio 5'),

	('00000000-0000-4000-8002-000000000002',
	 'Letzte Mahnung — Rechnung 4711 ueber EUR 89,00',
	 'Bitte begleichen Sie den offenen Betrag bis 30.06., sonst Inkasso.',
	 'mahnwesen@beispielshop.de',
	 'action', 5,
	 'Inkasso-Drohung + Frist → KLASSE B, prio 5'),

	('00000000-0000-4000-8002-000000000003',
	 'Save the Date: Hochzeit Anna & Tom am 14.09.',
	 'Bitte um Rueckmeldung bis 1. August. ICS-Datei anbei.',
	 'wedding@beispielinvite.de',
	 'action', 4,
	 'Einladung + Zusagepflicht → KLASSE C, prio 4'),

	('00000000-0000-4000-8002-000000000004',
	 'Sicherheitswarnung: verdaechtige Anmeldung an Deinem Konto',
	 'Wir haben eine Anmeldung aus einem unbekannten Land erkannt. Passwort zuruecksetzen?',
	 'security@cloudserviceexample.com',
	 'action', 5,
	 'Sicherheits-Alarm → KLASSE D, prio 5'),

	('00000000-0000-4000-8002-000000000005',
	 'Dein Wochenangebot — 30% auf Tiefkuehlpizza',
	 'Diese Woche im Angebot. Jetzt online bestellen!',
	 'newsletter@beispielsupermarkt.de',
	 'newsletter', 1,
	 'Klares Newsletter, kein Action'),

	('00000000-0000-4000-8002-000000000006',
	 'Deine Bestellung 12345 wurde versendet',
	 'Sendungsverfolgungsnummer 1Z999AA. Lieferung am 24.05.',
	 'versand@beispielshop.de',
	 'auto', 2,
	 'Versandbestaetigung → auto, nicht action'),

	('00000000-0000-4000-8002-000000000007',
	 'Frage zur Zusammenarbeit naechste Woche',
	 'Hi Marc, kannst du mir bis Mittwoch ein kurzes Statement schicken? Danke!',
	 'kollege@externekanzlei.de',
	 'action', 4,
	 'Persoenliche Anfrage mit Frist → action prio 4'),

	('00000000-0000-4000-8002-000000000008',
	 'Build erfolgreich: deploy-prod #4711',
	 'Pipeline gruen, alle Tests passed, deployed to prod.',
	 'ci@github.com',
	 'auto', 1,
	 'CI-OK-Meldung → auto prio 1'),

	('00000000-0000-4000-8002-000000000009',
	 'CC: Q2-Reporting — bitte zur Kenntnis',
	 'Anbei der Quartalsreport. Keine Aktion noetig, nur info.',
	 'reporting@firma.de',
	 'cc', 2,
	 'CC-Info-Mail → cc prio 2'),

	('00000000-0000-4000-8002-00000000000a',
	 'Re: Pillen ohne Rezept ::::: GuenSTige preise',
	 'Klicken Sie hier für die besten Pillen. UrgentMessage.',
	 'noreply@spam-domain-xz.ru',
	 'noise', 1,
	 'Spam-typische Charakteristika → noise');
