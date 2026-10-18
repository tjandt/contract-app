-- ============================================================
-- Vertragsverwaltung - Datenbankschema
-- ============================================================
-- Import z.B. per:  mysql -u DEIN_USER -p DEIN_DB < db.sql
-- Falls Emojis/Sonderzeichen danach falsch aussehen, stattdessen:
--   mysql --default-character-set=utf8mb4 -u DEIN_USER -p DEIN_DB < db.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60) NOT NULL,
    color_hex   VARCHAR(7)  NOT NULL DEFAULT '#8E8E93',
    icon        VARCHAR(10) NOT NULL DEFAULT '\u{1F4C4}',
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contracts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cost_history (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    contract_id   INT NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    valid_from    DATE NOT NULL,
    reason        VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    INDEX idx_contract_date (contract_id, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Standard-Kategorien
-- ------------------------------------------------------------
INSERT INTO categories (name, color_hex, icon, sort_order) VALUES
('Strom',            '#FF9F0A', '⚡', 1),
('Gas',               '#FF453A', '🔥', 2),
('Internet & Telefon','#0A84FF', '📡', 3),
('Handy',             '#5E5CE6', '📱', 4),
('Versicherung',      '#30D158', '🛡', 5),
('Streaming',         '#BF5AF2', '🎬', 6),
('Fitness',           '#FF375F', '🏋', 7),
('Sonstiges',         '#8E8E93', '📄', 8);
