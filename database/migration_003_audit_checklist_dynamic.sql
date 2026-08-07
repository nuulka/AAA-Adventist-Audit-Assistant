-- =============================================================================
-- Revizor Asszisztens — Migration 003: Dinamikus bizonylat-ellenőrző lista
-- Futtatandó a revizor_db adatbázison (az éles MySQL szerveren).
-- Az OTS adatbázis (tetkuhu1_ots) ÉRINTETLEN marad — a Revizor csak olvassa.
-- Használat: phpMyAdmin, MySQL CLI vagy cPanel "MySQL Databases".
--   mysql -u <user> -p revizor_db < migration_003_audit_checklist_dynamic.sql
-- =============================================================================

-- 1. Ellenőr aláírása (bizonylat-ellenőrző lista új pontja)
ALTER TABLE audit_checklist ADD COLUMN signature_auditor TINYINT(1) DEFAULT 0;
ALTER TABLE ots_cash_audit ADD COLUMN signature_auditor TINYINT(1) DEFAULT 0;

-- 2. Kiállító bélyegzője / gyülekezet neve
ALTER TABLE audit_checklist ADD COLUMN stamp_ok TINYINT(1) DEFAULT 0;
ALTER TABLE ots_cash_audit ADD COLUMN stamp_ok TINYINT(1) DEFAULT 0;

-- =============================================================================
-- Megjegyzés: Ha a fenti ALTER hibát ad (duplicate column), a sor már létezik,
-- nyugodtan folytathatod. A PHP oldalak amúgy is automatikusan létrehozzák
-- (ALTER-fallback) az első betöltéskor, így ez a fájl csak a gyorsításra való.
-- =============================================================================
