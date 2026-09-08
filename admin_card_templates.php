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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<style>
/* Стили (без изменений) */
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
.modal-box{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:26px 22px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;position:relative}
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
        <div style="display:flex;gap:10px;margin-top:10px;">
          <button class="btn" onclick="saveZone()">Сохранить зону</button>
          <button class="btn-sec" onclick="cancelDrawing()" style="border-color:#444;color:#888;">Отменить</button>
        </div>
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

<!-- МОДАЛЫ (без изменений) -->
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

<div class="modal" id="slotModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('slotModal')">✕</button>
    <div class="modal-title">НОВЫЙ СЛОТ ДОСТАВКИ</div>
    <form onsubmit="createSlot(event)">
      <div class="field">
        <div class="flbl">Карта</div>
        <select id="slotTplSelect" required onchange="loadZonesForSlot(this.value)"></select>
      </div>
      <div class="field">
        <div class="flbl">Зона доставки</div>
        <select id="slotZoneSelect">
          <option value="">— все зоны —</option>
        </select>
      </div>
      <div class="field">
        <div class="flbl">Дата доставки</div>
        <input type="date" id="slotDate" required>
      </div>
      <div class="row2">
        <div class="field"><div class="flbl">С</div><input type="time" id="slotStart" required></div>
        <div class="field"><div class="flbl">До</div><input type="time" id="slotEnd" required></div>
      </div>
      <div class="field">
        <div class="flbl">Макс. заказов</div>
        <input type="number" id="slotMax" value="10" min="1">
      </div>
      <div class="field">
        <div class="flbl">Действителен до (включительно)</div>
        <input type="date" id="slotValidUntil">
      </div>
      <button type="submit" class="btn" style="width:100%">Создать</button>
    </form>
  </div>
</div>

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
        <div class="field"><div class="flbl">Номер кошелька</div><input type="text" id="wOrderWallet" placeholder="41001234567890"></div>
        <div class="field"><div class="flbl">OAuth токен</div><input type="password" id="wOrderToken" placeholder="Токен доступа"></div>
        <button type="button" class="btn" style="width:100%" onclick="bindOrderWallet()">Привязать кошелёк</button>
      </div>
      <button type="button" class="btn-danger" id="unbindWalletBtn" style="display:none;width:100%;margin-top:8px" onclick="unbindOrderWallet()">Отвязать кошелёк</button>
    </div>
  </div>
</div>

<script>
const api = 'admin_card_templates_api.php';
let templates = [];
let map = null;
let drawnItems = null;
let savedZonesLayer = null;
let drawControl = null;
let mapInitialized = false;
let currentTemplateId = null;
let currentDrawnPolygon = null; // храним последний нарисованный полигон

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
  if (tab === 'zones') {
    const tid = document.getElementById('zoneTplSelect').value;
    if (tid) {
      document.getElementById('zoneMapWrap').style.display = 'block';
      setTimeout(() => {
        if (!mapInitialized) initMap();
        else if (map) map.invalidateSize();
        loadZones(tid);
      }, 300);
    } else {
      document.getElementById('zoneMapWrap').style.display = 'none';
    }
  }
}

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openTplModal() { openModal('tplModal'); }
function openSlotModal() {
  openModal('slotModal');
  const tplSelect = document.getElementById('slotTplSelect');
  if (tplSelect.value) {
    loadZonesForSlot(tplSelect.value);
  }
}

// ---------- КАРТЫ ----------
async function loadTemplates() {
  try {
    const r = await fetch(`${api}?action=list_templates`);
    if (!r.ok) { flash(`Ошибка сервера: HTTP ${r.status}`, 'err'); return; }
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch (e) { flash('Сервер вернул не-JSON', 'err'); console.error(text); return; }
    templates = d.templates || [];
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
  grid.innerHTML = templates.map(t => {
    const price = parseFloat(t.price) || 0;
    let imgSrc = t.cover_image || '';
    if (imgSrc && !imgSrc.startsWith('/')) imgSrc = '/' + imgSrc;
    return `
    <div class="tpl-card">
      <img src="${imgSrc}" class="tpl-img" alt="${t.name}" 
           onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22100%22%3E%3Crect width=%22200%22 height=%22100%22 fill=%22%23161616%22/%3E%3Ctext x=%2250%22 y=%2255%22 font-family=%22monospace%22 font-size=%2214%22 fill=%22%23444%22%3E%D0%9D%D0%B5%D1%82%20%D1%84%D0%BE%D1%82%D0%BE%3C/text%3E%3C/svg%3E'">
      <div class="tpl-body">
        <div class="tpl-name">${t.name}</div>
        <div class="tpl-price">${price.toFixed(2)} ₽</div>
        <div class="tpl-actions">
          <button class="btn-sec" onclick="toggleTemplate(${t.id})">${t.is_active ? 'Скрыть' : 'Показать'}</button>
          <button class="btn-danger" onclick="deleteTemplate(${t.id})">Удалить</button>
        </div>
      </div>
    </div>
  `}).join('');
}

function fillTplSelects() {
  const opts = templates.map(t => `<option value="${t.id}">${t.name}</option>`).join('');
  document.getElementById('zoneTplSelect').innerHTML = '<option value="">— выбери карту —</option>' + opts;
  document.getElementById('slotFilterSelect').innerHTML = '<option value="">— все —</option>' + opts;
  document.getElementById('slotTplSelect').innerHTML = opts;
}

async function createTemplate(e) {
  e.preventDefault();
  const nameVal = document.getElementById('tplName').value.trim();
  const priceVal = document.getElementById('tplPrice').value;
  const fileInput = document.getElementById('tplImage');

  if (!nameVal) { flash('Укажите название карты', 'err'); return; }
  if (!fileInput.files[0]) { flash('Выберите файл с дизайном', 'err'); return; }

  const fd = new FormData();
  fd.append('action', 'create_template');
  fd.append('name', nameVal);
  fd.append('price', priceVal);
  fd.append('image', fileInput.files[0]);

  const submitBtn = e.target.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;

  try {
    const r = await fetch(api, { method: 'POST', body: fd });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch (parseErr) {
      flash('Сервер вернул некорректный ответ', 'err');
      console.error('Ответ сервера:', text);
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
  }
}

async function toggleTemplate(id) {
  const r = await fetch(`${api}?action=toggle_template`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({template_id:id}) });
  const d = await r.json();
  if (d.ok) loadTemplates(); else flash(d.error, 'err');
}

async function deleteTemplate(id) {
  if (!confirm('Удалить карту?')) return;
  const r = await fetch(`${api}?action=delete_template`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({template_id:id}) });
  const d = await r.json();
  if (d.ok) loadTemplates(); else flash(d.error, 'err');
}

// ---------- ЗОНЫ ----------
function onZoneTplChange() {
  const tid = document.getElementById('zoneTplSelect').value;
  const wrap = document.getElementById('zoneMapWrap');
  if (tid) {
    currentTemplateId = tid;
    wrap.style.display = 'block';
    setTimeout(() => {
      if (!mapInitialized) initMap();
      else if (map) map.invalidateSize();
      loadZones(tid);
    }, 300);
  } else {
    wrap.style.display = 'none';
    if (savedZonesLayer) savedZonesLayer.clearLayers();
    if (drawnItems) drawnItems.clearLayers();
    currentDrawnPolygon = null;
  }
}

function initMap() {
  if (mapInitialized) {
    if (map) map.invalidateSize();
    return;
  }
  const container = document.getElementById('map');
  if (!container) return;
  container.style.display = 'block';
  container.style.height = '420px';

  map = L.map('map', { center: [55.7558, 37.6173], zoom: 10 });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap'
  }).addTo(map);

  savedZonesLayer = L.featureGroup().addTo(map);
  drawnItems = new L.FeatureGroup();
  map.addLayer(drawnItems);

  drawControl = new L.Control.Draw({
    edit: { featureGroup: drawnItems, remove: true },
    draw: {
      polygon: { allowIntersection: false, showArea: true, shapeOptions: { color: '#00ff88', weight: 2 } },
      rectangle: false, circle: false, marker: false, polyline: false, circlemarker: false
    }
  });
  map.addControl(drawControl);

  // Слушаем событие завершения рисования
  map.on('draw:created', function(e) {
    const layer = e.layer;
    // Добавляем в слой рисования
    drawnItems.addLayer(layer);
    // Сохраняем ссылку на текущий полигон
    currentDrawnPolygon = layer;
    flash('Полигон нарисован! Введите название и нажмите "Сохранить зону"', 'ok');
  });

  setTimeout(() => map.invalidateSize(), 500);
  mapInitialized = true;
  flash('Карта готова, рисуйте полигон!', 'ok');
}

// Загружаем сохранённые зоны
async function loadZones(tid) {
  try {
    const r = await fetch(`${api}?action=get_delivery_zones&template_id=${tid}`);
    const d = await r.json();
    const list = document.getElementById('zonesList');
    
    if (savedZonesLayer) savedZonesLayer.clearLayers();

    if (!d.zones || !d.zones.length) {
      list.innerHTML = '<div class="empty">Зон пока нет</div>';
      return;
    }

    d.zones.forEach(z => {
      if (z.polygon) {
        try {
          const geojson = JSON.parse(z.polygon);
          const layer = L.geoJSON(geojson, {
            style: { color: '#ff7800', weight: 2, fillOpacity: 0.2 }
          });
          savedZonesLayer.addLayer(layer);
        } catch (e) { console.warn('Ошибка парсинга полигона для зоны', z.id, e); }
      }
    });

    list.innerHTML = d.zones.map(z => `
      <div class="zone-row">
        <div class="zone-name">${z.name}</div>
        <button class="btn-danger" onclick="deleteZone(${z.id})">Удалить</button>
      </div>
    `).join('');
  } catch (e) { 
    flash('Ошибка загрузки зон', 'err');
    console.error(e);
  }
}

function cancelDrawing() {
  if (currentDrawnPolygon) {
    drawnItems.removeLayer(currentDrawnPolygon);
    currentDrawnPolygon = null;
    flash('Полигон удалён', 'ok');
  } else {
    flash('Нет активного полигона', 'err');
  }
}

function saveZone() {
  const name = document.getElementById('zoneNameInput').value.trim();
  const tid = document.getElementById('zoneTplSelect').value;
  if (!name) { flash('Укажите название зоны', 'err'); return; }
  if (!currentDrawnPolygon) { flash('Сначала нарисуйте полигон на карте', 'err'); return; }
  
  const geojson = currentDrawnPolygon.toGeoJSON().geometry;
  console.log('Сохраняем зону:', name, geojson);

  // Сразу добавляем на карту, чтобы не пропадал
  const tempLayer = L.geoJSON(geojson, {
    style: { color: '#ff7800', weight: 2, fillOpacity: 0.2 }
  });
  savedZonesLayer.addLayer(tempLayer);
  
  // Удаляем из рисования
  drawnItems.removeLayer(currentDrawnPolygon);
  currentDrawnPolygon = null;
  document.getElementById('zoneNameInput').value = '';
  flash('Зона сохраняется...', 'ok');

  fetch(`${api}?action=add_delivery_zone`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ template_id: tid, zone_name: name, zone_polygon: geojson })
  })
  .then(r => r.json())
  .then(d => {
    console.log('Ответ на сохранение:', d);
    if (d.ok) {
      flash('Зона сохранена!', 'ok');
      // Перезагружаем зоны, чтобы обновить список и получить ID
      loadZones(tid);
    } else {
      flash(d.error || 'Ошибка сохранения', 'err');
      // Удаляем временный полигон
      savedZonesLayer.removeLayer(tempLayer);
    }
  })
  .catch(err => {
    flash('Ошибка сети при сохранении', 'err');
    console.error(err);
    savedZonesLayer.removeLayer(tempLayer);
  });
}

async function deleteZone(id) {
  if (!confirm('Удалить зону?')) return;
  const r = await fetch(`${api}?action=delete_delivery_zone`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({zone_id:id}) });
  const d = await r.json();
  if (d.ok) {
    flash('Зона удалена', 'ok');
    loadZones(document.getElementById('zoneTplSelect').value);
  } else flash(d.error, 'err');
}

// ---------- СЛОТЫ ----------
async function loadZonesForSlot(templateId) {
  const select = document.getElementById('slotZoneSelect');
  if (!templateId) {
    select.innerHTML = '<option value="">— все зоны —</option>';
    return;
  }
  try {
    const r = await fetch(`${api}?action=get_delivery_zones&template_id=${templateId}`);
    const d = await r.json();
    if (d.ok && d.zones && d.zones.length) {
      select.innerHTML = '<option value="">— все зоны —</option>' + 
        d.zones.map(z => `<option value="${z.id}">${z.name}</option>`).join('');
    } else {
      select.innerHTML = '<option value="">— нет зон —</option>';
    }
  } catch (e) {
    select.innerHTML = '<option value="">— ошибка загрузки —</option>';
  }
}

async function loadSlots() {
  const tid = document.getElementById('slotFilterSelect').value;
  const r = await fetch(`${api}?action=get_delivery_slots${tid ? '&template_id='+tid : ''}`);
  const d = await r.json();
  const list = document.getElementById('slotsList');
  if (!d.slots || !d.slots.length) { list.innerHTML = '<div class="empty">Слотов нет</div>'; return; }
  list.innerHTML = d.slots.map(s => `
    <div class="slot-row">
      <div class="slot-info">
        ${s.template_name} · ${s.zone_name || 'Все зоны'} · 
        ${s.delivery_date} · ${s.time_start}–${s.time_end} · 
        ${s.current_orders ?? 0}/${s.max_orders} · 
        ${s.valid_until ? 'до ' + s.valid_until : 'бессрочно'}
      </div>
      <button class="btn-danger" onclick="deleteSlot(${s.id})">Удалить</button>
    </div>
  `).join('');
}

async function createSlot(e) {
  e.preventDefault();
  const body = {
    template_id: document.getElementById('slotTplSelect').value,
    zone_id: document.getElementById('slotZoneSelect').value || null,
    delivery_date: document.getElementById('slotDate').value,
    time_start: document.getElementById('slotStart').value,
    time_end: document.getElementById('slotEnd').value,
    max_orders: document.getElementById('slotMax').value,
    valid_until: document.getElementById('slotValidUntil').value || null,
  };
  const r = await fetch(`${api}?action=add_delivery_slot`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.ok) {
    flash('Слот создан', 'ok');
    closeModal('slotModal');
    e.target.reset();
    loadSlots();
  } else {
    flash(d.error, 'err');
  }
}

async function deleteSlot(id) {
  if (!confirm('Удалить слот?')) return;
  const r = await fetch(`${api}?action=delete_delivery_slot`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({slot_id:id}) });
  const d = await r.json();
  if (d.ok) loadSlots(); else flash(d.error, 'err');
}

// ---------- ЗАКАЗЫ ----------
async function loadOrders() {
  const status = document.getElementById('orderFilterSelect').value;
  const r = await fetch(`${api}?action=get_orders${status ? '&status='+status : ''}`);
  const d = await r.json();
  const list = document.getElementById('ordersList');
  if (!d.orders || !d.orders.length) { list.innerHTML = '<div class="empty">Заказов нет</div>'; return; }
  list.innerHTML = d.orders.map(o => `
    <div class="order-row">
      <div>
        <div class="zone-name">${o.user_name || 'Пользователь'} — ${o.template_name}</div>
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
  openModal('orderModal');
  loadOrderWallet(o.id);
}

// ---------- Кошелёк ----------
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

async function bindOrderWallet() {
  const orderId = document.getElementById('orderIdInput').value;
  const yoo_wallet = document.getElementById('wOrderWallet').value.trim();
  const yoo_access_token = document.getElementById('wOrderToken').value.trim();
  if (!yoo_wallet || !yoo_access_token) { flash('Заполните все поля', 'err'); return; }

  const r = await fetch('wallet_binding_api.php?action=bind_wallet', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ card_order_id: orderId, yoo_wallet, yoo_access_token })
  });
  const d = await r.json();
  if (d.ok) { flash('Кошелёк привязан', 'ok'); loadOrderWallet(orderId); }
  else flash(d.error || 'Ошибка', 'err');
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

// Запуск
loadTemplates();
</script>
</body>
</html>