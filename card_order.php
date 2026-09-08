<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid = (int)$_SESSION['user_id'];
$acc = getDB()->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
$acc->execute([$uid]);
$balance = (float)($acc->fetchColumn() ?: 0);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Заказать карту — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:480px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:480px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.progress{display:flex;gap:4px;margin-bottom:6px}
.pdot{flex:1;height:3px;background:#1e1e1e;border-radius:2px}
.pdot.active{background:#fff}
.step{display:none}
.step.active{display:flex;flex-direction:column;gap:14px}
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;margin-bottom:14px}
.design-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.design-card{border-radius:14px;overflow:hidden;border:2px solid #1e1e1e;cursor:pointer;transition:all .2s}
.design-card.selected{border-color:#fff}
.design-img{width:100%;aspect-ratio:16/10;object-fit:cover;background:#1a1a1a}
.design-body{padding:11px}
.design-name{font-size:.78rem;color:#ccc}
.design-price{font-family:'Space Mono',monospace;font-size:.8rem;color:#f0f0f0;margin-top:4px}
.loc-btn{background:#fff;color:#080808;border:none;border-radius:11px;font-family:'DM Sans',sans-serif;font-size:.78rem;font-weight:500;padding:12px;cursor:pointer;width:100%}
#map{width:100%;height:280px;border-radius:14px;margin-top:12px;border:1px solid #1e1e1e}
.zones-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.zone-item{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:12px 14px;cursor:pointer;font-size:.8rem;color:#ccc;transition:all .2s}
.zone-item.selected{border-color:#fff;background:#161616}
.address-block{background:#000;border-radius:16px 16px 0 0;padding:16px 18px;margin-top:14px;display:none}
.address-block.active{display:block;animation:up .25s ease}
@keyframes up{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.address-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.2em;color:#444;margin-bottom:6px}
.address-txt{font-size:.86rem;color:#f0f0f0}
.slots-scroll{display:flex;overflow-x:auto;gap:10px;padding:4px 0 8px}
.slot{flex-shrink:0;background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:12px 16px;cursor:pointer;text-align:center;transition:all .2s}
.slot.selected{background:#fff;color:#080808;border-color:#fff}
.slot-date{font-family:'Space Mono',monospace;font-size:.66rem;font-weight:700}
.slot-time{font-family:'Space Mono',monospace;font-size:.56rem;margin-top:3px;opacity:.7}
.pay-opt{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:14px 16px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:space-between}
.pay-opt.selected{border-color:#fff;background:#161616}
.pay-name{font-size:.84rem;color:#ccc}
.pay-sub{font-family:'Space Mono',monospace;font-size:.5rem;color:#444;margin-top:3px}
.btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.1em;padding:13px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.btn:hover{background:#e0e0e0}
.btn:disabled{opacity:.4;cursor:default}
.btn-sec{background:none;border:1px solid #1e1e1e;color:#666;font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.12em;padding:12px;border-radius:12px;cursor:pointer}
.btn-row{display:flex;gap:10px}
.btn-row .btn,.btn-row .btn-sec{flex:1}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:11px 13px;border-radius:11px}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.success{display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;padding:30px 10px}
.confetti{font-size:64px;animation:pop .5s ease}
@keyframes pop{0%{transform:scale(0)}60%{transform:scale(1.2)}100%{transform:scale(1)}}
.success-title{font-family:'Space Mono',monospace;font-size:.9rem;letter-spacing:.06em;color:#f0f0f0}
.success-sub{font-size:.82rem;color:#666;line-height:1.6}
.conf-code{font-family:'Space Mono',monospace;font-size:.72rem;color:#4ade80;background:#001a08;border:1px solid #002a10;padding:10px 16px;border-radius:10px;letter-spacing:.1em}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="dashboard.php" class="back">← Назад</a>
</div>
<div class="inner">
  <div class="progress">
    <div class="pdot active" data-p="1"></div>
    <div class="pdot" data-p="2"></div>
    <div class="pdot" data-p="3"></div>
    <div class="pdot" data-p="4"></div>
    <div class="pdot" data-p="5"></div>
  </div>

  <div id="flash"></div>

  <!-- Шаг 1: Дизайн -->
  <div class="step active" data-step="1">
    <div class="card">
      <div class="card-title">ВЫБЕРИ ДИЗАЙН КАРТЫ</div>
      <div class="design-grid" id="designGrid"><div style="color:#444;font-size:.7rem">Загрузка...</div></div>
    </div>
    <button class="btn" onclick="goStep(2)">Далее →</button>
  </div>

  <!-- Шаг 2: Зона -->
  <div class="step" data-step="2">
    <div class="card">
      <div class="card-title">ЗОНА ДОСТАВКИ</div>
      <button class="loc-btn" onclick="useMyLocation()">📍 Определить моё местоположение</button>
      <div id="map"></div>
      <div class="zones-list" id="zonesList"></div>
    </div>
    <div class="btn-row">
      <button class="btn-sec" onclick="goStep(1)">← Назад</button>
      <button class="btn" onclick="goStep(3)">Далее →</button>
    </div>
  </div>

  <!-- Шаг 3: Адрес и время -->
  <div class="step" data-step="3">
    <div class="card">
      <div class="card-title">АДРЕС И ВРЕМЯ ДОСТАВКИ</div>
      <div class="address-block active" id="addrBlock">
        <div class="address-lbl">АДРЕС ДОСТАВКИ</div>
        <div class="address-txt" id="addrText">Определяется по геолокации...</div>
      </div>
      <div style="margin-top:16px">
        <div class="card-title">ВЫБЕРИ ДАТУ И ВРЕМЯ</div>
        <div class="slots-scroll" id="slotsScroll"></div>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn-sec" onclick="goStep(2)">← Назад</button>
      <button class="btn" onclick="goStep(4)">Далее →</button>
    </div>
  </div>

  <!-- Шаг 4: Оплата -->
  <div class="step" data-step="4">
    <div class="card">
      <div class="card-title">СПОСОБ ОПЛАТЫ</div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div class="pay-opt selected" data-method="balance" onclick="selectPay('balance', this)">
          <div>
            <div class="pay-name">💳 Основной баланс</div>
            <div class="pay-sub">Доступно: <?php echo number_format($balance,2,'.',' '); ?> ₽</div>
          </div>
        </div>
        <div class="pay-opt" data-method="yoomoney" onclick="selectPay('yoomoney', this)">
          <div>
            <div class="pay-name">💰 ЮMoney</div>
            <div class="pay-sub">Оплата через кошелёк</div>
          </div>
        </div>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn-sec" onclick="goStep(3)">← Назад</button>
      <button class="btn" onclick="submitOrder()" id="payBtn">Подтвердить заказ</button>
    </div>
  </div>

  <!-- Шаг 5: Успех -->
  <div class="step" data-step="5">
    <div class="card success">
      <div class="confetti">🎉</div>
      <div class="success-title">Приняли заказ, начинаем делать карту</div>
      <div class="success-sub">Представитель свяжется с вами в назначенную дату доставки.</div>
      <div class="conf-code" id="confCode"></div>
      <button class="btn" style="margin-top:6px" onclick="location.href='dashboard.php'">Перейти на карту →</button>
    </div>
  </div>
</div>

<script>
const api = 'card_order_api.php';
let templates = [];
let selectedDesign = null, selectedZone = null, selectedSlot = null, selectedPay = 'balance';
let userLoc = null, map = null;

function flash(msg) {
  document.getElementById('flash').innerHTML = `<div class="flash err">${msg}</div>`;
  setTimeout(() => document.getElementById('flash').innerHTML = '', 3000);
}

function goStep(n) {
  document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
  document.querySelector(`.step[data-step="${n}"]`).classList.add('active');
  document.querySelectorAll('.pdot').forEach(d => d.classList.toggle('active', +d.dataset.p <= n));
  if (n === 3) loadSlots();
}

// ШАГ 1
async function loadTemplates() {
  const r = await fetch(`${api}?action=get_templates`);
  const d = await r.json();
  templates = d.templates || [];
  const grid = document.getElementById('designGrid');
  grid.innerHTML = templates.map(t => `
    <div class="design-card" data-id="${t.id}" onclick="selectDesign(${t.id}, this)">
      <img src="${t.cover_image || ''}" class="design-img">
      <div class="design-body">
        <div class="design-name">${t.name}</div>
        <div class="design-price">${t.price.toFixed(2)} ₽</div>
      </div>
    </div>
  `).join('');
}
function selectDesign(id, el) {
  selectedDesign = templates.find(t => t.id === id);
  document.querySelectorAll('.design-card').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
}

// ШАГ 2
function initMap() {
  if (map) { setTimeout(()=>map.invalidateSize(),100); return; }
  map = L.map('map').setView([55.7558, 37.6173], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
  setTimeout(()=>map.invalidateSize(),100);
}
function useMyLocation() {
  if (!selectedDesign) { flash('Сначала выбери дизайн'); return; }
  if (!navigator.geolocation) { flash('Геолокация недоступна'); return; }
  initMap();
  navigator.geolocation.getCurrentPosition(pos => {
    userLoc = [pos.coords.latitude, pos.coords.longitude];
    map.setView(userLoc, 14);
    L.marker(userLoc).addTo(map);
    checkZones();
  }, () => flash('Не удалось определить местоположение'));
}
async function checkZones() {
  const r = await fetch(`${api}?action=check_zone_available`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ template_id: selectedDesign.id, latitude: userLoc[0], longitude: userLoc[1] })
  });
  const d = await r.json();
  const list = document.getElementById('zonesList');
  if (!d.zones || !d.zones.length) { list.innerHTML = '<div style="color:#666;font-size:.78rem">Доставка недоступна в вашем районе</div>'; return; }
  list.innerHTML = d.zones.map(z => `<div class="zone-item" data-id="${z.id}" onclick="selectZone(${z.id},'${z.name}',this)">✅ ${z.name}</div>`).join('');
}
function selectZone(id, name, el) {
  selectedZone = { id, name };
  document.querySelectorAll('.zone-item').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
}

// ШАГ 3
async function loadSlots() {
  if (!selectedDesign) return;
  document.getElementById('addrText').textContent = userLoc ? `${userLoc[0].toFixed(5)}, ${userLoc[1].toFixed(5)}` : 'Адрес не определён';
  const r = await fetch(`${api}?action=get_slots&template_id=${selectedDesign.id}`);
  const d = await r.json();
  const scroll = document.getElementById('slotsScroll');
  scroll.innerHTML = (d.slots || []).map(s => `
    <div class="slot ${s.available?'':'disabled'}" ${s.available?`onclick="selectSlot(${s.id}, this)"`:''} style="${s.available?'':'opacity:.3'}">
      <div class="slot-date">${new Date(s.date).toLocaleDateString('ru',{day:'2-digit',month:'2-digit'})}</div>
      <div class="slot-time">${s.time_start}–${s.time_end}</div>
    </div>
  `).join('');
}
function selectSlot(id, el) {
  selectedSlot = id;
  document.querySelectorAll('.slot').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
}

// ШАГ 4
function selectPay(m, el) {
  selectedPay = m;
  document.querySelectorAll('.pay-opt').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
}

async function submitOrder() {
  if (!selectedDesign || !selectedZone || !selectedSlot || !userLoc) { flash('Заполните все шаги'); return; }
  const btn = document.getElementById('payBtn');
  btn.disabled = true;

  const r = await fetch(`${api}?action=create_order`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      template_id: selectedDesign.id, zone_id: selectedZone.id, slot_id: selectedSlot,
      address: document.getElementById('addrText').textContent,
      latitude: userLoc[0], longitude: userLoc[1], payment_method: selectedPay
    })
  });
  const d = await r.json();
  btn.disabled = false;

  if (d.ok) {
    document.getElementById('confCode').textContent = d.confirmation_code;
    goStep(5);
  } else {
    flash(d.error || 'Ошибка оформления заказа');
  }
}

loadTemplates();
</script>
</body>
</html>
