<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}
try { $db = getDB(); } catch (Exception $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit;
}
header('Content-Type: application/json; charset=utf-8');

// Подстраховка на случай, если migrate6.php ещё не запускали
$db->exec("CREATE TABLE IF NOT EXISTS plastic_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    card_number VARCHAR(19) DEFAULT NULL,
    expiry VARCHAR(5) DEFAULT NULL,
    pin_code VARCHAR(4) DEFAULT NULL,
    delivery_address TEXT DEFAULT NULL,
    delivery_date DATE DEFAULT NULL,
    delivery_time VARCHAR(5) DEFAULT NULL,
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory',
    rep_name VARCHAR(150) DEFAULT NULL,
    rep_phone VARCHAR(30) DEFAULT NULL,
    rep_photo VARCHAR(255) DEFAULT NULL,
    nfc_uid VARCHAR(64) DEFAULT NULL,
    nfc_scanned_at DATETIME DEFAULT NULL,
    is_delivered TINYINT(1) NOT NULL DEFAULT 0,
    delivered_at DATETIME DEFAULT NULL,
    yoo_access_token TEXT DEFAULT NULL,
    yoo_wallet VARCHAR(34) DEFAULT NULL,
    wallet_connected_at DATETIME DEFAULT NULL,
    balance_cached DECIMAL(14,2) DEFAULT NULL,
    balance_updated_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Автомиграция для установок, где таблица создавалась ещё со старой колонкой cvv
try {
    $hasOldCvv = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'cvv'")->fetch();
    $hasPin    = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'pin_code'")->fetch();
    if ($hasOldCvv && !$hasPin) {
        $db->exec("ALTER TABLE plastic_cards CHANGE COLUMN cvv pin_code VARCHAR(4) DEFAULT NULL");
    } elseif (!$hasPin) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN pin_code VARCHAR(4) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'wallet_connected_at'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN wallet_connected_at DATETIME DEFAULT NULL AFTER yoo_wallet");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'delivery_status'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory'");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_name'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_name VARCHAR(150) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_phone'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_phone VARCHAR(30) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_photo'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_photo VARCHAR(255) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'nfc_uid'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN nfc_uid VARCHAR(64) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'nfc_scanned_at'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN nfc_scanned_at DATETIME DEFAULT NULL");
    }
    $tokType = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plastic_cards' AND COLUMN_NAME='yoo_access_token'")->fetchColumn();
    if ($tokType && strtolower($tokType) === 'varchar') {
        $db->exec("ALTER TABLE plastic_cards MODIFY COLUMN yoo_access_token TEXT DEFAULT NULL");
    }
} catch (\Throwable $e) {}

/* Статусы доставки пластиковой карты (в порядке прохождения) */
function pc_delivery_statuses(): array {
    return [
        'to_factory'   => 'Везём пластик на фабрику',
        'printing'     => 'Печатаем карту',
        'packaging'    => 'Упаковываем',
        'transporting' => 'Везём',
        'handoff'      => 'Передаём представителю',
        'with_rep'     => 'Карта у представителя',
        'rep_enroute'  => 'Представитель в пути',
    ];
}


$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ─── Поиск клиента для выбора при создании карты ─── */
if ($action === 'search_users') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode(['ok'=>true,'users'=>[]]); exit; }
    $like = '%'.$q.'%';
    $s = $db->prepare('SELECT id, username, email FROM users WHERE is_admin=0 AND (username LIKE ? OR email LIKE ?) ORDER BY username LIMIT 20');
    $s->execute([$like, $like]);
    echo json_encode(['ok'=>true, 'users'=>$s->fetchAll()]);
    exit;
}

/* ─── Создать пластиковую карту ─── */
if ($action === 'create') {
    $userId  = (int)($_POST['user_id'] ?? 0);
    $name    = trim(substr($_POST['name'] ?? '', 0, 100));
    $address = trim(substr($_POST['delivery_address'] ?? '', 0, 500));
    $date    = trim($_POST['delivery_date'] ?? '');
    $time    = trim($_POST['delivery_time'] ?? '');
    $expiry  = trim($_POST['expiry'] ?? '');
    $pin     = trim($_POST['pin_code'] ?? '');

    if (!$userId) { echo json_encode(['ok'=>false,'error'=>'Выберите клиента']); exit; }
    if (!$name)   { echo json_encode(['ok'=>false,'error'=>'Укажите название карты']); exit; }
    if (!preg_match('/^\d{2}\/\d{2}$/', $expiry)) { echo json_encode(['ok'=>false,'error'=>'Формат срока действия: MM/YY']); exit; }
    if (!preg_match('/^\d{4}$/', $pin))            { echo json_encode(['ok'=>false,'error'=>'ПИН-код должен быть 4 цифры']); exit; }

    $uCheck = $db->prepare('SELECT id FROM users WHERE id=? AND is_admin=0');
    $uCheck->execute([$userId]);
    if (!$uCheck->fetch()) { echo json_encode(['ok'=>false,'error'=>'Клиент не найден']); exit; }

    $dateVal = null;
    if ($date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d) { echo json_encode(['ok'=>false,'error'=>'Неверная дата доставки']); exit; }
        $dateVal = $d->format('Y-m-d');
    }
    $timeVal = null;
    if ($time !== '') {
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) { echo json_encode(['ok'=>false,'error'=>'Неверное время доставки']); exit; }
        $timeVal = $time;
    }

    $cover = null;
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fname = uniqid('plastic_') . '.' . $ext;
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], rtrim($dir, '/') . '/' . $fname)) {
                $cover = $fname;
            }
        }
    }

    // Номер карты вводить вручную не нужно — генерируется автоматически для отображения
    $cardNumber = (string)random_int(4000, 5999) . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);

    $db->prepare(
        'INSERT INTO plastic_cards (user_id,name,cover_image,card_number,expiry,pin_code,delivery_address,delivery_date,delivery_time)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$userId, $name, $cover, $cardNumber, $expiry, $pin, $address ?: null, $dateVal, $timeVal]);

    echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
    exit;
}

/* ─── Обновить статус доставки карты ─── */
if ($action === 'update_delivery_status') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $statuses = pc_delivery_statuses();

    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    if (!isset($statuses[$status])) { echo json_encode(['ok'=>false,'error'=>'Неизвестный статус']); exit; }

    $s = $db->prepare('SELECT * FROM plastic_cards WHERE id=?');
    $s->execute([$id]);
    $card = $s->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    // Статусы «Карта у представителя» и «Представитель в пути» требуют данные представителя —
    // но только один раз: если ФИО+телефон уже сохранены (с прошлого статуса), повторно спрашивать не нужно
    // (хотя админ может их поменять через ссылку «изменить» — тогда новые значения придут в POST)
    $repNameInput  = trim(substr($_POST['rep_name'] ?? '', 0, 150));
    $repPhoneInput = trim(substr($_POST['rep_phone'] ?? '', 0, 30));
    $needsRepInfo  = in_array($status, ['with_rep', 'rep_enroute'], true) && empty($card['rep_name']);

    if ($needsRepInfo) {
        if (!$repNameInput)  { echo json_encode(['ok'=>false,'error'=>'Укажите ФИО представителя']); exit; }
        if (!$repPhoneInput) { echo json_encode(['ok'=>false,'error'=>'Укажите номер телефона представителя']); exit; }
    }

    $repName  = $repNameInput  !== '' ? $repNameInput  : $card['rep_name'];
    $repPhone = $repPhoneInput !== '' ? $repPhoneInput : $card['rep_phone'];
    $repPhoto = $card['rep_photo']; // сохраняем прежнее фото, если новое не загрузили
    if (isset($_FILES['rep_photo']) && $_FILES['rep_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['rep_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fname = uniqid('rep_') . '.' . $ext;
            if (move_uploaded_file($_FILES['rep_photo']['tmp_name'], rtrim($dir, '/') . '/' . $fname)) {
                $repPhoto = $fname;
            }
        }
    }

    $db->prepare('UPDATE plastic_cards SET delivery_status=?, rep_name=?, rep_phone=?, rep_photo=? WHERE id=?')
       ->execute([$status, $repName ?: null, $repPhone ?: null, $repPhoto, $id]);

    echo json_encode(['ok'=>true, 'status'=>$status, 'status_label'=>$statuses[$status]]);
    exit;
}

/* ─── Пометить как выданную / вернуть в ожидание ─── */
if ($action === 'toggle_delivered') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }

    $s = $db->prepare('SELECT is_delivered FROM plastic_cards WHERE id=?');
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    $new = $row['is_delivered'] ? 0 : 1;
    $db->prepare('UPDATE plastic_cards SET is_delivered=?, delivered_at=? WHERE id=?')
       ->execute([$new, $new ? date('Y-m-d H:i:s') : null, $id]);

    echo json_encode(['ok'=>true, 'is_delivered'=>$new]);
    exit;
}

/* ─── Привязать NFC-метку к карте (сканирует админ, необязательно) ─── */
if ($action === 'save_nfc') {
    $id  = (int)($_POST['id'] ?? 0);
    $uid = trim($_POST['nfc_uid'] ?? '');
    if (!$id)  { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    if (!$uid) { echo json_encode(['ok'=>false,'error'=>'Не получен UID метки']); exit; }

    $s = $db->prepare('SELECT id FROM plastic_cards WHERE id=?');
    $s->execute([$id]);
    if (!$s->fetch()) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    $db->prepare('UPDATE plastic_cards SET nfc_uid=?, nfc_scanned_at=NOW() WHERE id=?')
       ->execute([substr($uid, 0, 64), $id]);

    echo json_encode(['ok'=>true]);
    exit;
}

/* ─── Отвязать NFC-метку ─── */
if ($action === 'clear_nfc') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('UPDATE plastic_cards SET nfc_uid=NULL, nfc_scanned_at=NULL WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

/* ─── Удалить карту ─── */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('DELETE FROM plastic_cards WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
