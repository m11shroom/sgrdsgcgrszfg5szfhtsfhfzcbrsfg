<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function setupDB(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        avatar VARCHAR(255) DEFAULT NULL,
        is_admin TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        credential_id VARCHAR(512) NOT NULL UNIQUE,
        public_key TEXT NOT NULL,
        sign_count INT UNSIGNED DEFAULT 0,
        label VARCHAR(100) DEFAULT 'Device',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance DECIMAL(12,2) DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'transfer',
        card_number VARCHAR(20) DEFAULT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        bank_name VARCHAR(50) DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL,
        commission DECIMAL(12,2) DEFAULT 0.00,
        status VARCHAR(20) DEFAULT 'processing',
        refunded TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        sender_id INT NOT NULL DEFAULT 0,
        is_admin TINYINT(1) DEFAULT 0,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS bank_topups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        credit DECIMAL(12,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        yoo_operation_id VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS payment_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        service_name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        one_time TINYINT(1) DEFAULT 1,
        buyer_fields TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS payment_link_txs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id INT NOT NULL,
        payment_id VARCHAR(32) NOT NULL DEFAULT '',
        payer_user_id INT DEFAULT NULL,
        method VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        creator_gets DECIMAL(12,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        yoo_operation_id VARCHAR(255) DEFAULT NULL,
        buyer_email VARCHAR(255) DEFAULT NULL,
        buyer_phone VARCHAR(50) DEFAULT NULL,
        buyer_name VARCHAR(150) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (link_id) REFERENCES payment_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Payment verification codes (for bank payment 2FA) ──────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS payment_verify_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        code VARCHAR(6) NOT NULL,
        attempts TINYINT UNSIGNED DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_token (user_id, token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safe migrations for existing installs
    foreach ([
        "ALTER TABLE transactions ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'transfer'",
        "ALTER TABLE support_messages ADD COLUMN sender_id INT NOT NULL DEFAULT 0",
        "ALTER TABLE payment_links ADD COLUMN one_time TINYINT(1) DEFAULT 1",
        "ALTER TABLE payment_links ADD COLUMN buyer_fields TEXT DEFAULT NULL",
        "ALTER TABLE payment_link_txs ADD COLUMN payment_id VARCHAR(32) NOT NULL DEFAULT ''",
        "ALTER TABLE payment_link_txs ADD COLUMN buyer_email VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE payment_link_txs ADD COLUMN buyer_phone VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE payment_link_txs ADD COLUMN buyer_name VARCHAR(150) DEFAULT NULL",
    ] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) {}
    }

    $s = $db->prepare('SELECT id FROM users WHERE email=?');
    $s->execute([ADMIN_EMAIL]);
    if (!$s->fetch()) {
        $h = password_hash('Mushroomqwerty123', PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (email,username,password,is_admin) VALUES (?,?,?,1)')->execute([ADMIN_EMAIL,'Admin',$h]);
    }
    $db->exec("INSERT IGNORE INTO bank_accounts (user_id) SELECT id FROM users");
}

setupDB();
