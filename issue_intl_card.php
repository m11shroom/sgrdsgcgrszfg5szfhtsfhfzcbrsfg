<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Международная карта — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding-bottom:60px}
.hdr{position:absolute;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:20px 16px;z-index:5}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#fff;display:flex;align-items:center;gap:7px;text-shadow:0 1px 4px rgba(0,0,0,.6)}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:rgba(0,0,0,.4);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.2);border-radius:10px;color:#fff;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none}

.hero{width:100%;height:40vh;min-height:260px;background:linear-gradient(180deg,rgba(0,0,0,0) 0%,rgba(8,8,8,1) 95%),url('assets/cardwelcome.png') center/cover no-repeat;position:relative}

.inner{max-width:480px;margin:0 auto;padding:0 16px;display:flex;flex-direction:column;gap:16px;margin-top:-30px;position:relative;z-index:2}

.card-select{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.pay-option{border-radius:16px;padding:16px;cursor:pointer;border:2px solid #1e1e1e;background:#111;transition:all .2s;text-align:center}
.pay-option.selected{border-color:#fff}
.pay-face{width:100%;aspect-ratio:1.586/1;border-radius:12px;overflow:hidden;margin-bottom:10px;background:#1a1a1a}
.pay-face img{width:100%;height:100%;object-fit:cover;display:block}
.pay-name{font-weight:600;font-size:.86rem}
.pay-price{font-family:'Space Mono',monospace;color:#a78bfa;font-size:.9rem;margin-top:4px}

.tag{display:inline-block;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.12em;padding:5px 11px;border-radius:20px;background:#1a1030;color:#a78bfa;text-transform:uppercase}

.card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px}
.card-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;margin-bottom:14px}
.benefit-row{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #161616}
.benefit-row:last-child{border-bottom:none}
.benefit-icon{font-size:1.3rem;flex-shrink:0}
.benefit-text{font-size:.82rem;color:#aaa;line-height:1.5}
.benefit-text b{color:#e0e0e0}

.terms{font-family:'Space Mono',monospace;font-size:.56rem;color:#333;line-height:1.8}
.terms b{color:#666}

.btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:600;letter-spacing:.05em;padding:14px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.btn:hover{background:#e0e0e0}
.btn:disabled{opacity:.35;cursor:default}

.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:11px 13px;border-radius:11px}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}

/* Экран ожидания оплаты */
.waiting{display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;padding:30px 10px}
.spin{width:34px;height:34px;border:3px solid #1e1e1e;border-top-color:#a78bfa;border-radius:50%;animation:sp 1s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}

/* Успех */
.success{display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;padding:30px 10px}
.confetti{font-size:60px;animation:pop .5s ease}
@keyframes pop{0%{transform:scale(0)}60%{transform:scale(1.2)}100%{transform:scale(1)}}

/* Карта пользователя - лицевая сторона + блюр данных */
.my-card{border-radius:18px;overflow:hidden;position:relative;aspect-ratio:1.586/1;background:#1a1a1a}
.my-card img{width:100%;height:100%;object-fit:cover;display:block}
.my-card-overlay{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;padding:16px;background:linear-gradient(180deg,rgba(0,0,0,0) 40%,rgba(0,0,0,.55) 100%)}
.card-data-row{font-family:'Space Mono',monospace;color:#fff;font-size:1rem;letter-spacing:.12em;text-shadow:0 1px 4px rgba(0,0,0,.5);cursor:pointer;user-select:none}
.card-data-row.blurred{filter:blur(7px)}
.card-data-sub{display:flex;gap:16px;margin-top:8px}
.card-data-sub span{font-family:'Space Mono',monospace;color:#fff;font-size:.72rem;cursor:pointer}
.card-data-sub span.blurred{filter:blur(5px)}
.reveal-hint{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.1em;color:#aaa;margin-top:6px}
.online-only-badge{position:absolute;top:12px;right:12px;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);color:#fff;font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.1em;padding:5px 9px;border-radius:20px}
.status-line{font-family:'Space Mono',monospace;font-size:.56rem;color:#888;margin-top:10px;text-align:center}
</style>
</head>
<body>
<div class="hero">
  <div class="hdr">
    <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
    <a href="dashboard.php" class="back">← Назад</a>
  </div>
</div>

<div class="inner">
  <div id="flash"></div>

  <!-- Мои уже заказанные карты -->
  <div id="myCardsBlock"></div>

  <!-- Выбор платёжной системы -->
  <div class="card" id="selectCard">
    <div class="tag">🇺🇸 Выпуск из США</div>
    <div class="card-title" style="margin-top:12px">ВЫБЕРИ ПЛАТЁЖНУЮ СИСТЕМУ</div>
    <div class="card-select">
      <div class="pay-option selected" data-system="visa" onclick="selectSystem('visa', this)">
        <div class="pay-face"><img src="assets/visam1.png" alt="Visa"></div>
        <div class="pay-name">Visa</div>
        <div class="pay-price">1500 ₽</div>
      </div>
      <div class="pay-option" data-system="mastercard" onclick="selectSystem('mastercard', this)">
        <div class="pay-face"><img src="assets/masterm1.png" alt="Mastercard"></div>
        <div class="pay-name">Mastercard</div>
        <div class="pay-price">2000 ₽</div>
      </div>
    </div>
  </div>

  <!-- Преимущества -->
  <div class="card">
    <div class="card-title">ПРЕИМУЩЕСТВА КАРТЫ</div>
    <div class="benefit-row"><div class="benefit-icon">🌍</div><div class="benefit-text"><b>Зарубежный шопинг</b><br>Работает на всех иностранных сайтах и сервисах без ограничений</div></div>
    <div class="benefit-row"><div class="benefit-icon">🛍️</div><div class="benefit-text"><b>Только для онлайн-покупок</b><br>Оптимизирована для интернет-магазинов, подписок и сервисов</div></div>
    <div class="benefit-row"><div class="benefit-icon">⚡</div><div class="benefit-text"><b>Мгновенная активация</b><br>После оплаты и активации админом карта сразу готова к использованию</div></div>
    <div class="benefit-row"><div class="benefit-icon">🔒</div><div class="benefit-text"><b>Данные под защитой</b><br>Номер, срок и CVV скрыты — показываются только по нажатию</div></div>
  </div>

  <!-- Оплата -->
  <div class="card" id="payCard">
    <div class="card-title">ОПЛАТА</div>
    <button class="btn" id="payBtn" onclick="startPayment()">Оплатить и заказать →</button>
  </div>

  <!-- Ожидание оплаты (скрыто) -->
  <div class="card" id="waitingCard" style="display:none">
    <div class="waiting">
      <div class="spin"></div>
      <div>Ожидаем оплату...</div>
      <div class="status-line">Проверяем каждую секунду, пока вы в приложении</div>
    </div>
  </div>

  <!-- Успех (скрыто) -->
  <div class="card" id="successCard" style="display:none">
    <div class="success">
      <div class="confetti">🎉</div>
      <div style="font-weight:600">Оплата получена!</div>
      <div class="status-line">Карта появится в списке выше, когда администратор внесёт данные</div>
      <button class="btn" onclick="location.reload()">Готово</button>
    </div>
  </div>

  <!-- Условия -->
  <div class="card">
    <div class="card-title">УСЛОВИЯ ВЫПУСКА</div>
    <div class="terms">
      <b>1.</b> Карта предназначена исключительно для онлайн-покупок, оплата в физических точках продаж невозможна.<br><br>
      <b>2.</b> Карта выпускается от банка-эмитента (США). Комиссии платёжной системы могут применяться отдельно.<br><br>
      <b>3.</b> После оплаты заказ передаётся администратору для внесения реквизитов. Обычно занимает до 24 часов.<br><br>
      <b>4.</b> Если оплата не поступит в течение 7 дней с момента создания заказа — заказ автоматически отменяется.<br><br>
      <b>5.</b> Реквизиты карты (номер, срок, CVV) скрыты по умолчанию и показываются только при нажатии на них вами лично.
    </div>
  </div>
</div>

<script>
const api = 'intl_cards_api.php';
let selectedSystem = 'visa';
let activeOrderId = null;
let pollTimer = null;

function flash(msg, type) {
  document.getElementById('flash').innerHTML = `<div class="flash ${type}">${msg}</div>`;
  setTimeout(() => document.getElementById('flash').innerHTML = '', 4000);
}

function selectSystem(sys, el) {
  selectedSystem = sys;
  document.querySelectorAll('.pay-option').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
}

async function startPayment() {
  const btn = document.getElementById('payBtn');
  btn.disabled = true;
  try {
    const r = await fetch(`${api}?action=create_order`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_system: selectedSystem })
    });
    const d = await r.json();
    if (!d.ok) { flash(d.error, 'err'); btn.disabled = false; return; }

    activeOrderId = d.order_id;
    window.open(d.pay_url, '_blank');

    document.getElementById('selectCard').style.display = 'none';
    document.getElementById('payCard').style.display = 'none';
    document.getElementById('waitingCard').style.display = 'block';

    startPolling();
  } catch (e) {
    flash('Ошибка сети', 'err');
    btn.disabled = false;
  }
}

function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const r = await fetch(`${api}?action=check_payment&order_id=${activeOrderId}`);
      const d = await r.json();
      if (!d.ok) return;
      if (d.status === 'paid' || d.status === 'active') {
        clearInterval(pollTimer);
        document.getElementById('waitingCard').style.display = 'none';
        document.getElementById('successCard').style.display = 'block';
        loadMyCards();
      } else if (d.status === 'cancelled') {
        clearInterval(pollTimer);
        flash('Заказ отменён — истёк срок оплаты (7 дней)', 'err');
        document.getElementById('waitingCard').style.display = 'none';
        document.getElementById('selectCard').style.display = 'block';
        document.getElementById('payCard').style.display = 'block';
        document.getElementById('payBtn').disabled = false;
      }
    } catch (e) {}
  }, 1000);
}

/* ── Мои карты ── */
async function loadMyCards() {
  const r = await fetch(`${api}?action=my_cards`);
  const d = await r.json();
  const block = document.getElementById('myCardsBlock');
  if (!d.ok || !d.cards.length) { block.innerHTML = ''; return; }

  block.innerHTML = d.cards.map(c => {
    const face = c.payment_system === 'visa' ? 'assets/visam1.png' : 'assets/masterm1.png';
    const statusText = { pending_payment: 'Ожидает оплаты', paid: 'Оплачено, ждём активации', active: 'Активна' }[c.status] || c.status;
    const hasData = c.card_number && c.expiry && c.cvv;
    return `
      <div class="card" style="margin-bottom:14px">
        <div class="my-card">
          <img src="${face}" alt="${c.payment_system}">
          <div class="online-only-badge">Только онлайн</div>
          <div class="my-card-overlay">
            ${hasData ? `
              <div class="card-data-row blurred" onclick="toggleBlur(this)">${c.card_number}</div>
              <div class="card-data-sub">
                <span class="blurred" onclick="toggleBlur(this)">${c.expiry}</span>
                <span class="blurred" onclick="toggleBlur(this)">CVV ${c.cvv}</span>
              </div>
              <div class="reveal-hint">Нажми, чтобы посмотреть</div>
            ` : `<div class="status-line" style="color:#fff">${statusText}</div>`}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function toggleBlur(el) {
  el.classList.toggle('blurred');
}

loadMyCards();
</script>
</body>
</html>
