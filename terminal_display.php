<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>M1plus wallet — Терминал</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;overflow:hidden}
.screen{width:100vw;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .2s ease;position:relative}
.screen.hidden{display:none}

.idle-main{text-align:center}
.idle-title{font-family:'DM Sans',sans-serif;font-weight:700;font-size:3rem;color:#ffffff;letter-spacing:-0.02em;margin-bottom:0.75rem}
.idle-org{font-family:'DM Sans',sans-serif;font-weight:400;font-size:1rem;color:#888888;max-width:80vw;word-break:break-word}

.pay-header{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.3em;color:#444;margin-bottom:32px}
.pay-amount{font-family:'Space Mono',monospace;font-size:3.6rem;font-weight:500;color:#f0f0f0;margin-bottom:8px;text-align:center}
.pay-currency{font-size:1.2rem;color:#888}
.pay-org{font-size:.9rem;color:#444;margin-bottom:40px;text-align:center;max-width:80vw;word-break:break-word}
.qr-container{background:#fff;border-radius:20px;padding:18px;margin-bottom:32px;box-shadow:0 8px 20px rgba(0,0,0,0.3)}
.pay-hint{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.18em;color:#2a2a2a}
.pay-hint-bt{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.18em;color:#2a5a2a;margin-top:6px;display:flex;align-items:center;gap:6px}

.result-icon{font-size:4rem;margin-bottom:20px;text-align:center}
.result-title{font-family:'Space Mono',monospace;font-size:.8rem;letter-spacing:.3em;color:#f87171;margin-bottom:8px;text-align:center}
.result-sub{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.18em;color:#444;text-align:center}

.paid-container{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center}
.paid-emoji{font-size:5rem;animation:pop 0.4s ease-out;filter:drop-shadow(0 0 8px #4ade80);margin-bottom:0.25rem}
@keyframes pop{0%{transform:scale(0.5);opacity:0}80%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
.paid-title{font-family:'Space Mono',monospace;font-size:.9rem;letter-spacing:.3em;color:#4ade80;margin-top:12px;margin-bottom:8px;text-align:center}
.paid-amt{font-family:'Space Mono',monospace;font-size:2rem;color:#f0f0f0;text-align:center}
.paid-method{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#2a5a2a;margin-top:8px;text-align:center}

.spark{position:absolute;width:4px;height:12px;background:linear-gradient(135deg,#4ade80,#22c55e);border-radius:2px;opacity:0;pointer-events:none;z-index:20;box-shadow:0 0 4px #4ade80}
.spark-animate{animation:sparkFly 1.2s ease-out forwards}
@keyframes sparkFly{0%{opacity:1;transform:translate(0,0) scale(1)}100%{opacity:0;transform:translate(var(--dx),var(--dy)) scale(0.3)}}

/* Bluetooth indicator */
.bt-indicator{position:fixed;top:16px;right:16px;background:#0a0a0a;border:1px solid #1a1a1a;border-radius:10px;color:#333;font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.18em;padding:8px 14px;display:flex;align-items:center;gap:8px;z-index:999;transition:all .3s;user-select:none;cursor:default}
.bt-indicator.ready{border-color:#1a3a1a;color:#4ade80}
.bt-indicator.advertising{border-color:#1a3a1a;color:#4ade80}
.bt-indicator.off{border-color:#1a1a1a;color:#252525}
.bt-led{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.bt-led.pulse{animation:btpulse 2s ease-in-out infinite}
@keyframes btpulse{0%,100%{opacity:.3}50%{opacity:1}}

/* BT permission modal */
.bt-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:800;display:flex;align-items:center;justify-content:center;padding:24px;backdrop-filter:blur(6px)}
.bt-modal{background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:28px 24px;max-width:320px;width:100%;display:flex;flex-direction:column;gap:16px;align-items:center;text-align:center}
.bt-modal-icon{width:56px;height:56px;border-radius:50%;background:#0a1a0a;border:1px solid #1a3a1a;display:flex;align-items:center;justify-content:center}
.bt-modal-title{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.22em;color:#888}
.bt-modal-desc{font-size:.82rem;color:#555;line-height:1.6;max-width:260px}
.bt-modal-btn{background:#4ade80;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.78rem;font-weight:500;letter-spacing:.1em;padding:11px 28px;cursor:pointer;width:100%;transition:background .2s}
.bt-modal-btn:hover{background:#22c55e}
.bt-modal-skip{background:none;border:1px solid #1e1e1e;border-radius:12px;color:#333;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:9px 16px;cursor:pointer;width:100%;transition:all .2s}
.bt-modal-skip:hover{border-color:#333;color:#777}
@keyframes pulse3{0%,100%{opacity:.2}50%{opacity:1}}
</style>
</head>
<body>

<?php
session_start();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
?>

<!-- Bluetooth permission modal -->
<div class="bt-modal-overlay" id="btPermModal">
  <div class="bt-modal">
    <div class="bt-modal-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="6.5 6.5 17.5 17.5 12 23 12 1 17.5 6.5 6.5 17.5"/>
      </svg>
    </div>
    <div class="bt-modal-title">BLUETOOTH ОПЛАТА</div>
    <div class="bt-modal-desc">
      Разрешите терминалу использовать Bluetooth, чтобы клиенты могли оплачивать без сканирования QR.
    </div>
    <button class="bt-modal-btn" onclick="btRequestPermission()">Разрешить Bluetooth</button>
    <button class="bt-modal-skip" onclick="btSkipPermission()">Пропустить, только QR</button>
  </div>
</div>

<!-- BT indicator (fixed top-right) -->
<div class="bt-indicator off" id="btIndicator">
  <div class="bt-led" id="btLed"></div>
  <span id="btIndTxt">BT OFF</span>
</div>

<div class="screen" id="screenIdle">
  <div class="idle-main">
    <div class="idle-title">M1plus wallet</div>
    <div class="idle-org" id="idleOrg">Загрузка...</div>
  </div>
</div>

<div class="screen hidden" id="screenPay">
  <div class="pay-header">ЗАПРОС ОПЛАТЫ</div>
  <div class="pay-amount"><span id="payAmt">0</span> <span class="pay-currency">₽</span></div>
  <div class="pay-org" id="payOrg"></div>
  <div class="qr-container"><div id="payQr"></div></div>
  <div class="pay-hint">НАВЕДИТЕ КАМЕРУ ДЛЯ ОПЛАТЫ</div>
  <div class="pay-hint-bt" id="payBtHint" style="display:none">
    <svg width="11" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6.5 6.5 17.5 17.5 12 23 12 1 17.5 6.5 6.5 17.5"/></svg>
    ДОСТУПНА BT-ОПЛАТА
  </div>
</div>

<div class="screen hidden" id="screenPaid">
  <div class="paid-container" id="paidContainer">
    <div class="paid-emoji">✌️</div>
    <div class="paid-title">УСПЕШНО ОПЛАЧЕНО</div>
    <div class="paid-amt" id="paidAmt"></div>
    <div class="paid-method" id="paidMethod"></div>
  </div>
</div>

<div class="screen hidden" id="screenDeclined">
  <div class="result-icon">✗</div>
  <div class="result-title">ОТКЛОНЕНО</div>
  <div class="result-sub">ВОЗВРАТ К ГЛАВНОМУ ЭКРАНУ...</div>
</div>

<script>
var TOKEN = <?php echo json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var lastStatus = null, lastAmount = null, qrAmount = null, pollInterval = null;
var processedPaymentKey = null, returnToIdleTimer = null, activeSparks = [];

// ── BLUETOOTH VARS ──────────────────────────────────────────────────────────
var BT_LS_KEY     = 'm1plus_bt_pay';   // ключ в localStorage — клиент читает отсюда
var btEnabled     = false;
var btAdvertising = false;
var currentOrgName = '';
var currentAmount  = null;

// ── SOUND ──────────────────────────────────────────────────────────────────
function playSuccessSound() {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var now = ctx.currentTime;
        var gain = ctx.createGain();
        gain.gain.setValueAtTime(0.25, now);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.8);
        gain.connect(ctx.destination);
        var osc1 = ctx.createOscillator(); osc1.frequency.value = 880; osc1.type = 'sine';
        osc1.connect(gain); osc1.start(); osc1.stop(now + 0.25);
        var osc2 = ctx.createOscillator(); osc2.frequency.value = 659.25; osc2.type = 'sine';
        osc2.connect(gain); osc2.start(now + 0.12); osc2.stop(now + 0.4);
        if (ctx.state === 'suspended') ctx.resume();
    } catch(e) {}
}

// ── SCREENS ────────────────────────────────────────────────────────────────
function showScreen(id) {
    ['screenIdle','screenPay','screenPaid','screenDeclined'].forEach(s =>
        document.getElementById(s).classList.toggle('hidden', s !== id)
    );
}

// ── SPARKS ─────────────────────────────────────────────────────────────────
function clearSparks() { activeSparks.forEach(s => s.remove()); activeSparks = []; }

function createSparks(cx, cy) {
    clearSparks();
    var container = document.getElementById('paidContainer');
    if (!container) return;
    for (var i = 0; i < 36; i++) {
        var s = document.createElement('div'); s.classList.add('spark');
        var angle = Math.random() * Math.PI * 2;
        var dist  = 80 + Math.random() * 200;
        s.style.setProperty('--dx', (Math.cos(angle) * dist) + 'px');
        s.style.setProperty('--dy', (Math.sin(angle) * dist) + 'px');
        s.style.transform = 'rotate(' + (Math.random()*360) + 'deg)';
        s.style.width  = (3 + Math.random()*4) + 'px';
        s.style.height = (8 + Math.random()*12) + 'px';
        s.style.left = cx + 'px'; s.style.top = cy + 'px';
        s.style.position = 'absolute';
        s.classList.add('spark-animate');
        container.appendChild(s); activeSparks.push(s);
        s.addEventListener('animationend', () => { s.remove(); activeSparks = activeSparks.filter(sp => sp !== s); });
    }
}

function triggerPaidAnimation(method) {
    var emoji = document.querySelector('#screenPaid .paid-emoji');
    if (emoji) {
        var rect = emoji.getBoundingClientRect();
        setTimeout(() => createSparks(rect.left + rect.width/2, rect.top + rect.height/2), 150);
    }
    var methodEl = document.getElementById('paidMethod');
    if (methodEl) methodEl.textContent = method === 'bt' ? '📡 VIA BLUETOOTH' : '■ VIA QR CODE';
    playSuccessSound();
}

// ── IDLE RESET ─────────────────────────────────────────────────────────────
function resetToIdle(keepFlag) {
    if (returnToIdleTimer) clearTimeout(returnToIdleTimer);
    if (!keepFlag) processedPaymentKey = null;
    lastStatus = 'waiting'; lastAmount = null; qrAmount = null;
    clearSparks();
    // Очищаем BT данные при возврате в ожидание
    btClearAdvertising();
    showScreen('screenIdle');
}

// ── BLUETOOTH LOGIC ────────────────────────────────────────────────────────

// Показываем модал с запросом разрешения при старте
function btShowPermModal() {
    if (!navigator.bluetooth) {
        // Браузер не поддерживает — скрываем модал, показываем "OFF"
        document.getElementById('btPermModal').style.display = 'none';
        btSetIndicator('off', 'BT N/A');
        return;
    }
    document.getElementById('btPermModal').style.display = 'flex';
}

// Пользователь нажал "Разрешить Bluetooth"
async function btRequestPermission() {
    document.getElementById('btPermModal').style.display = 'none';
    btEnabled = true;
    btSetIndicator('ready', 'BT READY');

    // Пробуем проверить доступность BT
    try {
        if (navigator.bluetooth.getAvailability) {
            var avail = await navigator.bluetooth.getAvailability();
            if (!avail) {
                btSetIndicator('off', 'BT ВЫКЛ');
                return;
            }
        }
        btSetIndicator('ready', 'BT ГОТОВ');
    } catch(e) {
        btSetIndicator('ready', 'BT ГОТОВ');
    }

    // Если уже есть активная сессия — сразу начинаем advertise
    if (currentAmount && currentOrgName) {
        btStartAdvertising(currentOrgName, currentAmount, TOKEN);
    }
}

// Пользователь нажал "Пропустить"
function btSkipPermission() {
    document.getElementById('btPermModal').style.display = 'none';
    btEnabled = false;
    btSetIndicator('off', 'BT ПРОПУЩЕН');
}

// Обновляем индикатор BT
function btSetIndicator(state, text) {
    var ind = document.getElementById('btIndicator');
    var led = document.getElementById('btLed');
    var txt = document.getElementById('btIndTxt');
    if (!ind) return;
    ind.className = 'bt-indicator ' + state;
    txt.textContent = text;
    if (state === 'advertising') { led.classList.add('pulse'); }
    else { led.classList.remove('pulse'); }
}

// Публикуем данные о платеже через localStorage + BroadcastChannel
// (клиент на том же устройстве/браузере мгновенно это получит)
function btStartAdvertising(orgName, amount, token) {
    if (!btEnabled) return;
    currentOrgName = orgName;
    currentAmount  = amount;
    btAdvertising  = true;

    var payload = { org: orgName, amount: amount, token: token, ts: Date.now() };

    // localStorage — для клиента на том же устройстве
    try { localStorage.setItem(BT_LS_KEY, JSON.stringify(payload)); } catch(e) {}

    // BroadcastChannel — мгновенная доставка в другие вкладки того же origin
    try {
        if (window._btChannel) window._btChannel.close();
        window._btChannel = new BroadcastChannel('m1plus_bt');
        window._btChannel.postMessage(payload);
    } catch(e) {}

    // Показываем подсказку на экране оплаты
    var hint = document.getElementById('payBtHint');
    if (hint) hint.style.display = 'flex';

    btSetIndicator('advertising', 'BT ACTIVE');
}

// Очищаем advertising при сбросе
function btClearAdvertising() {
    if (!btEnabled) return;
    btAdvertising = false;
    try { localStorage.removeItem(BT_LS_KEY); } catch(e) {}
    try { if (window._btChannel) { window._btChannel.postMessage({cleared:true}); } } catch(e) {}
    var hint = document.getElementById('payBtHint');
    if (hint) hint.style.display = 'none';
    btSetIndicator('ready', 'BT ГОТОВ');
}

// Обновляем BT данные при обновлении суммы
function btUpdatePayload(orgName, amount, token) {
    if (!btEnabled || !btAdvertising) return;
    var payload = { org: orgName, amount: amount, token: token, ts: Date.now() };
    try { localStorage.setItem(BT_LS_KEY, JSON.stringify(payload)); } catch(e) {}
    try { if (window._btChannel) window._btChannel.postMessage(payload); } catch(e) {}
}

// ── MAIN UI LOGIC ──────────────────────────────────────────────────────────
function updateUI(s) {
    if (!s) return;
    document.getElementById('idleOrg').textContent = s.org_name;
    document.getElementById('payOrg').textContent  = s.org_name;
    currentOrgName = s.org_name;

    var currentKey = s.status + '|' + s.amount;
    if (processedPaymentKey === currentKey && (s.status === 'paid' || s.status === 'declined')) return;
    if (s.status === 'pending' && processedPaymentKey !== null && !processedPaymentKey.startsWith('pending|' + s.amount)) processedPaymentKey = null;
    if (s.status === 'waiting') processedPaymentKey = null;
    if (s.status === lastStatus && s.amount === lastAmount && processedPaymentKey !== null) return;

    lastStatus = s.status;
    lastAmount = s.amount;

    if (s.status === 'waiting') {
        if (returnToIdleTimer) clearTimeout(returnToIdleTimer);
        showScreen('screenIdle');
        qrAmount = null; clearSparks();
        btClearAdvertising();
    }
    else if (s.status === 'pending') {
        showScreen('screenPay');
        var amtNum = parseFloat(s.amount);
        document.getElementById('payAmt').textContent = isNaN(amtNum) ? '0' : amtNum.toLocaleString('ru-RU');

        if (qrAmount !== s.amount && TOKEN) {
            qrAmount = s.amount;
            var qrDiv = document.getElementById('payQr'); qrDiv.innerHTML = '';
            var currentPath = window.location.pathname;
            var scanPath = currentPath.replace(/terminal_display\.php/i, 'terminal_scan.php');
            if (scanPath === currentPath) {
                var baseUrl = window.location.origin + currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
                scanPath = baseUrl + 'terminal_scan.php';
            }
            var scanUrl = window.location.origin + scanPath + '?token=' + encodeURIComponent(TOKEN);
            try {
                new QRCode(qrDiv, { text: scanUrl, width: 200, height: 200, colorDark: '#080808', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
            } catch(e) { qrDiv.innerHTML = '<div style="color:#000;font-size:12px;">QR error</div>'; }
        }

        // Запускаем BT advertising
        btStartAdvertising(s.org_name, s.amount, TOKEN);
    }
    else if (s.status === 'paid') {
        processedPaymentKey = currentKey;
        document.getElementById('paidAmt').textContent = parseFloat(s.amount).toLocaleString('ru-RU') + ' ₽';
        showScreen('screenPaid');
        // Определяем метод оплаты — если пришёл через BT (за < 3 секунды после появления суммы) — показываем BT
        triggerPaidAnimation(s.pay_method || 'qr');
        btClearAdvertising();
        if (returnToIdleTimer) clearTimeout(returnToIdleTimer);
        returnToIdleTimer = setTimeout(() => resetToIdle(true), 4000);
    }
    else if (s.status === 'declined') {
        processedPaymentKey = currentKey;
        showScreen('screenDeclined');
        btClearAdvertising();
        if (returnToIdleTimer) clearTimeout(returnToIdleTimer);
        returnToIdleTimer = setTimeout(() => resetToIdle(true), 2000);
    }
}

function poll() {
    if (!TOKEN) return;
    fetch('terminal_api.php?action=get_status&token=' + encodeURIComponent(TOKEN))
        .then(r => r.json())
        .then(d => { if (d && d.ok) updateUI(d.session); })
        .catch(e => console.error(e));
}

// ── INIT ───────────────────────────────────────────────────────────────────
if (TOKEN) {
    poll();
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(poll, 1000);
    // Показываем BT модал через 600мс чтобы основной UI успел загрузиться
    setTimeout(btShowPermModal, 600);
} else {
    document.getElementById('idleOrg').textContent = 'Неверная ссылка';
    document.getElementById('btPermModal').style.display = 'none';
    btSetIndicator('off', 'BT N/A');
}

window.addEventListener('load', () => { if (TOKEN && lastStatus === null) showScreen('screenIdle'); });

// Очищаем localStorage при закрытии страницы
window.addEventListener('beforeunload', () => {
    try { localStorage.removeItem(BT_LS_KEY); } catch(e) {}
    try { if (window._btChannel) window._btChannel.close(); } catch(e) {}
});
</script>
</body>
</html>
