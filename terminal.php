<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus wallet — Терминал</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 80px}
.header{max-width:680px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:24px 0 26px}
.hbrand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back-btn{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.back-btn:hover{border-color:#555;color:#ccc}
.inner{max-width:680px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
.ptitle{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.3em;color:#444}
.sec{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:14px}
.sec-title{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.28em;color:#444;text-transform:uppercase}
.cflbl{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#333;text-transform:uppercase;margin-bottom:5px}
.cinput{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none}
.cinput:focus{border-color:#444}
.csubmit{background:#fff;color:#080808;border:none;border-radius:10px;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.1em;padding:10px 18px;cursor:pointer;transition:background .2s}
.csubmit:hover{background:#e0e0e0}
.flash-msg{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.12em;padding:9px;border-radius:9px}
.flash-ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash-err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.links-grid{display:flex;flex-direction:column;gap:10px}
.link-row{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.link-lbl{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#333;text-transform:uppercase;margin-bottom:4px}
.link-url{font-family:'Space Mono',monospace;font-size:.62rem;color:#555;word-break:break-all}
.link-actions{display:flex;gap:8px;flex-shrink:0}
.lbtn{background:none;border:1px solid #1e1e1e;border-radius:9px;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:6px 11px;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.lbtn:hover{border-color:#555;color:#ccc}
.lbtn.primary{background:#fff;color:#080808;border-color:#fff}
.lbtn.primary:hover{background:#e0e0e0}
.qr-wrap{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
.qr-box{background:#fff;border-radius:12px;padding:10px;flex-shrink:0}
.qr-info{flex:1;min-width:180px}
.qr-org{font-size:1rem;font-weight:500;color:#f0f0f0;margin-bottom:4px}
.qr-sub{font-family:'Space Mono',monospace;font-size:.56rem;color:#444}
.amt-form{display:flex;flex-direction:column;gap:12px}
.amt-row{display:flex;gap:10px;align-items:flex-end}
.amt-row .cinput{flex:1}
.cancel-btn{background:none;border:1px solid #2a0000;border-radius:10px;color:#f87171;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;padding:8px 13px;cursor:pointer;transition:all .2s}
.cancel-btn:hover{background:#1a0000}
.status-bar{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:8px 13px;border-radius:9px;text-align:center}
.st-waiting{color:#888;background:#0a0a0a;border:1px solid #1a1a1a}
.st-pending{color:#facc15;background:#1a1200;border:1px solid #2a1e00}
.st-paid{color:#4ade80;background:#001a08;border:1px solid #002a10}
.st-declined{color:#f87171;background:#1a0000;border:1px solid #2a0000}
</style>
</head>
<body>
<div class="header">
  <div class="hbrand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="admin.php" class="back-btn">← Назад</a>
</div>
<div class="inner">
  <div class="ptitle">ТЕРМИНАЛ</div>

  <!-- Step 1: Create session -->
  <div class="sec" id="stepCreate">
    <div class="sec-title">Создать терминал</div>
    <div>
      <div class="cflbl">Название организации</div>
      <input type="text" id="orgName" class="cinput" placeholder="ООО Ромашка">
    </div>
    <div id="createResult" style="display:none" class="flash-msg"></div>
    <button class="csubmit" onclick="createSession()">Создать терминал</button>
  </div>

  <!-- Step 2: Session created - show links + amount form -->
  <div class="sec" id="stepSession" style="display:none">
    <div class="sec-title">Терминал активен</div>

    <!-- QR + info -->
    <div class="qr-wrap">
      <div class="qr-box"><div id="qrCode"></div></div>
      <div class="qr-info">
        <div class="qr-org" id="sessOrgName"></div>
        <div class="qr-sub">M1plus wallet</div>
        <div class="qr-sub" style="margin-top:8px;color:#222">Клиент наводит камеру для оплаты</div>
      </div>
    </div>

    <!-- Links -->
    <div class="links-grid">
      <div class="link-row">
        <div style="flex:1;min-width:0">
          <div class="link-lbl">Ссылка на кассу (этот экран)</div>
          <div class="link-url" id="cashierLink"></div>
        </div>
        <div class="link-actions">
          <button class="lbtn" onclick="copyLink('cashierLink')">Копировать</button>
        </div>
      </div>
      <div class="link-row">
        <div style="flex:1;min-width:0">
          <div class="link-lbl">Экран для покупателя (на весь экран)</div>
          <div class="link-url" id="displayLink"></div>
        </div>
        <div class="link-actions">
          <button class="lbtn" onclick="copyLink('displayLink')">Копировать</button>
          <a class="lbtn primary" id="displayLinkOpen" href="#" target="_blank">Открыть</a>
        </div>
      </div>
    </div>

    <!-- Status -->
    <div class="status-bar st-waiting" id="statusBar">ОЖИДАНИЕ ЗАПРОСА</div>

    <!-- Amount form -->
    <div class="amt-form" id="amtForm">
      <div class="sec-title" style="margin-top:4px">Запрос оплаты</div>
      <div>
        <div class="cflbl">Сумма (₽)</div>
        <div class="amt-row">
          <input type="number" id="payAmount" class="cinput" placeholder="1000" min="1" step="1">
          <button class="csubmit" onclick="requestPayment()">Выставить</button>
        </div>
      </div>
      <div id="amtResult" style="display:none" class="flash-msg"></div>
    </div>

    <!-- Cancel button (shown when pending) -->
    <button class="cancel-btn" id="cancelBtn" style="display:none" onclick="cancelPayment()">Отменить запрос</button>
  </div>
</div>

<script>
var sessToken = null;
var pollInterval = null;
var currentStatus = 'waiting';
var paidTimer = null;        // таймер для автоматического сброса после оплаты

function createSession() {
  var org = document.getElementById('orgName').value.trim();
  var res = document.getElementById('createResult');
  res.style.display = 'none';
  if (!org) { res.style.display='block'; res.className='flash-msg flash-err'; res.textContent='Введите название'; return; }
  var fd = new FormData();
  fd.append('action','create_session'); fd.append('org_name', org);
  fetch('terminal_api.php', {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok) {
        sessToken = d.token;
        initSession(org, d.token);
        // Очищаем параметр token из URL, чтобы при обновлении страницы не было конфликта
        var url = new URL(window.location.href);
        url.searchParams.delete('token');
        window.history.replaceState({}, document.title, url.toString());
      } else {
        res.style.display='block'; res.className='flash-msg flash-err'; res.textContent = d.error||'Ошибка';
      }
    });
}

function initSession(org, token) {
  // Показываем панель терминала, скрываем создание
  document.getElementById('stepCreate').style.display = 'none';
  document.getElementById('stepSession').style.display = '';
  document.getElementById('sessOrgName').textContent = org;

  // Корректно формируем базовый путь (исправлена ошибка с кавычкой)
  var base = window.location.href.substring(0, window.location.href.lastIndexOf('/')+1);
  var scanUrl = base + 'terminal_scan.php?token=' + token;
  var displayUrl = base + 'terminal_display.php?token=' + token;
  var cashierUrl = window.location.href.split('?')[0] + '?token=' + token;

  document.getElementById('cashierLink').textContent = cashierUrl;
  document.getElementById('displayLink').textContent = displayUrl;
  document.getElementById('displayLinkOpen').href = displayUrl;

  // QR
  var qrDiv = document.getElementById('qrCode');
  qrDiv.innerHTML = '';
  new QRCode(qrDiv, {
    text: scanUrl,
    width: 140,
    height: 140,
    colorDark: '#080808',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });

  // Если уже был интервал, останавливаем
  if(pollInterval) clearInterval(pollInterval);
  startPolling();
}

function requestPayment() {
  var amt = parseFloat(document.getElementById('payAmount').value)||0;
  var res = document.getElementById('amtResult');
  res.style.display = 'none';
  if(amt < 1) { res.style.display='block'; res.className='flash-msg flash-err'; res.textContent='Введите сумму'; return; }
  var fd = new FormData();
  fd.append('action','set_amount'); fd.append('token', sessToken); fd.append('amount', amt);
  fetch('terminal_api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    if(d.ok) { res.style.display='none'; }
    else { res.style.display='block'; res.className='flash-msg flash-err'; res.textContent=d.error||'Ошибка'; }
  });
}

function cancelPayment() {
  var fd = new FormData();
  fd.append('action','cancel'); fd.append('token', sessToken);
  fetch('terminal_api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    if(d.ok) { updateStatusUI('waiting'); document.getElementById('payAmount').value=''; }
  });
}

function startPolling() {
  pollInterval = setInterval(pollStatus, 1000);
}

function pollStatus() {
  if(!sessToken) return;
  fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(sessToken))
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok) updateStatusUI(d.session.status, d.session.amount);
    });
}

function updateStatusUI(status, amount) {
  if(status === currentStatus && amount === lastAmount) return;
  currentStatus = status;
  lastAmount = amount;

  var bar = document.getElementById('statusBar');
  var cancelBtn = document.getElementById('cancelBtn');
  var amtForm = document.getElementById('amtForm');
  var labels = {waiting:'ОЖИДАНИЕ ЗАПРОСА', pending:'ОЖИДАНИЕ ОПЛАТЫ — '+formatAmt(amount)+' ₽', paid:'ОПЛАЧЕНО ✓', declined:'ОТКЛОНЕНО'};
  bar.textContent = labels[status] || status;
  bar.className = 'status-bar st-'+(status==='pending'?'pending':status==='paid'?'paid':status==='declined'?'declined':'waiting');
  cancelBtn.style.display = status==='pending' ? '' : 'none';
  amtForm.style.display = (status==='paid') ? 'none' : '';

  // Если статус "paid" – запускаем сброс через 3 секунды (отменяем на сервере)
  if(status === 'paid' && !paidTimer) {
    paidTimer = setTimeout(function() {
      autoResetAfterPaid();
    }, 3000);
  }
  // Если статус ушёл из paid (например, вручную отменили), сбрасываем таймер
  if(status !== 'paid' && paidTimer) {
    clearTimeout(paidTimer);
    paidTimer = null;
  }
}

var lastAmount = null;
function formatAmt(a) { return a ? parseFloat(a).toLocaleString('ru-RU') : ''; }

function autoResetAfterPaid() {
  paidTimer = null;
  // Отправляем cancel, чтобы сервер перевёл сессию в waiting
  var fd = new FormData();
  fd.append('action','cancel'); fd.append('token', sessToken);
  fetch('terminal_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok) {
        // Локально тоже сбросим интерфейс
        updateStatusUI('waiting');
        document.getElementById('payAmount').value = '';
      } else {
        // Если cancel не удался (например, сессия уже удалена), просто перезагрузим форму
        updateStatusUI('waiting');
        document.getElementById('payAmount').value = '';
      }
    });
}

function copyLink(id) {
  var txt = document.getElementById(id).textContent;
  navigator.clipboard && navigator.clipboard.writeText(txt);
}

// Восстановление сессии из URL (если есть параметр token)
(function(){
  var params = new URLSearchParams(window.location.search);
  var t = params.get('token');
  if(t) {
    sessToken = t;
    fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(t))
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.ok && d.session) {
          // Сразу показываем активную сессию, скрываем форму создания
          document.getElementById('stepCreate').style.display = 'none';
          document.getElementById('stepSession').style.display = '';
          initSession(d.session.org_name, t);
          updateStatusUI(d.session.status, d.session.amount);
        } else {
          // Если сессия не найдена или истекла, переключаем на создание
          document.getElementById('stepCreate').style.display = '';
          document.getElementById('stepSession').style.display = 'none';
        }
      });
  }
})();
</script>
</body>
</html>