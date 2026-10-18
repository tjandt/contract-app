<?php
/**
 * Konfigurationsdatei
 * ------------------------------------------------------------
 * Bitte vor dem ersten Start anpassen:
 * 1) Datenbankzugangsdaten eintragen
 * 2) Ein Passwort-Hash fuer den Login erzeugen, z.B. per CLI:
 *      php -r "echo password_hash('DeinPasswort', PASSWORD_DEFAULT);"
 *    und das Ergebnis unten bei APP_PASSWORD_HASH eintragen.
 * ------------------------------------------------------------
 */

// ---- Datenbank ----
// Umgebungsvariablen (VERTRAEGE_DB_*) haben Vorrang – praktisch für Tests/CI, ohne diese Datei anzufassen.
define('DB_HOST', getenv('VERTRAEGE_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('VERTRAEGE_DB_NAME') ?: 'vertraege');
define('DB_USER', getenv('VERTRAEGE_DB_USER') ?: 'DEIN_DB_USER');
define('DB_PASS', getenv('VERTRAEGE_DB_PASS') ?: 'DEIN_DB_PASSWORT');
define('DB_CHARSET', 'utf8mb4');

// ---- Login ----
// Hash von "changeme123" - UNBEDINGT vor Live-Betrieb ersetzen!
define('APP_PASSWORD_HASH', '$2y$10$1arQsZu6fPvwK9Qk5JEXBO0wOXiR/YguZZLWjmr4qlaotDA1vQWwO');

// ---- Sonstiges ----
define('APP_NAME', 'Verträge');
date_default_timezone_set('Europe/Berlin');

// Schwellenwerte (in Tagen) für Kündigungs-Reminder auf dem Dashboard
define('REMINDER_WARN_DAYS', 90);   // ab wann ein Vertrag als "bald kündbar" markiert wird
define('REMINDER_URGENT_DAYS', 30); // ab wann er als "dringend" markiert wird

// Preiserhöhungen der letzten X Tage werden mit dem Hinweis auf ein mögliches Sonderkündigungsrecht markiert
define('PRICE_INCREASE_FLAG_DAYS', 90);

// Eine eingereichte Kündigung ohne Bestätigung wird nach X Tagen als Reminder angezeigt
define('CANCELLATION_CONFIRMATION_DAYS', 14);

// ---- Dokumente (PDF-Verträge, Kündigungsbestätigungen, …) ----
// Idealerweise ein Verzeichnis AUSSERHALB des Webroots (z. B. '/var/www/vertraege-storage').
// Standard: ./storage/documents (per .htaccess gesperrt, Auslieferung nur über document_download.php).
define('DOCUMENT_DIR', __DIR__ . '/storage/documents');
define('DOCUMENT_MAX_BYTES', 10 * 1024 * 1024); // 10 MB je Datei
