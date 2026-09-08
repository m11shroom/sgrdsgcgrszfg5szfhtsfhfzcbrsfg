<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = trim($_GET['token'] ?? '');
if (!$token) { header('Location: index.php'); exit; }

$db = getDB();
$s  = $db->prepare('SELECT mp.*, m.name AS merchant_name, m.secret_key FROM merchant_payments mp JOIN merchants m ON m.id=mp.merchant_id WHERE mp.payment_token=?');
$s->execute([$token]); $p = $s->fetch();

if (!$p) { echo '<body style="background:#080808;color:#555;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">Платёж не найден или истёк</body>'; exit; }
if ($p['status'] === 'paid') { echo '<body style="background:#080808;color:#4ade80;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">✅ Этот платёж уже оплачен</body>'; exit; }
if ($p['status'] === 'cancelled') { echo '<body style="background:#080808;color:#f87171;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">❌ Платёж отменён</body>'; exit; }

$isLoggedIn = !empty($_SESSION['user_id']);
$myBalance  = 0;
if ($isLoggedIn) {
    $ba = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $ba->execute([(int)$_SESSION['user_id']]); $br = $ba->fetch();
    $myBalance = (float)($br['balance'] ?? 0);
}

$amount   = (float)$p['amount'];
$merchant = htmlspecialchars($p['merchant_name']);
$desc     = htmlspecialchars($p['description'] ?? '');
$tokenH   = htmlspecialchars($token);

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $merchant; ?> — Оплата</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:400px;max-width:96vw;background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:28px;display:flex;flex-direction:column;gap:16px;box-shadow:0 16px 60px rgba(0,0,0,.7)}
.brand{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.3em;color:#2a2a2a}
.merchant-name{font-size:1.2rem;font-weight:600;color:#f0f0f0;margin-top:2px}
.desc{font-size:.82rem;color:#555;line-height:1.5}
.amount-box{background:#0a0a0a;border:1px solid #1a1a1a;border-radius:14px;padding:16px 18px;text-align:center}
.amt-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.28em;color:#1e1e1e;margin-bottom:5px}
.amt-val{font-family:'Space Mono',monospace;font-size:2.2rem;color:#f0f0f0}
.divider{height:1px;background:#161616}
.sec-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.28em;color:#1e1e1e;text-transform:uppercase}
.pay-btn{width:100%;border:none;border-radius:13px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:500;padding:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;transition:all .2s}
.pay-btn.white{background:#fff;color:#080808}.pay-btn.white:hover{background:#e8e8e8}
.pay-btn.dark{background:#1a1a1a;color:#ccc;border:1px solid #2a2a2a}.pay-btn.dark:hover{background:#222;color:#fff}
.pay-btn.green{background:#2d6a2d;color:#fff}.pay-btn.green:hover{background:#3a7a3a}
.pay-btn:disabled{opacity:.4;cursor:default}
.bal-note{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#2a2a2a;text-align:center}
.bal-ok{color:#4ade80}.bal-err{color:#f87171}
.bank-block{display:none;flex-direction:column;gap:12px}
.bank-block.open{display:flex}
.det-row{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;color:#555;border-bottom:1px solid #161616;padding-bottom:8px;gap:8px}
.det-val{font-family:'Space Mono',monospace;color:#ccc}
.err-msg{font-family:'Space Mono',monospace;font-size:.52rem;color:#f87171;min-height:14px;text-align:center}
.login-note{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;color:#1e1e1e;text-align:center;line-height:1.7}
.login-note a{color:#555;text-decoration:none}.login-note a:hover{color:#aaa}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:10px 12px;border-radius:10px;text-align:center}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.ref-row{display:flex;justify-content:space-between;font-family:'Space Mono',monospace;font-size:.5rem;color:#252525;margin-top:4px}
</style>
</head>
<body>
<div class="card">
  <div>
    <div class="brand">M1PLUS WALLET · PAYMENT</div>
    <div class="merchant-name"><?php echo $merchant; ?></div>
    <?php if ($desc): ?><div class="desc" style="margin-top:6px"><?php echo $desc; ?></div><?php endif; ?>
  </div>

  <div class="amount-box">
    <div class="amt-lbl">СУММА К ОПЛАТЕ</div>
    <div class="amt-val"><?php echo number_format($amount,2,'.',' '); ?> <?php echo htmlspecialchars($p['currency']); ?></div>
  </div>

  <?php if ($p['external_id']): ?>
  <div class="ref-row"><span>Заказ</span><span>#<?php echo htmlspecialchars($p['external_id']); ?></span></div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="flash <?php echo str_starts_with($flash,'ok:')?'ok':'err'; ?>"><?php echo htmlspecialchars(ltrim($flash,'ok:')); ?></div>
  <?php endif; ?>

  <div class="divider"></div>
  <div class="sec-lbl">Способ оплаты</div>

  <!-- YooMoney -->
  <button class="pay-btn white" onclick="payYoo()">💳 Картой / SberPay / ЮMoney</button>

  <div class="divider"></div>

  <?php if ($isLoggedIn): ?>
    <button class="pay-btn dark" onclick="document.getElementById('bb').classList.toggle('open')">🏦 Оплатить с M1plus wallet</button>
    <div class="bank-block" id="bb">
      <div class="det-row">Магазин<span class="det-val"><?php echo $merchant; ?></span></div>
      <div class="det-row">Сумма<span class="det-val"><?php echo number_format($amount,2,'.',' '); ?> ₽</span></div>
      <div class="bal-note">Баланс: <span class="<?php echo $myBalance>=$amount?'bal-ok':'bal-err'; ?>"><?php echo number_format($myBalance,2,'.',' '); ?> ₽</span></div>
      <?php if ($myBalance >= $amount): ?>
        <div class="err-msg" id="bankErr"></div>
        <button class="pay-btn green" onclick="payBank()">✓ Подтвердить</button>
      <?php else: ?>
        <div class="bal-note bal-err">Недостаточно средств. <a href="dashboard.php" style="color:#4ade80">Пополнить</a></div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="login-note">Оплата с M1plus wallet:<br><a href="index.php?redirect=<?php echo urlencode('merchant_pay.php?token='.$token); ?>">войдите в аккаунт</a></div>
  <?php endif; ?>
</div>

<script>
var TOKEN = <?php echo json_encode($token); ?>;

function payYoo(){
  var fd=new FormData(); fd.append('token',TOKEN); fd.append('source','yoomoney');
  fetch('merchant_pay_process.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.url) window.location.href=d.url;
    else alert(d.error||'Ошибка');
  });
}

function payBank(){
  var btn=document.querySelector('.pay-btn.green'); if(btn) btn.disabled=true;
  var fd=new FormData(); fd.append('token',TOKEN); fd.append('source','bank');
  fetch('merchant_pay_process.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok) window.location.href='merchant_success.php?token='+TOKEN;
    else{
      document.getElementById('bankErr').textContent=d.error||'Ошибка';
      if(btn) btn.disabled=false;
    }
  });
}
</script>
</body>
</html>
