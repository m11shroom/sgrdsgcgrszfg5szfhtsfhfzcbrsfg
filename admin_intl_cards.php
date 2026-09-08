<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Международные карты — Админ M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:800px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none}
.inner{max-width:800px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;margin-bottom:14px}
select{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;margin-bottom:14px}
.order-row{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:14px 16px;margin-top:8px}
.order-top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.order-name{font-size:.84rem;color:#ccc}
.order-meta{font-family:'Space Mono',monospace;font-size:.56rem;color:#555;margin-top:4px}
.status-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.12em;padding:4px 10px;border-radius:20px}
.st-pending_payment{background:#1a1a1a;color:#888}
.st-paid{background:#1a1200;color:#facc15}
.st-active{background:#001a08;color:#4ade80}
.st-cancelled{background:#1a0000;color:#f87171}
.fill-form{display:none;margin-top:14px;padding-top:14px;border-top:1px solid #161616;gap:10px;flex-direction:column}
.fill-form.active{display:flex}
.field{display:flex;flex-direction:column;gap:6px}
.flbl{font-size:.5rem;letter-spacing:.2em;color:#363636;text-transform:uppercase}
input{background:#080808;border:1px solid #1e1e1e;border-radius:9px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.78rem;padding:9px 11px;outline:none}
.btn{background:#fff;color:#080808;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.76rem;font-weight:500;padding:9px 14px;cursor:pointer}
.btn-sec{background:none;border:1px solid #1e1e1e;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;padding:7px 12px;border-radius:8px;cursor:pointer}
.btn-danger{background:none;border:1px solid #2a0000;color:#a04040;font-family:'Space Mono',monospace;font-size:.5rem;padding:7px 12px;border-radius:8px;cursor:pointer}
.btn-row{display:flex;gap:8px}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;padding:11px 13px;border-radius:11px}
.flash.ok{color:#4ade80;background:#001a08}
.flash.err{color:#f87171;background:#1a0000}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;color:#1a1a1a;text-align:center;padding:24px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET · КАРТЫ VISA/MC</div>
  <a href="admin.php" class="back">← Панель</a>
</div>
<div class="inner">
  <div class="pg-ttl">МЕЖДУНАРОДНЫЕ КАРТЫ</div>
  <div id="flash"></div>
  <div class="card">
    <div class="card-title">ФИЛЬТР</div>
    <select id="statusFilter" onchange="loadOrders()">
      <option value="">Все</option>
      <option value="pending_payment">Ожидают оплаты</option>
      <option value="paid" selected>Оплачены, ждут данных</option>
      <option value="active">Активны</option>
      <option value="cancelled">Отменены</option>
    </select>
    <div id="ordersList"></div>
  </div>
</div>

<script>
const api = 'admin_intl_cards_api.php';

function flash(msg, type) {
  document.getElementById('flash').innerHTML = `<div class="flash ${type}">${msg}</div>`;
  setTimeout(() => document.getElementById('flash').innerHTML = '', 3000);
}

async function loadOrders() {
  const status = document.getElementById('statusFilter').value;
  const r = await fetch(`${api}?action=list_orders${status ? '&status='+status : ''}`);
  const d = await r.json();
  const list = document.getElementById('ordersList');
  if (!d.ok || !d.orders.length) { list.innerHTML = '<div class="empty">Заказов нет</div>'; return; }

  const labels = { pending_payment: 'Ожидает оплаты', paid: 'Оплачено', active: 'Активна', cancelled: 'Отменена' };

  list.innerHTML = d.orders.map(o => `
    <div class="order-row">
      <div class="order-top">
        <div>
          <div class="order-name">@${o.username} — ${o.payment_system === 'visa' ? 'Visa' : 'Mastercard'}</div>
          <div class="order-meta">${o.price} ₽ · создан ${new Date(o.created_at).toLocaleString('ru')}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <span class="status-badge st-${o.status}">${labels[o.status]}</span>
          ${o.status === 'paid' ? `<button class="btn-sec" onclick="toggleForm(${o.id})">Внести данные</button>` : ''}
          ${o.status !== 'cancelled' && o.status !== 'active' ? `<button class="btn-danger" onclick="cancelOrder(${o.id})">Отменить</button>` : ''}
        </div>
      </div>
      ${o.status === 'paid' ? `
      <div class="fill-form" id="form${o.id}">
        <div class="field"><div class="flbl">Номер карты</div><input type="text" id="num${o.id}" placeholder="4000 1234 5678 9010"></div>
        <div class="btn-row">
          <div class="field" style="flex:1"><div class="flbl">MM/YY</div><input type="text" id="exp${o.id}" placeholder="12/28"></div>
          <div class="field" style="flex:1"><div class="flbl">CVV</div><input type="text" id="cvv${o.id}" placeholder="123" maxlength="3"></div>
        </div>
        <button class="btn" onclick="fillCard(${o.id})">Сохранить и активировать</button>
      </div>` : ''}
    </div>
  `).join('');
}

function toggleForm(id) {
  document.getElementById('form' + id).classList.toggle('active');
}

async function fillCard(id) {
  const number = document.getElementById('num' + id).value;
  const expiry = document.getElementById('exp' + id).value;
  const cvv = document.getElementById('cvv' + id).value;
  const r = await fetch(`${api}?action=fill_card`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: id, card_number: number, expiry, cvv })
  });
  const d = await r.json();
  if (d.ok) { flash('Карта активирована', 'ok'); loadOrders(); } else flash(d.error, 'err');
}

async function cancelOrder(id) {
  if (!confirm('Отменить заказ?')) return;
  const r = await fetch(`${api}?action=cancel_order`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: id })
  });
  const d = await r.json();
  if (d.ok) { flash('Отменено', 'ok'); loadOrders(); } else flash(d.error, 'err');
}

loadOrders();
</script>
</body>
</html>
