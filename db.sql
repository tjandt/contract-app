-- ============================================================
-- Vertragsverwaltung - Datenbankschema (aktueller Stand)
-- ============================================================
-- Neuinstallation per Import, z. B.:
--   mysql --default-character-set=utf8mb4 -u DEIN_USER -p DEINE_DB < db.sql
--
-- Alternative: Leere Datenbank anlegen und `php migrate.php` ausführen – das Skript
-- spielt alle Migrationen aus includes/migrations.php der Reihe nach ein und führt
-- dabei auch bestehende Installationen auf den aktuellen Stand.
--
-- WICHTIG: Wird db.sql geändert, muss die Änderung auch als neue Migration in
-- includes/migrations.php stehen (und die INSERTs ganz unten ergänzt werden).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60) NOT NULL,
    color_hex   VARCHAR(7)  NOT NULL DEFAULT '#8E8E93',
    icon        VARCHAR(10) NOT NULL DEFAULT '📄',
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contracts (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(150) NOT NULL,
    category_id           INT NULL,
    provider_name         VARCHAR(150) NULL,
    provider_url          VARCHAR(255) NULL,
    contract_number       VARCHAR(100) NULL,
    status                ENUM('active','paused') NOT NULL DEFAULT 'active',
    start_date            DATE NOT NULL,
    initial_term_months   INT NOT NULL DEFAULT 12,
    renewal_term_months   INT NOT NULL DEFAULT 12,
    -- Wert der Kündigungsfrist; die Einheit steht in notice_period_unit (Spaltenname historisch)
    notice_period_days    INT NOT NULL DEFAULT 30,
    notice_period_unit    ENUM('days','months') NOT NULL DEFAULT 'days',
    end_alignment         ENUM('none','month_end','quarter_end','year_end') NOT NULL DEFAULT 'none',
    billing_cycle         ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    billing_anchor_date   DATE NULL,
    -- Zwischenspeicher: wird bei jedem Aufruf aus cost_history abgeleitet (siehe sync_current_costs())
    current_cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes                 TEXT NULL,
    -- Datum, zu dem der Vertrag endet ("Gekündigt zum"); leer = noch nicht gekündigt
    cancelled_at          DATE NULL,
    cancellation_submitted_at DATE NULL,
    cancellation_confirmed_at DATE NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cost_history (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    contract_id   INT NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    valid_from    DATE NOT NULL,
    reason        VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    UNIQUE KEY uq_contract_valid_from (contract_id, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contract_documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version     INT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Standard-Kategorien (nur in eine leere Tabelle, damit ein erneuter Import nichts verdoppelt)
-- ------------------------------------------------------------
INSERT INTO categories (name, color_hex, icon, sort_order)
SELECT * FROM (
    SELECT 'Strom' AS name, '#FF9F0A' AS color_hex, '⚡' AS icon, 1 AS sort_order
    UNION ALL SELECT 'Gas',                '#FF453A', '🔥', 2
    UNION ALL SELECT 'Internet & Telefon', '#0A84FF', '📡', 3
    UNION ALL SELECT 'Handy',              '#5E5CE6', '📱', 4
    UNION ALL SELECT 'Versicherung',       '#30D158', '🛡', 5
    UNION ALL SELECT 'Streaming',          '#BF5AF2', '🎬', 6
    UNION ALL SELECT 'Fitness',            '#FF375F', '🏋', 7
    UNION ALL SELECT 'Sonstiges',          '#8E8E93', '📄', 8
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM categories);

-- ------------------------------------------------------------
-- Schema-Stand: alle Migrationen aus includes/migrations.php gelten als eingespielt
-- ------------------------------------------------------------
INSERT IGNORE INTO schema_migrations (version, description) VALUES
(1, 'Basisschema (Kategorien, Verträge, Kostenhistorie)'),
(2, 'Kostenhistorie: Duplikate bereinigen, je Vertrag und Datum nur ein Eintrag'),
(3, 'Status "cancelled" entfernen (ergibt sich aus cancelled_at)'),
(4, 'Fristlogik: Frist in Tagen/Monaten, Ausrichtung auf Monats-/Quartals-/Jahresende, Kündigungsprozess'),
(5, 'Zahlungskalender: Abbuchungsanker'),
(6, 'Dokumente je Vertrag'),
(7, 'Kategorie-Icons: Default und defekte Werte (u{…}) reparieren');
