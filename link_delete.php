<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid    = (int)$_SESSION['user_id'];
$linkId = (int)($_POST['link_id'] ?? 0);
if ($linkId) {
    getDB()->prepare('UPDATE payment_links SET is_active=0 WHERE id=? AND user_id=?')->execute([$linkId,$uid]);
}
header('Location: create_link.php'); exit;
