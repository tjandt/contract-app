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
define('DB_HOST', 'localhost');
define('DB_NAME', 'vertraege');
define('DB_USER', 'DEIN_DB_USER');
define('DB_PASS', 'DEIN_DB_PASSWORT');
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

// ------------------------------------------------------------
// PDO-Verbindung
// ------------------------------------------------------------
function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Datenbankverbindung fehlgeschlagen. Bitte config.php prüfen. (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $pdo;
}
