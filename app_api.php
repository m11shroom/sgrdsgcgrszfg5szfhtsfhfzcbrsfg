<?php
/**
 * app_api.php — API личного кабинета артиста (PHP 8.1)
 * -----------------------------------------------------------------------------
 * Самодостаточный endpoint, который использует ТОЛЬКО панель артиста
 * (index.html / app.js). Заменяет вызовы, которые раньше шли на админский файл.
 *
 * Обрабатывает:
 *   - addRelease             : создание релиза (multipart: обложка + аудио по трекам)
 *   - getActiveNewsForUser   : список активных новостей для раздела «Новости»
 *   - getActiveNotification  : одно активное попап-уведомление
 *   - markNotificationViewed : отметка просмотра уведомления пользователем
 *
 * Ожидаемые таблицы (та же БД, что у админки):
 *   releases(id, user_id, title, artist, featuring, format, release_date,
 *            language, genres, wishes, cover_url, status, status_comment,
 *            upc, created_at)
 *   release_tracks(id, release_id, track_number, title, artist, featuring,
 *            composer, lyricists, producer, duration, isrc, explicit, audio_url)
 *   notifications, notification_views
 *
 * Колонки, которых нет в вашей схеме, автоматически пропускаются — файл не
 * упадёт, если таблица немного отличается. При необходимости поправьте имена.
 */

ini_set('display_errors', '0');   // держим JSON чистым; '1' включайте только для отладки
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

/* --------------------------------------------------------------- helpers */
function wm_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Определяем id залогиненного артиста. auth.php может класть его под разным
 *  ключом сессии, поэтому перебираем популярные, затем берём id от клиента. */
function wm_user_id($input) {
    foreach (array('user_id', 'id', 'userId', 'uid', 'wm_user_id', 'wm_uid') as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    if (!empty($input['user_id']) && is_numeric($input['user_id'])) return (int)$input['user_id'];
    if (!empty($_POST['user_id']) && is_numeric($_POST['user_id'])) return (int)$_POST['user_id'];
    return 0;
}

/** Реальный список колонок таблицы (кешируется). */
function wm_columns($pdo, $table) {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $cols = array();
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute(array($table));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[$c] = true;
    } catch (Exception $e) {}
    return $cache[$table] = $cols;
}

/** INSERT по ассоциативному массиву, оставляя только существующие колонки. */
function wm_insert($pdo, $table, $data) {
    $cols = wm_columns($pdo, $table);
    $use  = array();
    foreach ($data as $k => $v) if (isset($cols[$k])) $use[$k] = $v;
    if (!$use) throw new Exception("Нет подходящих колонок для `$table`");
    $names = array_keys($use);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES ("
         . implode(',', array_fill(0, count($names), '?')) . ")";
    $pdo->prepare($sql)->execute(array_values($use));
    return $pdo->lastInsertId();
}

/** Сохранить простой файл ($_FILES[$field]). Возвращает web-путь или null. */
function wm_save_file($field, $dir, $allowed) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if ($allowed && !in_array($ext, $allowed, true)) throw new Exception('Недопустимый тип файла: .' . $ext);
    $dir = rtrim($dir, '/') . '/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $name = bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $name)) {
        throw new Exception('Не удалось сохранить ' . $field);
    }
    return '/' . $dir . $name;
}

/** Сохранить вложенный аудиофайл трека ($_FILES['tracks'][...][$i]['audio']). */
function wm_save_track_audio($i) {
    if (!isset($_FILES['tracks']['error'][$i]['audio'])) return null;
    if ($_FILES['tracks']['error'][$i]['audio'] !== UPLOAD_ERR_OK) return null;
    $name = $_FILES['tracks']['name'][$i]['audio'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = array('mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac');
    if (!in_array($ext, $allowed, true)) throw new Exception('Недопустимый аудиоформат: .' . $ext);
    $dir = 'uploads/audio/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $fname = bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['tracks']['tmp_name'][$i]['audio'], $dir . $fname)) {
        throw new Exception('Не удалось сохранить аудио трека ' . $i);
    }
    return '/' . $dir . $fname;
}

/* ----------------------------------------------------------- читаем вход */
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = array();
$action = $input['action'] ?? ($_POST['action'] ?? ($_GET['action'] ?? ''));

$demoNews = array(
    array('id'=>1,'title'=>'Welcome to ward music','message'=>'Submit your releases and distribute to all major platforms like Spotify, Apple Music and more.','image_url'=>'','emoji'=>'🎵','created_at'=>date('Y-m-d H:i:s')),
    array('id'=>2,'title'=>'How to submit a release','message'=>'Go to "New Release", fill in the metadata, upload cover and audio files, then submit. We handle distribution!','image_url'=>'','emoji'=>'🚀','created_at'=>date('Y-m-d H:i:s')),
);

/* Подключаемся к БД. Если не вышло — для новостей/уведомлений возвращаем заглушку */
try {
    $pdo = m1_get_db();
} catch (Exception $_dbErr) {
    if ($action === 'getActiveNewsForUser') {
        wm_json(array('success' => true, 'news' => $demoNews));
    }
    if ($action === 'getActiveNotification') {
        wm_json(array('success' => true, 'notification' => null));
    }
    if ($action === 'markNotificationViewed') {
        wm_json(array('success' => true));
    }
    wm_json(array('success' => false, 'error' => 'Database unavailable'));
}

/* гарантируем наличие show_as_popup (как авто-миграция в админке) */
try {
    $has = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                        AND COLUMN_NAME = 'show_as_popup'")->fetchColumn();
    if (!$has) $pdo->exec("ALTER TABLE notifications ADD COLUMN show_as_popup TINYINT(1) NOT NULL DEFAULT 1");
} catch (Exception $e) {}

/* ========================================================= ЗАГРУЗКА РЕЛИЗА */
if ($action === 'addRelease') {
    $userId = wm_user_id($input);
    if ($userId <= 0) wm_json(array('success' => false, 'error' => 'Нужно войти, чтобы отправить релиз.'));

    $title     = trim($_POST['title']       ?? '');
    $artist    = trim($_POST['artist']      ?? '');
    $featuring = trim($_POST['featuring']   ?? '');
    $format    = trim($_POST['format']      ?? 'single');
    $relDate   = trim($_POST['releaseDate'] ?? '');
    $language  = trim($_POST['language']    ?? '');
    $wishes    = trim($_POST['wishes']      ?? '');
    $genres    = json_decode($_POST['genres'] ?? '[]', true);
    if (!is_array($genres)) $genres = array();
    $tracksIn  = $_POST['tracks'] ?? array();

    if ($title === '' || $artist === '' || $relDate === '' || !count($genres)) {
        wm_json(array('success' => false, 'error' => 'Заполните все обязательные поля релиза.'));
    }
    if (!is_array($tracksIn) || !count($tracksIn)) {
        wm_json(array('success' => false, 'error' => 'Добавьте хотя бы один трек.'));
    }

    try {
        $coverUrl = wm_save_file('cover', 'uploads/covers', array('jpg', 'jpeg', 'png', 'webp'));
    } catch (Exception $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
    if (!$coverUrl) wm_json(array('success' => false, 'error' => 'Обложка обязательна.'));

    try {
        $pdo->beginTransaction();

        $releaseId = wm_insert($pdo, 'releases', array(
            'user_id'        => $userId,
            'title'          => $title,
            'artist'         => $artist,
            'featuring'      => $featuring,
            'format'         => $format,
            'release_date'   => $relDate ?: null,
            'language'       => $language,
            'genres'         => json_encode($genres, JSON_UNESCAPED_UNICODE),
            'wishes'         => $wishes,
            'cover_url'      => $coverUrl,
            'status'         => 'pending',
            'status_comment' => null,
            'upc'            => '',
            'created_at'     => date('Y-m-d H:i:s'),
        ));

        $n = 0;
        foreach ($tracksIn as $i => $t) {
            $n++;
            $audioUrl = wm_save_track_audio($i);
            $lyr = trim($t['lyricists'] ?? '');
            wm_insert($pdo, 'release_tracks', array(
                'release_id'   => $releaseId,
                'track_number' => isset($t['track_number']) ? (int)$t['track_number'] : $n,
                'title'        => trim($t['title']     ?? ''),
                'artist'       => trim($t['artist']    ?? ''),
                'featuring'    => trim($t['featuring'] ?? ''),
                'composer'     => trim($t['composer']  ?? ''),
                'lyricists'    => $lyr,        // на случай столбца "lyricists"
                'lyricist'     => $lyr,        // на случай столбца "lyricist"
                'producer'     => trim($t['producer']  ?? ''),
                'duration'     => trim($t['duration']  ?? ''),
                'isrc'         => trim($t['isrc']      ?? ''),
                'explicit'     => (!empty($t['explicit']) && $t['explicit'] !== '0') ? 1 : 0,
                'audio_url'    => $audioUrl,
            ));
        }

        $pdo->commit();
        wm_json(array('success' => true, 'release_id' => $releaseId));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('addRelease error: ' . $e->getMessage());
        wm_json(array('success' => false, 'error' => 'Не удалось сохранить релиз: ' . $e->getMessage()));
    }
}

/* ============================================================= НОВОСТИ / ПОПАПЫ */
if ($action === 'getActiveNewsForUser') {
    try {
        $st = $pdo->query("SELECT id, title, message, image_url, emoji, created_at
                           FROM notifications WHERE is_active = 1 ORDER BY created_at DESC");
        $news = $st->fetchAll(PDO::FETCH_ASSOC);
        
        // Если нет новостей - добавляем демо
        if (empty($news)) {
            $news = array(
                array(
                    'id' => 1,
                    'title' => 'Welcome to ward music',
                    'message' => 'Welcome to your personal artist account. Submit your releases and earn royalties!',
                    'image_url' => '',
                    'emoji' => '🎵',
                    'created_at' => date('Y-m-d H:i:s')
                ),
                array(
                    'id' => 2,
                    'title' => 'Submit Your First Release',
                    'message' => 'Start distributing to Spotify, Apple Music, and more. Click "New Release" to begin.',
                    'image_url' => '',
                    'emoji' => '🚀',
                    'created_at' => date('Y-m-d H:i:s')
                )
            );
        }
        
        wm_json(array('success' => true, 'news' => $news));
    } catch (Exception $e) {
        // Если таблица не существует - возвращаем демо
        $demoNews = array(
            array(
                'id' => 1,
                'title' => 'Welcome to ward music',
                'message' => 'Welcome to your personal artist account. Submit your releases and earn royalties!',
                'image_url' => '',
                'emoji' => '🎵',
                'created_at' => date('Y-m-d H:i:s')
            ),
            array(
                'id' => 2,
                'title' => 'Submit Your First Release',
                'message' => 'Start distributing to Spotify, Apple Music, and more. Click "New Release" to begin.',
                'image_url' => '',
                'emoji' => '🚀',
                'created_at' => date('Y-m-d H:i:s')
            )
        );
        wm_json(array('success' => true, 'news' => $demoNews));
    }
}

if ($action === 'getActiveNotification') {
    try {
        $st = $pdo->query("SELECT id, message, emoji, image_url, created_at
                           FROM notifications WHERE is_active = 1 AND show_as_popup = 1
                           ORDER BY created_at DESC LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        wm_json(array('success' => true, 'notification' => $row ?: null));
    } catch (Exception $e) {
        try {
            $st = $pdo->query("SELECT id, message, emoji, image_url, created_at
                               FROM notifications WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
            $row = $st->fetch(PDO::FETCH_ASSOC);
            wm_json(array('success' => true, 'notification' => $row ?: null));
        } catch (Exception $e2) {
            wm_json(array('success' => true, 'notification' => null));
        }
    }
}

if ($action === 'markNotificationViewed') {
    try {
        $notifId = (int)($input['notification_id'] ?? 0);
        if (!$notifId) wm_json(array('success' => true));
        $userId = wm_user_id($input);
        if ($userId > 0) {
            $chk = $pdo->prepare("SELECT id FROM notification_views
                                  WHERE notification_id = ? AND user_id = ? LIMIT 1");
            $chk->execute(array($notifId, $userId));
            if (!$chk->fetch()) {
                $pdo->prepare("INSERT INTO notification_views (notification_id, user_id, viewed_at)
                               VALUES (?, ?, NOW())")->execute(array($notifId, $userId));
            }
        }
        wm_json(array('success' => true));
    } catch (Exception $e) {
        wm_json(array('success' => true));
    }
}

/* ─── LOGIN HISTORY & 2FA (пункты 15, 16) ─── */

// Записать вход + проверить смену IP
if ($action === 'recordLogin') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => true));

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            ip_address VARCHAR(45) NOT NULL, user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Записываем вход
        $pdo->prepare("INSERT INTO login_history (user_id, ip_address, user_agent) VALUES (?,?,?)")
            ->execute([$userId, $ip, $ua]);

        // Проверяем сменился ли IP
        $lastIp = '';
        try {
            $r = $pdo->prepare("SELECT last_known_ip FROM users WHERE id=?");
            $r->execute([$userId]);
            $lastIp = (string)($r->fetchColumn() ?: '');
        } catch (Throwable $e) {}

        $ipChanged = ($lastIp && $lastIp !== $ip);

        // Обновляем последний IP
        try { $pdo->prepare("UPDATE users SET last_known_ip=? WHERE id=?")->execute([$ip, $userId]); } catch (Throwable $e) {}

        wm_json(array('success' => true, 'ip_changed' => $ipChanged, 'ip' => $ip));
    } catch (Throwable $e) {
        wm_json(array('success' => true, 'ip_changed' => false));
    }
}

// Получить историю входов
if ($action === 'getLoginHistory') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => true, 'history' => array()));

    try {
        $st = $pdo->prepare("SELECT ip_address, user_agent, created_at FROM login_history WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
        $st->execute([$userId]);
        wm_json(array('success' => true, 'history' => $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        wm_json(array('success' => true, 'history' => array()));
    }
}

// Отправить код 2FA на почту (при смене IP)
if ($action === 'send2FACode') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => false, 'error' => 'Not authorized'));

    try {
        $u = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $u->execute([$userId]);
        $email = (string)($u->fetchColumn() ?: '');
        if (!$email) wm_json(array('success' => false, 'error' => 'No email'));

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_codes (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            code VARCHAR(6) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare("INSERT INTO login_codes (user_id, code, ip_address, expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")
            ->execute([$userId, $code, $ip]);

        // Отправляем код через SMTP
        $sent = false;
        if (file_exists(__DIR__ . '/smtp_mailer.php')) {
            require_once __DIR__ . '/smtp_mailer.php';
            if (function_exists('wm_smtp_send')) {
                $body = "Здравствуйте!\n\nОбнаружен вход с нового IP-адреса.\n\nКод подтверждения: {$code}\n\nКод действителен 24 часа.\nЕсли это были не вы — смените пароль.\n\n— ward music";
                $r = wm_smtp_send($email, 'Код подтверждения входа — ward music', $body);
                $sent = !empty($r['ok']);
            }
        }
        if (!$sent) {
            $sent = @mail($email, 'Код подтверждения входа — ward music', "Ваш код: {$code}\nДействителен 24 часа.");
        }

        wm_json(array('success' => true, 'email' => $email, 'sent' => $sent));
    } catch (Throwable $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
}

// Проверить код 2FA
if ($action === 'verify2FACode') {
    $userId = wm_user_id($input);
    $code   = trim($input['code'] ?? '');
    if (!$userId || !$code) wm_json(array('success' => false, 'error' => 'Invalid'));

    try {
        $st = $pdo->prepare("SELECT id FROM login_codes WHERE user_id=? AND code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $st->execute([$userId, $code]);
        $row = $st->fetch();
        if (!$row) wm_json(array('success' => false, 'error' => 'Неверный или истёкший код'));

        $pdo->prepare("UPDATE login_codes SET used=1 WHERE id=?")->execute([$row['id']]);
        wm_json(array('success' => true));
    } catch (Throwable $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
}

/* ─── DRAFTS (черновики релизов, пункт 9) ─── */

if ($action === 'saveDraft') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => false, 'error' => 'Not authorized'));

    $data  = $input['draft_data'] ?? '';
    $title = substr(trim($input['title'] ?? 'Untitled draft'), 0, 255);
    $draftId = (int)($input['draft_id'] ?? 0);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS release_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            draft_data LONGTEXT NOT NULL, title VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $jsonData = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($draftId) {
            $pdo->prepare("UPDATE release_drafts SET draft_data=?, title=?, updated_at=NOW() WHERE id=? AND user_id=?")
                ->execute([$jsonData, $title, $draftId, $userId]);
            wm_json(array('success' => true, 'draft_id' => $draftId));
        } else {
            $pdo->prepare("INSERT INTO release_drafts (user_id, draft_data, title) VALUES (?,?,?)")
                ->execute([$userId, $jsonData, $title]);
            wm_json(array('success' => true, 'draft_id' => (int)$pdo->lastInsertId()));
        }
    } catch (Throwable $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
}

if ($action === 'getDrafts') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => true, 'drafts' => array()));
    try {
        $st = $pdo->prepare("SELECT id, title, updated_at FROM release_drafts WHERE user_id=? ORDER BY updated_at DESC");
        $st->execute([$userId]);
        wm_json(array('success' => true, 'drafts' => $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        wm_json(array('success' => true, 'drafts' => array()));
    }
}

if ($action === 'getDraft') {
    $userId = wm_user_id($input);
    $draftId = (int)($input['draft_id'] ?? 0);
    if (!$userId || !$draftId) wm_json(array('success' => false, 'error' => 'Invalid'));
    try {
        $st = $pdo->prepare("SELECT draft_data FROM release_drafts WHERE id=? AND user_id=?");
        $st->execute([$draftId, $userId]);
        $data = $st->fetchColumn();
        if ($data === false) wm_json(array('success' => false, 'error' => 'Not found'));
        wm_json(array('success' => true, 'draft_data' => json_decode($data, true)));
    } catch (Throwable $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
}

if ($action === 'deleteDraft') {
    $userId = wm_user_id($input);
    $draftId = (int)($input['draft_id'] ?? 0);
    if (!$userId || !$draftId) wm_json(array('success' => false, 'error' => 'Invalid'));
    try {
        $pdo->prepare("DELETE FROM release_drafts WHERE id=? AND user_id=?")->execute([$draftId, $userId]);
        wm_json(array('success' => true));
    } catch (Throwable $e) {
        wm_json(array('success' => false, 'error' => $e->getMessage()));
    }
}

/* ─── RELEASE CALENDAR (пункт 10) ─── */
if ($action === 'getReleaseCalendar') {
    $userId = wm_user_id($input);
    if (!$userId) wm_json(array('success' => true, 'releases' => array()));
    try {
        $st = $pdo->prepare("SELECT id, title, artist, release_date, status FROM releases WHERE user_id=? AND release_date IS NOT NULL ORDER BY release_date ASC");
        $st->execute([$userId]);
        wm_json(array('success' => true, 'releases' => $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        wm_json(array('success' => true, 'releases' => array()));
    }
}

wm_json(array('success' => false, 'error' => 'Unknown action'));
