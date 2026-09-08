<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid = (int)$_SESSION['user_id'];
$oid = (int)($_GET['id'] ?? 0);
$db  = getDB();

$s = $db->prepare(
    'SELECT o.*, t.name AS template_name, t.cover_image, p.preview_image
     FROM card_orders o
     LEFT JOIN delivery_card_templates t ON t.id=o.card_template_id
     LEFT JOIN card_previews p ON p.card_order_id=o.id
     WHERE o.id=? AND o.user_id=?'
);
$s->execute([$oid, $uid]);
$order = $s->fetch();
if (!$order) { header('Location: dashboard.php'); exit; }

$statuses = [
    'pending'       => ['ru' => 'В ожидании', 'i' => '⏳'],
    'confirmed'     => ['ru' => 'Подтверждён', 'i' => '✅'],
    'in_production' => ['ru' => 'В производстве', 'i' => '🔄'],
    'shipped'       => ['ru' => 'Отправлен', 'i' => '📦'],
    'delivered'     => ['ru' => 'Доставлен', 'i' => '🎉'],
    'cancelled'     => ['ru' => 'Отменён', 'i' => '❌'],
];
$st = $statuses[$order['status']] ?? $statuses['pending'];
$img = $order['preview_image'] ?: $order['cover_image'];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Заказ <?php echo htmlspecialchars($order['confirmation_code']); ?> — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:480px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:480px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.status-badge{display:inline-flex;align-items:center;gap:6px;font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.12em;padding:8px 14px;border-radius:20px;background:#1a1a1a;color:#ccc;margin-bottom:16px}
.card-img{width:100%;border-radius:14px;margin-bottom:16px;background:#1a1a1a;aspect-ratio:16/10;object-fit:cover}
.info-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #161616}
.info-row:last-child{border-bottom:none}
.info-lbl{font-size:.76rem;color:#666}
.info-val{font-family:'Space Mono',monospace;font-size:.74rem;color:#ccc;text-align:right}
.rep-card{background:#0a0a0a;border:1px solid #161616;border-radius:14px;padding:16px;margin-top:14px}
.rep-title{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#444;margin-bottom:10px}
.rep-name{font-size:.88rem;color:#f0f0f0;font-weight:500}
.rep-phone{font-family:'Space Mono',monospace;color:#4ade80;text-decoration:none;font-size:.8rem;margin-top:6px;display:inline-block}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="dashboard.php" class="back">← Назад</a>
</div>
<div class="inner">
  <div class="card">
    <div class="status-badge"><?php echo $st['i']; ?> <?php echo $st['ru']; ?></div>
    <?php if ($img): ?>
    <img src="<?php echo UPLOAD_URL . htmlspecialchars($img); ?>" class="card-img" alt="">
    <?php endif; ?>

    <div class="info-row"><span class="info-lbl">Карта</span><span class="info-val"><?php echo htmlspecialchars($order['template_name']); ?></span></div>
    <div class="info-row"><span class="info-lbl">Код заказа</span><span class="info-val"><?php echo htmlspecialchars($order['confirmation_code']); ?></span></div>
    <div class="info-row"><span class="info-lbl">Адрес</span><span class="info-val"><?php echo htmlspecialchars($order['delivery_address']); ?></span></div>
    <?php if ($order['delivery_date_actual']): ?>
    <div class="info-row"><span class="info-lbl">Дата доставки</span><span class="info-val"><?php echo date('d.m.Y', strtotime($order['delivery_date_actual'])); ?></span></div>
    <?php endif; ?>

    <?php if ($order['representative_name']): ?>
    <div class="rep-card">
      <div class="rep-title">ВАШ ПРЕДСТАВИТЕЛЬ</div>
      <div class="rep-name"><?php echo htmlspecialchars($order['representative_name']); ?></div>
      <a href="tel:<?php echo htmlspecialchars($order['representative_phone']); ?>" class="rep-phone">📞 <?php echo htmlspecialchars($order['representative_phone']); ?></a>
    </div>
    <?php endif; ?>

    <?php
    $wb = $db->prepare('SELECT balance_cached, balance_updated_at FROM wallet_bindings WHERE card_order_id=?');
    $wb->execute([$order['id']]);
    $wallet = $wb->fetch();
    if ($wallet): ?>
    <div class="rep-card">
      <div class="rep-title">БАЛАНС КОШЕЛЬКА КАРТЫ</div>
      <div class="rep-name"><?php echo number_format((float)($wallet['balance_cached'] ?? 0), 2, '.', ' '); ?> ₽</div>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
