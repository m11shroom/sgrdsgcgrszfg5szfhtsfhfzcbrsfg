-- M1PLUS WALLET — Delivery & Wallet Binding Schema
-- Новые таблицы для: заказа пластиковых карт, зон/времени доставки, привязки YooMoney кошелька.
-- НЕ трогает существующие users / bank_accounts / transactions / card_templates(cards.php) и т.д.
-- Можно выполнить прямо в phpMyAdmin одним запросом — таблицы создаются только если их ещё нет.
-- ======================================================

CREATE TABLE IF NOT EXISTS card_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_template_id INT UNSIGNED NOT NULL,
    zone_name VARCHAR(100) NOT NULL,
    zone_polygon LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_template (card_template_id),
    CONSTRAINT fk_dz_template FOREIGN KEY (card_template_id) REFERENCES card_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_slots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_template_id INT UNSIGNED NOT NULL,
    delivery_date DATE NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    max_orders INT DEFAULT 10,
    current_orders INT DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_template (card_template_id),
    KEY idx_date (delivery_date),
    CONSTRAINT fk_ds_template FOREIGN KEY (card_template_id) REFERENCES card_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_template_id INT UNSIGNED NOT NULL,
    delivery_zone_id INT UNSIGNED DEFAULT NULL,
    delivery_slot_id INT UNSIGNED DEFAULT NULL,
    delivery_address TEXT DEFAULT NULL,
    delivery_latitude DECIMAL(10,8) DEFAULT NULL,
    delivery_longitude DECIMAL(11,8) DEFAULT NULL,
    payment_method VARCHAR(20) DEFAULT 'balance',
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    confirmation_code VARCHAR(64) DEFAULT NULL,
    representative_name VARCHAR(150) DEFAULT NULL,
    representative_phone VARCHAR(30) DEFAULT NULL,
    representative_photo VARCHAR(255) DEFAULT NULL,
    delivery_date_actual DATE DEFAULT NULL,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_template (card_template_id),
    KEY idx_status (status),
    CONSTRAINT fk_co_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_co_template FOREIGN KEY (card_template_id) REFERENCES card_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_co_zone FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL,
    CONSTRAINT fk_co_slot FOREIGN KEY (delivery_slot_id) REFERENCES delivery_slots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_previews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_order_id INT UNSIGNED NOT NULL UNIQUE,
    preview_image VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cp_order FOREIGN KEY (card_order_id) REFERENCES card_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Привязка кошелька YooMoney к ЗАКАЗУ КАРТЫ (делает АДМИН, не пользователь) ──
-- Админ привязывает кошелёк после того, как заказ карты уже оформлен.

CREATE TABLE IF NOT EXISTS wallet_bindings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_order_id INT UNSIGNED NOT NULL UNIQUE,
    yoo_wallet VARCHAR(34) NOT NULL,
    yoo_access_token TEXT NOT NULL,
    connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_check DATETIME DEFAULT NULL,
    balance_cached DECIMAL(14,2) DEFAULT NULL,
    balance_updated_at DATETIME DEFAULT NULL,
    KEY idx_order (card_order_id),
    CONSTRAINT fk_wb_order FOREIGN KEY (card_order_id) REFERENCES card_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    wallet_binding_id INT UNSIGNED NOT NULL,
    yoo_operation_id VARCHAR(64) NOT NULL UNIQUE,
    amount DECIMAL(14,2) NOT NULL,
    credited_amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    credited_at DATETIME DEFAULT NULL,
    KEY idx_binding (wallet_binding_id),
    KEY idx_status (status),
    CONSTRAINT fk_wtl_binding FOREIGN KEY (wallet_binding_id) REFERENCES wallet_bindings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
