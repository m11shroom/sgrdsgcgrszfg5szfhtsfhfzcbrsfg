<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid = (int)$_SESSION['user_id'];
$db  = getDB();

try { $db->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}

$vCheck = $db->prepare('SELECT is_verified FROM users WHERE id=?');
$vCheck->execute([$uid]); $vRow = $vCheck->fetch();
$isVerified = !empty($vRow['is_verified']);

$vStatus = 'none';
try {
    $db->exec("CREATE TABLE IF NOT EXISTS didit_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        session_id VARCHAR(200) NOT NULL DEFAULT '', status VARCHAR(50) DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $sv = $db->prepare("SELECT status FROM didit_verifications WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
    $sv->execute([$uid]); $svRow = $sv->fetch();
    $vStatus = $svRow['status'] ?? 'none';
} catch (\Throwable $e) {}

// Normalise status
$vStatus = strtolower($vStatus);
$inReview = in_array($vStatus, ['in_review','in review','in_progress','in progress','pending_review','under_review']);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Верификация — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{width:400px;max-width:95vw;background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:34px 28px;display:flex;flex-direction:column;gap:18px;text-align:center}
.brand{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.32em;color:#2a2a2a;margin-bottom:6px}
.big{font-size:3.2rem;line-height:1}
.title{font-family:'Space Mono',monospace;font-size:.72rem;letter-spacing:.26em;color:#888}
.desc{font-size:.85rem;color:#555;line-height:1.7}
.desc b{color:#888;font-weight:500}
.eta{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.16em;color:#2a2a2a;background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:12px 16px;line-height:1.8}
.eta span{color:#555}
.btn{display:block;width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:background .2s}
.btn:hover{background:#e0e0e0}
.btn.sec{background:none;border:1px solid #1e1e1e;color:#444;font-family:'Space Mono',monospace;font-size:.6rem}
.btn.sec:hover{border-color:#555;color:#ccc}
.badge{display:inline-block;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.18em;padding:6px 14px;border-radius:20px}
.badge-review{color:#f5a623;background:#1a1000;border:1px solid #2a1800}
.badge-ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.badge-err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.badge-pend{color:#aaa;background:#111;border:1px solid #1e1e1e}
</style>
</head>
<body>
<div class="card">
  <div class="brand">M1PLUS WALLET</div>

<?php if ($isVerified): ?>
  <div class="big">✅</div>
  <div class="title">ВЕРИФИКАЦИЯ ПРОЙДЕНА</div>
  <div class="desc">Ваша личность подтверждена. Раздел платёжных ссылок открыт.</div>
  <a href="create_link.php" class="btn">Создать платёжную ссылку →</a>
  <a href="dashboard.php" class="btn sec">На главную</a>

<?php elseif ($inReview): ?>
  <div class="big">🔍</div>
  <div class="title">НА МОДЕРАЦИИ</div>
  <div class="badge badge-review">ПРОВЕРЯЕТСЯ</div>
  <div class="desc">
    Ваша верификация отправлена на <b>ручную проверку</b>.<br>
    Это происходит когда система не может подтвердить документы автоматически.
  </div>
  <div class="eta">
    Обычное время проверки:<br>
    <span>⏱ До 24 часов в рабочие дни</span><br>
    <span>📩 Результат придёт автоматически</span><br>
    <span>🔄 Эта страница обновится сама</span>
  </div>
  <div class="desc" style="font-size:.76rem">Никаких дополнительных действий от вас не требуется. Просто подождите.</div>
  <a href="dashboard.php" class="btn">На главную</a>

<?php elseif ($vStatus === 'declined'): ?>
  <div class="big">❌</div>
  <div class="title">ОТКЛОНЕНО</div>
  <div class="badge badge-err">ОТКЛОНЕНО</div>
  <div class="desc">Верификация не прошла. Убедитесь что документы читаемы, освещение хорошее, и попробуйте снова.</div>
  <a href="didit_start.php" class="btn">Попробовать снова</a>
  <a href="dashboard.php" class="btn sec">На главную</a>

<?php elseif ($vStatus === 'approved'): ?>
  <?php
    // Webhook might have set status but not updated users table yet - fix it
    $db->prepare('UPDATE users SET is_verified=1 WHERE id=?')->execute([$uid]);
    header('Location: didit_callback.php'); exit;
  ?>

<?php else: ?>
  <div class="big">⏳</div>
  <div class="title">ОЖИДАНИЕ</div>
  <div class="badge badge-pend">В ОБРАБОТКЕ</div>
  <div class="desc">Верификация завершается. Обычно это занимает менее минуты.</div>
  <a href="dashboard.php" class="btn">На главную</a>
<?php endif; ?>

</div>

<?php if ($inReview): ?>
<script>
// Auto-refresh every 30 seconds while in review
setTimeout(function(){ window.location.reload(); }, 30000);
</script>
<?php endif; ?>

</body>
</html>