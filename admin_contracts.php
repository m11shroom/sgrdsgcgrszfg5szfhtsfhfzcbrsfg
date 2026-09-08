<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Договоры — Админ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<style>
/* Стили как в начале, без изменений */
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:960px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:960px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;margin-bottom:14px}
.btn{background:#fff;color:#080808;border:none;border-radius:11px;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;letter-spacing:.1em;padding:11px 18px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.btn:hover{background:#e0e0e0}
.btn:disabled{opacity:.35;cursor:default}
.btn-sec{background:none;border:1px solid #1e1e1e;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:7px 12px;border-radius:8px;cursor:pointer;transition:all .2s}
.btn-sec:hover{border-color:#555;color:#ccc}
.btn-sec.active{background:#fff;color:#080808;border-color:#fff}
.btn-danger{background:none;border:1px solid #2a0000;color:#a04040;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:7px 12px;border-radius:8px;cursor:pointer;transition:all .2s}
.btn-danger:hover{border-color:#c04040;color:#f87171}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
input{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none}
input:focus{border-color:#383838}
.field{margin-bottom:14px}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:11px 13px;border-radius:11px;margin-bottom:12px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:24px}
.pdf-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.zone-mode-btns{display:flex;gap:6px;flex-wrap:wrap}
.page-nav{display:flex;align-items:center;gap:8px;margin-left:auto;font-family:'Space Mono',monospace;font-size:.6rem;color:#888}
.pdf-wrap{position:relative;display:inline-block;border:1px solid #1e1e1e;border-radius:8px;overflow:hidden;background:#1a1a1a;max-width:100%}
.pdf-wrap canvas{display:block;max-width:100%;height:auto}
.zone-overlay{position:absolute;top:0;left:0;width:100%;height:100%;cursor:crosshair}
.zone-box{position:absolute;border:2px solid;border-radius:4px;pointer-events:none;display:flex;align-items:flex-start;justify-content:flex-start}
.zone-box.sig0{border-color:#4ade80;background:rgba(74,222,128,.15)}
.zone-box.sig1{border-color:#60a5fa;background:rgba(96,165,250,.15)}
.zone-box.day{border-color:#facc15;background:rgba(250,204,21,.15)}
.zone-box.month{border-color:#f97316;background:rgba(249,115,22,.15)}
.zone-box.year{border-color:#a855f7;background:rgba(168,85,247,.15)}
.zone-label{font-family:'Space Mono',monospace;font-size:.5rem;padding:2px 5px;color:#080808;font-weight:700}
.zone-box.sig0 .zone-label{background:#4ade80}
.zone-box.sig1 .zone-label{background:#60a5fa}
.zone-box.day .zone-label{background:#facc15}
.zone-box.month .zone-label{background:#f97316}
.zone-box.year .zone-label{background:#a855f7}
.contract-row{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;flex-wrap:wrap}
.contract-info{display:flex;flex-direction:column;gap:4px}
.contract-title{font-size:.84rem;color:#ccc}
.contract-meta{font-family:'Space Mono',monospace;font-size:.56rem;color:#555}
.status-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.12em;padding:4px 10px;border-radius:20px;text-transform:uppercase}
.st-pending{background:#1a1a1a;color:#888}
.st-opened{background:#1a1200;color:#facc15}
.st-signed{background:#001a08;color:#4ade80}
.code-chip{font-family:'Space Mono',monospace;font-size:.68rem;color:#a78bfa;background:#1a1030;padding:4px 10px;border-radius:8px;cursor:pointer}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:1000;align-items:center;justify-content:center;padding:10px;overflow-y:auto}
.modal.active{display:flex}
.modal-box{background:#111;border:1px solid #2a2a2a;border-radius:18px;padding:20px;max-width:95vw;width:100%;max-height:95vh;overflow-y:auto;position:relative;margin:auto}
.modal-close{position:absolute;top:10px;right:16px;background:none;border:none;color:#888;font-size:26px;cursor:pointer;z-index:5}
.modal-title{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.2em;color:#888;margin-bottom:14px}
.replay-canvas-wrap{background:#fff;border-radius:8px;overflow:hidden;margin-top:10px;max-width:100%}
.hint{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#333;line-height:1.7;margin-top:8px}
.download-btn{background:#1a3a2a;border:1px solid #2a5a3a;color:#6fcf97;padding:8px 16px;border-radius:8px;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;transition:all .2s}
.download-btn:hover{background:#2a4a3a}
.replay-btn{background:#1f3a4a;border:1px solid #3a5a6a;color:#b0c8dd;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:12px;margin-top:6px}
.replay-btn:hover{background:#2a4a5a}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET · ДОГОВОРЫ</div>
  <a href="admin.php" class="back">← Панель</a>
</div>
<div class="inner">
  <div class="pg-ttl">ЭЛЕКТРОННОЕ ПОДПИСАНИЕ ДОГОВОРОВ</div>
  <div id="flash"></div>

  <!-- Шаг 1: загрузка PDF -->
  <div class="card" id="uploadCard">
    <div class="card-title">1. ЗАГРУЗИТЬ ДОГОВОР (PDF)</div>
    <div class="field"><div class="flbl">Название (необязательно)</div><input type="text" id="pdfTitle" placeholder="Договор оказания услуг №..."></div>
    <div class="field"><div class="flbl">Файл PDF</div><input type="file" id="pdfFile" accept="application/pdf"></div>
    <button class="btn" onclick="uploadPdf()">Загрузить и разметить зоны →</button>
  </div>

  <!-- Шаг 2: разметка зон -->
  <div class="card" id="zonesCard" style="display:none">
    <div class="card-title">2. ОТМЕТЬ ЗОНЫ НА ДОГОВОРЕ</div>
    <div class="hint">Выбери режим, затем зажми и растяни прямоугольник на странице.</div>
    <div class="pdf-toolbar" style="margin-top:12px">
      <div class="zone-mode-btns">
        <button class="btn-sec" id="modeSig0" onclick="setZoneMode('sig0')">🖊 Подпись 1</button>
        <button class="btn-sec" id="modeSig1" onclick="setZoneMode('sig1')">🖊 Подпись 2</button>
        <button class="btn-sec" id="modeDay" onclick="setZoneMode('day')">📅 Число</button>
        <button class="btn-sec" id="modeMonth" onclick="setZoneMode('month')">📅 Месяц</button>
        <button class="btn-sec" id="modeYear" onclick="setZoneMode('year')">📅 Год</button>
      </div>
      <div class="page-nav">
        <button class="btn-sec" onclick="prevPage()">←</button>
        <span id="pageIndicator">1 / 1</span>
        <button class="btn-sec" onclick="nextPage()">→</button>
      </div>
    </div>
    <div class="pdf-wrap" id="pdfWrap">
      <canvas id="pdfCanvas"></canvas>
      <div class="zone-overlay" id="zoneOverlay"></div>
    </div>
    <div class="field" style="margin-top:16px"><div class="flbl">Код для подписания (пусто – сгенерируется)</div><input type="text" id="customCode" placeholder="Например: DOGOVOR-2026"></div>
    <button class="btn" onclick="saveZones()">Сохранить зоны</button>
  </div>

  <!-- Список договоров -->
  <div class="card">
    <div class="card-title">ВСЕ ДОГОВОРЫ</div>
    <div id="contractsList"><div class="empty">Загрузка...</div></div>
  </div>
</div>

<!-- Модалка просмотра подписанного договора -->
<div class="modal" id="viewModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeViewModal()">✕</button>
    <div class="modal-title">ПРОСМОТР ДОГОВОРА</div>
    <div id="viewContent"></div>
  </div>
</div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const api = 'admin_contracts_api.php';
let currentContractId = null;
let currentPdfDoc = null;
let currentPageNum = 1;
let totalPages = 1;
let zones = { sig0: null, sig1: null, day: null, month: null, year: null };
let activeMode = null;

function flash(msg, type) {
  document.getElementById('flash').innerHTML = `<div class="flash ${type}">${msg}</div>`;
  setTimeout(() => document.getElementById('flash').innerHTML = '', 4000);
}

// ----- ЗАГРУЗКА PDF -----
async function uploadPdf() {
  const fileInput = document.getElementById('pdfFile');
  if (!fileInput.files[0]) { flash('Выберите PDF файл', 'err'); return; }
  try {
    const fd = new FormData();
    fd.append('pdf', fileInput.files[0]);
    fd.append('title', document.getElementById('pdfTitle').value);
    const r = await fetch(`${api}?action=upload_pdf`, { method: 'POST', body: fd });
    const contentType = r.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      const text = await r.text();
      flash('Сервер вернул не JSON: ' + text.substring(0, 200), 'err');
      return;
    }
    const d = await r.json();
    if (!d.ok) { flash(d.error, 'err'); return; }
    currentContractId = d.contract_id;
    const pdfUrl = `get_pdf.php?file=${d.file}`;
    currentPdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
    totalPages = currentPdfDoc.numPages;
    currentPageNum = 1;
    zones = { sig0: null, sig1: null, day: null, month: null, year: null };
    document.getElementById('zonesCard').style.display = 'block';
    document.getElementById('uploadCard').style.display = 'none';
    renderPage(1);
  } catch (e) {
    flash('Ошибка: ' + e.message, 'err');
    console.error(e);
  }
}

// ----- РЕНДЕР СТРАНИЦЫ С ЗОНАМИ (старый, работающий) -----
async function renderPage(num) {
  try {
    const page = await currentPdfDoc.getPage(num);
    const viewport = page.getViewport({ scale: 1.4 });
    const canvas = document.getElementById('pdfCanvas');
    const ctx = canvas.getContext('2d');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({ canvasContext: ctx, viewport }).promise;
    document.getElementById('pageIndicator').textContent = `${num} / ${totalPages}`;
    redrawZoneBoxes();
  } catch (e) {
    flash('Ошибка рендеринга: ' + e.message, 'err');
  }
}

function redrawZoneBoxes() {
  const overlay = document.getElementById('zoneOverlay');
  overlay.innerHTML = '';
  const labels = { sig0: 'Подпись 1', sig1: 'Подпись 2', day: 'Число', month: 'Месяц', year: 'Год' };
  for (const key of ['sig0', 'sig1', 'day', 'month', 'year']) {
    const z = zones[key];
    if (!z || z.page !== currentPageNum) continue;
    const box = document.createElement('div');
    box.className = 'zone-box ' + key;
    box.style.left = z.xPct + '%';
    box.style.top = z.yPct + '%';
    box.style.width = z.wPct + '%';
    box.style.height = z.hPct + '%';
    box.innerHTML = `<span class="zone-label">${labels[key]}</span>`;
    overlay.appendChild(box);
  }
}

function prevPage() { if (currentPageNum > 1) { currentPageNum--; renderPage(currentPageNum); } }
function nextPage() { if (currentPageNum < totalPages) { currentPageNum++; renderPage(currentPageNum); } }

function setZoneMode(mode) {
  activeMode = mode;
  ['sig0','sig1','day','month','year'].forEach(m => {
    const id = 'mode' + (m.charAt(0).toUpperCase() + m.slice(1));
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const btnId = 'mode' + (mode.charAt(0).toUpperCase() + mode.slice(1));
  const el = document.getElementById(btnId);
  if (el) el.classList.add('active');
}

// ----- РИСОВАНИЕ ЗОН (мышь/тач) -----
(function initDrawing() {
  const overlay = document.getElementById('zoneOverlay');
  let drawing = false, startX = 0, startY = 0, tempBox = null;
  function getPos(e) {
    const rect = overlay.getBoundingClientRect();
    const cx = e.touches ? e.touches[0].clientX : e.clientX;
    const cy = e.touches ? e.touches[0].clientY : e.clientY;
    return { x: cx - rect.left, y: cy - rect.top };
  }
  function start(e) {
    if (!activeMode) { flash('Выберите режим', 'err'); return; }
    e.preventDefault();
    const pos = getPos(e);
    drawing = true; startX = pos.x; startY = pos.y;
    tempBox = document.createElement('div');
    tempBox.className = 'zone-box ' + activeMode;
    tempBox.style.left = pos.x + 'px';
    tempBox.style.top = pos.y + 'px';
    overlay.appendChild(tempBox);
  }
  function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const pos = getPos(e);
    const x = Math.min(pos.x, startX), y = Math.min(pos.y, startY);
    const w = Math.abs(pos.x - startX), h = Math.abs(pos.y - startY);
    tempBox.style.left = x + 'px'; tempBox.style.top = y + 'px';
    tempBox.style.width = w + 'px'; tempBox.style.height = h + 'px';
  }
  function end(e) {
    if (!drawing) return;
    drawing = false;
    const rect = overlay.getBoundingClientRect();
    const boxRect = tempBox.getBoundingClientRect();
    const xPct = ((boxRect.left - rect.left) / rect.width) * 100;
    const yPct = ((boxRect.top - rect.top) / rect.height) * 100;
    const wPct = (boxRect.width / rect.width) * 100;
    const hPct = (boxRect.height / rect.height) * 100;
    if (wPct < 2 || hPct < 1) { tempBox.remove(); return; }
    zones[activeMode] = { page: currentPageNum, xPct, yPct, wPct, hPct };
    tempBox.remove();
    redrawZoneBoxes();
  }
  overlay.addEventListener('mousedown', start);
  overlay.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  overlay.addEventListener('touchstart', start, { passive: false });
  overlay.addEventListener('touchmove', move, { passive: false });
  overlay.addEventListener('touchend', end);
})();

// ----- СОХРАНЕНИЕ ЗОН -----
async function saveZones() {
  const sigZones = [];
  if (zones.sig0) sigZones.push(zones.sig0);
  if (zones.sig1) sigZones.push(zones.sig1);
  if (sigZones.length < 1) { flash('Отметьте хотя бы одну зону подписи', 'err'); return; }

  const dateParts = {};
  if (zones.day) dateParts.day = zones.day;
  if (zones.month) dateParts.month = zones.month;
  if (zones.year) dateParts.year = zones.year;

  const r = await fetch(`${api}?action=save_zones`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      contract_id: currentContractId,
      signature_zones: sigZones,
      date_parts: dateParts,
      code: document.getElementById('customCode').value.trim()
    })
  });
  const d = await r.json();
  if (!d.ok) { flash(d.error, 'err'); return; }
  flash(`✅ Готово. Код: ${d.code}`, 'ok');
  document.getElementById('zonesCard').style.display = 'none';
  document.getElementById('uploadCard').style.display = 'block';
  document.getElementById('pdfFile').value = '';
  document.getElementById('pdfTitle').value = '';
  document.getElementById('customCode').value = '';
  loadContracts();
}

// ----- СПИСОК ДОГОВОРОВ -----
async function loadContracts() {
  const r = await fetch(`${api}?action=list_contracts`);
  const d = await r.json();
  const list = document.getElementById('contractsList');
  if (!d.ok || !d.contracts.length) { list.innerHTML = '<div class="empty">Договоров пока нет</div>'; return; }
  const statusLabels = { pending: 'Ожидает', opened: 'Открыт', signed: 'Подписан' };
  list.innerHTML = d.contracts.map(c => `
    <div class="contract-row">
      <div class="contract-info">
        <div class="contract-title">${c.title || ('Договор #' + c.id)}</div>
        <div class="contract-meta">Создан: ${new Date(c.created_at).toLocaleString('ru')}</div>
        ${c.code ? `<div class="code-chip" onclick="copyCode('${c.code}')">${c.code} 📋</div>` : '<div class="hint">Код не назначен</div>'}
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="status-badge st-${c.status}">${statusLabels[c.status] || c.status}</span>
        ${c.status === 'signed' ? `<button class="btn-sec" onclick="viewContract(${c.id})">Просмотр</button>` : ''}
        <button class="btn-danger" onclick="deleteContract(${c.id})">Удалить</button>
      </div>
    </div>
  `).join('');
}

function copyCode(code) {
  navigator.clipboard?.writeText(code);
  flash('Код скопирован: ' + code, 'ok');
}

// ----- УДАЛЕНИЕ -----
async function deleteContract(id) {
  if (!confirm('Удалить договор безвозвратно?')) return;
  try {
    const r = await fetch(`${api}?action=delete_contract`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contract_id: id })
    });
    const d = await r.json();
    if (d.ok) { flash('Удалено', 'ok'); loadContracts(); } else flash(d.error, 'err');
  } catch (e) { flash('Ошибка: ' + e.message, 'err'); }
}

// ======================== ПРОСМОТР ПОДПИСАННОГО ========================
async function viewContract(id) {
  const r = await fetch(`${api}?action=get_contract&id=${id}`);
  const d = await r.json();
  if (!d.ok) { flash(d.error, 'err'); return; }
  const c = d.contract;

  let html = `<div class="hint" style="margin-bottom:14px">
    Подписан: ${c.signed_at ? new Date(c.signed_at).toLocaleString('ru') : '—'}<br>
    Хеш оригинала: ${c.original_hash}
    ${c.signed_pdf ? `<br><button class="download-btn" onclick="downloadSignedPdf('${c.signed_pdf}', '${c.code}')">⬇ Скачать подписанный PDF</button>` : ''}
  </div>`;

  html += `
    <div class="pdf-wrap" style="position:relative;border:1px solid #1e1e1e;border-radius:8px;overflow:hidden;background:#fff;">
      <canvas id="viewPdfCanvas" style="display:block;width:100%;height:auto;"></canvas>
      <div id="viewOverlay" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;"></div>
    </div>
    <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn-sec" onclick="viewPdfPrevPage()">←</button>
      <span id="viewPageIndicator">1 / 1</span>
      <button class="btn-sec" onclick="viewPdfNextPage()">→</button>
    </div>
  `;

  // Блок воспроизведения
  if (c.signatures && c.signatures.length > 0) {
    html += `<div style="margin-top:16px;padding-top:12px;border-top:1px solid #1e1e1e;"><div class="flbl">Воспроизведение подписей (для экспертизы)</div>`;
    c.signatures.forEach((sig, idx) => {
      const strokeDataJson = JSON.stringify(sig.stroke_data || []);
      html += `
        <div style="margin-top:10px;">
          <div class="flbl">Подпись ${idx+1}</div>
          <div class="replay-canvas-wrap"><canvas id="replayCanvas${idx}" width="400" height="150"></canvas></div>
          <button class="replay-btn" onclick='replaySignature(${idx}, ${strokeDataJson})'>▶ Воспроизвести подпись</button>
        </div>
      `;
    });
    html += `</div>`;
  } else {
    html += `<div style="margin-top:16px;color:#666;font-size:12px;">Нет сохранённых подписей для воспроизведения.</div>`;
  }

  document.getElementById('viewContent').innerHTML = html;
  document.getElementById('viewModal').classList.add('active');

  window._viewContractData = c;
  window._viewCurrentPage = 1;
  renderViewPdf(1);
}

// ----- ОТРИСОВКА PDF В МОДАЛКЕ (старый, рабочий метод с отдельными canvas для подписей и даты) -----
async function renderViewPdf(pageNum) {
  const data = window._viewContractData;
  if (!data) return;
  const pdfUrl = data.pdf_url;
  const pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
  const page = await pdfDoc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.4 });
  const canvas = document.getElementById('viewPdfCanvas');
  const ctx = canvas.getContext('2d');
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await page.render({ canvasContext: ctx, viewport }).promise;

  const overlay = document.getElementById('viewOverlay');
  overlay.innerHTML = '';
  overlay.style.width = viewport.width + 'px';
  overlay.style.height = viewport.height + 'px';

  // --- ПОДПИСИ (отдельные canvas) ---
  if (data.signatures && data.signature_zones) {
    data.signatures.forEach((sig, idx) => {
      const zone = data.signature_zones[idx] || null;
      if (!zone || zone.page != pageNum) return;
      const strokeData = sig.stroke_data;
      if (!strokeData || !Array.isArray(strokeData)) return;
      const canvasOverlay = document.createElement('canvas');
      canvasOverlay.width = viewport.width;
      canvasOverlay.height = viewport.height;
      canvasOverlay.style.position = 'absolute';
      canvasOverlay.style.top = '0';
      canvasOverlay.style.left = '0';
      canvasOverlay.style.pointerEvents = 'none';
      overlay.appendChild(canvasOverlay);
      const ctxOver = canvasOverlay.getContext('2d');
      ctxOver.strokeStyle = '#000';
      ctxOver.lineWidth = 2.5;
      ctxOver.lineCap = 'round';
      ctxOver.lineJoin = 'round';
      const zoneX = zone.xPct / 100 * viewport.width;
      const zoneY = zone.yPct / 100 * viewport.height;
      const zoneW = zone.wPct / 100 * viewport.width;
      const zoneH = zone.hPct / 100 * viewport.height;
      const scaleX = zoneW / 600;
      const scaleY = zoneH / 200;
      strokeData.forEach(stroke => {
        if (stroke.length < 2) return;
        ctxOver.beginPath();
        ctxOver.moveTo(zoneX + stroke[0].x * scaleX, zoneY + stroke[0].y * scaleY);
        for (let i = 1; i < stroke.length; i++) {
          ctxOver.lineTo(zoneX + stroke[i].x * scaleX, zoneY + stroke[i].y * scaleY);
        }
        ctxOver.stroke();
      });
    });
  }

  // --- ДАТА (отдельные canvas для каждой части) ---
  const dateParts = data.date_zone;
  if (dateParts && data.signed_at) {
    const signedDate = new Date(data.signed_at);
    const day = signedDate.getDate();
    const monthNames = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    const month = monthNames[signedDate.getMonth()];
    const year = String(signedDate.getFullYear()).slice(-2);
    const partsMap = { day: day, month: month, year: year };
    for (const [key, value] of Object.entries(partsMap)) {
      if (!dateParts[key] || dateParts[key].page != pageNum) continue;
      const zone = dateParts[key];
      const x = zone.xPct / 100 * viewport.width;
      const y = zone.yPct / 100 * viewport.height;
      const w = zone.wPct / 100 * viewport.width;
      const h = zone.hPct / 100 * viewport.height;
      const textCanvas = document.createElement('canvas');
      textCanvas.width = viewport.width;
      textCanvas.height = viewport.height;
      textCanvas.style.position = 'absolute';
      textCanvas.style.top = '0';
      textCanvas.style.left = '0';
      textCanvas.style.pointerEvents = 'none';
      overlay.appendChild(textCanvas);
      const ctxText = textCanvas.getContext('2d');
      ctxText.font = 'bold 18px Arial, sans-serif';
      ctxText.fillStyle = '#000';
      ctxText.textAlign = 'center';
      ctxText.textBaseline = 'middle';
      ctxText.fillText(String(value), x + w/2, y + h/2);
    }
  }

  document.getElementById('viewPageIndicator').textContent = `${pageNum} / ${pdfDoc.numPages}`;
  window._viewPdfDoc = pdfDoc;
  window._viewCurrentPage = pageNum;
}

function viewPdfPrevPage() {
  if (window._viewCurrentPage > 1) {
    renderViewPdf(window._viewCurrentPage - 1);
  }
}
function viewPdfNextPage() {
  if (window._viewCurrentPage < window._viewPdfDoc.numPages) {
    renderViewPdf(window._viewCurrentPage + 1);
  }
}

// ----- ВОСПРОИЗВЕДЕНИЕ ПОДПИСИ -----
function replaySignature(index, strokeData) {
  const canvas = document.getElementById('replayCanvas' + index);
  if (!canvas) {
    console.error('Canvas not found for index', index);
    return;
  }
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = '#111';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';

  if (!strokeData || !Array.isArray(strokeData) || strokeData.length === 0) {
    ctx.fillStyle = '#999';
    ctx.font = '14px Arial';
    ctx.textAlign = 'center';
    ctx.fillText('Нет данных для воспроизведения', canvas.width/2, canvas.height/2);
    return;
  }

  let strokeIdx = 0;
  const startTime = performance.now();

  function step() {
    if (strokeIdx >= strokeData.length) return;
    const stroke = strokeData[strokeIdx];
    if (!stroke || stroke.length < 2) { strokeIdx++; requestAnimationFrame(step); return; }

    const elapsed = performance.now() - startTime;
    const scaleX = canvas.width / 600;
    const scaleY = canvas.height / 200;

    ctx.beginPath();
    ctx.moveTo(stroke[0].x * scaleX, stroke[0].y * scaleY);
    for (let i = 1; i < stroke.length; i++) {
      if (stroke[i].t <= elapsed) {
        ctx.lineTo(stroke[i].x * scaleX, stroke[i].y * scaleY);
      } else {
        break;
      }
    }
    ctx.stroke();

    const lastTime = stroke[stroke.length - 1].t;
    if (elapsed >= lastTime) {
      strokeIdx++;
      requestAnimationFrame(step);
    } else {
      requestAnimationFrame(step);
    }
  }
  requestAnimationFrame(step);
}

// ----- СКАЧИВАНИЕ -----
function downloadSignedPdf(base64Data, code) {
  if (!base64Data) {
    flash('Нет подписанного PDF для скачивания', 'err');
    return;
  }
  const link = document.createElement('a');
  link.href = 'data:application/pdf;base64,' + base64Data;
  link.download = `договор_${code}_подписанный.pdf`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function closeViewModal() {
  document.getElementById('viewModal').classList.remove('active');
}

// ----- СТАРТ -----
loadContracts();
</script>
</body>
</html>