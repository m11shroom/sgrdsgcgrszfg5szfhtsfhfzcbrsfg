<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    $_SESSION['flash'] = 'err:Войдите в аккаунт для оплаты';
    header('Location: index.php'); exit;
}

$token = $_GET['token'] ?? '';
$db = getDB();

$sess = null;
if ($token) {
    $stmt = $db->prepare("SELECT * FROM terminal_sessions WHERE token=?");
    $stmt->execute([$token]); $sess = $stmt->fetch();
}

$uid = (int)$_SESSION['user_id'];
$accStmt = $db->prepare("SELECT balance FROM bank_accounts WHERE user_id=?");
$accStmt->execute([$uid]); $acc = $accStmt->fetch();
$balance = $acc ? (float)$acc['balance'] : 0;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus wallet — Оплата</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.header{max-width:420px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:24px 0 26px}
.hbrand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.inner{max-width:420px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.card{background:#111;border:1px solid #1e1e1e;border-radius:20px;padding:24px 20px;display:flex;flex-direction:column;gap:16px}
.org-badge{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.22em;color:#444;text-transform:uppercase}
.org-name{font-size:1.4rem;font-weight:500;color:#f0f0f0}
.bank-name{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;color:#222}
.divider{height:1px;background:#1a1a1a}
.amount-lbl{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.2em;color:#444}
.amount-val{font-family:'Space Mono',monospace;font-size:2.4rem;font-weight:500;color:#f0f0f0}
.amount-cur{font-size:.9rem;color:#888;margin-left:4px}
.balance-note{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.12em;color:#2a2a2a}
.balance-note.low{color:#f87171}
.pay-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:13px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.1em;padding:14px;cursor:pointer;transition:background .2s}
.pay-btn:hover{background:#e0e0e0}
.pay-btn:disabled{background:#1a1a1a;color:#333;cursor:default}
.flash-msg{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.12em;padding:10px;border-radius:10px;text-align:center}
.flash-ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash-err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.waiting-note{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.18em;color:#2a2a2a;text-align:center;padding:20px 0}
.paid-wrap{display:flex;flex-direction:column;align-items:center;gap:14px;padding:10px 0}
.paid-icon{font-size:3.5rem}
.paid-title{font-family:'Space Mono',monospace;font-size:.72rem;letter-spacing:.24em;color:#4ade80}
.back-btn{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#555;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.14em;padding:9px 14px;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;display:block;margin-top:4px}
.back-btn:hover{border-color:#555;color:#ccc}
</style>
</head>
<body>
<div class="header">
  <div class="hbrand"><div class="bdot"></div>M1PLUS WALLET</div>
</div>
<div class="inner">
<?php if (!$sess): ?>
  <div class="card">
    <div class="waiting-note">Терминал не найден</div>
    <a href="dashboard.php" class="back-btn">← На главную</a>
  </div>
<?php else: ?>
  <div class="card" id="mainCard">
    <div>
      <div class="org-badge">Получатель</div>
      <div class="org-name"><?php echo htmlspecialchars($sess['org_name']); ?></div>
      <div class="bank-name">M1PLUS WALLET</div>
    </div>
    <div class="divider"></div>

    <div id="paySection">
      <?php if ($sess['status'] === 'pending' && $sess['amount']): ?>
      <div>
        <div class="amount-lbl">К ОПЛАТЕ</div>
        <div class="amount-val"><?php echo number_format((float)$sess['amount'],2,'.',' '); ?><span class="amount-cur">₽</span></div>
      </div>
      <div class="balance-note <?php echo $balance < (float)$sess['amount'] ? 'low' : ''; ?>">
        Ваш баланс: <?php echo number_format($balance,2,'.',' '); ?> ₽
      </div>
      <div id="payResult" style="display:none" class="flash-msg"></div>
      <button class="pay-btn" id="payBtn" <?php echo $balance < (float)$sess['amount'] ? 'disabled' : ''; ?> onclick="doPay()">
        Оплатить <?php echo number_format((float)$sess['amount'],2,'.',' '); ?> ₽
      </button>
      <?php elseif($sess['status'] === 'paid'): ?>
      <div class="paid-wrap">
        <div class="paid-icon">✌️</div>
        <div class="paid-title">УЖЕ ОПЛАЧЕНО</div>
      </div>
      <?php else: ?>
      <div class="waiting-note" id="waitNote">ОЖИДАНИЕ СУММЫ...</div>
      <?php endif; ?>
    </div>
  </div>
  <a href="dashboard.php" class="back-btn">← На главную</a>
<?php endif; ?>
</div>

<script>
var TOKEN = <?php echo json_encode($token); ?>;
var paid = <?php echo $sess && $sess['status']==='paid' ? 'true' : 'false'; ?>;
var hasPending = <?php echo ($sess && $sess['status']==='pending' && $sess['amount']) ? 'true' : 'false'; ?>;

function doPay() {
  var btn = document.getElementById('payBtn');
  var res = document.getElementById('payResult');
  if(!btn) return;
  btn.disabled = true; btn.textContent = '...';
  var fd = new FormData();
  fd.append('action','pay'); fd.append('token', TOKEN);
  fetch('terminal_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok) {
        paid = true;
        res.style.display='none';
        document.getElementById('paySection').innerHTML =
          '<div class="paid-wrap"><div class="paid-icon">✌️</div><div class="paid-title">УСПЕШНО ОПЛАЧЕНО</div><div style="font-family:Space Mono,monospace;font-size:.7rem;color:#444;margin-top:6px">'+parseFloat(d.amount).toLocaleString('ru-RU')+' ₽</div></div>';
      } else {
        btn.disabled = false;
        res.style.display='block'; res.className='flash-msg flash-err';
        res.textContent = d.error||'Ошибка';
        btn.textContent = 'Оплатить';
      }
    });
}

// Poll for status if waiting
if(!paid && !hasPending && TOKEN) {
  setInterval(function(){
    fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(TOKEN))
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok) return;
        if(d.session.status === 'pending' && d.session.amount && !paid) {
          location.reload();
        }
      });
  }, 1000);
}
</script>
</body>
</html>
