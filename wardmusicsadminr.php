<?php
/**
 * wardmusicsadminr.php — Admin Panel (PHP 8.1)
 * С поддержкой новостей в панели артиста
 */

// ── ВКЛЮЧАЕМ ОТОБРАЖЕНИЕ ОШИБОК ────────────────────────────────────────────
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// ── Helpers ──────────────────────────────────────────────────────────────────
function wm_admin_id() {
    if (!empty($_SESSION['wm_admin_id']))        return (int)$_SESSION['wm_admin_id'];
    if (!empty($_SESSION['m1plus_admin']))      return (int)$_SESSION['m1plus_admin'];
    return 0;
}
function wm_is_admin() {
    return wm_admin_id() > 0;
}

function wm_admin_user() {
    $aid = wm_admin_id();
    if (!$aid) return null;
    try {
        $pdo = m1_get_db();
        try {
            $s = $pdo->prepare("SELECT id, email, nickname FROM admins WHERE id = ? LIMIT 1");
            $s->execute(array($aid));
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        } catch (Exception $e2) {}
        $s = $pdo->prepare("SELECT id, email, nickname FROM users WHERE id = ? LIMIT 1");
        $s->execute(array($aid));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: array('id'=>$aid,'email'=>'admin','nickname'=>'Admin');
    } catch (Exception $e) {
        return array('id'=>$aid,'email'=>'admin','nickname'=>'Admin');
    }
}

function wm_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function wm_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── ОТПРАВКА ПИСЕМ ──────────────────────────────────────────────────────────
function sendRejectionEmail($toEmail, $artistName, $releaseTitle, $reason, $releaseId, $coverUrl = '') {
    if (empty($toEmail)) {
        error_log("Rejection email skipped: empty recipient for release $releaseId");
        return false;
    }

    // Отправка через SMTP (ящик rejectwhy@)
    require_once __DIR__ . '/smtp_mailer.php';
    $result = wm_send_rejection_email($toEmail, $releaseTitle, $reason);
    if ($result['ok']) {
        error_log("Rejection email sent to $toEmail for release $releaseId");
        return true;
    } else {
        error_log("FAILED to send rejection email to $toEmail for release $releaseId: " . $result['error']);
        return false;
    }
}

function sendRejectionEmailOld($toEmail, $artistName, $releaseTitle, $reason, $releaseId, $coverUrl = '') {
    if (empty($toEmail)) {
        return false;
    }

    $subject = 'Your release "' . $releaseTitle . '" has been rejected';
    $body = "Your release \"$releaseTitle\" by $artistName has been rejected.\n\n";
    $body .= "Reason:\n$reason\n\n";
    $body .= "Please review the feedback and resubmit after making the necessary changes.\n";
    $body .= "If you have questions, open a support ticket in your personal account.\n\n";
    $body .= "— ward music team";

    $from     = 'admin@app.wardmusic.space';
    $fromName = 'ward music Admin';
    $headers  = "From: " . $fromName . " <" . $from . ">\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $result = @mail($toEmail, $subject, $body, $headers, '-f' . $from);
    if ($result) {
        error_log("Rejection email sent to $toEmail for release $releaseId");
    } else {
        error_log("FAILED to send rejection email to $toEmail for release $releaseId");
    }
    return $result;
}

// ── ФУНКЦИЯ ДЛЯ ОТДАЧИ ФАЙЛА С RANGE ──────────────────────────────────────
function sendFileWithRange($filePath, $contentType, $filename, $download = false) {
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found');
    }
    $size = filesize($filePath);
    $fp = fopen($filePath, 'rb');
    if (!$fp) {
        http_response_code(500);
        die('Could not open file');
    }

    $start = 0;
    $end = $size - 1;
    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

    if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = intval($matches[1]);
        if (!empty($matches[2])) {
            $end = intval($matches[2]);
        }
        if ($start > $end || $end >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            die('Requested range not satisfiable');
        }
        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);
    } else {
        $length = $size;
        http_response_code(200);
        header('Content-Length: ' . $size);
    }

    if ($download) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $filename . '"');
    }
    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');

    if ($start > 0) {
        fseek($fp, $start);
    }
    $bufferSize = 1024 * 1024;
    $bytesSent = 0;
    while ($bytesSent < $length) {
        $chunk = min($bufferSize, $length - $bytesSent);
        echo fread($fp, $chunk);
        $bytesSent += $chunk;
        flush();
    }
    fclose($fp);
    exit;
}

// ── API endpoint ─────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $input  = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = array();
    
    $action = isset($input['action']) ? $input['action'] : (isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : ''));
    $pdo    = m1_get_db();

    // ── AUTO-MIGRATION: добавляем show_as_popup если колонки нет ────────────
    try {
        $colExists = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='show_as_popup'")->fetchColumn();
        if (!$colExists) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN show_as_popup TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Exception $e) { /* ignore */ }

    // ── PUBLIC API ENDPOINTS (NO AUTH NEEDED) ────────────────────────────────
    if ($action === 'getActiveNewsForUser') {
        try {
            // Показываем ВСЕ активные новости (и popup, и news-only)
            $stmt = $pdo->query("SELECT id, title, message, image_url, emoji, created_at 
                                 FROM notifications 
                                 WHERE is_active = 1 
                                 ORDER BY created_at DESC");
            $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
            wm_json(array('success' => true, 'news' => $news));
        } catch (Exception $e) {
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'getActiveNotification') {
        try {
            // Только те, у которых show_as_popup = 1 (обычные уведомления при входе)
            $stmt = $pdo->query("SELECT id, message, emoji, image_url, created_at 
                                 FROM notifications 
                                 WHERE is_active = 1 AND show_as_popup = 1
                                 ORDER BY created_at DESC 
                                 LIMIT 1");
            $notif = $stmt->fetch(PDO::FETCH_ASSOC);
            wm_json(array('success' => true, 'notification' => $notif ?: null));
        } catch (Exception $e) {
            // Fallback если колонка ещё не создана
            try {
                $stmt = $pdo->query("SELECT id, message, emoji, image_url, created_at FROM notifications WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
                $notif = $stmt->fetch(PDO::FETCH_ASSOC);
                wm_json(array('success' => true, 'notification' => $notif ?: null));
            } catch (Exception $e2) {
                wm_json(array('success' => true, 'notification' => null));
            }
        }
    }

    if ($action === 'markNotificationViewed') {
        try {
            $notifId = (int)(isset($input['notification_id']) ? $input['notification_id'] : 0);
            if (!$notifId) wm_json(array('success' => true));

            // Пытаемся найти user_id из запроса или из сессии
            $userId = (int)(isset($input['user_id']) ? $input['user_id'] : 0);
            if (!$userId) {
                foreach (array('user_id', 'id', 'userId', 'uid', 'wm_user_id') as $k) {
                    if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
                        $userId = (int)$_SESSION[$k];
                        break;
                    }
                }
            }

            if ($userId > 0) {
                $check = $pdo->prepare("SELECT id FROM notification_views WHERE notification_id = ? AND user_id = ? LIMIT 1");
                $check->execute(array($notifId, $userId));
                if (!$check->fetch()) {
                    $pdo->prepare("INSERT INTO notification_views (notification_id, user_id, viewed_at) VALUES (?, ?, NOW())")
                        ->execute(array($notifId, $userId));
                }
            }
            wm_json(array('success' => true));
        } catch (Exception $e) {
            wm_json(array('success' => true)); // молча успех
        }
    }

    // ── ADMIN PROTECTED ENDPOINTS ────────────────────────────────────────────
    if (!wm_is_admin()) {
        wm_json(array('success' => false, 'error' => 'Access denied'));
    }

    // ── RELEASES ──────────────────────────────────────────────────────────
    if ($action === 'listReleases') {
        $filter = isset($input['filter']) ? $input['filter'] : (isset($_GET['filter']) ? $_GET['filter'] : 'all');
        if ($filter === 'all') {
            $stmt = $pdo->query("SELECT r.*, u.email AS author_email, u.nickname AS author_name
                                 FROM releases r
                                 LEFT JOIN users u ON u.id = r.user_id
                                 ORDER BY r.created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT r.*, u.email AS author_email, u.nickname AS author_name
                                   FROM releases r
                                   LEFT JOIN users u ON u.id = r.user_id
                                   WHERE r.status = ?
                                   ORDER BY r.created_at DESC");
            $stmt->execute(array($filter));
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['genres'] = $r['genres'] ? json_decode($r['genres'], true) : array();
            if (!is_array($r['genres'])) $r['genres'] = array();
            $t = $pdo->prepare("SELECT * FROM release_tracks WHERE release_id = ? ORDER BY track_number ASC, id ASC");
            $t->execute(array($r['id']));
            $r['tracks'] = $t->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($r);
        wm_json(array('success' => true, 'releases' => $rows));
    }

    if ($action === 'updateReleaseStatus') {
        $releaseId     = (int)(isset($input['release_id']) ? $input['release_id'] : 0);
        $status        = isset($input['status']) ? $input['status'] : '';
        $statusComment = trim(isset($input['status_comment']) ? $input['status_comment'] : '');
        $upc           = trim(isset($input['upc']) ? $input['upc'] : '');
        $tracks        = isset($input['tracks']) ? $input['tracks'] : array();

        $allowed = array('pending', 'approved', 'rejected', 'ozhidaet');
        if (!in_array($status, $allowed)) {
            wm_json(array('success' => false, 'error' => 'Invalid status'));
        }
        if ($status === 'rejected' && $statusComment === '') {
            wm_json(array('success' => false, 'error' => 'Rejection reason is required'));
        }

        $stmt = $pdo->prepare("SELECT r.*, u.email AS user_email, u.nickname AS user_name
                                FROM releases r LEFT JOIN users u ON u.id = r.user_id
                                WHERE r.id = ? LIMIT 1");
        $stmt->execute(array($releaseId));
        $release = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$release) wm_json(array('success' => false, 'error' => 'Release not found'));

        try {
            $pdo->prepare("UPDATE releases SET status = ?, status_comment = ?, upc = ? WHERE id = ?")
                ->execute(array($status, $statusComment ?: null, $upc, $releaseId));
        } catch (Exception $e) {
            $pdo->prepare("UPDATE releases SET status = ?, upc = ? WHERE id = ?")
                ->execute(array($status, $upc, $releaseId));
        }

        foreach ($tracks as $t) {
            if (isset($t['id'], $t['isrc'])) {
                $pdo->prepare("UPDATE release_tracks SET isrc = ? WHERE id = ? AND release_id = ?")
                    ->execute(array(trim($t['isrc']), (int)$t['id'], $releaseId));
            }
        }

        if ($status === 'rejected' && !empty($release['user_email'])) {
            sendRejectionEmail(
                $release['user_email'],
                $release['user_name'] ? $release['user_name'] : $release['user_email'],
                $release['title'],
                $statusComment,
                $releaseId,
                isset($release['cover_url']) ? $release['cover_url'] : ''
            );
        }

        wm_json(array('success' => true));
    }

    // ── TICKETS ──────────────────────────────────────────────────────────
    if ($action === 'replyTicket') {
        try {
            $ticketId = (int)(isset($input['ticket_id']) ? $input['ticket_id'] : 0);
            $message  = trim(isset($input['message']) ? $input['message'] : '');
            if (!$ticketId || !$message) {
                wm_json(array('success' => false, 'error' => 'Missing ticket ID or message'));
            }
            $adminId = (int)$_SESSION['wm_admin_id'];
            if (!$adminId) {
                wm_json(array('success' => false, 'error' => 'Admin not logged in'));
            }
            $pdo->prepare("INSERT INTO ticket_responses (ticket_id, user_id, message, is_admin, created_at) VALUES (?,?,?,1,NOW())")
                ->execute(array($ticketId, $adminId, $message));
            $pdo->prepare("UPDATE tickets SET status='open' WHERE id=?")
                ->execute(array($ticketId));
            wm_json(array('success' => true));
        } catch (Exception $e) {
            error_log("replyTicket error: " . $e->getMessage());
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'closeTicket') {
        try {
            $id = (int)(isset($input['ticket_id']) ? $input['ticket_id'] : 0);
            if (!$id) {
                wm_json(array('success' => false, 'error' => 'Ticket ID required'));
            }
            $pdo->prepare("UPDATE tickets SET status='closed' WHERE id=?")
                ->execute(array($id));
            wm_json(array('success' => true));
        } catch (Exception $e) {
            error_log("closeTicket error: " . $e->getMessage());
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'reopenTicket') {
        try {
            $id = (int)(isset($input['ticket_id']) ? $input['ticket_id'] : 0);
            if (!$id) {
                wm_json(array('success' => false, 'error' => 'Ticket ID required'));
            }
            $pdo->prepare("UPDATE tickets SET status='open' WHERE id=?")
                ->execute(array($id));
            wm_json(array('success' => true));
        } catch (Exception $e) {
            error_log("reopenTicket error: " . $e->getMessage());
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'listTickets') {
        $filter = isset($input['filter']) ? $input['filter'] : (isset($_GET['filter']) ? $_GET['filter'] : 'all');
        if ($filter === 'all') {
            $stmt = $pdo->query("SELECT t.*, u.email AS user_email, u.nickname AS user_name
                                 FROM tickets t LEFT JOIN users u ON u.id = t.user_id
                                 ORDER BY t.created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT t.*, u.email AS user_email, u.nickname AS user_name
                                   FROM tickets t LEFT JOIN users u ON u.id = t.user_id
                                   WHERE t.status = ? ORDER BY t.created_at DESC");
            $stmt->execute(array($filter));
        }
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tickets as &$tick) {
            $r = $pdo->prepare("SELECT * FROM ticket_responses WHERE ticket_id = ? ORDER BY created_at ASC");
            $r->execute(array($tick['id']));
            $tick['responses'] = $r->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($tick);
        wm_json(array('success' => true, 'tickets' => $tickets));
    }

    // ── USERS ─────────────────────────────────────────────────────────────
    if ($action === 'listUsers') {
        $stmt = $pdo->query("SELECT id,email,nickname,role,banned,balance,vk,telegram,tariff_id,tariff_until,created_at FROM users ORDER BY created_at DESC");
        wm_json(array('success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'updateUser') {
        $id          = (int)(isset($input['id']) ? $input['id'] : 0);
        $email       = trim(isset($input['email'])    ? $input['email']    : '');
        $nickname    = trim(isset($input['nickname']) ? $input['nickname'] : '');
        $role        = isset($input['role'])     ? $input['role']     : 'user';
        $banned      = isset($input['banned'])   ? (int)$input['banned'] : 0;
        $balance     = (float)(isset($input['balance']) ? $input['balance'] : 0);
        $vk          = trim(isset($input['vk'])       ? $input['vk']       : '');
        $telegram    = trim(isset($input['telegram']) ? $input['telegram'] : '');
        $tariffId    = (isset($input['tariff_id']) && $input['tariff_id']) ? (int)$input['tariff_id'] : null;
        $tariffUntil = (isset($input['tariff_until']) && $input['tariff_until']) ? $input['tariff_until'] : null;
        $password    = isset($input['password']) ? $input['password'] : '';
        if (!$id || !$email || !$nickname) wm_json(array('success' => false, 'error' => 'Missing required fields'));
        if ($password) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET email=?,nickname=?,role=?,banned=?,balance=?,vk=?,telegram=?,tariff_id=?,tariff_until=?,password_hash=? WHERE id=?")
                ->execute(array($email,$nickname,$role,$banned,$balance,$vk?:null,$telegram?:null,$tariffId,$tariffUntil,$hash,$id));
        } else {
            $pdo->prepare("UPDATE users SET email=?,nickname=?,role=?,banned=?,balance=?,vk=?,telegram=?,tariff_id=?,tariff_until=? WHERE id=?")
                ->execute(array($email,$nickname,$role,$banned,$balance,$vk?:null,$telegram?:null,$tariffId,$tariffUntil,$id));
        }
        wm_json(array('success' => true));
    }

    if ($action === 'deleteUser') {
        $id = (int)(isset($input['id']) ? $input['id'] : 0);
        if (!$id || $id === (int)$_SESSION['wm_admin_id']) {
            wm_json(array('success' => false, 'error' => 'Cannot delete this user'));
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute(array($id));
        wm_json(array('success' => true));
    }

    // ── NOTIFICATIONS (NEWS IN ADMIN PANEL) ──────────────────────────────
    if ($action === 'listNotifications') {
        $stmt = $pdo->query("SELECT n.*, COUNT(v.id) AS views_count
                             FROM notifications n
                             LEFT JOIN notification_views v ON n.id = v.notification_id
                             GROUP BY n.id
                             ORDER BY n.created_at DESC");
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notifs as &$n) {
            $users = $pdo->prepare("SELECT u.id, u.email, u.nickname, v.viewed_at
                                    FROM notification_views v
                                    JOIN users u ON u.id = v.user_id
                                    WHERE v.notification_id = ?
                                    ORDER BY v.viewed_at DESC");
            $users->execute(array($n['id']));
            $n['viewers'] = $users->fetchAll(PDO::FETCH_ASSOC);
        }
        wm_json(array('success' => true, 'notifications' => $notifs));
    }

    if ($action === 'createNotification') {
        $title       = trim(isset($input['title'])       ? $input['title']       : '');
        $message     = trim(isset($input['message'])     ? $input['message']     : '');
        $emoji       = trim(isset($input['emoji'])       ? $input['emoji']       : '');
        $image       = trim(isset($input['image_url'])   ? $input['image_url']   : '');
        $showAsPopup = isset($input['show_as_popup'])    ? (int)$input['show_as_popup'] : 1;
        if (!$message) wm_json(array('success' => false, 'error' => 'Message is required'));
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (title, message, emoji, image_url, is_active, show_as_popup, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())");
            $result = $stmt->execute(array($title ?: null, $message, $emoji ?: null, $image ?: null, $showAsPopup));
            if ($result) {
                $lastId = $pdo->lastInsertId();
                wm_json(array('success' => true, 'id' => $lastId, 'message' => 'Notification created successfully'));
            } else {
                wm_json(array('success' => false, 'error' => 'Failed to insert notification'));
            }
        } catch (Exception $e) {
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'updateNotification') {
        $id          = (int)(isset($input['id'])         ? $input['id']         : 0);
        $title       = trim(isset($input['title'])       ? $input['title']      : '');
        $message     = trim(isset($input['message'])     ? $input['message']    : '');
        $emoji       = trim(isset($input['emoji'])       ? $input['emoji']      : '');
        $image       = trim(isset($input['image_url'])   ? $input['image_url']  : '');
        $showAsPopup = isset($input['show_as_popup'])    ? (int)$input['show_as_popup'] : 1;
        if (!$id)      wm_json(array('success' => false, 'error' => 'ID required'));
        if (!$message) wm_json(array('success' => false, 'error' => 'Message is required'));
        try {
            // Обновляем без изменения created_at — никаких следов редактирования
            $pdo->prepare("UPDATE notifications SET title=?, message=?, emoji=?, image_url=?, show_as_popup=? WHERE id=?")
                ->execute(array($title ?: null, $message, $emoji ?: null, $image ?: null, $showAsPopup, $id));
            wm_json(array('success' => true));
        } catch (Exception $e) {
            wm_json(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        }
    }

    if ($action === 'toggleNotification') {
        $id = (int)(isset($input['id']) ? $input['id'] : 0);
        if (!$id) wm_json(array('success' => false, 'error' => 'ID required'));
        $stmt = $pdo->prepare("UPDATE notifications SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute(array($id));
        wm_json(array('success' => true));
    }

    if ($action === 'deleteNotification') {
        $id = (int)(isset($input['id']) ? $input['id'] : 0);
        if (!$id) wm_json(array('success' => false, 'error' => 'ID required'));
        $pdo->prepare("DELETE FROM notification_views WHERE notification_id = ?")->execute(array($id));
        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute(array($id));
        wm_json(array('success' => true));
    }

    if ($action === 'uploadNotificationImage') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/notifications/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $filename = 'notif_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $path = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
                wm_json(array('success' => true, 'url' => '/' . $path));
            } else {
                wm_json(array('success' => false, 'error' => 'Upload failed'));
            }
        } else {
            wm_json(array('success' => false, 'error' => 'No file or upload error'));
        }
    }

    // ── TRACK LISTENS ──────────────────────────────────────────────────────
    if ($action === 'listTrackListens') {
        $trackId = (int)(isset($input['track_id']) ? $input['track_id'] : (isset($_GET['track_id']) ? $_GET['track_id'] : 0));
        if (!$trackId) wm_json(array('success' => false, 'error' => 'Track ID required'));
        $stmt = $pdo->prepare("SELECT * FROM track_listens WHERE track_id = ? ORDER BY platform, added_at DESC");
        $stmt->execute(array($trackId));
        $listens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        wm_json(array('success' => true, 'listens' => $listens));
    }

    if ($action === 'addTrackListen') {
        $trackId = (int)(isset($input['track_id']) ? $input['track_id'] : 0);
        $platform = trim(isset($input['platform']) ? $input['platform'] : '');
        $count = (int)(isset($input['count']) ? $input['count'] : 0);
        $addedAt = isset($input['added_at']) ? $input['added_at'] : null;
        if (!$trackId || !$platform) {
            wm_json(array('success' => false, 'error' => 'Track ID and platform required'));
        }
        $stmt = $pdo->prepare("INSERT INTO track_listens (track_id, platform, count, added_at) VALUES (?, ?, ?, ?)");
        $params = array($trackId, $platform, $count, $addedAt ?: date('Y-m-d H:i:s'));
        $stmt->execute($params);
        wm_json(array('success' => true, 'id' => $pdo->lastInsertId()));
    }

    if ($action === 'updateTrackListen') {
        $id = (int)(isset($input['id']) ? $input['id'] : 0);
        $count = (int)(isset($input['count']) ? $input['count'] : 0);
        if (!$id) wm_json(array('success' => false, 'error' => 'ID required'));
        $stmt = $pdo->prepare("UPDATE track_listens SET count = ? WHERE id = ?");
        $stmt->execute(array($count, $id));
        wm_json(array('success' => true));
    }

    if ($action === 'deleteTrackListen') {
        $id = (int)(isset($input['id']) ? $input['id'] : 0);
        if (!$id) wm_json(array('success' => false, 'error' => 'ID required'));
        $stmt = $pdo->prepare("DELETE FROM track_listens WHERE id = ?");
        $stmt->execute(array($id));
        wm_json(array('success' => true));
    }

    // ── AUDIO ─────────────────────────────────────────────────────────────
    if ($action === 'getWavAudio') {
        $filePath = isset($_GET['path']) ? $_GET['path'] : (isset($input['path']) ? $input['path'] : '');
        $download = isset($_GET['download']) ? (bool)$_GET['download'] : (isset($input['download']) ? (bool)$input['download'] : false);
        
        if (empty($filePath)) {
            http_response_code(400);
            die('Missing file path');
        }
        
        $baseDir = realpath(__DIR__ . '/uploads/');
        $fullPath = realpath(__DIR__ . '/' . ltrim($filePath, '/'));
        if (!$fullPath || strpos($fullPath, $baseDir) !== 0) {
            http_response_code(403);
            die('Access denied');
        }
        if (!file_exists($fullPath)) {
            http_response_code(404);
            die('File not found: ' . $fullPath);
        }
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeMap = [
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'aac'  => 'audio/aac',
            'flac' => 'audio/flac',
            'ogg'  => 'audio/ogg',
            'm4a'  => 'audio/mp4',
        ];
        $mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
        
        $ffmpegAvailable = false;
        exec('which ffmpeg', $ffmpegOutput, $ffmpegCode);
        if ($ffmpegCode === 0 && !empty($ffmpegOutput)) {
            $ffmpegAvailable = true;
        }
        
        $outputFile = $fullPath;
        $filename = basename($filePath);
        $contentType = $mime;
        
        if ($ffmpegAvailable && $ext !== 'wav') {
            $tempFile = tempnam(sys_get_temp_dir(), 'wav_') . '.wav';
            $cmd = "ffmpeg -i " . escapeshellarg($fullPath) . " -acodec pcm_s16le -ar 44100 -ac 2 " . escapeshellarg($tempFile) . " -y 2>&1";
            exec($cmd, $output, $returnCode);
            if ($returnCode === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                $contentType = 'audio/wav';
                $outputFile = $tempFile;
                $filename = pathinfo($filePath, PATHINFO_FILENAME) . '.wav';
            } else {
                @unlink($tempFile);
                $outputFile = $fullPath;
                $filename = basename($filePath);
                $contentType = $mime;
                error_log("FFmpeg conversion failed for $fullPath, serving original");
            }
        }
        
        sendFileWithRange($outputFile, $contentType, $filename, $download);
        exit;
    }

    // ─── WITHDRAWALS (выводы средств) ───
    if ($action === 'listWithdrawals') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
                phone VARCHAR(32) NOT NULL, bank_name VARCHAR(128) NOT NULL,
                amount DECIMAL(12,2) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'processing',
                refunded TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        $rows = $pdo->query("SELECT w.*, u.email, u.nickname FROM withdrawals w LEFT JOIN users u ON u.id=w.user_id ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        wm_json(array('success' => true, 'withdrawals' => $rows));
    }

    if ($action === 'updateWithdrawal') {
        $wid = (int)($input['id'] ?? 0);
        $status = $input['status'] ?? '';
        if (!in_array($status, array('processing','completed','declined'), true)) {
            wm_json(array('success' => false, 'error' => 'Invalid status'));
        }
        $wRow = $pdo->prepare("SELECT * FROM withdrawals WHERE id=?");
        $wRow->execute([$wid]);
        $w = $wRow->fetch(PDO::FETCH_ASSOC);
        if (!$w) wm_json(array('success' => false, 'error' => 'Not found'));

        // Отклонено → вернуть на баланс
        if ($status === 'declined' && $w['status'] !== 'declined' && !$w['refunded']) {
            $done = false;
            try { $r = $pdo->prepare("UPDATE user_balances SET balance=balance+? WHERE user_id=?"); $r->execute([$w['amount'], $w['user_id']]); $done = $r->rowCount() > 0; } catch (Throwable $e) {}
            if (!$done) { try { $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$w['amount'], $w['user_id']]); } catch (Throwable $e) {} }
            $pdo->prepare("UPDATE withdrawals SET status=?, refunded=1 WHERE id=?")->execute([$status, $wid]);
        }
        // Сняли отклонение → снова списать
        elseif ($w['status'] === 'declined' && $status !== 'declined' && $w['refunded']) {
            try { $pdo->prepare("UPDATE user_balances SET balance=balance-? WHERE user_id=?")->execute([$w['amount'], $w['user_id']]); }
            catch (Throwable $e) { try { $pdo->prepare("UPDATE users SET balance=balance-? WHERE id=?")->execute([$w['amount'], $w['user_id']]); } catch (Throwable $e2) {} }
            $pdo->prepare("UPDATE withdrawals SET status=?, refunded=0 WHERE id=?")->execute([$status, $wid]);
        }
        else {
            $pdo->prepare("UPDATE withdrawals SET status=? WHERE id=?")->execute([$status, $wid]);
        }
        wm_json(array('success' => true));
    }

    wm_json(array('success' => false, 'error' => 'Unknown action'));
}

// ── Logout ───────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION['wm_admin_id'], $_SESSION['wm_admin_role'], $_SESSION['m1plus_admin']);
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── Login ────────────────────────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wm_login'])) {
    $em = trim(isset($_POST['email'])    ? $_POST['email']    : '');
    $pw =       isset($_POST['password']) ? $_POST['password'] : '';
    try {
        $pdo  = m1_get_db();
        $stmt = $pdo->prepare("SELECT id, email, nickname, password_hash FROM admins WHERE email = ? LIMIT 1");
        $stmt->execute(array($em));
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($pw, $u["password_hash"])) {
            $_SESSION['wm_admin_id']   = $u['id'];
            $_SESSION['wm_admin_role'] = 'admin';
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            header('Location: ' . $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $loginError = 'Invalid credentials.';
        }
    } catch (Exception $e) {
        $loginError = 'Database error: ' . $e->getMessage();
    }
}

// ── Auth check ──────────────────────────────────────────────────────────────
$isAdmin   = wm_is_admin();
$adminUser = $isAdmin ? wm_admin_user() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ward music — Admin Panel</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#0b1020;--surface:#111827;--surface2:#1f2937;--border:rgba(255,255,255,0.08);
--text:#f9fafb;--muted:#9ca3af;--accent:#6366f1;--success:#22c55e;--warning:#f59e0b;--danger:#ef4444;--radius:10px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;}

/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2.5rem 2rem;width:360px;}
.login-logo{font-size:1.4rem;font-weight:700;margin-bottom:0.2rem;}
.login-sub{color:var(--muted);font-size:0.8rem;margin-bottom:2rem;}
.form-group{margin-bottom:1rem;}
.form-label{display:block;font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.4rem;}
.form-control{width:100%;padding:0.6rem 0.85rem;background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);font-size:0.9rem;outline:none;transition:border 0.15s;}
.form-control:focus{border-color:var(--accent);}
textarea.form-control{resize:vertical;}
.btn{display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;border:none;border-radius:var(--radius);font-size:0.85rem;font-weight:600;cursor:pointer;transition:opacity 0.15s;text-decoration:none;}
.btn:hover{opacity:0.82;}
.btn-primary{background:var(--accent);color:#fff;}
.btn-success{background:var(--success);color:#fff;}
.btn-warning{background:var(--warning);color:#000;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-ghost{background:var(--surface2);color:var(--text);}
.btn-sm{padding:0.32rem 0.65rem;font-size:0.76rem;}
.btn-block{width:100%;justify-content:center;}
.error-box{background:rgba(239,68,68,0.1);border:1px solid var(--danger);color:var(--danger);border-radius:var(--radius);padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.85rem;}

/* Layout */
.layout{display:flex;min-height:100vh;}
.sidebar{width:220px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:100;}
.sb-logo{padding:1.25rem 1rem 1rem;border-bottom:1px solid var(--border);}
.sb-logo-text{font-size:1rem;font-weight:700;}
.sb-logo-sub{font-size:0.7rem;color:var(--muted);}
.sb-nav{flex:1;padding:1rem 0;}
.sb-section-title{padding:0.25rem 1rem;font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;}
.nav-btn{display:flex;align-items:center;gap:0.6rem;padding:0.55rem 1rem;color:var(--muted);font-size:0.85rem;cursor:pointer;border:none;background:none;width:100%;text-align:left;transition:background 0.12s,color 0.12s;}
.nav-btn:hover,.nav-btn.active{background:rgba(99,102,241,0.12);color:var(--text);}
.nav-btn i{width:16px;text-align:center;}
.sb-footer{padding:1rem;border-top:1px solid var(--border);}
.sb-user{font-size:0.75rem;color:var(--muted);margin-bottom:0.5rem;word-break:break-all;}

.main{margin-left:220px;flex:1;display:flex;flex-direction:column;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1rem;font-weight:600;}
.content{padding:1.5rem;flex:1;}

/* Cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:1.5rem;}
.card-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;}
.card-title{font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem;}
.card-body{padding:1.25rem;}

.filter-tabs{display:flex;gap:0.35rem;flex-wrap:wrap;}
.ftab{padding:0.28rem 0.7rem;border-radius:6px;font-size:0.76rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--surface2);color:var(--muted);}
.ftab.active{background:var(--accent);color:#fff;border-color:var(--accent);}

table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th{text-align:left;padding:0.6rem 0.75rem;color:var(--muted);border-bottom:1px solid var(--border);font-weight:600;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.04em;}
td{padding:0.6rem 0.75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,0.02);}

.badge{display:inline-block;padding:0.18rem 0.5rem;border-radius:99px;font-size:0.68rem;font-weight:700;}
.badge-pending{background:rgba(245,158,11,0.15);color:var(--warning);}
.badge-approved{background:rgba(34,197,94,0.15);color:var(--success);}
.badge-rejected{background:rgba(239,68,68,0.15);color:var(--danger);}
.badge-waiting{background:rgba(99,102,241,0.15);color:#818cf8;}
.badge-open{background:rgba(34,197,94,0.15);color:var(--success);}
.badge-closed{background:rgba(156,163,175,0.15);color:var(--muted);}
.badge-admin{background:rgba(239,68,68,0.15);color:var(--danger);}
.badge-user{background:rgba(99,102,241,0.15);color:#818cf8;}
.badge-banned{background:rgba(239,68,68,0.3);color:var(--danger);}
.badge-explicit{background:rgba(239,68,68,0.2);color:var(--danger);}
.badge-clean{background:rgba(34,197,94,0.15);color:var(--success);}
.badge-active{background:rgba(34,197,94,0.15);color:var(--success);}
.badge-inactive{background:rgba(156,163,175,0.15);color:var(--muted);}

.actions{display:flex;gap:0.35rem;flex-wrap:wrap;}
.cover-thumb{width:52px;height:52px;border-radius:6px;object-fit:cover;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);overflow:hidden;flex-shrink:0;}
.cover-thumb img{width:100%;height:100%;object-fit:cover;}

/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem;}
.modal-bg.open{display:flex;}
.modal-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.75rem;width:100%;max-width:760px;max-height:90vh;overflow-y:auto;}
.modal-title{font-size:1rem;font-weight:700;margin-bottom:1.25rem;}
.modal-footer{display:flex;gap:0.6rem;justify-content:flex-end;margin-top:1.25rem;flex-wrap:wrap;}

.space-y>*+*{margin-top:0.75rem;}
.flex{display:flex;}
.flex-wrap{flex-wrap:wrap;}
.items-center{align-items:center;}
.gap-sm{gap:0.5rem;}
.gap-md{gap:1rem;}
.flex-1{flex:1;}
.text-muted{color:var(--muted);}
.text-sm{font-size:0.8rem;}
.text-xs{font-size:0.7rem;}
.mt-sm{margin-top:0.5rem;}
.mt-md{margin-top:1rem;}
.mb-md{margin-bottom:1rem;}
.font-bold{font-weight:700;}

.rejection-box{background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius);padding:1rem;margin:0.75rem 0;}
.rejection-box label{display:block;font-size:0.72rem;color:var(--danger);margin-bottom:0.4rem;font-weight:700;text-transform:uppercase;}

.empty{text-align:center;color:var(--muted);padding:3rem 1rem;}
.empty i{font-size:2.5rem;margin-bottom:1rem;display:block;}

#notif{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:0.85rem 1.1rem;font-size:0.85rem;display:none;max-width:320px;box-shadow:0 8px 24px rgba(0,0,0,0.4);}
#notif.ok{border-color:var(--success);color:var(--success);}
#notif.err{border-color:var(--danger);color:var(--danger);}
#notif.info{border-color:var(--accent);color:var(--accent);}

/* Notification form */
.emoji-picker{display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.3rem;}
.emoji-picker span{font-size:1.8rem;cursor:pointer;transition:transform 0.1s;background:var(--surface2);padding:0.2rem 0.5rem;border-radius:6px;border:1px solid transparent;}
.emoji-picker span:hover{transform:scale(1.2);border-color:var(--accent);}
.emoji-picker span.selected{background:var(--accent);border-color:var(--accent);}

/* Chill game styles */
#chillCanvas {
    border-radius: 12px;
    background: #1a3a5c;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    width: 100%;
    max-width: 600px;
    height: auto;
}
.chill-stats {
    display: flex;
    gap: 1.5rem;
    margin-top: 1rem;
    justify-content: center;
    font-size: 1.1rem;
}
.chill-stats span i { margin-right: 0.4rem; }
</style>
</head>
<body>

<?php if (!$isAdmin): ?>
<!-- LOGIN -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">ward music</div>
    <div class="login-sub">Administration Panel — authorised access only</div>
    <?php if ($loginError): ?>
      <div class="error-box"><i class="fas fa-exclamation-circle"></i> <?php echo wm_esc($loginError); ?></div>
    <?php endif; ?>
    <form method="POST" action="<?php echo wm_esc($_SERVER['PHP_SELF']); ?>">
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="admin@wardmusic.ru" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="Password" required>
      </div>
      <input type="hidden" name="wm_login" value="1">
      <button type="submit" class="btn btn-primary btn-block mt-md">
        <i class="fas fa-sign-in-alt"></i> Sign In
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ADMIN APP -->
<div class="layout">

  <aside class="sidebar">
    <div class="sb-logo">
      <div class="sb-logo-text">ward music</div>
      <div class="sb-logo-sub">Admin Panel</div>
    </div>
    <nav class="sb-nav">
      <div class="sb-section-title">Moderation</div>
      <button class="nav-btn active" id="navReleases" onclick="switchTab('releases')">
        <i class="fas fa-compact-disc"></i> Releases
      </button>
      <button class="nav-btn" id="navTickets" onclick="switchTab('tickets')">
        <i class="fas fa-ticket-alt"></i> Tickets
      </button>
      <div class="sb-section-title" style="margin-top:0.5rem">Users</div>
      <button class="nav-btn" id="navUsers" onclick="switchTab('users')">
        <i class="fas fa-users-cog"></i> Manage Users
      </button>
      <div class="sb-section-title" style="margin-top:0.5rem">News & Updates</div>
      <button class="nav-btn" id="navNotifications" onclick="switchTab('notifications')">
        <i class="fas fa-bell"></i> News & Notifications
      </button>
      <div class="sb-section-title" style="margin-top:0.5rem">Finance</div>
      <button class="nav-btn" id="navWithdrawals" onclick="switchTab('withdrawals')">
        <i class="fas fa-money-bill-wave"></i> Withdrawals
      </button>
      <div class="sb-section-title" style="margin-top:0.5rem">Fun</div>
      <button class="nav-btn" id="navChill" onclick="switchTab('chill')">
        <i class="fas fa-fish"></i> Fishing
      </button>
    </nav>
    <div class="sb-footer">
      <div class="sb-user"><?php echo wm_esc($adminUser['email']); ?></div>
      <a href="?logout=1" class="btn btn-ghost btn-sm btn-block">
        <i class="fas fa-sign-out-alt"></i> Log out
      </a>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div class="topbar-title" id="topbarTitle"><i class="fas fa-compact-disc"></i> Releases</div>
      <span class="text-sm text-muted">Signed in as <strong><?php echo wm_esc($adminUser['nickname'] ?: $adminUser['email']); ?></strong></span>
    </div>
    <div class="content">

      <!-- RELEASES -->
      <div id="tabReleases">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-compact-disc"></i> All Releases</div>
            <div class="filter-tabs">
              <button class="ftab active" onclick="loadReleases('all',this)">All</button>
              <button class="ftab" onclick="loadReleases('pending',this)">Pending</button>
              <button class="ftab" onclick="loadReleases('approved',this)">Approved</button>
              <button class="ftab" onclick="loadReleases('rejected',this)">Rejected</button>
              <button class="ftab" onclick="loadReleases('ozhidaet',this)">Waiting</button>
            </div>
          </div>
          <div class="card-body" id="releasesBody"><div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div></div>
        </div>
      </div>

      <!-- TICKETS -->
      <div id="tabTickets" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-ticket-alt"></i> Support Tickets</div>
            <div class="filter-tabs">
              <button class="ftab active" onclick="loadTickets('all',this)">All</button>
              <button class="ftab" onclick="loadTickets('open',this)">Open</button>
              <button class="ftab" onclick="loadTickets('closed',this)">Closed</button>
            </div>
          </div>
          <div class="card-body" id="ticketsBody"><div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div></div>
        </div>
      </div>

      <!-- USERS -->
      <div id="tabUsers" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-users-cog"></i> Users</div>
          </div>
          <div class="card-body" id="usersBody"><div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div></div>
        </div>
      </div>

      <!-- NOTIFICATIONS / NEWS -->
      <div id="tabNotifications" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-bell"></i> News & Notifications for All Users</div>
            <button class="btn btn-primary btn-sm" onclick="showCreateNotification()"><i class="fas fa-plus"></i> New News</button>
          </div>
          <div class="card-body" id="notificationsBody"><div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div></div>
        </div>
      </div>

      <!-- CHILL -->
      <div id="tabChill" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-fish"></i> Chill – Fishing Time</div>
            <span class="text-muted">Click on the water to cast your line</span>
          </div>
          <div class="card-body" style="text-align:center;">
            <canvas id="chillCanvas" width="600" height="400"></canvas>
            <div class="chill-stats">
              <span><i class="fas fa-fish"></i> Catch: <span id="fishCount">0</span></span>
              <span><i class="fas fa-times-circle"></i> Miss: <span id="missCount">0</span></span>
            </div>
            <p class="text-muted text-sm mt-sm">🎣 Click anywhere on the water to fish. Good luck!</p>
          </div>
        </div>
      </div>

      <!-- WITHDRAWALS -->
      <div id="tabWithdrawals" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-money-bill-wave"></i> Withdrawal Requests</div>
            <button class="btn btn-ghost btn-sm" onclick="loadWithdrawals()"><i class="fas fa-sync"></i> Refresh</button>
          </div>
          <div class="card-body">
            <div id="withdrawalsList"><p class="text-muted">Loading...</p></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Модальные окна -->
<div class="modal-bg" id="modalRelease">
  <div class="modal-box" style="max-width:760px">
    <div class="modal-title" id="modalReleaseTitle">Release</div>
    <div id="modalReleaseBody"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalRelease')">Close</button>
      <button class="btn btn-primary" onclick="saveRelease()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalReject">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-title"><i class="fas fa-times-circle" style="color:var(--danger)"></i> Rejection Reason</div>
    <p class="text-sm text-muted mb-md">This message will be sent to the artist by email.</p>
    <div class="form-group">
      <label class="form-label">Reason *</label>
      <textarea class="form-control" id="rejectReason" rows="4" placeholder="Explain why the release is being rejected…"></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalReject')">Cancel</button>
      <button class="btn btn-danger" onclick="doReject()"><i class="fas fa-times"></i> Reject &amp; Notify</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalTicket">
  <div class="modal-box">
    <div class="modal-title" id="modalTicketTitle">Ticket</div>
    <div id="modalTicketBody"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalTicket')">Close</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalUser">
  <div class="modal-box">
    <div class="modal-title" id="modalUserTitle">Edit User</div>
    <div id="modalUserBody"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalUser')">Cancel</button>
      <button class="btn btn-primary" onclick="saveUser()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalNotification">
  <div class="modal-box">
    <div class="modal-title" id="modalNotifTitle">New News & Notification</div>
    <div id="modalNotifBody">
      <div class="form-group">
        <label class="form-label">News Title (optional)</label>
        <input id="notifTitle" class="form-control" placeholder="e.g. New Features Released">
      </div>
      <div class="form-group">
        <label class="form-label">Emoji (optional)</label>
        <input id="notifEmoji" class="form-control" placeholder="e.g. 🎉" maxlength="10">
        <div class="emoji-picker">
          <span onclick="pickEmoji('🎉')">🎉</span>
          <span onclick="pickEmoji('🔥')">🔥</span>
          <span onclick="pickEmoji('💎')">💎</span>
          <span onclick="pickEmoji('⭐')">⭐</span>
          <span onclick="pickEmoji('🎵')">🎵</span>
          <span onclick="pickEmoji('📢')">📢</span>
          <span onclick="pickEmoji('✅')">✅</span>
          <span onclick="pickEmoji('🚀')">🚀</span>
          <span onclick="pickEmoji('💡')">💡</span>
          <span onclick="pickEmoji('👋')">👋</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Message *</label>
        <textarea id="notifMessage" class="form-control" rows="4" placeholder="Your announcement…"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Image (optional)</label>
        <input type="file" id="notifImage" class="form-control" accept="image/*" onchange="uploadNotifImage(this)">
        <div id="notifImagePreview" style="margin-top:0.5rem;"></div>
        <input type="hidden" id="notifImageUrl" value="">
      </div>
      <div class="form-group" style="background:var(--surface2);border-radius:var(--radius);padding:0.75rem;">
        <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;margin:0;">
          <input type="checkbox" id="notifShowAsPopup" checked style="width:16px;height:16px;accent-color:var(--accent);">
          <span style="font-weight:600;">Показывать как попап при входе/обновлении</span>
        </label>
        <div class="text-xs text-muted mt-sm" style="padding-left:1.6rem;">
          Снять галку = новость видна только в разделе «Новости», без всплывающего окна
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalNotification')">Cancel</button>
      <button class="btn btn-primary" id="notifSaveBtn" onclick="saveNotification()"><i class="fas fa-paper-plane"></i> Send to All</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalNotifDetail">
  <div class="modal-box">
    <div class="modal-title" id="notifDetailTitle">Notification Views</div>
    <div id="notifDetailBody"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalNotifDetail')">Close</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modalListens">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-title" id="modalListensTitle">Track Listens</div>
    <div id="modalListensBody"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modalListens')">Close</button>
    </div>
  </div>
</div>

<!-- Notification -->
<div id="notif"></div>

<script>
var pendingReject = null;
var currentReleaseId = null;
var currentTicketId = null;
var currentUserId = null;
var currentListenTrackId = null;
var notifEditId = 0; // 0 = создание, >0 = редактирование

// ════════════════════════════════════════════════════════════════════
// API REQUESTS WITH LOGGING
// ════════════════════════════════════════════════════════════════════
async function api(payload) {
    try {
        console.log('[API REQUEST]', payload);
        var r = await fetch('?api=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var json = await r.json();
        console.log('[API RESPONSE]', json);
        return json;
    } catch (err) {
        console.error('[API ERROR]', err);
        return {success: false, error: err.message};
    }
}

// ════════════════════════════════════════════════════════════════════
// UI HELPERS
// ════════════════════════════════════════════════════════════════════
function notify(msg, type) {
    var el = document.getElementById('notif');
    el.textContent = msg;
    el.className = type || 'ok';
    el.style.display = 'block';
    console.log('[NOTIFY]', type, msg);
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function sbadge(s) {
    var map = {pending:'badge-pending',approved:'badge-approved',rejected:'badge-rejected',ozhidaet:'badge-waiting',open:'badge-open',closed:'badge-closed'};
    var lbl = {pending:'Pending',approved:'Approved',rejected:'Rejected',ozhidaet:'Waiting',open:'Open',closed:'Closed'};
    return '<span class="badge '+(map[s]||'')+'">'+( lbl[s]||s)+'</span>';
}

function fmt(d) {
    if (!d) return '—';
    var dt = new Date(d);
    return dt.toLocaleDateString('en-US',{day:'2-digit',month:'short',year:'numeric'}) + ' ' + dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
}

// ════════════════════════════════════════════════════════════════════
// SWITCH TABS
// ════════════════════════════════════════════════════════════════════
function switchTab(name) {
    var tabs = ['releases','tickets','users','notifications','chill','withdrawals'];
    tabs.forEach(function(t) {
        var tabEl = document.getElementById('tab'+cap(t));
        var navEl = document.getElementById('nav'+cap(t));
        if (tabEl) tabEl.style.display = (t===name)?'':'none';
        if (navEl) navEl.classList.toggle('active', t===name);
    });
    var icons = {releases:'compact-disc',tickets:'ticket-alt',users:'users-cog',notifications:'bell',chill:'fish',withdrawals:'money-bill-wave'};
    var labels = {releases:'Releases',tickets:'Tickets',users:'Users',notifications:'News & Notifications',chill:'Chill',withdrawals:'Withdrawals'};
    document.getElementById('topbarTitle').innerHTML = '<i class="fas fa-'+icons[name]+'"></i> '+labels[name];
    if (name==='releases') loadReleases('all');
    if (name==='tickets')  loadTickets('all');
    if (name==='users')    loadUsers();
    if (name==='notifications') loadNotifications();
    if (name==='chill')    initChillGame();
    if (name==='withdrawals') loadWithdrawals();
}
function cap(s) { return s[0].toUpperCase()+s.slice(1); }

// ════════════════════════════════════════════════════════════════════
// WITHDRAWALS
// ════════════════════════════════════════════════════════════════════
async function loadWithdrawals() {
    var box = document.getElementById('withdrawalsList');
    if (!box) return;
    box.innerHTML = '<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
    var d = await api({action:'listWithdrawals'});
    if (!d.success) { box.innerHTML = '<p class="text-muted">Failed to load: '+(d.error||'')+'</p>'; return; }
    if (!d.withdrawals || !d.withdrawals.length) { box.innerHTML = '<div class="empty"><i class="fas fa-money-bill-wave"></i><br>No withdrawal requests.</div>'; return; }

    var statusColors = {processing:'#f59e0b', completed:'#10b981', declined:'#ef4444'};
    var html = '<table><thead><tr><th>User</th><th>Amount</th><th>Bank / Phone</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    d.withdrawals.forEach(function(w) {
        var color = statusColors[w.status] || '#888';
        var date = '';
        try { date = new Date(w.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); } catch(e){}
        var who = esc(w.nickname || w.email || ('User #'+w.user_id));
        html += '<tr>';
        html += '<td>'+who+'</td>';
        html += '<td><strong>'+parseFloat(w.amount).toLocaleString('ru')+' ₽</strong></td>';
        html += '<td>'+esc(w.bank_name)+'<br><span class="text-sm text-muted">'+esc(w.phone)+'</span></td>';
        html += '<td><span style="color:'+color+';font-weight:600;text-transform:capitalize;">'+esc(w.status)+'</span>'+(w.refunded==1?' <span class="text-xs text-muted">(refunded)</span>':'')+'</td>';
        html += '<td class="text-sm text-muted">'+date+'</td>';
        html += '<td><div style="display:flex;gap:4px;flex-wrap:wrap;">';
        if (w.status !== 'completed') html += '<button class="btn btn-sm" style="background:#10b981;color:#fff;" onclick="setWithdrawalStatus('+w.id+',\'completed\')">Complete</button>';
        if (w.status !== 'declined')  html += '<button class="btn btn-sm" style="background:#ef4444;color:#fff;" onclick="setWithdrawalStatus('+w.id+',\'declined\')">Decline</button>';
        if (w.status !== 'processing') html += '<button class="btn btn-sm btn-ghost" onclick="setWithdrawalStatus('+w.id+',\'processing\')">Processing</button>';
        html += '</div></td></tr>';
    });
    html += '</tbody></table>';
    box.innerHTML = html;
}

async function setWithdrawalStatus(id, status) {
    var msg = status === 'declined' ? 'Decline this withdrawal? Funds will be returned to the user balance.' : 'Change status to '+status+'?';
    if (!confirm(msg)) return;
    var d = await api({action:'updateWithdrawal', id:id, status:status});
    if (d.success) { loadWithdrawals(); }
    else { alert(d.error || 'Error'); }
}

// ════════════════════════════════════════════════════════════════════
// RELEASES
// ════════════════════════════════════════════════════════════════════
async function loadReleases(filter, btn) {
    if (btn) {
        document.querySelectorAll('#tabReleases .ftab').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
    }
    var body = document.getElementById('releasesBody');
    body.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    var d = await api({action:'listReleases', filter: filter||'all'});
    if (!d.success) { body.innerHTML = '<div class="empty">Failed to load.</div>'; return; }
    if (!d.releases.length) { body.innerHTML = '<div class="empty"><i class="fas fa-compact-disc"></i><br>No releases.</div>'; return; }
    var html = '<table><thead><tr><th>Cover</th><th>Title / Artist</th><th>Format</th><th>Status</th><th>User</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    d.releases.forEach(function(r) {
        var cover = r.cover_url ? '<img src="'+esc(r.cover_url)+'" alt="">' : '<i class="fas fa-compact-disc"></i>';
        html += '<tr>';
        html += '<td><div class="cover-thumb">'+cover+'</div></td>';
        html += '<td><div class="font-bold">'+esc(r.title)+'</div><div class="text-xs text-muted">'+esc(r.artist)+' · #'+r.id+'</div></td>';
        html += '<td class="text-xs">'+esc(r.format||'—')+'</td>';
        html += '<td>'+sbadge(r.status)+'</td>';
        html += '<td class="text-xs">'+esc(r.author_name||'')+'<br><span class="text-muted">'+esc(r.author_email||'')+'</span></td>';
        html += '<td class="text-xs">'+fmt(r.created_at)+'</td>';
        html += '<td><div class="actions">';
        html += '<button class="btn btn-ghost btn-sm" onclick="openRelease('+r.id+')"><i class="fas fa-eye"></i></button>';
        html += '<button class="btn btn-success btn-sm" title="Approve" onclick="quickStatus('+r.id+',\'approved\')"><i class="fas fa-check"></i></button>';
        html += '<button class="btn btn-danger btn-sm"  title="Reject"  onclick="startReject('+r.id+',\'\')"><i class="fas fa-times"></i></button>';
        html += '<button class="btn btn-warning btn-sm" title="Waiting" onclick="quickStatus('+r.id+',\'ozhidaet\')"><i class="fas fa-clock"></i></button>';
        html += '</div></td></tr>';
    });
    html += '</tbody></table>';
    body.innerHTML = html;
}

async function openRelease(id) {
    currentReleaseId = id;
    var d = await api({action:'listReleases', filter:'all'});
    var r = d.releases && d.releases.find(function(x){ return x.id==id; });
    if (!r) { notify('Release not found','err'); return; }
    document.getElementById('modalReleaseTitle').textContent = 'Release #'+id+' — '+r.title;

    var coverUrl = r.cover_url || '';
    if (coverUrl && !coverUrl.match(/^https?:\/\//) && coverUrl.charAt(0) !== '/') {
        coverUrl = '/' + coverUrl;
    }
    var coverHtml = coverUrl ? '<img src="'+esc(coverUrl)+'" alt="" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<i class=\\\'fas fa-compact-disc\\\'></i>\';">' : '<i class="fas fa-compact-disc"></i>';

    var genresHtml = r.genres && r.genres.length ? r.genres.join(', ') : '—';

    var tracksHtml = '';
    if (r.tracks && r.tracks.length) {
        tracksHtml = '<div class="form-group"><label class="form-label">Tracks</label><div style="overflow-x:auto;"><table style="width:100%;font-size:0.78rem;border-collapse:collapse;">'
            + '<thead><tr><th>#</th><th>Title</th><th>Artist</th><th>Duration</th><th>Explicit</th><th>ISRC</th><th>Audio</th></tr></thead><tbody>';
        r.tracks.forEach(function(t) {
            var explicitBadge = (t.explicit == 1) ? '<span class="badge badge-explicit">Explicit</span>' : '<span class="badge badge-clean">Clean</span>';
            var duration = t.duration_sec ? t.duration_sec + 's' : (t.duration || '—');
            
            var audioHtml = '—';
            if (t.audio_url) {
                var audioUrl = t.audio_url;
                if (!audioUrl.match(/^https?:\/\//) && audioUrl.charAt(0) !== '/') {
                    audioUrl = '/' + audioUrl;
                }
                var wavUrl = '?api=1&action=getWavAudio&path=' + encodeURIComponent(audioUrl) + '&download=0';
                var downloadUrl = '?api=1&action=getWavAudio&path=' + encodeURIComponent(audioUrl) + '&download=1';
                audioHtml = '<audio controls style="width:120px;height:30px;" onerror="notify(\'Failed to load audio for track #'+t.id+'\',\'err\')"><source src="'+wavUrl+'" type="audio/wav"></audio>'
                    + ' <a href="'+downloadUrl+'" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></a>';
            }
            
            tracksHtml += '<tr><td>'+esc(t.track_number||'')+'</td>'
                + '<td>'+esc(t.title)+'</td>'
                + '<td>'+esc(t.artist||'')+'</td>'
                + '<td>'+esc(duration)+'</td>'
                + '<td>'+explicitBadge+'</td>'
                + '<td><input class="form-control" style="width:120px;padding:0.2rem 0.4rem;" placeholder="ISRC" id="isrc_'+t.id+'" value="'+esc(t.isrc||'')+'"></td>'
                + '<td>'+audioHtml+'</td></tr>';
        });
        tracksHtml += '</tbody></table></div></div>';
    }

    var audioHtml = '';
    if (r.audio_url) {
        audioHtml = '<div class="form-group"><label class="form-label">Audio Preview (Release)</label><a href="'+esc(r.audio_url)+'" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-play"></i> Listen</a></div>';
    }

    var isRejected = (r.status === 'rejected');
    document.getElementById('modalReleaseBody').innerHTML = '<div class="space-y">'
        + '<div class="flex items-center gap-md">'
        + '<div class="cover-thumb" style="width:72px;height:72px">'+coverHtml+'</div>'
        + '<div><div class="font-bold" style="font-size:1rem">'+esc(r.title)+'</div>'
        + '<div class="text-muted text-sm">'+esc(r.artist)+'</div>'
        + '<div class="text-xs text-muted mt-sm">'+esc(r.author_name||'')+' &lt;'+esc(r.author_email||'')+'&gt;</div>'
        + '<div class="text-xs text-muted">Genres: '+genresHtml+'</div>'
        + '</div></div>'
        + audioHtml
        + '<div class="form-group"><label class="form-label">UPC</label><input id="rUPC" class="form-control" value="'+esc(r.upc||'')+'"></div>'
        + '<div class="form-group"><label class="form-label">Status</label>'
        + '<select id="rStatus" class="form-control" onchange="toggleRejectBox()">'
        + '<option value="pending"'  +(r.status==='pending'  ?' selected':'')+'>Pending</option>'
        + '<option value="approved"' +(r.status==='approved' ?' selected':'')+'>Approved</option>'
        + '<option value="rejected"' +(r.status==='rejected' ?' selected':'')+'>Rejected</option>'
        + '<option value="ozhidaet"' +(r.status==='ozhidaet' ?' selected':'')+'>Waiting</option>'
        + '</select></div>'
        + '<div id="rejectBoxInline" class="rejection-box"'+(isRejected?'':' style="display:none"')+'>'
        + '<label>Rejection Reason — required, sent to artist by email</label>'
        + '<textarea id="rComment" class="form-control" rows="3" placeholder="Reason…">'+esc(r.status_comment||r.statusComment||'')+'</textarea>'
        + '</div>'
        + tracksHtml
        + '</div>';
    openModal('modalRelease');
}

function toggleRejectBox() {
    var s = document.getElementById('rStatus').value;
    var box = document.getElementById('rejectBoxInline');
    if (box) box.style.display = (s==='rejected') ? '' : 'none';
}

async function saveRelease() {
    var status  = document.getElementById('rStatus').value;
    var comment = (document.getElementById('rComment')||{}).value || '';
    var upc     = document.getElementById('rUPC').value.trim();

    if (status === 'rejected') {
        if (!comment.trim()) {
            notify('Please enter a rejection reason.','err'); return;
        }
        pendingReject = { releaseId: currentReleaseId, upc: upc, comment: comment.trim(), fromModal: true };
        doReject();
        return;
    }

    var d2 = await api({action:'listReleases',filter:'all'});
    var r = d2.releases && d2.releases.find(function(x){ return x.id==currentReleaseId; });
    var tracks = (r&&r.tracks||[]).map(function(t){ return {id:t.id, isrc: (document.getElementById('isrc_'+t.id)||{}).value||''}; });

    var res = await api({action:'updateReleaseStatus', release_id:currentReleaseId, status:status, upc:upc, status_comment:'', tracks:tracks});
    if (res.success) { notify('Release updated.','ok'); closeModal('modalRelease'); loadReleases(); }
    else notify(res.error||'Error','err');
}

async function quickStatus(id, status) {
    if (status==='rejected') { startReject(id,''); return; }
    var res = await api({action:'updateReleaseStatus', release_id:id, status:status, upc:'', status_comment:'', tracks:[]});
    if (res.success) { notify('Status: '+status,'ok'); loadReleases(); }
    else notify(res.error||'Error','err');
}

function startReject(releaseId, upc) {
    pendingReject = { releaseId: releaseId, upc: upc||'', comment: null, fromModal: false };
    document.getElementById('rejectReason').value = '';
    openModal('modalReject');
}

async function doReject() {
    if (!pendingReject) return;
    var reason = '';
    if (pendingReject.fromModal && pendingReject.comment) {
        reason = pendingReject.comment;
    } else {
        reason = document.getElementById('rejectReason').value.trim();
        if (!reason) { notify('Please enter a rejection reason.','err'); return; }
    }

    var tracks = [];
    if (pendingReject.fromModal) {
        var d2 = await api({action:'listReleases',filter:'all'});
        var r = d2.releases && d2.releases.find(function(x){ return x.id==pendingReject.releaseId; });
        if (r) tracks = (r.tracks||[]).map(function(t){ return {id:t.id, isrc:(document.getElementById('isrc_'+t.id)||{}).value||''}; });
    }

    var res = await api({action:'updateReleaseStatus', release_id:pendingReject.releaseId, status:'rejected', upc:pendingReject.upc, status_comment:reason, tracks:tracks});
    if (res.success) {
        notify('Release rejected. Email sent to artist.','ok');
        closeModal('modalReject');
        closeModal('modalRelease');
        loadReleases();
    } else {
        notify(res.error||'Error','err');
    }
    pendingReject = null;
}

// ════════════════════════════════════════════════════════════════════
// TICKETS
// ════════════════════════════════════════════════════════════════════
async function loadTickets(filter, btn) {
    if (btn) {
        document.querySelectorAll('#tabTickets .ftab').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
    }
    var body = document.getElementById('ticketsBody');
    body.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    var d = await api({action:'listTickets', filter:filter||'all'});
    if (!d.success||!d.tickets.length) { body.innerHTML='<div class="empty"><i class="fas fa-ticket-alt"></i><br>No tickets.</div>'; return; }
    var html = '<table><thead><tr><th>ID</th><th>Subject</th><th>User</th><th>Priority</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    d.tickets.forEach(function(t) {
        var pc = {high:'badge-rejected',medium:'badge-pending',low:'badge-approved'};
        html += '<tr><td>#'+t.id+'</td><td>'+esc(t.subject)+'</td>';
        html += '<td class="text-xs">'+esc(t.user_name||'')+'<br><span class="text-muted">'+esc(t.user_email||'')+'</span></td>';
        html += '<td><span class="badge '+(pc[t.priority]||'')+'">'+esc(t.priority)+'</span></td>';
        html += '<td>'+sbadge(t.status)+'</td>';
        html += '<td class="text-xs">'+fmt(t.created_at)+'</td>';
        html += '<td><div class="actions"><button class="btn btn-ghost btn-sm" onclick="openTicket('+t.id+')"><i class="fas fa-eye"></i></button>';
        if (t.status==='open') html += '<button class="btn btn-warning btn-sm" onclick="closeTicketA('+t.id+')">Close</button>';
        else                   html += '<button class="btn btn-success btn-sm" onclick="reopenTicketA('+t.id+')">Reopen</button>';
        html += '</div></td></tr>';
    });
    body.innerHTML = html + '</tbody></table>';
}

async function openTicket(id) {
    currentTicketId = id;
    var d = await api({action:'listTickets',filter:'all'});
    var t = d.tickets && d.tickets.find(function(x){ return x.id==id; });
    if (!t) return;
    document.getElementById('modalTicketTitle').textContent = 'Ticket #'+id+': '+t.subject;
    var resp = (t.responses||[]).map(function(r){
        return '<div style="background:var(--surface2);border-radius:8px;padding:0.75rem;border-left:3px solid '+(r.is_admin?'var(--accent)':'var(--border)')+';">'
            + '<div class="text-xs text-muted" style="margin-bottom:0.35rem">'+(r.is_admin?'🛡 Support':'👤 User')+' · '+fmt(r.created_at)+'</div>'
            + esc(r.message)+'</div>';
    }).join('');
    var replyBox = t.status!=='closed' ? '<div class="form-group mt-md"><label class="form-label">Reply</label><textarea id="adminReply" class="form-control" rows="3" placeholder="Your reply…"></textarea></div>'
        + '<div class="actions mt-sm"><button class="btn btn-primary" onclick="sendReply()"><i class="fas fa-paper-plane"></i> Send</button>'
        + '<button class="btn btn-warning" onclick="closeTicketA('+t.id+')">Close Ticket</button></div>'
        : '<p class="text-muted text-sm mt-md">This ticket is closed.</p>';
    document.getElementById('modalTicketBody').innerHTML = '<div class="space-y">'
        + '<div><strong>From:</strong> '+esc(t.user_name)+' &lt;'+esc(t.user_email)+'&gt;</div>'
        + '<div><strong>Priority:</strong> '+esc(t.priority)+' &nbsp; <strong>Status:</strong> '+t.status+'</div>'
        + '<div style="background:var(--surface2);border-radius:8px;padding:0.75rem">'+esc(t.message)+'</div>'
        + (resp ? '<div>'+resp+'</div>' : '')
        + replyBox + '</div>';
    openModal('modalTicket');
}

async function sendReply() {
    var msg = (document.getElementById('adminReply')||{}).value;
    if (!msg||!msg.trim()) { notify('Message is empty','err'); return; }
    var res = await api({action:'replyTicket', ticket_id:currentTicketId, message:msg.trim()});
    if (res.success) { notify('Reply sent','ok'); closeModal('modalTicket'); loadTickets(); }
    else notify(res.error||'Error','err');
}

async function closeTicketA(id) {
    var res = await api({action:'closeTicket', ticket_id:id});
    if (res.success) { notify('Ticket closed','ok'); closeModal('modalTicket'); loadTickets(); }
    else notify(res.error||'Error','err');
}

async function reopenTicketA(id) {
    var res = await api({action:'reopenTicket', ticket_id:id});
    if (res.success) { notify('Ticket reopened','ok'); loadTickets(); }
    else notify(res.error||'Error','err');
}

// ════════════════════════════════════════════════════════════════════
// USERS
// ════════════════════════════════════════════════════════════════════
async function loadUsers() {
    var body = document.getElementById('usersBody');
    body.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    var d = await api({action:'listUsers'});
    if (!d.success||!d.users.length) { body.innerHTML='<div class="empty"><i class="fas fa-users"></i><br>No users.</div>'; return; }
    var html = '<table><thead><tr><th>ID</th><th>Name / Email</th><th>Role</th><th>Status</th><th>Balance</th><th>Plan</th><th>Registered</th><th>Actions</th></tr></thead><tbody>';
    d.users.forEach(function(u) {
        var plan = u.tariff_id==1?'Pro':u.tariff_id==35?'Premium':'Standard';
        var statusBadge = u.banned ? '<span class="badge badge-banned">Banned</span>' : '<span class="badge badge-active">Active</span>';
        html += '<tr><td>#'+u.id+'</td>';
        html += '<td><div class="font-bold">'+esc(u.nickname)+'</div><div class="text-xs text-muted">'+esc(u.email)+'</div></td>';
        html += '<td><span class="badge '+(u.role==='admin'?'badge-admin':'badge-user')+'">'+esc(u.role)+'</span></td>';
        html += '<td>'+statusBadge+'</td>';
        html += '<td>₽'+parseFloat(u.balance||0).toFixed(2)+'</td>';
        html += '<td class="text-xs">'+plan+(u.tariff_until?'<br><span class="text-muted">until '+fmt(u.tariff_until)+'</span>':'')+'</td>';
        html += '<td class="text-xs">'+fmt(u.created_at)+'</td>';
        html += '<td><div class="actions">'
            + '<button class="btn btn-ghost btn-sm" onclick="editUser('+u.id+')"><i class="fas fa-edit"></i></button>'
            + (u.banned ? '<button class="btn btn-success btn-sm" onclick="toggleBan('+u.id+',0)"><i class="fas fa-check"></i> Unban</button>'
                         : '<button class="btn btn-danger btn-sm" onclick="toggleBan('+u.id+',1)"><i class="fas fa-ban"></i> Ban</button>')
            + '<button class="btn btn-danger btn-sm" onclick="deleteUserA('+u.id+',\''+esc(u.email)+'\')"><i class="fas fa-trash"></i></button>'
            + '</div></td></tr>';
    });
    body.innerHTML = html + '</tbody></table>';
}

async function toggleBan(id, banned) {
    var d = await api({action:'listUsers'});
    var u = d.users && d.users.find(function(x){ return x.id==id; });
    if (!u) return;
    var payload = {
        action:'updateUser', id:id,
        email: u.email,
        nickname: u.nickname,
        role: u.role,
        banned: banned,
        balance: u.balance,
        tariff_id: u.tariff_id,
        tariff_until: u.tariff_until,
        vk: u.vk||'',
        telegram: u.telegram||'',
        password: ''
    };
    var res = await api(payload);
    if (res.success) {
        notify(banned ? 'User banned' : 'User unbanned','ok');
        loadUsers();
    } else {
        notify(res.error||'Error','err');
    }
}

async function editUser(id) {
    currentUserId = id;
    var d = await api({action:'listUsers'});
    var u = d.users && d.users.find(function(x){ return x.id==id; });
    if (!u) return;
    document.getElementById('modalUserTitle').textContent = 'Edit User #'+u.id;
    var until = u.tariff_until ? u.tariff_until.split(' ')[0] : '';
    document.getElementById('modalUserBody').innerHTML = '<div class="space-y">'
        + '<div class="form-group"><label class="form-label">Email *</label><input id="uEmail" class="form-control" value="'+esc(u.email)+'"></div>'
        + '<div class="form-group"><label class="form-label">Artist Name *</label><input id="uNick" class="form-control" value="'+esc(u.nickname)+'"></div>'
        + '<div class="form-group"><label class="form-label">New Password (blank = keep)</label><input type="password" id="uPass" class="form-control"></div>'
        + '<div class="form-group"><label class="form-label">Role</label><select id="uRole" class="form-control"><option value="user"'+(u.role==='user'?' selected':'')+'>User</option><option value="admin"'+(u.role==='admin'?' selected':'')+'>Administrator</option></select></div>'
        + '<div class="form-group"><label class="form-label">Banned</label><select id="uBanned" class="form-control"><option value="0"'+(u.banned==0?' selected':'')+'>No</option><option value="1"'+(u.banned==1?' selected':'')+'>Yes</option></select></div>'
        + '<div class="form-group"><label class="form-label">Balance (₽)</label><input type="number" id="uBal" class="form-control" value="'+parseFloat(u.balance||0).toFixed(2)+'" step="0.01"></div>'
        + '<div class="form-group"><label class="form-label">Plan ID (1=Pro, 35=Premium, 0=none)</label><input type="number" id="uTariff" class="form-control" value="'+(u.tariff_id||0)+'"></div>'
        + '<div class="form-group"><label class="form-label">Plan Expiry</label><input type="date" id="uUntil" class="form-control" value="'+until+'"></div>'
        + '<div class="form-group"><label class="form-label">VK</label><input id="uVK" class="form-control" value="'+esc(u.vk||'')+'"></div>'
        + '<div class="form-group"><label class="form-label">Telegram</label><input id="uTg" class="form-control" value="'+esc(u.telegram||'')+'"></div>'
        + '</div>';
    openModal('modalUser');
}

async function saveUser() {
    var payload = {
        action:'updateUser', id:currentUserId,
        email:    document.getElementById('uEmail').value.trim(),
        nickname: document.getElementById('uNick').value.trim(),
        password: document.getElementById('uPass').value,
        role:     document.getElementById('uRole').value,
        banned:   parseInt(document.getElementById('uBanned').value)||0,
        balance:  parseFloat(document.getElementById('uBal').value)||0,
        tariff_id:    parseInt(document.getElementById('uTariff').value)||null,
        tariff_until: document.getElementById('uUntil').value||null,
        vk:       document.getElementById('uVK').value.trim(),
        telegram: document.getElementById('uTg').value.trim()
    };
    if (!payload.email||!payload.nickname) { notify('Email and name required','err'); return; }
    var res = await api(payload);
    if (res.success) { notify('User saved','ok'); closeModal('modalUser'); loadUsers(); }
    else notify(res.error||'Error','err');
}

async function deleteUserA(id, email) {
    if (!confirm('Delete user '+email+'?\n\nThis cannot be undone.')) return;
    var res = await api({action:'deleteUser', id:id});
    if (res.success) { notify('User deleted','ok'); loadUsers(); }
    else notify(res.error||'Error','err');
}

// ════════════════════════════════════════════════════════════════════
// NOTIFICATIONS / NEWS
// ════════════════════════════════════════════════════════════════════
async function loadNotifications() {
    var body = document.getElementById('notificationsBody');
    body.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    var d = await api({action:'listNotifications'});
    if (!d.success) { body.innerHTML = '<div class="empty">Failed to load.</div>'; return; }
    if (!d.notifications.length) {
        body.innerHTML = '<div class="empty"><i class="fas fa-bell"></i><br>No news/notifications yet.</div>';
        return;
    }
    var html = '<table><thead><tr><th>ID</th><th>Emoji</th><th>Title</th><th>Message</th><th>Тип</th><th>Status</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
    d.notifications.forEach(function(n) {
        var statusBadge = n.is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Inactive</span>';
        var typeBadge = (n.show_as_popup == null || n.show_as_popup == 1)
            ? '<span class="badge badge-pending" title="Показывается как попап при входе и в новостях">📢 Попап</span>'
            : '<span class="badge badge-approved" title="Только в разделе Новости">📰 Только новость</span>';
        var imgHtml = n.image_url ? '<a href="'+esc(n.image_url)+'" target="_blank"><i class="fas fa-image"></i></a>' : '—';
        var viewsHtml = '<span onclick="showNotificationViews('+n.id+')" style="cursor:pointer;color:var(--accent);">'+n.views_count+' view'+(n.views_count!=1?'s':'')+'</span>';
        var title = n.title && n.title.trim() ? esc(n.title) : '<span class="text-muted">(нет заголовка)</span>';
        var msgShort = n.message.length > 50 ? esc(n.message.substring(0,50))+'…' : esc(n.message);
        html += '<tr>'
            + '<td>#'+n.id+'</td>'
            + '<td style="font-size:1.5rem;">'+esc(n.emoji||'')+'</td>'
            + '<td>'+title+'</td>'
            + '<td style="max-width:180px;word-break:break-word;font-size:0.78rem;">'+msgShort+'</td>'
            + '<td>'+typeBadge+'</td>'
            + '<td>'+statusBadge+'</td>'
            + '<td>'+viewsHtml+'</td>'
            + '<td class="text-xs">'+fmt(n.created_at)+'</td>'
            + '<td><div class="actions">'
            + '<button class="btn btn-ghost btn-sm" title="Редактировать" onclick="editNotification('+n.id+')"><i class="fas fa-edit"></i></button>'
            + '<button class="btn btn-ghost btn-sm" title="Вкл/Выкл" onclick="toggleNotification('+n.id+')"><i class="fas fa-power-off"></i></button>'
            + '<button class="btn btn-danger btn-sm" title="Удалить" onclick="deleteNotification('+n.id+')"><i class="fas fa-trash"></i></button>'
            + '</div></td>'
            + '</tr>';
    });
    html += '</tbody></table>';
    body.innerHTML = html;
}

function showCreateNotification() {
    notifEditId = 0;
    document.getElementById('modalNotifTitle').textContent = 'New News & Notification';
    document.getElementById('notifTitle').value = '';
    document.getElementById('notifEmoji').value = '';
    document.getElementById('notifMessage').value = '';
    document.getElementById('notifImage').value = '';
    document.getElementById('notifImagePreview').innerHTML = '';
    document.getElementById('notifImageUrl').value = '';
    document.getElementById('notifShowAsPopup').checked = true;
    document.querySelectorAll('.emoji-picker span').forEach(function(el){ el.classList.remove('selected'); });
    document.getElementById('notifSaveBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Send to All';
    openModal('modalNotification');
}

async function editNotification(id) {
    var d = await api({action:'listNotifications'});
    if (!d.success || !d.notifications) { notify('Ошибка загрузки','err'); return; }
    var n = d.notifications.find(function(x){ return x.id == id; });
    if (!n) { notify('Не найдено','err'); return; }

    notifEditId = id;
    document.getElementById('modalNotifTitle').textContent = 'Редактировать #' + id;
    document.getElementById('notifTitle').value   = n.title   || '';
    document.getElementById('notifEmoji').value   = n.emoji   || '';
    document.getElementById('notifMessage').value = n.message || '';
    document.getElementById('notifImage').value   = '';
    document.getElementById('notifImageUrl').value = n.image_url || '';
    document.getElementById('notifShowAsPopup').checked = (n.show_as_popup == null || n.show_as_popup == 1);

    document.getElementById('notifImagePreview').innerHTML = n.image_url
        ? '<img src="'+esc(n.image_url)+'" style="max-width:200px;max-height:150px;border-radius:6px;border:1px solid var(--border);">'
        : '';

    document.querySelectorAll('.emoji-picker span').forEach(function(el){
        el.classList.toggle('selected', el.textContent === (n.emoji || ''));
    });

    document.getElementById('notifSaveBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить изменения';
    openModal('modalNotification');
}

function pickEmoji(emoji) {
    document.getElementById('notifEmoji').value = emoji;
    document.querySelectorAll('.emoji-picker span').forEach(function(el) {
        el.classList.toggle('selected', el.textContent === emoji);
    });
}

async function uploadNotifImage(input) {
    var file = input.files[0];
    if (!file) return;
    var formData = new FormData();
    formData.append('action', 'uploadNotificationImage');
    formData.append('file', file);
    console.log('[UPLOAD] Starting image upload...');
    var r = await fetch('?api=1', { method: 'POST', body: formData });
    var data = await r.json();
    console.log('[UPLOAD RESPONSE]', data);
    if (data.success) {
        document.getElementById('notifImageUrl').value = data.url;
        document.getElementById('notifImagePreview').innerHTML = '<img src="'+data.url+'" style="max-width:200px;max-height:150px;border-radius:6px;border:1px solid var(--border);">';
        notify('Image uploaded','ok');
    } else {
        notify(data.error||'Upload failed','err');
    }
}

async function saveNotification() {
    var title       = document.getElementById('notifTitle').value.trim();
    var emoji       = document.getElementById('notifEmoji').value.trim();
    var message     = document.getElementById('notifMessage').value.trim();
    var imageUrl    = document.getElementById('notifImageUrl').value.trim();
    var showAsPopup = document.getElementById('notifShowAsPopup').checked ? 1 : 0;

    if (!message) { notify('Message is required','err'); return; }

    if (notifEditId > 0) {
        // ── РЕДАКТИРОВАНИЕ ──────────────────────────────────────────
        var res = await api({
            action: 'updateNotification',
            id: notifEditId,
            title: title,
            message: message,
            emoji: emoji,
            image_url: imageUrl,
            show_as_popup: showAsPopup
        });
        if (res && res.success) {
            notify('✅ Новость обновлена','ok');
            closeModal('modalNotification');
            setTimeout(loadNotifications, 300);
        } else {
            notify((res && res.error) || 'Ошибка','err');
        }
    } else {
        // ── СОЗДАНИЕ ────────────────────────────────────────────────
        var res = await api({
            action: 'createNotification',
            title: title,
            message: message,
            emoji: emoji || '',
            image_url: imageUrl || '',
            show_as_popup: showAsPopup
        });
        if (res && res.success) {
            notify('✅ News sent to all users!','ok');
            closeModal('modalNotification');
            setTimeout(loadNotifications, 500);
        } else {
            notify((res && res.error) || 'Unknown error','err');
        }
    }
}

async function toggleNotification(id) {
    var res = await api({action:'toggleNotification', id:id});
    if (res.success) { notify('Toggled','ok'); loadNotifications(); }
    else notify(res.error||'Error','err');
}

async function deleteNotification(id) {
    if (!confirm('Delete this news/notification and all view logs?')) return;
    var res = await api({action:'deleteNotification', id:id});
    if (res.success) { notify('Deleted','ok'); loadNotifications(); }
    else notify(res.error||'Error','err');
}

async function showNotificationViews(id) {
    var d = await api({action:'listNotifications'});
    var n = d.notifications && d.notifications.find(function(x){ return x.id==id; });
    if (!n) return;
    document.getElementById('notifDetailTitle').textContent = 'Views for Notification #'+id;
    var html = '<p><strong>Message:</strong> '+esc(n.message)+'</p><p><strong>Total views:</strong> '+n.views_count+'</p>';
    if (n.viewers && n.viewers.length) {
        html += '<table style="width:100%;font-size:0.8rem;"><thead><tr><th>User</th><th>Email</th><th>Viewed At</th></tr></thead><tbody>';
        n.viewers.forEach(function(v) {
            html += '<tr><td>'+esc(v.nickname||'')+'</td><td>'+esc(v.email)+'</td><td class="text-xs">'+fmt(v.viewed_at)+'</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        html += '<p class="text-muted">No one has viewed this yet.</p>';
    }
    document.getElementById('notifDetailBody').innerHTML = html;
    openModal('modalNotifDetail');
}

// ════════════════════════════════════════════════════════════════════
// CHILL GAME
// ════════════════════════════════════════════════════════════════════
var chillInitialized = false;
var fishCount = 0;
var missCount = 0;
var fishing = false;
var fish = [];
var canvas, ctx;
var waterOffset = 0;
var animationFrame;
var castX, castY;

function initChillGame() {
    if (chillInitialized) return;
    canvas = document.getElementById('chillCanvas');
    if (!canvas) return;
    ctx = canvas.getContext('2d');
    for (var i = 0; i < 8; i++) {
        fish.push({
            x: Math.random() * 550 + 25,
            y: Math.random() * 280 + 60,
            size: 15 + Math.random() * 20,
            speed: 0.5 + Math.random() * 1.5,
            angle: Math.random() * Math.PI * 2,
            color: ['#facc15','#f87171','#34d399','#60a5fa','#a78bfa'][Math.floor(Math.random()*5)]
        });
    }
    canvas.addEventListener('click', onCastLine);
    chillInitialized = true;
    updateStats();
    animate();
}

function animate() {
    if (!chillInitialized) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawWater();
    drawFish();
    if (fishing) {
        drawFishingLine();
    }
    animationFrame = requestAnimationFrame(animate);
}

function drawWater() {
    var gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, '#1a5276');
    gradient.addColorStop(0.6, '#1f6f8b');
    gradient.addColorStop(1, '#2e86ab');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.beginPath();
    ctx.moveTo(0, canvas.height * 0.35);
    for (var x = 0; x <= canvas.width; x += 2) {
        var y = canvas.height * 0.35 + Math.sin(x * 0.02 + waterOffset) * 6 + Math.sin(x * 0.04 + waterOffset * 1.3) * 4;
        ctx.lineTo(x, y);
    }
    ctx.lineTo(canvas.width, canvas.height);
    ctx.lineTo(0, canvas.height);
    ctx.closePath();
    ctx.fillStyle = 'rgba(13, 71, 105, 0.45)';
    ctx.fill();
    for (var i = 0; i < 3; i++) {
        var bx = (Math.sin(waterOffset * 0.5 + i * 2) * 0.5 + 0.5) * canvas.width;
        var by = canvas.height * 0.3 + i * 25;
        ctx.beginPath();
        ctx.arc(bx, by, 30 + i * 8, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.04)';
        ctx.fill();
    }
    waterOffset += 0.02;
}

function drawFish() {
    fish.forEach(function(f) {
        f.x += Math.cos(f.angle) * f.speed;
        f.y += Math.sin(f.angle) * f.speed * 0.6;
        if (f.x < 20 || f.x > canvas.width - 20) f.angle = Math.PI - f.angle;
        if (f.y < 40 || f.y > canvas.height - 30) f.angle = -f.angle;
        if (Math.random() < 0.003) f.angle += (Math.random() - 0.5) * 0.8;
        ctx.save();
        ctx.translate(f.x, f.y);
        ctx.rotate(f.angle);
        ctx.beginPath();
        ctx.ellipse(0, 0, f.size * 0.7, f.size * 0.4, 0, 0, Math.PI * 2);
        ctx.fillStyle = f.color;
        ctx.fill();
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(f.size * 0.3, -3, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.beginPath();
        ctx.arc(f.size * 0.35, -3, 1.5, 0, Math.PI * 2);
        ctx.fillStyle = '#000';
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(-f.size * 0.6, 0);
        ctx.lineTo(-f.size * 0.9, -f.size * 0.3);
        ctx.lineTo(-f.size * 0.9, f.size * 0.3);
        ctx.closePath();
        ctx.fillStyle = f.color;
        ctx.fill();
        ctx.restore();
    });
}

function drawFishingLine() {
    ctx.beginPath();
    ctx.moveTo(canvas.width / 2, 0);
    ctx.lineTo(castX, castY);
    ctx.strokeStyle = '#8b5a2b';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(castX, castY, 6, 0, Math.PI * 2);
    ctx.fillStyle = '#f59e0b';
    ctx.fill();
    ctx.strokeStyle = '#b45309';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    for (var r = 0; r < 3; r++) {
        ctx.beginPath();
        ctx.arc(castX, castY, 12 + r * 8 + Math.sin(Date.now() / 300 + r) * 4, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(255,255,255,0.2)';
        ctx.lineWidth = 1;
        ctx.stroke();
    }
}

function onCastLine(e) {
    if (fishing) return;
    var rect = canvas.getBoundingClientRect();
    var scaleX = canvas.width / rect.width;
    var scaleY = canvas.height / rect.height;
    castX = (e.clientX - rect.left) * scaleX;
    castY = (e.clientY - rect.top) * scaleY;
    if (castY < 40) return;
    fishing = true;
    setTimeout(function() {
        var caught = Math.random() < 0.45;
        if (caught) {
            fishCount++;
            showCatchEffect(castX, castY);
            fish.push({
                x: Math.random() * 550 + 25,
                y: Math.random() * 280 + 60,
                size: 15 + Math.random() * 20,
                speed: 0.5 + Math.random() * 1.5,
                angle: Math.random() * Math.PI * 2,
                color: ['#facc15','#f87171','#34d399','#60a5fa','#a78bfa'][Math.floor(Math.random()*5)]
            });
        } else {
            missCount++;
        }
        fishing = false;
        updateStats();
        notify(caught ? '🎣 Fish caught!' : '😕 Nothing...', caught ? 'ok' : 'err');
    }, 600 + Math.random() * 800);
}

function showCatchEffect(x, y) {
    for (var i = 0; i < 10; i++) {
        setTimeout(function() {
            ctx.beginPath();
            ctx.arc(x + (Math.random()-0.5)*40, y + (Math.random()-0.5)*40, 3 + Math.random()*8, 0, Math.PI*2);
            ctx.fillStyle = ['#fbbf24','#fcd34d','#f59e0b'][Math.floor(Math.random()*3)];
            ctx.fill();
        }, i * 80);
    }
}

function updateStats() {
    document.getElementById('fishCount').textContent = fishCount;
    document.getElementById('missCount').textContent = missCount;
}

// ════════════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════════════
console.log('[INIT] Loading initial releases...');
loadReleases('all');
</script>

<?php endif; ?>
</body>
</html>