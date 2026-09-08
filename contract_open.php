<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Подписание договора</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0b0e14;color:#e4e7ed;font-family:'Inter','Segoe UI',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:10px}
.container{width:100%;max-width:1200px;background:#151e26;border-radius:24px;padding:20px;box-shadow:0 12px 48px rgba(0,0,0,0.7);border:1px solid #2a3540}
h1{font-size:24px;font-weight:600;display:flex;align-items:center;gap:12px;margin-bottom:6px}
h1 span{background:#2a3a4a;padding:4px 14px;border-radius:30px;font-size:13px;font-weight:400;color:#9bb8d4}
.sub{color:#8a9aa8;font-size:14px;margin-bottom:16px;border-bottom:1px solid #24303a;padding-bottom:10px}
.code-area{display:flex;gap:10px;margin-bottom:16px}
.code-area input{flex:1;padding:12px 16px;background:#0d151d;border:1px solid #2f3d4a;border-radius:12px;color:#fff;font-size:16px;outline:none}
.code-area input:focus{border-color:#5b8cba}
.code-area button{padding:12px 24px;background:#3b6a9e;border:none;border-radius:12px;color:#fff;font-weight:600;cursor:pointer;transition:.2s;font-size:15px}
.code-area button:hover{background:#4e7db3}
.info-card{background:#0d151d;border-radius:14px;padding:14px;margin-bottom:12px;border:1px solid #24303a}
.info-card strong{color:#b0c8dd}
.timer-box{background:#1f2b33;border-radius:12px;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;margin-top:10px}
.timer{font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;color:#facc15;letter-spacing:1px}
.timer.warning{color:#ef4444}
.status-badge{padding:4px 14px;border-radius:30px;font-size:12px;font-weight:500}
.status-badge.opened{background:#1f3a2a;color:#6fcf97}
.status-badge.signed{background:#2a3a4a;color:#9bb8d4}
.status-badge.expired{background:#3a1f1f;color:#f87171}
.pdf-wrap{position:relative;border-radius:14px;overflow:hidden;background:#0a1118;border:1px solid #24303a;margin:12px 0}
.pdf-wrap canvas{display:block;width:100%;height:auto}
.overlay{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none}
.signature-area{margin:12px 0;background:#0d151d;border-radius:14px;padding:14px;border:1px solid #24303a}
.signature-area h3{font-size:15px;margin-bottom:6px;color:#b0c8dd}
.signature-canvas-wrap{background:#0a1118;border-radius:10px;border:1px solid #2a3540;touch-action:none}
.signature-canvas-wrap canvas{display:block;width:100%;height:auto;cursor:crosshair}
.controls{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
.controls button{padding:10px 20px;border:none;border-radius:12px;font-weight:600;font-size:14px;cursor:pointer;transition:.2s}
.btn-primary{background:#3b6a9e;color:#fff}
.btn-primary:hover{background:#4e7db3}
.btn-secondary{background:#2a3540;color:#b0c8dd}
.btn-secondary:hover{background:#3a4a5a}
.btn-danger{background:#7a2a2a;color:#f87171}
.btn-danger:hover{background:#9a3a3a}
.btn:disabled{opacity:0.5;pointer-events:none}
.flash{padding:12px 16px;border-radius:12px;margin:8px 0;display:none}
.flash.ok{background:#1a3a2a;color:#6fcf97;border:1px solid #2a5a3a;display:block}
.flash.err{background:#3a1a1a;color:#f87171;border:1px solid #5a2a2a;display:block}
.hidden{display:none!important}
.page-nav{display:flex;align-items:center;gap:14px;margin:8px 0}
.page-nav button{background:#1a2630;border:1px solid #2a3540;color:#b0c8dd;padding:6px 14px;border-radius:10px;cursor:pointer}
.page-nav button:hover{background:#2a3a4a}
.page-nav span{font-size:14px}
</style>
</head>
<body>
<div class="container" id="app">
  <h1>📄 Подписание договора <span id="statusBadge" class="status-badge">Ожидает</span></h1>
  <div class="sub" id="contractTitle">Загрузка...</div>
  <div id="flash" class="flash"></div>

  <div id="codeEntry" class="code-area">
    <input type="text" id="codeInput" placeholder="Введите код договора" value="">
    <button onclick="loadContract()">Открыть</button>
  </div>

  <div id="contractInfo" class="hidden">
    <div class="info-card">
      <div><strong>Код:</strong> <span id="contractCode"></span></div>
      <div><strong>Статус:</strong> <span id="contractStatus"></span></div>
      <div class="timer-box">
        <span>⏳ Осталось времени:</span>
        <span class="timer" id="timerDisplay">12:00</span>
      </div>
    </div>
  </div>

  <div id="pdfView" class="hidden">
    <div class="page-nav">
      <button onclick="prevPage()">←</button>
      <span id="pageIndicator">1 / 1</span>
      <button onclick="nextPage()">→</button>
    </div>
    <div class="pdf-wrap" id="pdfWrap">
      <canvas id="pdfCanvas"></canvas>
      <div class="overlay" id="zoneOverlay"></div>
    </div>
  </div>

  <div id="signatureAreas" class="hidden"></div>
  <div id="signedInfo" class="hidden" style="margin-top:12px;background:#1a2a3a;padding:14px;border-radius:12px;border:1px solid #2a4a5a;">
    <p style="color:#6fcf97">✅ Договор подписан</p>
    <p style="font-size:13px;color:#8a9aa8">Дата подписания: <span id="signedDate"></span></p>
  </div>

  <div id="controls" class="hidden" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
    <button class="btn-primary" id="submitBtn" onclick="submitSignatures()">Подписать и отправить</button>
    <button class="btn-secondary" onclick="clearSignatures()">Очистить всё</button>
    <button class="btn-secondary" onclick="undoLastStroke()">Отменить штрих</button>
  </div>
</div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const API = 'contract_api.php';
let contractData = null;
let pdfDoc = null;
let currentPage = 1;
let totalPages = 1;
let signatureCanvases = [];
let timerInterval = null;
let timerExpired = false;
let isSignedMode = false;

function flash(msg, type) {
  const el = document.getElementById('flash');
  el.textContent = msg;
  el.className = 'flash ' + type;
  el.style.display = 'block';
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

async function loadContract() {
  const code = document.getElementById('codeInput').value.trim();
  if (!code) { flash('Введите код договора', 'err'); return; }

  try {
    const r = await fetch(`${API}?action=get_contract_by_code&code=${encodeURIComponent(code)}`);
    const d = await r.json();
    if (!d.ok) { flash(d.error, 'err'); return; }

    contractData = d.contract;

    document.getElementById('codeEntry').classList.add('hidden');
    document.getElementById('contractInfo').classList.remove('hidden');
    document.getElementById('contractCode').textContent = contractData.code;
    document.getElementById('contractTitle').textContent = contractData.title || 'Без названия';

    const statusMap = { 'pending':'Ожидает', 'opened':'Открыт', 'signed':'Подписан' };
    document.getElementById('contractStatus').textContent = statusMap[contractData.status] || contractData.status;
    const badge = document.getElementById('statusBadge');
    badge.textContent = statusMap[contractData.status] || contractData.status;
    badge.className = 'status-badge ' + contractData.status;

    if (contractData.status === 'signed') {
      isSignedMode = true;
      document.getElementById('signedInfo').classList.remove('hidden');
      document.getElementById('signedDate').textContent = contractData.signed_at ? new Date(contractData.signed_at).toLocaleString('ru') : '—';
      document.getElementById('controls').classList.add('hidden');
      document.getElementById('signatureAreas').classList.add('hidden');
      const pdfUrl = contractData.pdf_url;
      pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
      totalPages = pdfDoc.numPages;
      currentPage = 1;
      document.getElementById('pdfView').classList.remove('hidden');
      renderPage(1, true);
      return;
    }

    if (contractData.status === 'opened') {
      isSignedMode = false;
      document.getElementById('pdfView').classList.remove('hidden');
      document.getElementById('signatureAreas').classList.remove('hidden');
      document.getElementById('controls').classList.remove('hidden');
      document.getElementById('signedInfo').classList.add('hidden');

      const pdfUrl = contractData.pdf_url;
      pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
      totalPages = pdfDoc.numPages;
      currentPage = 1;
      renderPage(1, false);
      buildSignatureAreas();
      startTimer(contractData.expires_at);
    }
  } catch (e) {
    flash('Ошибка: ' + e.message, 'err');
    console.error(e);
  }
}

function startTimer(expiresAt) {
  const end = new Date(expiresAt).getTime();
  const timerEl = document.getElementById('timerDisplay');
  if (timerInterval) clearInterval(timerInterval);
  function update() {
    const now = Date.now();
    const diff = Math.max(0, end - now);
    const minutes = Math.floor(diff / 60000);
    const seconds = Math.floor((diff % 60000) / 1000);
    timerEl.textContent = String(minutes).padStart(2,'0') + ':' + String(seconds).padStart(2,'0');
    if (diff < 60000) timerEl.classList.add('warning');
    else timerEl.classList.remove('warning');
    if (diff <= 0) {
      clearInterval(timerInterval);
      timerExpired = true;
      flash('⏰ Время истекло! Договор удалён.', 'err');
      if (contractData && contractData.id) {
        fetch(API, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete_contract', contract_id: contractData.id })
        }).then(() => {
          document.getElementById('controls').classList.add('hidden');
          document.getElementById('signatureAreas').classList.add('hidden');
          document.getElementById('pdfView').classList.add('hidden');
          document.getElementById('contractInfo').classList.add('hidden');
          document.getElementById('codeEntry').classList.remove('hidden');
          document.getElementById('statusBadge').textContent = 'Удалён';
          document.getElementById('statusBadge').className = 'status-badge expired';
          flash('Договор удалён по истечении времени.', 'err');
        });
      }
    }
  }
  update();
  timerInterval = setInterval(update, 1000);
}

async function renderPage(pageNum, signedMode) {
  const page = await pdfDoc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.4 });
  const canvas = document.getElementById('pdfCanvas');
  const ctx = canvas.getContext('2d');
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await page.render({ canvasContext: ctx, viewport }).promise;

  document.getElementById('pageIndicator').textContent = `${pageNum} / ${totalPages}`;

  const overlay = document.getElementById('zoneOverlay');
  overlay.innerHTML = '';
  const overlayCanvas = document.createElement('canvas');
  overlayCanvas.width = viewport.width;
  overlayCanvas.height = viewport.height;
  overlayCanvas.style.position = 'absolute';
  overlayCanvas.style.top = '0';
  overlayCanvas.style.left = '0';
  overlayCanvas.style.pointerEvents = 'none';
  overlay.appendChild(overlayCanvas);
  const ctxOver = overlayCanvas.getContext('2d');

  if (!signedMode) {
    drawZones(ctxOver, viewport, pageNum);
  }
  drawDate(ctxOver, viewport, pageNum, signedMode);
  if (signedMode) {
    drawSignatures(ctxOver, viewport, pageNum);
  }
}

function drawZones(ctx, viewport, pageNum) {
  const zones = [];
  if (contractData.signature_zones) {
    contractData.signature_zones.forEach((z, idx) => {
      if (z.page === pageNum) {
        zones.push({ ...z, key: 'sig' + idx, label: 'Подпись ' + (idx+1) });
      }
    });
  }
  if (contractData.date_zone) {
    const parts = ['day','month','year'];
    const labels = { day:'Число', month:'Месяц', year:'Год' };
    parts.forEach(p => {
      if (contractData.date_zone[p] && contractData.date_zone[p].page === pageNum) {
        zones.push({ ...contractData.date_zone[p], key: p, label: labels[p] });
      }
    });
  }
  zones.forEach(z => {
    const x = z.xPct / 100 * viewport.width;
    const y = z.yPct / 100 * viewport.height;
    const w = z.wPct / 100 * viewport.width;
    const h = z.hPct / 100 * viewport.height;
    ctx.strokeStyle = '#5b8cba';
    ctx.lineWidth = 2;
    ctx.setLineDash([6,4]);
    ctx.strokeRect(x, y, w, h);
    ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(59,106,158,0.12)';
    ctx.fillRect(x, y, w, h);
    ctx.fillStyle = '#b0c8dd';
    ctx.font = '10px Arial';
    ctx.fillText(z.label, x+4, y+14);
  });
}

function drawDate(ctx, viewport, pageNum, fromSigned) {
  let day, month, year;
  if (fromSigned && contractData.signed_at) {
    const d = new Date(contractData.signed_at);
    day = d.getDate();
    const monthNames = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    month = monthNames[d.getMonth()];
    year = String(d.getFullYear()).slice(-2);
  } else {
    const now = new Date();
    day = now.getDate();
    const monthNames = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    month = monthNames[now.getMonth()];
    year = String(now.getFullYear()).slice(-2);
  }

  const dateParts = contractData.date_zone || {};
  const partsMap = { day, month, year };
  for (const [key, value] of Object.entries(partsMap)) {
    if (!dateParts[key] || dateParts[key].page !== pageNum) continue;
    const zone = dateParts[key];
    const x = zone.xPct / 100 * viewport.width;
    const y = zone.yPct / 100 * viewport.height;
    const w = zone.wPct / 100 * viewport.width;
    const h = zone.hPct / 100 * viewport.height;
    ctx.font = '14px Arial, sans-serif';
    ctx.fillStyle = '#000';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(String(value), x + w/2, y + h/2);
  }
}

function drawSignatures(ctx, viewport, pageNum) {
  if (!contractData.signatures) return;
  contractData.signatures.forEach((sig, idx) => {
    const zone = contractData.signature_zones ? contractData.signature_zones[idx] : null;
    if (!zone || zone.page !== pageNum) return;
    const strokeData = sig.stroke_data;
    if (!strokeData) return;
    const zoneX = zone.xPct / 100 * viewport.width;
    const zoneY = zone.yPct / 100 * viewport.height;
    const zoneW = zone.wPct / 100 * viewport.width;
    const zoneH = zone.hPct / 100 * viewport.height;
    const scaleX = zoneW / 600;
    const scaleY = zoneH / 200;
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    strokeData.forEach(stroke => {
      if (stroke.length < 2) return;
      ctx.beginPath();
      ctx.moveTo(zoneX + stroke[0].x * scaleX, zoneY + stroke[0].y * scaleY);
      for (let i = 1; i < stroke.length; i++) {
        ctx.lineTo(zoneX + stroke[i].x * scaleX, zoneY + stroke[i].y * scaleY);
      }
      ctx.stroke();
    });
  });
}

function prevPage() {
  if (currentPage > 1) { currentPage--; renderPage(currentPage, isSignedMode); }
}
function nextPage() {
  if (currentPage < totalPages) { currentPage++; renderPage(currentPage, isSignedMode); }
}

function buildSignatureAreas() {
  const container = document.getElementById('signatureAreas');
  container.innerHTML = '';
  signatureCanvases = [];
  if (!contractData.signature_zones || contractData.signature_zones.length === 0) {
    container.innerHTML = '<p style="color:#8a9aa8">Нет зон для подписи.</p>';
    return;
  }
  contractData.signature_zones.forEach((zone, idx) => {
    const div = document.createElement('div');
    div.className = 'signature-area';
    div.innerHTML = `<h3>Подпись ${idx+1} (нарисуйте здесь)</h3>
      <div class="signature-canvas-wrap">
        <canvas id="sigCanvas${idx}" width="600" height="200"></canvas>
      </div>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn-secondary" onclick="clearSignature(${idx})">Очистить</button>
        <button class="btn-secondary" onclick="undoSignature(${idx})">Отменить штрих</button>
      </div>
    `;
    container.appendChild(div);
    const canvas = document.getElementById('sigCanvas' + idx);
    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#e4e7ed';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    const state = { canvas, ctx, drawing: false, strokes: [], currentStroke: null, zoneIndex: idx };
    signatureCanvases.push(state);

    function getPos(e) {
      const rect = canvas.getBoundingClientRect();
      const scaleX = canvas.width / rect.width;
      const scaleY = canvas.height / rect.height;
      const cx = e.touches ? e.touches[0].clientX : e.clientX;
      const cy = e.touches ? e.touches[0].clientY : e.clientY;
      return { x: (cx - rect.left) * scaleX, y: (cy - rect.top) * scaleY };
    }

    function startDraw(e) {
      e.preventDefault();
      const pos = getPos(e);
      state.drawing = true;
      state.currentStroke = [{ x: pos.x, y: pos.y, t: 0 }];
      state.strokes.push(state.currentStroke);
    }
    function draw(e) {
      if (!state.drawing) return;
      e.preventDefault();
      const pos = getPos(e);
      const last = state.currentStroke[state.currentStroke.length - 1];
      const dist = Math.hypot(pos.x - last.x, pos.y - last.y);
      if (dist < 1) return;
      state.currentStroke.push({ x: pos.x, y: pos.y, t: performance.now() });
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();
    }
    function endDraw(e) {
      if (state.drawing) {
        state.drawing = false;
        redrawCanvas(state);
      }
    }
    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove', draw, { passive: false });
    canvas.addEventListener('touchend', endDraw);
  });
}

function redrawCanvas(state) {
  const ctx = state.ctx;
  ctx.clearRect(0, 0, state.canvas.width, state.canvas.height);
  ctx.strokeStyle = '#e4e7ed';
  ctx.lineWidth = 2.5;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  state.strokes.forEach(stroke => {
    if (stroke.length < 2) return;
    ctx.beginPath();
    ctx.moveTo(stroke[0].x, stroke[0].y);
    for (let i = 1; i < stroke.length; i++) {
      ctx.lineTo(stroke[i].x, stroke[i].y);
    }
    ctx.stroke();
  });
}

function clearSignature(idx) {
  const state = signatureCanvases[idx];
  if (!state) return;
  state.strokes = [];
  redrawCanvas(state);
}

function undoSignature(idx) {
  const state = signatureCanvases[idx];
  if (!state || state.strokes.length === 0) return;
  state.strokes.pop();
  redrawCanvas(state);
}

function clearSignatures() {
  signatureCanvases.forEach((s, i) => clearSignature(i));
}

function undoLastStroke() {
  signatureCanvases.forEach((s, i) => undoSignature(i));
}

async function submitSignatures() {
  if (timerExpired) {
    flash('Время истекло, подписание невозможно', 'err');
    return;
  }
  const strokeData = {};
  let hasAny = false;
  signatureCanvases.forEach((state, idx) => {
    if (state.strokes.length > 0) {
      strokeData[idx] = state.strokes;
      hasAny = true;
    }
  });
  if (!hasAny) {
    flash('Нарисуйте хотя бы одну подпись', 'err');
    return;
  }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Отправка...';

  try {
    const r = await fetch(`${API}?action=sign_contract`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        code: contractData.code,
        stroke_data: strokeData
      })
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.error);
    flash('✅ Договор подписан!', 'ok');
    clearInterval(timerInterval);
    const r2 = await fetch(`${API}?action=get_contract_by_code&code=${encodeURIComponent(contractData.code)}`);
    const d2 = await r2.json();
    if (d2.ok) {
      contractData = d2.contract;
      isSignedMode = true;
      document.getElementById('signedInfo').classList.remove('hidden');
      document.getElementById('signedDate').textContent = contractData.signed_at ? new Date(contractData.signed_at).toLocaleString('ru') : '—';
      document.getElementById('controls').classList.add('hidden');
      document.getElementById('signatureAreas').classList.add('hidden');
      document.getElementById('statusBadge').textContent = 'Подписан';
      document.getElementById('statusBadge').className = 'status-badge signed';
      document.getElementById('contractStatus').textContent = 'Подписан';
      pdfDoc = await pdfjsLib.getDocument(contractData.pdf_url).promise;
      totalPages = pdfDoc.numPages;
      currentPage = 1;
      renderPage(1, true);
    }
  } catch (e) {
    flash('Ошибка: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Подписать и отправить';
  }
}

window.onload = function() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get('code');
  if (code) {
    document.getElementById('codeInput').value = code;
    loadContract();
  }
};
</script>
</body>
</html>