-- M1PLUS WALLET — Card System Schema v2
-- ======================================================

CREATE TABLE IF NOT EXISTS card_templates (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    cover_image   VARCHAR(255) DEFAULT NULL,
    issue_cost    DECIMAL(12,2) NOT NULL DEFAULT 0,
    issue_type    ENUM('instant','wait') NOT NULL DEFAULT 'instant',
    wait_days     INT DEFAULT NULL,
    balance_type  ENUM('zero','prepaid') NOT NULL DEFAULT 'zero',
    currency      ENUM('RUB','EUR','USD') NOT NULL DEFAULT 'RUB',
    prepaid_amount DECIMAL(12,2) DEFAULT NULL,   -- фиксированная сумма (устанавливает админ)
    quantity      INT DEFAULT NULL,               -- NULL = неограничено
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS issued_cards (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    template_id      INT DEFAULT NULL,
    cover_image      VARCHAR(255) DEFAULT NULL,
    status           ENUM('pending','active') NOT NULL DEFAULT 'pending',
    balance_type     ENUM('zero','prepaid') NOT NULL DEFAULT 'zero',
    issue_type       ENUM('instant','wait') NOT NULL DEFAULT 'instant',
    wait_days        INT DEFAULT NULL,
    currency         ENUM('RUB','EUR','USD') NOT NULL DEFAULT 'RUB',
    card_number      VARCHAR(19) DEFAULT NULL,
    expiry           VARCHAR(5) DEFAULT NULL,
    cvv              VARCHAR(3) DEFAULT NULL,
    prepaid_amount   DECIMAL(12,2) DEFAULT NULL,
    requested_amount DECIMAL(12,2) DEFAULT NULL,
    purpose          VARCHAR(255) DEFAULT NULL,   -- назначение, устанавливает админ
    commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    issue_cost_paid  DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at     DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES card_templates(id)
);

CREATE TABLE IF NOT EXISTS card_topups (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    card_id    INT NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    note       VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (card_id) REFERENCES issued_cards(id)
);

CREATE TABLE IF NOT EXISTS card_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    card_id    INT DEFAULT NULL,
    purpose    ENUM('issue','reveal') NOT NULL,
    code       VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ======================================================
-- Если таблицы уже есть — выполните только ALTER:
-- ======================================================
-- ALTER TABLE card_templates
--   ADD COLUMN currency ENUM('RUB','EUR','USD') NOT NULL DEFAULT 'RUB' AFTER balance_type,
--   ADD COLUMN prepaid_amount DECIMAL(12,2) DEFAULT NULL AFTER currency,
--   ADD COLUMN quantity INT DEFAULT NULL AFTER prepaid_amount;
--
-- ALTER TABLE issued_cards
--   ADD COLUMN currency ENUM('RUB','EUR','USD') NOT NULL DEFAULT 'RUB' AFTER wait_days,
--   ADD COLUMN purpose VARCHAR(255) DEFAULT NULL AFTER requested_amount,
--   ADD COLUMN commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER purpose;
