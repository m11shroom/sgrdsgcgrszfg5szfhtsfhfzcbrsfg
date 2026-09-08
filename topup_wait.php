<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$label = $_GET['label'] ?? '';

$st = $db->prepare("SELECT * FROM topups WHERE label=? AND user_id=?");
$st->execute([$label, $uid]);
$topup = $st->fetch();
if (!$topup) { header('Location: dashboard.php'); exit; }

$cfg = ym_getConfig($db);
$wallet = $cfg['wallet'] ?? '';

/* Ссылка оплаты YooMoney quickpay (AC — банковская карта, PC — кошелёк ЮMoney) */
$payUrl = 'https://yoomoney.ru/quickpay/confirm.xml?' . http_build_query([
    'receiver'      => $wallet,
    'quickpay-form' => 'button',
    'paymentType'   => 'AC',
    'sum'           => number_format((float)$topup['amount'], 2, '.', ''),
    'label'         => $label,
    'targets'       => 'Пополнение M1plus wallet',
]);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0a0a12">
<title>M1plus — Оплата</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#15151f;border-radius:22px;padding:26px 22px;max-width:360px;width:100%;display:flex;flex-direction:column;gap:16px;text-align:center}
.title{font-size:1.05rem;font-weight:700}
.amt{font-size:2.2rem;font-weight:700}
.amt span{font-size:1.1rem;color:#8f8fa3}
.sub{font-size:.78rem;color:#8f8fa3;line-height:1.5}
.sub b{color:#4ade80}
.pay-btn{background:#8b5cf6;color:#fff;border:none;border-radius:14px;font-family:inherit;font-size:.94rem;font-weight:500;padding:14px;cursor:pointer;text-decoration:none;display:block}
.pay-btn:active{background:#6d28d9}
.status{display:flex;align-items:center;justify-content:center;gap:9px;font-size:.8rem;color:#8f8fa3;min-height:24px}
.spin{width:15px;height:15px;border:2px solid #2d1f52;border-top-color:#8b5cf6;border-radius:50%;animation:sp 1s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.status.paid{color:#4ade80;font-weight:700;font-size:.95rem}
.cancel{color:#55556b;font-size:.76rem;text-decoration:none}
.paid-emoji{font-size:3rem;display:none}
</style>
</head>
<body>
<div class="card">
  <div class="paid-emoji" id="paidEmoji">✌️</div>
  <div class="title" id="title">Пополнение баланса</div>
  <div class="amt"><?php echo number_format((float)$topup['amount'], 0, '.', ' '); ?> <span>₽</span></div>
  <div class="sub" id="sub">Зачислится: <b><?php echo number_format((float)$topup['credit_amount'], 2, ',', ' '); ?> ₽</b> (комиссия 5%)<br>После оплаты вернись на эту страницу — зачисление произойдёт автоматически.</div>
  <a class="pay-btn" id="payBtn" href="<?php echo htmlspecialchars($payUrl); ?>" target="_blank" rel="noopener">Перейти к оплате</a>
  <div class="status" id="status"><div class="spin"></div><span>Ожидаем оплату — проверяем каждую секунду...</span></div>
  <a class="cancel" href="dashboard.php">← Вернуться без оплаты</a>
</div>

<script>
var LABEL = <?php echo json_encode($label); ?>;
var done = false;

function check(){
  if(done) return;
  fetch('topup_check.php?label='+encodeURIComponent(LABEL))
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok && d.paid){
        done = true;
        clearInterval(timer);
        document.getElementById('paidEmoji').style.display='block';
        document.getElementById('title').textContent='Успешно оплачено';
        document.getElementById('status').className='status paid';
        document.getElementById('status').innerHTML='+ '+(d.credited||0).toLocaleString('ru-RU')+' ₽ зачислено';
        document.getElementById('payBtn').style.display='none';
        document.getElementById('sub').textContent='Возвращаем на главный экран...';
        setTimeout(function(){ location.href='dashboard.php'; }, 2500);
      }
    })
    .catch(function(){});
}
var timer = setInterval(check, 1000);
check();
</script>
</body>
</html>
