-- ============================================================
-- Migration v2 – für bereits bestehende Installationen
-- ============================================================
-- Behebt die defekten Kategorie-Icons (ursprünglich als ungültiges
-- MySQL-Escape "\u{...}" gespeichert, wodurch z.B. "u{26A1}" statt
-- des Blitz-Emojis in der Datenbank landete).
--
-- Die Spalte "cancelled_at" existierte bereits im ursprünglichen
-- Schema, daher ist hierfür keine ALTER TABLE nötig.
--
-- Einspielen mit:  mysql -u DEIN_USER -p DEINE_DB < migration_v2.sql
--
-- WICHTIG: Falls die Icons danach immer noch falsch aussehen (z.B. Fragezeichen
-- oder Kästchen statt Emoji), liegt es meist am Verbindungs-Encoding des
-- mysql-Clients beim Import. Dann stattdessen so einspielen:
--   mysql --default-character-set=utf8mb4 -u DEIN_USER -p DEINE_DB < migration_v2.sql
-- Das untenstehende "SET NAMES utf8mb4" erzwingt das zusätzlich serverseitig
-- für diese Session.
-- ============================================================

SET NAMES utf8mb4;

UPDATE categories SET icon = '⚡' WHERE name = 'Strom';
UPDATE categories SET icon = '🔥' WHERE name = 'Gas';
UPDATE categories SET icon = '📡' WHERE name = 'Internet & Telefon';
UPDATE categories SET icon = '📱' WHERE name = 'Handy';
UPDATE categories SET icon = '🛡' WHERE name = 'Versicherung';
UPDATE categories SET icon = '🎬' WHERE name = 'Streaming';
UPDATE categories SET icon = '🏋' WHERE name = 'Fitness';
UPDATE categories SET icon = '📄' WHERE name = 'Sonstiges';

-- Falls eigene Kategorien mit demselben Fehler angelegt wurden,
-- hier die Icons manuell korrigieren, z.B.:
-- UPDATE categories SET icon = '🎵' WHERE name = 'Musik';
