<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = trim($_GET['token'] ?? '');
$db    = getDB();
$p     = null;
if ($token) {
    $s = $db->prepare('SELECT mp.*, m.name AS merchant_name FROM merchant_payments mp JOIN merchants m ON m.id=mp.merchant_id WHERE mp.payment_token=?');
    $s->execute([$token]); $p = $s->fetch();
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Оплата выполнена</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:380px;max-width:95vw;background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:32px;display:flex;flex-direction:column;gap:18px}
.brand{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.3em;color:#2a2a2a;text-align:center}
.big{font-size:3rem;text-align:center}
.title{font-family:'Space Mono',monospace;font-size:.72rem;letter-spacing:.26em;color:#4ade80;text-align:center}
.divider{height:1px;background:#161616}
.row{display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid #111;gap:10px}
.row:last-of-type{border-bottom:none}
.rl{font-size:.72rem;color:#555}
.rv{font-family:'Space Mono',monospace;font-size:.72rem;color:#aaa;text-align:right}
.rv.big2{font-size:1.1rem;color:#f0f0f0}
.tid{text-align:center;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.12em;color:#252525}
.btn{display:block;width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:12px;cursor:pointer;text-transform:uppercase;text-decoration:none;text-align:center;transition:background .2s}
.btn:hover{background:#e0e0e0}
</style>
</head>
<body>
<div class="card">
  <div class="brand">M1PLUS WALLET · PAYMENT</div>
  <div class="big">✅</div>
  <div class="title">ОПЛАЧЕНО</div>
  <?php if ($p): ?>
  <div class="divider"></div>
  <div class="row"><span class="rl">Магазин</span><span class="rv"><?php echo htmlspecialchars($p['merchant_name']); ?></span></div>
  <?php if ($p['description']): ?><div class="row"><span class="rl">Описание</span><span class="rv"><?php echo htmlspecialchars($p['description']); ?></span></div><?php endif; ?>
  <?php if ($p['external_id']): ?><div class="row"><span class="rl">Заказ</span><span class="rv">#<?php echo htmlspecialchars($p['external_id']); ?></span></div><?php endif; ?>
  <div class="row"><span class="rl">Сумма</span><span class="rv big2"><?php echo number_format((float)$p['amount'],2,'.',' '); ?> <?php echo htmlspecialchars($p['currency']); ?></span></div>
  <div class="row"><span class="rl">Дата</span><span class="rv"><?php echo $p['paid_at'] ? date('d.m.Y H:i',strtotime($p['paid_at'])) : date('d.m.Y H:i'); ?></span></div>
  <div class="divider"></div>
  <div class="tid">ID: <?php echo htmlspecialchars($p['payment_token']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['user_id'])): ?><a href="dashboard.php" class="btn">На главную</a><?php else: ?><a href="index.php" class="btn">На главную</a><?php endif; ?>
</div>
</body>
</html>
