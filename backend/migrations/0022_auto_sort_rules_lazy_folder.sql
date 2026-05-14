-- 0022_auto_sort_rules_lazy_folder.sql
--
-- Carry-Over aus DA-Impl 6b Finding 3: KI-Vorschlag-Folder-Pfad wurde
-- bisher eager zum Discovery-Zeitpunkt aufgelöst (folder_default.<primary>
-- + '/' + sub_label). Wenn Admin im Panel später folder_default.* ändert,
-- zeigt eine schlummernde KI-Rule auf den alten Pfad — User aktiviert sie
-- in gutem Glauben, Outlook erstellt einen Ordner, den der Admin gerade
-- abgeschafft hat.
--
-- Fix: folder_name darf NULL sein. KI-Rules werden mit NULL angelegt
-- (Marker für „resolve at activation/lookup time"). AutoSortRepository
-- + Service rendern den Default lazy aus dem aktuellen folder_default.*
-- Setting bei jedem Read.

ALTER TABLE auto_sort_rules
	MODIFY folder_name VARCHAR(200) NULL DEFAULT NULL;
