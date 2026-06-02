-- 0063_scoring_batch_size_setting.sql
-- Anzahl Mails pro Scoring-LLM-Call als Laufzeit-Setting (statt nur config.php).
INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('scoring.batch_size', '20', 'int',
	 'Mails pro Scoring-LLM-Call (Prompt-Batching). 1 = kein Batching. Groesser = guenstiger/schneller, aber an P-SCORE max_tokens gekoppelt: zu gross => JSON-Truncation => ganzer Chunk faellt aus.');
