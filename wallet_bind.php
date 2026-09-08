<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Кошелёк — M1plus wallet</title>
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
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:14px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
input{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;-webkit-appearance:none}
input:focus{border-color:#383838}
.hint{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;margin-top:4px;line-height:1.6}
.submit-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:12px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.submit-btn:hover{background:#e0e0e0}
.submit-btn:disabled{opacity:.4;cursor:default}
.status-row{display:flex;align-items:center;gap:10px;padding:13px 15px;background:#0a0a0a;border:1px solid #161616;border-radius:11px}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-on{background:#4ade80;box-shadow:0 0 8px rgba(74,222,128,.6)}
.dot-off{background:#444}
.status-txt{font-size:.8rem;color:#ccc}
.info-block{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:13px 15px}
.info-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.18em;color:#2a2a2a;text-transform:uppercase;margin-bottom:4px}
.info-val{font-family:'Space Mono',monospace;font-size:.78rem;color:#ccc}
.balance-val{font-family:'Space Mono',monospace;font-size:1.6rem;color:#f0f0f0}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:11px 13px;border-radius:11px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.danger-btn{background:none;border:1px solid #2a0000;border-radius:11px;color:#a04040;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:10px;cursor:pointer;text-transform:uppercase;transition:all .2s}
.danger-btn:hover{border-color:#c04040;color:#f87171}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="dashboard.php" class="back">← Назад</a>
</div>
<div class="inner">
  <div id="flash-container"></div>

  <div class="card">
    <div class="card-title">СОСТОЯНИЕ КОШЕЛЬКА</div>
    <div class="status-row">
      <div class="dot dot-off" id="statusDot"></div>
      <div class="status-txt" id="statusTxt">Проверяем...</div>
    </div>
    <div id="balanceBlock" style="display:none">
      <div class="info-lbl">БАЛАНС ЮMONEY</div>
      <div class="balance-val" id="balanceVal">0 ₽</div>
      <div class="hint">Обновляется каждые 2 секунды, пока страница открыта</div>
    </div>
    <button class="danger-btn" id="unbindBtn" style="display:none" onclick="unbind()">Отвязать кошелёк</button>
  </div>

  <div class="card" id="formCard">
    <div class="card-title">ПРИВЯЗАТЬ КОШЕЛЁК ЮMONEY</div>
    <form id="bindForm">
      <div style="margin-bottom:12px">
        <div class="flbl">Номер кошелька</div>
        <input type="text" id="yoo_wallet" placeholder="41001234567890" required>
      </div>
      <div style="margin-bottom:12px">
        <div class="flbl">OAuth токен</div>
        <input type="password" id="yoo_token" placeholder="Токен доступа" required>
        <div class="hint">Получить можно на yoomoney.ru/myservices/new — раздел «Работа с кошельком»</div>
      </div>
      <button type="submit" class="submit-btn">Привязать</button>
    </form>
  </div>
</div>

<script>
const api = 'wallet_binding_api.php';
let pollTimer = null;

async function loadStatus() {
  try {
    const r = await fetch(`${api}?action=get_wallet_info`);
    const d = await r.json();
    if (d.connected) {
      document.getElementById('statusDot').className = 'dot dot-on';
      document.getElementById('statusTxt').textContent = 'Подключен: ' + d.wallet_id;
      document.getElementById('balanceBlock').style.display = 'block';
      document.getElementById('balanceVal').textContent = (d.balance || 0).toFixed(2) + ' ₽';
      document.getElementById('unbindBtn').style.display = 'block';
      document.getElementById('formCard').style.display = 'none';
      startPolling();
    } else {
      document.getElementById('statusDot').className = 'dot dot-off';
      document.getElementById('statusTxt').textContent = 'Кошелёк не привязан';
      document.getElementById('balanceBlock').style.display = 'none';
      document.getElementById('unbindBtn').style.display = 'none';
      document.getElementById('formCard').style.display = 'flex';
    }
  } catch (e) { console.error(e); }
}

document.getElementById('bindForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const yoo_wallet = document.getElementById('yoo_wallet').value.trim();
  const yoo_access_token = document.getElementById('yoo_token').value.trim();
  const btn = e.target.querySelector('button');
  btn.disabled = true;

  try {
    const r = await fetch(`${api}?action=bind_wallet`, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({yoo_wallet, yoo_access_token})
    });
    const d = await r.json();
    if (d.ok) {
      flash('Кошелёк привязан', 'ok');
      loadStatus();
    } else {
      flash(d.error || 'Ошибка', 'err');
    }
  } catch (e) {
    flash('Ошибка сети', 'err');
  } finally {
    btn.disabled = false;
  }
});

async function unbind() {
  if (!confirm('Отвязать кошелёк?')) return;
  await fetch(`${api}?action=unbind_wallet`, {method:'POST'});
  if (pollTimer) clearInterval(pollTimer);
  loadStatus();
}

function startPolling() {
  if (pollTimer) return;
  pollTimer = setInterval(async () => {
    try {
      const r = await fetch(`${api}?action=poll_transactions`);
      const d = await r.json();
      if (d.ok && d.new_transactions > 0) {
        flash(`Зачислено операций: ${d.new_transactions}`, 'ok');
        loadStatus();
      }
    } catch (e) {}
  }, 2000);
}

function flash(msg, type) {
  const c = document.getElementById('flash-container');
  c.innerHTML = `<div class="flash ${type}">${msg}</div>`;
  setTimeout(() => c.innerHTML = '', 3000);
}

loadStatus();
</script>
</body>
</html>
