<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Доставка карт — Админ M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet-draw/1.0.4/leaflet.draw.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-draw/1.0.4/leaflet.draw.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:900px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
.tabs{display:flex;gap:8px;flex-wrap:wrap}
.tab{background:#111;border:1px solid #1e1e1e;border-radius:10px;color:#555;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:9px 15px;cursor:pointer;text-transform:uppercase;transition:all .2s}
.tab.active{background:#fff;color:#080808;border-color:#fff}
.section{display:none}
.section.active{display:block}
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;margin-bottom:16px}
.btn{background:#fff;color:#080808;border:none;border-radius:11px;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;letter-spacing:.1em;padding:11px 18px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.btn:hover{background:#e0e0e0}
.btn-sec{background:none;border:1px solid #1e1e1e;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:7px 12px;border-radius:8px;cursor:pointer;transition:all .2s}
.btn-sec:hover{border-color:#555;color:#ccc}
.btn-danger{background:none;border:1px solid #2a0000;color:#a04040;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:7px 12px;border-radius:8px;cursor:pointer;transition:all .2s}
.btn-danger:hover{border-color:#c04040;color:#f87171}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:16px}
.tpl-card{background:#0a0a0a;border:1px solid #161616;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
.tpl-img{width:100%;aspect-ratio:16/10;object-fit:cover;background:#161616}
.tpl-body{padding:13px 14px;display:flex;flex-direction:column;gap:8px}
.tpl-name{font-size:.86rem;color:#ccc;font-weight:500}
.tpl-price{font-family:'Space Mono',monospace;color:#f0f0f0}
.tpl-actions{display:flex;gap:7px}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
input,select{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;-webkit-appearance:none}
input:focus,select:focus{border-color:#383838}
#map{width:100%;height:420px;border-radius:14px;margin:14px 0;border:1px solid #1e1e1e}
.zone-row,.slot-row,.order-row{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px;flex-wrap:wrap}
.zone-name{font-size:.82rem;color:#ccc}
.slot-info{font-family:'Space Mono',monospace;font-size:.7rem;color:#888}
.status-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.12em;padding:4px 10px;border-radius:20px;background:#1a1a1a;color:#888;text-transform:uppercase}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal.active{display:flex}
.modal-box{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:26px 22px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto;position:relative}
.modal-close{position:absolute;top:16px;right:16px;background:none;border:none;color:#666;font-size:20px;cursor:pointer}
.modal-title{font-family:'Space Mono',monospace;font-size:.64rem;letter-spacing:.2em;color:#888;margin-bottom:18px}
.field{margin-bottom:14px}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:24px}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:11px 13px;border-radius:11px;margin-bottom:12px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET · ДОСТАВКА КАРТ</div>
  <a href="admin.php" class="back">← Панель</a>
</div>
<div class="inner">
  <div class="pg-ttl">УПРАВЛЕНИЕ ПЛАСТИКОВЫМИ КАРТАМИ</div>
  <div id="flash"></div>

  <div class="tabs">
    <button class="tab active" data-tab="templates" onclick="switchTab('templates')">Карты</button>
    <button class="tab" data-tab="zones" onclick="switchTab('zones')">Зоны доставки</button>
    <button class="tab" data-tab="slots" onclick="switchTab('slots')">Время доставки</button>
    <button class="tab" data-tab="orders" onclick="switchTab('orders')">Заказы</button>
  </div>

  <!-- КАРТЫ -->
  <div class="section active" id="tab-templates">
    <div class="card">
      <div class="card-title">ШАБЛОНЫ КАРТ</div>
      <button class="btn" onclick="openTplModal()">+ Новая карта</button>
      <div class="grid" id="templatesGrid"><div class="empty">Загрузка...</div></div>
    </div>
  </div>

  <!-- ЗОНЫ -->
  <div class="section" id="tab-zones">
    <div class="card">
      <div class="card-title">ЗОНЫ ДОСТАВКИ</div>
      <div class="field">
        <div class="flbl">Карта</div>
        <select id="zoneTplSelect" onchange="onZoneTplChange()"><option value="">— выбери карту —</option></select>
      </div>
      <div id="zoneMapWrap" style="display:none">
        <div class="field">
          <div class="flbl">Название зоны (нарисуй полигон на карте)</div>
          <input type="text" id="zoneNameInput" placeholder="Например: Центр Москвы">
        </div>
        <div id="map"></div>
        <button class="btn" onclick="saveZone()">Сохранить нарисованную зону</button>
      </div>
      <div id="zonesList"></div>
    </div>
  </div>

  <!-- СЛОТЫ -->
  <div class="section" id="tab-slots">
    <div class="card">
      <div class="card-title">ВРЕМЯ ДОСТАВКИ</div>
      <button class="btn" onclick="openSlotModal()">+ Новый слот</button>
      <div class="field" style="margin-top:14px">
        <div class="flbl">Фильтр по карте</div>
        <select id="slotFilterSelect" onchange="loadSlots()"><option value="">— все —</option></select>
      </div>
      <div id="slotsList"></div>
    </div>
  </div>

  <!-- ЗАКАЗЫ -->
  <div class="section" id="tab-orders">
    <div class="card">
      <div class="card-title">ЗАКАЗЫ КАРТ</div>
      <div class="field">
        <div class="flbl">Статус</div>
        <select id="orderFilterSelect" onchange="loadOrders()">
          <option value="">— все —</option>
          <option value="pending">В ожидании</option>
          <option value="confirmed">Подтверждён</option>
          <option value="in_production">В производстве</option>
          <option value="shipped">Отправлен</option>
          <option value="delivered">Доставлен</option>
          <option value="cancelled">Отменён</option>
        </select>
      </div>
      <div id="ordersList"></div>
    </div>
  </div>
</div>

<!-- МОДАЛ: НОВАЯ КАРТА -->
<div class="modal" id="tplModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('tplModal')">✕</button>
    <div class="modal-title">НОВАЯ КАРТА</div>
    <form onsubmit="createTemplate(event)">
      <div class="field"><div class="flbl">Название</div><input type="text" id="tplName" required></div>
      <div class="field"><div class="flbl">Цена (₽)</div><input type="number" id="tplPrice" min="0" step="0.01" required></div>
      <div class="field"><div class="flbl">Фото дизайна</div><input type="file" id="tplImage" accept="image/*" required></div>
      <button type="submit" class="btn" style="width:100%">Создать</button>
    </form>
  </div>
</div>

<!-- МОДАЛ: НОВЫЙ СЛОТ -->
<div class="modal" id="slotModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('slotModal')">✕</button>
    <div class="modal-title">НОВЫЙ СЛОТ ДОСТАВКИ</div>
    <form onsubmit="createSlot(event)">
      <div class="field"><div class="flbl">Карта</div><select id="slotTplSelect" required></select></div>
      <div class="field"><div class="flbl">Дата</div><input type="date" id="slotDate" required></div>
      <div class="row2">
        <div class="field"><div class="flbl">С</div><input type="time" id="slotStart" required></div>
        <div class="field"><div class="flbl">До</div><input type="time" id="slotEnd" required></div>
      </div>
      <div class="field"><div class="flbl">Макс. заказов</div><input type="number" id="slotMax" value="10" min="1"></div>
      <button type="submit" class="btn" style="width:100%">Создать</button>
    </form>
  </div>
</div>

<!-- МОДАЛ: ОБНОВИТЬ ЗАКАЗ -->
<div class="modal" id="orderModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('orderModal')">✕</button>
    <div class="modal-title">ОБНОВИТЬ ЗАКАЗ</div>
    <form onsubmit="updateOrder(event)">
      <input type="hidden" id="orderIdInput">
      <div class="field">
        <div class="flbl">Статус</div>
        <select id="orderStatusInput" required>
          <option value="pending">В ожидании</option>
          <option value="confirmed">Подтверждён</option>
          <option value="in_production">В производстве</option>
          <option value="shipped">Отправлен</option>
          <option value="delivered">Доставлен</option>
          <option value="cancelled">Отменён</option>
        </select>
      </div>
      <div class="field"><div class="flbl">Представитель — ФИО</div><input type="text" id="orderRepName"></div>
      <div class="field"><div class="flbl">Представитель — телефон</div><input type="text" id="orderRepPhone"></div>
      <div class="field"><div class="flbl">Дата доставки</div><input type="date" id="orderDelDate"></div>
      <button type="submit" class="btn" style="width:100%">Сохранить</button>
    </form>

    <div style="margin-top:20px;padding-top:18px;border-top:1px solid #1e1e1e">
      <div class="flbl" style="margin-bottom:10px">КОШЕЛЁК ЮMONEY ДЛЯ ЭТОГО ЗАКАЗА</div>
      <div id="walletStatus" style="font-family:'Space Mono',monospace;font-size:.66rem;color:#666;margin-bottom:10px">Загрузка...</div>
      <div id="walletBalanceBlock" style="display:none;margin-bottom:10px">
        <div class="flbl">Баланс кошелька</div>
        <div style="font-family:'Space Mono',monospace;font-size:1.2rem;color:#f0f0f0" id="walletBalanceVal">0 ₽</div>
      </div>
      <div id="walletFormBlock">
        <div class="hint" style="font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;margin-bottom:10px;line-height:1.6">Авторизация через официальный OAuth ЮMoney — без ручного ввода токена</div>
        <a class="btn" style="width:100%;display:block;text-align:center;text-decoration:none;box-sizing:border-box" id="oauthWalletBtn" href="#">Авторизовать через ЮMoney →</a>
      </div>
      <button type="button" class="btn-danger" id="unbindWalletBtn" style="display:none;width:100%;margin-top:8px" onclick="unbindOrderWallet()">Отвязать кошелёк</button>
    </div>
  </div>
</div>

<script>
const api = 'admin_card_templates_api.php';
let templates = [];
let map = null, drawnItems = null, drawControl = null;

function flash(msg, type) {
  document.getElementById('flash').innerHTML = `<div class="flash ${type}">${msg}</div>`;
  setTimeout(() => document.getElementById('flash').innerHTML = '', 3000);
}

function switchTab(tab) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.querySelector(`.tab[data-tab="${tab}"]`).classList.add('active');
  if (tab === 'slots') loadSlots();
  if (tab === 'orders') loadOrders();
}

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openTplModal() { openModal('tplModal'); }
function openSlotModal() { openModal('slotModal'); }

// ── КАРТЫ ──
async function loadTemplates() {
  try {
    const r = await fetch(`${api}?action=list_templates`);
    if (!r.ok) { flash(`Ошибка сервера: HTTP ${r.status}`, 'err'); return; }
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch (e) { flash('Сервер вернул не-JSON (см. консоль)', 'err'); console.error('Ответ сервера:', text); return; }
    templates = d.templates || [];
    // Защита от визуального дублирования, если в БД случайно оказались дубли
    const seen = new Set();
    templates = templates.filter(t => {
      if (seen.has(t.id)) return false;
      seen.add(t.id);
      return true;
    });
    renderTemplates();
    fillTplSelects();
  } catch (e) {
    flash('Ошибка загрузки: ' + e.message, 'err');
    console.error(e);
  }
}

function renderTemplates() {
  const grid = document.getElementById('templatesGrid');
  if (!templates.length) { grid.innerHTML = '<div class="empty">Карт ещё нет</div>'; return; }
  grid.innerHTML = templates.map(t => `
    <div class="tpl-card">
      <img src="${t.cover_image || ''}" class="tpl-img" alt="${t.name}">
      <div class="tpl-body">
        <div class="tpl-name">${t.name}</div>
        <div class="tpl-price">${t.price.toFixed(2)} ₽</div>
        <div class="tpl-actions">
          <button class="btn-sec" onclick="toggleTemplate(${t.id})">${t.is_active ? 'Скрыть' : 'Показать'}</button>
          <button class="btn-danger" onclick="deleteTemplate(${t.id})">Удалить</button>
        </div>
      </div>
    </div>
  `).join('');
}

function fillTplSelects() {
  const opts = templates.map(t => `<option value="${t.id}">${t.name}</option>`).join('');
  document.getElementById('zoneTplSelect').innerHTML = '<option value="">— выбери карту —</option>' + opts;
  document.getElementById('slotFilterSelect').innerHTML = '<option value="">— все —</option>' + opts;
  document.getElementById('slotTplSelect').innerHTML = opts;
}

let isSubmittingTemplate = false;

async function createTemplate(e) {
  e.preventDefault();
  if (isSubmittingTemplate) return; // защита от двойного клика/Enter
  isSubmittingTemplate = true;

  const nameVal = document.getElementById('tplName').value.trim();
  const priceVal = document.getElementById('tplPrice').value;
  const fileInput = document.getElementById('tplImage');

  if (!nameVal) { flash('Укажите название карты', 'err'); isSubmittingTemplate = false; return; }
  if (!fileInput.files[0]) { flash('Выберите файл с дизайном', 'err'); isSubmittingTemplate = false; return; }

  const fd = new FormData();
  fd.append('name', nameVal);
  fd.append('price', priceVal);
  fd.append('image', fileInput.files[0]);

  const submitBtn = e.target.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;

  try {
    const r = await fetch(`${api}?action=create_template`, { method: 'POST', body: fd });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch (parseErr) {
      flash('Сервер вернул некорректный ответ (см. консоль браузера)', 'err');
      console.error('Сырой ответ сервера при создании карты:', text);
      return;
    }
    if (d.ok) {
      flash('Карта создана', 'ok');
      closeModal('tplModal');
      e.target.reset();
      loadTemplates();
    } else {
      flash(d.error || 'Ошибка', 'err');
    }
  } catch (networkErr) {
    flash('Ошибка сети: ' + networkErr.message, 'err');
    console.error(networkErr);
  } finally {
    if (submitBtn) submitBtn.disabled = false;
    isSubmittingTemplate = false;
  }
}

async function toggleTemplate(id) {
  const r = await fetch(`${api}?action=toggle_template`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({template_id:id}) });
  const d = await r.json();
  if (d.ok) loadTemplates(); else flash(d.error, 'err');
}

async function deleteTemplate(id) {
  if (!confirm('Удалить карту? Связанные заказы тоже пострадают.')) return;
  const r = await fetch(`${api}?action=delete_template`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({template_id:id}) });
  const d = await r.json();
  if (d.ok) loadTemplates(); else flash(d.error, 'err');
}

// ── ЗОНЫ ──
function onZoneTplChange() {
  const tid = document.getElementById('zoneTplSelect').value;
  document.getElementById('zoneMapWrap').style.display = tid ? 'block' : 'none';
  if (!tid) return;
  initMap();
  loadZones(tid);
}

function initMap() {
  if (map) { setTimeout(() => map.invalidateSize(), 100); return; }
  map = L.map('map').setView([55.7558, 37.6173], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
  drawnItems = new L.FeatureGroup();
  map.addLayer(drawnItems);
  drawControl = new L.Control.Draw({
    edit: { featureGroup: drawnItems },
    draw: { polygon: true, rectangle: false, circle: false, marker: false, polyline: false, circlemarker: false }
  });
  map.addControl(drawControl);
  setTimeout(() => map.invalidateSize(), 100);
}

async function loadZones(tid) {
  const r = await fetch(`${api}?action=get_delivery_zones&template_id=${tid}`);
  const d = await r.json();
  const list = document.getElementById('zonesList');
  if (!d.zones || !d.zones.length) { list.innerHTML = '<div class="empty">Зон пока нет</div>'; return; }
  list.innerHTML = d.zones.map(z => `
    <div class="zone-row">
      <div class="zone-name">${z.name}</div>
      <button class="btn-danger" onclick="deleteZone(${z.id})">Удалить</button>
    </div>
  `).join('');
}

function saveZone() {
  const name = document.getElementById('zoneNameInput').value.trim();
  const tid = document.getElementById('zoneTplSelect').value;
  if (!name) { flash('Укажите название зоны', 'err'); return; }
  const layers = drawnItems.getLayers();
  if (!layers.length) { flash('Нарисуйте полигон на карте', 'err'); return; }
  const layer = layers[layers.length - 1];
  const geojson = layer.toGeoJSON().geometry;

  fetch(`${api}?action=add_delivery_zone`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ template_id: tid, zone_name: name, zone_polygon: geojson })
  }).then(r=>r.json()).then(d=>{
    if (d.ok) { flash('Зона сохранена', 'ok'); document.getElementById('zoneNameInput').value=''; drawnItems.clearLayers(); loadZones(tid); }
    else flash(d.error, 'err');
  });
}

async function deleteZone(id) {
  if (!confirm('Удалить зону?')) return;
  const r = await fetch(`${api}?action=delete_delivery_zone`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({zone_id:id}) });
  const d = await r.json();
  if (d.ok) loadZones(document.getElementById('zoneTplSelect').value); else flash(d.error, 'err');
}

// ── СЛОТЫ ──
async function loadSlots() {
  const tid = document.getElementById('slotFilterSelect').value;
  const r = await fetch(`${api}?action=get_delivery_slots${tid ? '&template_id='+tid : ''}`);
  const d = await r.json();
  const list = document.getElementById('slotsList');
  if (!d.slots || !d.slots.length) { list.innerHTML = '<div class="empty">Слотов нет</div>'; return; }
  list.innerHTML = d.slots.map(s => `
    <div class="slot-row">
      <div class="slot-info">${s.template_name} · ${s.date} · ${s.time_start}–${s.time_end} · ${s.current_orders}/${s.max_orders}</div>
      <button class="btn-danger" onclick="deleteSlot(${s.id})">Удалить</button>
    </div>
  `).join('');
}

async function createSlot(e) {
  e.preventDefault();
  const body = {
    template_id: document.getElementById('slotTplSelect').value,
    delivery_date: document.getElementById('slotDate').value,
    time_start: document.getElementById('slotStart').value,
    time_end: document.getElementById('slotEnd').value,
    max_orders: document.getElementById('slotMax').value,
  };
  const r = await fetch(`${api}?action=add_delivery_slot`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const d = await r.json();
  if (d.ok) { flash('Слот создан', 'ok'); closeModal('slotModal'); e.target.reset(); loadSlots(); }
  else flash(d.error, 'err');
}

async function deleteSlot(id) {
  if (!confirm('Удалить слот?')) return;
  const r = await fetch(`${api}?action=delete_delivery_slot`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({slot_id:id}) });
  const d = await r.json();
  if (d.ok) loadSlots(); else flash(d.error, 'err');
}

// ── ЗАКАЗЫ ──
async function loadOrders() {
  const status = document.getElementById('orderFilterSelect').value;
  const r = await fetch(`${api}?action=get_orders${status ? '&status='+status : ''}`);
  const d = await r.json();
  const list = document.getElementById('ordersList');
  if (!d.orders || !d.orders.length) { list.innerHTML = '<div class="empty">Заказов нет</div>'; return; }
  list.innerHTML = d.orders.map(o => `
    <div class="order-row">
      <div>
        <div class="zone-name">${o.user_name} — ${o.template_name}</div>
        <div class="slot-info">${o.address || ''}</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="status-badge">${o.status}</span>
        <button class="btn-sec" onclick='openOrderModal(${JSON.stringify(o)})'>Обновить</button>
      </div>
    </div>
  `).join('');
}

function openOrderModal(o) {
  document.getElementById('orderIdInput').value = o.id;
  document.getElementById('orderStatusInput').value = o.status;
  document.getElementById('orderRepName').value = o.representative_name || '';
  document.getElementById('orderRepPhone').value = o.representative_phone || '';
  document.getElementById('orderDelDate').value = o.delivery_date_actual || '';
  const oauthBtn = document.getElementById('oauthWalletBtn');
  if (oauthBtn) oauthBtn.href = 'yoomoney_auth.php?connect_delivery_order=' + o.id;
  openModal('orderModal');
  loadOrderWallet(o.id);
}

// ── Кошелёк ЮMoney для заказа (привязывает только админ через OAuth ЮMoney) ──
let walletPollTimer = null;

async function loadOrderWallet(orderId) {
  try {
    const r = await fetch(`wallet_binding_api.php?action=get_wallet_info&card_order_id=${orderId}`);
    const d = await r.json();
    if (d.connected) {
      document.getElementById('walletStatus').textContent = '✅ Подключен: ' + d.wallet_id;
      document.getElementById('walletBalanceBlock').style.display = 'block';
      document.getElementById('walletBalanceVal').textContent = (d.balance || 0).toFixed(2) + ' ₽';
      document.getElementById('walletFormBlock').style.display = 'none';
      document.getElementById('unbindWalletBtn').style.display = 'block';
      startWalletPolling(orderId);
    } else {
      document.getElementById('walletStatus').textContent = '❌ Кошелёк не привязан';
      document.getElementById('walletBalanceBlock').style.display = 'none';
      document.getElementById('walletFormBlock').style.display = 'block';
      document.getElementById('unbindWalletBtn').style.display = 'none';
    }
  } catch (e) { console.error(e); }
}

async function unbindOrderWallet() {
  if (!confirm('Отвязать кошелёк от этого заказа?')) return;
  const orderId = document.getElementById('orderIdInput').value;
  await fetch('wallet_binding_api.php?action=unbind_wallet', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ card_order_id: orderId })
  });
  if (walletPollTimer) { clearInterval(walletPollTimer); walletPollTimer = null; }
  loadOrderWallet(orderId);
}

function startWalletPolling(orderId) {
  if (walletPollTimer) clearInterval(walletPollTimer);
  walletPollTimer = setInterval(async () => {
    try {
      const r = await fetch(`wallet_binding_api.php?action=poll_transactions&card_order_id=${orderId}`);
      const d = await r.json();
      if (d.ok && d.new_transactions > 0) {
        flash(`Зачислено операций: ${d.new_transactions}`, 'ok');
        loadOrderWallet(orderId);
      }
    } catch (e) {}
  }, 2000);
}

// Останавливаем поллинг при закрытии модалки заказа
const _origCloseModal = closeModal;
closeModal = function(id) {
  _origCloseModal(id);
  if (id === 'orderModal' && walletPollTimer) { clearInterval(walletPollTimer); walletPollTimer = null; }
};

async function updateOrder(e) {
  e.preventDefault();
  const body = {
    order_id: document.getElementById('orderIdInput').value,
    status: document.getElementById('orderStatusInput').value,
    representative_name: document.getElementById('orderRepName').value,
    representative_phone: document.getElementById('orderRepPhone').value,
    delivery_date_actual: document.getElementById('orderDelDate').value,
  };
  const r = await fetch(`${api}?action=update_order_status`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const d = await r.json();
  if (d.ok) { flash('Заказ обновлён', 'ok'); closeModal('orderModal'); loadOrders(); }
  else flash(d.error, 'err');
}

loadTemplates();

// Если вернулись с OAuth-авторизации кошелька (?wallet_connected=ID) — сразу открываем этот заказ
(function () {
  const params = new URLSearchParams(window.location.search);
  const connectedOrderId = params.get('wallet_connected');
  if (connectedOrderId) {
    switchTabByName('orders');
    fetch(`${api}?action=get_orders`).then(r => r.json()).then(d => {
      const found = (d.orders || []).find(o => String(o.id) === String(connectedOrderId));
      if (found) openOrderModal(found);
      flash('Кошелёк успешно привязан к заказу', 'ok');
    });
    const url = new URL(window.location.href);
    url.searchParams.delete('wallet_connected');
    window.history.replaceState({}, document.title, url.toString());
  }
})();

function switchTabByName(tab) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.querySelector(`.tab[data-tab="${tab}"]`).classList.add('active');
}
</script>
</body>
</html>
