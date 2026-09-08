<?php
declare(strict_types=1);
// intl_cards_setup.php — автосоздание таблиц системы Visa/Mastercard карт.
// Отдельная система, не трогает существующие card_templates/issued_cards.

$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS intl_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    payment_system ENUM('visa','mastercard') NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    status ENUM('pending_payment','paid','active','cancelled') NOT NULL DEFAULT 'pending_payment',
    payment_label VARCHAR(64) NOT NULL UNIQUE,
    card_number VARCHAR(19) DEFAULT NULL,
    expiry VARCHAR(5) DEFAULT NULL,
    cvv VARCHAR(3) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    activated_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    KEY idx_user (user_id),
    KEY idx_status (status),
    KEY idx_label (payment_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
