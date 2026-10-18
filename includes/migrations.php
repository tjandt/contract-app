<?php
/**
 * Versionierte Datenbank-Migrationen.
 *
 * Jede Migration läuft genau einmal; der Stand steht in der Tabelle `schema_migrations`.
 * Ausführen mit `php migrate.php` oder (nach Login) über migrate.php im Browser.
 * Neue Schema-Änderungen werden hier als nächste Versionsnummer angehängt – und zusätzlich in db.sql
 * nachgezogen, damit Neuinstallationen per Import denselben Stand erreichen.
 *
 * Die Hilfsfunktionen prüfen den Ist-Zustand über information_schema, damit die Migrationen auf
 * MySQL 5.7+ und MariaDB laufen (kein "ADD COLUMN IF NOT EXISTS").
 */

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (bool) $stmt->fetchColumn();
}

/** Fügt eine Spalte hinzu, falls sie noch nicht existiert. $definition z. B. "VARCHAR(10) NULL AFTER name". */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

/**
 * @return array<int,array{description:string, up:callable}>
 */
function schema_migrations(): array
{
    return [
        1 => ['description' => 'Basisschema (Kategorien, Verträge, Kostenhistorie)', 'up' => function (PDO $pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(60) NOT NULL,
                color_hex   VARCHAR(7)  NOT NULL DEFAULT '#8E8E93',
                icon        VARCHAR(10) NOT NULL DEFAULT '📄',
                sort_order  INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS contracts (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                name                  VARCHAR(150) NOT NULL,
                category_id           INT NULL,
                provider_name         VARCHAR(150) NULL,
                provider_url          VARCHAR(255) NULL,
                contract_number       VARCHAR(100) NULL,
                status                ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
                start_date            DATE NOT NULL,
                initial_term_months   INT NOT NULL DEFAULT 12,
                renewal_term_months   INT NOT NULL DEFAULT 12,
                notice_period_days    INT NOT NULL DEFAULT 30,
                billing_cycle         ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
                current_cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                notes                 TEXT NULL,
                cancelled_at          DATE NULL,
                created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                INDEX idx_status (status),
                INDEX idx_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS cost_history (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                contract_id   INT NOT NULL,
                amount        DECIMAL(10,2) NOT NULL,
                valid_from    DATE NOT NULL,
                reason        VARCHAR(255) NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
                INDEX idx_contract_date (contract_id, valid_from)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if ((int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
                seed_default_categories($pdo);
            }
        }],

        2 => ['description' => 'Kostenhistorie: Duplikate bereinigen, je Vertrag und Datum nur ein Eintrag', 'up' => function (PDO $pdo) {
            // Bei doppelten Einträgen am selben Datum gewinnt der zuletzt angelegte
            $pdo->exec("DELETE h1 FROM cost_history h1
                        JOIN cost_history h2 ON h1.contract_id = h2.contract_id AND h1.valid_from = h2.valid_from AND h1.id < h2.id");
            if (!index_exists($pdo, 'cost_history', 'uq_contract_valid_from')) {
                $pdo->exec("ALTER TABLE cost_history ADD UNIQUE KEY uq_contract_valid_from (contract_id, valid_from)");
            }
            if (index_exists($pdo, 'cost_history', 'idx_contract_date')) {
                $pdo->exec("ALTER TABLE cost_history DROP INDEX idx_contract_date"); // vom Unique-Index abgedeckt
            }
        }],

        3 => ['description' => 'Status "cancelled" entfernen (ergibt sich aus cancelled_at)', 'up' => function (PDO $pdo) {
            $pdo->exec("UPDATE contracts SET status = 'active' WHERE status = 'cancelled'");
            $pdo->exec("ALTER TABLE contracts MODIFY status ENUM('active','paused') NOT NULL DEFAULT 'active'");
        }],

        4 => ['description' => 'Fristlogik: Frist in Tagen/Monaten, Ausrichtung auf Monats-/Quartals-/Jahresende, Kündigungsprozess', 'up' => function (PDO $pdo) {
            add_column_if_missing($pdo, 'contracts', 'notice_period_unit', "ENUM('days','months') NOT NULL DEFAULT 'days' AFTER notice_period_days");
            add_column_if_missing($pdo, 'contracts', 'end_alignment', "ENUM('none','month_end','quarter_end','year_end') NOT NULL DEFAULT 'none' AFTER notice_period_unit");
            add_column_if_missing($pdo, 'contracts', 'cancellation_submitted_at', "DATE NULL AFTER cancelled_at");
            add_column_if_missing($pdo, 'contracts', 'cancellation_confirmed_at', "DATE NULL AFTER cancellation_submitted_at");
        }],

        5 => ['description' => 'Zahlungskalender: Abbuchungsanker', 'up' => function (PDO $pdo) {
            add_column_if_missing($pdo, 'contracts', 'billing_anchor_date', "DATE NULL AFTER billing_cycle");
        }],

        6 => ['description' => 'Dokumente je Vertrag', 'up' => function (PDO $pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS contract_documents (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                contract_id    INT NOT NULL,
                doc_type       ENUM('contract','cancellation','invoice','other') NOT NULL DEFAULT 'other',
                original_name  VARCHAR(255) NOT NULL,
                stored_name    VARCHAR(80)  NOT NULL,
                mime_type      VARCHAR(100) NOT NULL,
                size_bytes     INT UNSIGNED NOT NULL,
                uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
                UNIQUE KEY uq_stored_name (stored_name),
                INDEX idx_contract (contract_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }],

        7 => ['description' => 'Kategorie-Icons: Default und defekte Werte (u{…}) reparieren', 'up' => function (PDO $pdo) {
            $pdo->exec("ALTER TABLE categories MODIFY icon VARCHAR(10) NOT NULL DEFAULT '📄'");
            $defaults = [
                'Strom' => '⚡', 'Gas' => '🔥', 'Internet & Telefon' => '📡', 'Handy' => '📱',
                'Versicherung' => '🛡', 'Streaming' => '🎬', 'Fitness' => '🏋', 'Sonstiges' => '📄',
            ];
            $fix = $pdo->prepare("UPDATE categories SET icon = ? WHERE name = ? AND icon LIKE 'u{%'");
            foreach ($defaults as $name => $icon) {
                $fix->execute([$icon, $name]);
            }
            // Eigene Kategorien mit demselben Defekt bekommen das neutrale Standard-Icon
            $pdo->exec("UPDATE categories SET icon = '📄' WHERE icon LIKE 'u{%'");
        }],
    ];
}

function seed_default_categories(PDO $pdo): void
{
    $stmt = $pdo->prepare("INSERT INTO categories (name, color_hex, icon, sort_order) VALUES (?, ?, ?, ?)");
    $defaults = [
        ['Strom',              '#FF9F0A', '⚡',  1],
        ['Gas',                '#FF453A', '🔥', 2],
        ['Internet & Telefon', '#0A84FF', '📡', 3],
        ['Handy',              '#5E5CE6', '📱', 4],
        ['Versicherung',       '#30D158', '🛡',  5],
        ['Streaming',          '#BF5AF2', '🎬', 6],
        ['Fitness',            '#FF375F', '🏋',  7],
        ['Sonstiges',          '#8E8E93', '📄', 8],
    ];
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }
}

function latest_schema_version(): int
{
    return max(array_keys(schema_migrations()));
}

function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version     INT PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Aktueller Schema-Stand (0, wenn noch nichts migriert wurde bzw. die Tabelle fehlt). */
function schema_version(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM schema_migrations")->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/**
 * Führt alle noch offenen Migrationen der Reihe nach aus.
 *
 * @return list<string> Protokollzeilen
 */
function run_pending_migrations(PDO $pdo): array
{
    ensure_migrations_table($pdo);
    $current = schema_version($pdo);
    $log = [];
    $record = $pdo->prepare("INSERT INTO schema_migrations (version, description) VALUES (?, ?)");

    foreach (schema_migrations() as $version => $migration) {
        if ($version <= $current) {
            continue;
        }
        ($migration['up'])($pdo);
        $record->execute([$version, $migration['description']]);
        $log[] = sprintf('✓ Migration %d: %s', $version, $migration['description']);
    }
    return $log ?: ['Datenbank ist bereits auf dem aktuellen Stand (Version ' . $current . ').'];
}
