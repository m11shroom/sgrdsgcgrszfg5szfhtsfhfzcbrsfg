<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['user_id'];
$db  = getDB();
$mid = (int)($_GET['mid'] ?? 0);
$m   = $db->prepare('SELECT * FROM merchants WHERE id=? AND user_id=?');
$m->execute([$mid,$uid]); $m=$m->fetch();
if (!$m) { header('Location: merchants.php'); exit; }
$ps = $db->prepare('SELECT mp.*, u.username AS payer FROM merchant_payments mp LEFT JOIN users u ON u.id=mp.payer_user_id WHERE mp.merchant_id=? ORDER BY mp.created_at DESC LIMIT 100');
$ps->execute([$mid]); $ps=$ps->fetchAll();
$total_paid = array_sum(array_map(fn($p)=>$p['status']==='paid'?(float)$p['amount']:0,$ps));
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Платежи — <?php echo htmlspecialchars($m['name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:800px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:800px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.28em;color:#444}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.sc{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:12px 16px;flex:1;min-width:100px}
.sn{font-family:'Space Mono',monospace;font-size:1.2rem;color:#f0f0f0;margin-bottom:2px}
.sl{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.18em;color:#252525;text-transform:uppercase}
.p-card{background:#111;border:1px solid #1e1e1e;border-radius:13px;padding:14px 16px;display:flex;flex-direction:column;gap:8px}
.p-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.p-desc{font-size:.85rem;font-weight:500;color:#ccc}
.p-amount{font-family:'Space Mono',monospace;font-size:.9rem;color:#f0f0f0}
.p-meta{display:flex;flex-wrap:wrap;gap:12px}
.pm{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525}
.pm span{color:#555}
.p-status{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.14em;padding:3px 8px;border-radius:6px}
.st-paid{color:#4ade80;background:#001a08;border:1px solid #002a10}
.st-pending{color:#aaa;background:#111;border:1px solid #1e1e1e}
.st-cancelled{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.webhook-ok{color:#4ade80;font-family:'Space Mono',monospace;font-size:.46rem}
.webhook-no{color:#555;font-family:'Space Mono',monospace;font-size:.46rem}
.tok{font-family:'Space Mono',monospace;font-size:.48rem;color:#1e1e1e;word-break:break-all}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;color:#1a1a1a;text-align:center;padding:24px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div><?php echo htmlspecialchars($m['name']); ?></div>
  <a href="merchants.php" class="back">← Мерчанты</a>
</div>
<div class="inner">
  <div class="pg-ttl">ПЛАТЕЖИ</div>
  <div class="stats">
    <div class="sc"><div class="sn"><?php echo count($ps); ?></div><div class="sl">Всего</div></div>
    <div class="sc"><div class="sn"><?php echo count(array_filter($ps,fn($p)=>$p['status']==='paid')); ?></div><div class="sl">Оплачено</div></div>
    <div class="sc"><div class="sn"><?php echo number_format($total_paid,0,'.',','); ?> ₽</div><div class="sl">Выручка</div></div>
  </div>
  <?php if (empty($ps)): ?><div class="empty">Платежей ещё нет</div>
  <?php else: foreach ($ps as $p): ?>
  <div class="p-card">
    <div class="p-top">
      <div class="p-desc"><?php echo htmlspecialchars($p['description']); ?><?php if($p['external_id']) echo ' <span style="color:#2a2a2a;font-size:.76rem">#'.htmlspecialchars($p['external_id']).'</span>'; ?></div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="p-status st-<?php echo $p['status']; ?>"><?php echo strtoupper($p['status']); ?></span>
        <span class="p-amount"><?php echo number_format((float)$p['amount'],2,'.',' '); ?> ₽</span>
      </div>
    </div>
    <div class="p-meta">
      <?php if($p['payer']): ?><div class="pm">Плательщик: <span>@<?php echo htmlspecialchars($p['payer']); ?></span></div><?php endif; ?>
      <?php if($p['buyer_email']): ?><div class="pm">Email: <span><?php echo htmlspecialchars($p['buyer_email']); ?></span></div><?php endif; ?>
      <?php if($p['buyer_phone']): ?><div class="pm">Тел: <span><?php echo htmlspecialchars($p['buyer_phone']); ?></span></div><?php endif; ?>
      <?php if($p['payment_method']): ?><div class="pm">Метод: <span><?php echo $p['payment_method']==='bank'?'🏦 Bank':'💳 YooMoney'; ?></span></div><?php endif; ?>
      <div class="pm">Создан: <span><?php echo date('d.m.Y H:i',strtotime($p['created_at'])); ?></span></div>
      <?php if($p['paid_at']): ?><div class="pm">Оплачен: <span><?php echo date('d.m.Y H:i',strtotime($p['paid_at'])); ?></span></div><?php endif; ?>
      <?php if($m['webhook_url']): ?>
        <div class="<?php echo $p['webhook_sent']?'webhook-ok':'webhook-no'; ?>">
          <?php echo $p['webhook_sent']?'✓ Webhook отправлен':'✗ Webhook не отправлен ('.$p['webhook_attempts'].' попыток)'; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="tok"><?php echo htmlspecialchars($p['payment_token']); ?></div>
  </div>
  <?php endforeach; endif; ?>
</div>
</body>
</html>
