<?php
/**
 * Datenbankzugriff: PDO-Verbindung, Schema-Prüfung und Synchronisierung des aktuellen Preises.
 */

require_once __DIR__ . '/migrations.php';

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
        http_response_code(500);
        die('Datenbankverbindung fehlgeschlagen. Bitte config.php prüfen (Details im Server-Log).');
    }

    // SQL-Funktionen wie CURDATE() sollen dieselbe Zeitzone wie PHP verwenden (sonst weichen sie um Mitternacht ab)
    $pdo->exec("SET time_zone = '" . date('P') . "'");

    if (!defined('APP_SKIP_SCHEMA_CHECK') && schema_version($pdo) < latest_schema_version()) {
        http_response_code(503);
        die('Die Datenbank ist nicht auf dem aktuellen Stand. Bitte <code>php migrate.php</code> auf dem Server ausführen '
            . 'oder <a href="migrate.php">migrate.php</a> im Browser öffnen (Login erforderlich).');
    }

    if (!defined('APP_SKIP_SCHEMA_CHECK')) {
        sync_current_costs($pdo);
    }
    return $pdo;
}

/**
 * Setzt contracts.current_cost auf den heute gültigen Eintrag der Kostenhistorie.
 * Dadurch werden zukünftig datierte Preisänderungen am Stichtag ohne Cronjob wirksam, und rückdatierte
 * Einträge überschreiben keinen neueren Preis. Verträge ohne gültigen Eintrag (Start in der Zukunft)
 * behalten den erfassten Preis.
 */
function sync_current_costs(PDO $pdo, ?int $contractId = null): void
{
    $sql = "UPDATE contracts c
            JOIN cost_history h ON h.id = (
                SELECT h2.id FROM cost_history h2
                WHERE h2.contract_id = c.id AND h2.valid_from <= CURDATE()
                ORDER BY h2.valid_from DESC, h2.id DESC LIMIT 1)
            SET c.current_cost = h.amount
            WHERE c.current_cost <> h.amount" . ($contractId !== null ? ' AND c.id = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($contractId !== null ? [$contractId] : []);
}
