<?php
declare(strict_types=1);
// m1plus_delivery_setup.php — создаёт таблицы для системы доставки карт, если их ещё нет.
// Подключается через require_once во всех m1plus API файлах, ничего не трогает в существующих таблицах.

$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS delivery_card_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_template_id INT UNSIGNED NOT NULL,
    zone_name VARCHAR(100) NOT NULL,
    zone_polygon LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_template (card_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS delivery_slots (
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
    KEY idx_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS card_orders (
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
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS card_previews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    card_order_id INT UNSIGNED NOT NULL UNIQUE,
    preview_image VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
