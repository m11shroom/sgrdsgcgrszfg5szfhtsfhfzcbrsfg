<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS plastic_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    card_number VARCHAR(19) DEFAULT NULL,
    expiry VARCHAR(5) DEFAULT NULL,
    pin_code VARCHAR(4) DEFAULT NULL,
    delivery_address TEXT DEFAULT NULL,
    delivery_date DATE DEFAULT NULL,
    delivery_time VARCHAR(5) DEFAULT NULL,
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory',
    rep_name VARCHAR(150) DEFAULT NULL,
    rep_phone VARCHAR(30) DEFAULT NULL,
    rep_photo VARCHAR(255) DEFAULT NULL,
    is_delivered TINYINT(1) NOT NULL DEFAULT 0,
    delivered_at DATETIME DEFAULT NULL,
    yoo_access_token TEXT DEFAULT NULL,
    yoo_wallet VARCHAR(34) DEFAULT NULL,
    wallet_connected_at DATETIME DEFAULT NULL,
    balance_cached DECIMAL(14,2) DEFAULT NULL,
    balance_updated_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try {
    $hasOldCvv = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'cvv'")->fetch();
    $hasPin    = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'pin_code'")->fetch();
    if ($hasOldCvv && !$hasPin) {
        $db->exec("ALTER TABLE plastic_cards CHANGE COLUMN cvv pin_code VARCHAR(4) DEFAULT NULL");
    } elseif (!$hasPin) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN pin_code VARCHAR(4) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'wallet_connected_at'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN wallet_connected_at DATETIME DEFAULT NULL AFTER yoo_wallet");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'delivery_status'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory'");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_name'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_name VARCHAR(150) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_phone'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_phone VARCHAR(30) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_photo'")->fetch()) {
        $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_photo VARCHAR(255) DEFAULT NULL");
    }
    // Раньше колонка была VARCHAR(255) и обрезала реальные токены YooMoney (они длиннее) -> 401 при опросе
    $tokType = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plastic_cards' AND COLUMN_NAME='yoo_access_token'")->fetchColumn();
    if ($tokType && strtolower($tokType) === 'varchar') {
        $db->exec("ALTER TABLE plastic_cards MODIFY COLUMN yoo_access_token TEXT DEFAULT NULL");
    }
} catch (\Throwable $e) {}

/* Статусы доставки пластиковой карты (в порядке прохождения) */
function pc_delivery_statuses(): array {
    return [
        'to_factory'   => 'Везём пластик на фабрику',
        'printing'     => 'Печатаем карту',
        'packaging'    => 'Упаковываем',
        'transporting' => 'Везём',
        'handoff'      => 'Передаём представителю',
        'with_rep'     => 'Карта у представителя',
        'rep_enroute'  => 'Представитель в пути',
    ];
}


$cards = $db->query(
    'SELECT pc.*, u.username, u.email
     FROM plastic_cards pc
     JOIN users u ON u.id = pc.user_id
     ORDER BY pc.created_at DESC'
)->fetchAll();

// Основной кошелёк сайта (используется для пополнений баланса банка, топ-апов и т.д.)
// — нужен, чтобы предупредить админа, если кошелёк карты по ошибке совпал с ним
require_once __DIR__ . '/yoomoney_lib.php';
$siteWalletCfg = null;
try { $siteWalletCfg = ym_getConfig($db); } catch (\Throwable $e) {}
$siteWallet = $siteWalletCfg['wallet'] ?? null;

$walletConnectedId = isset($_GET['wallet_connected']) ? (int)$_GET['wallet_connected'] : 0;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus wallet — Пластиковые карты</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 70px}
.hdr{max-width:820px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:820px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.28em;color:#444}
.sec{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px;display:flex;flex-direction:column;gap:14px}
.sec-title{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.28em;color:#444;text-transform:uppercase}
.cflbl{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#333;text-transform:uppercase;margin-bottom:5px}
.cinput,textarea.cinput{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;-webkit-appearance:none}
textarea.cinput{resize:vertical;min-height:60px;font-family:'DM Sans',sans-serif;font-size:.82rem}
.cinput:focus{border-color:#444}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.csubmit{background:#fff;color:#080808;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;letter-spacing:.1em;padding:11px 18px;cursor:pointer;transition:background .2s}
.csubmit:hover{background:#e0e0e0}
.flash-msg{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.12em;padding:9px;border-radius:9px}
.flash-ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash-err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.cs-wrap{position:relative}
.cs-results{position:absolute;top:100%;left:0;right:0;background:#151515;border:1px solid #2a2a2a;border-radius:10px;margin-top:4px;max-height:180px;overflow-y:auto;z-index:20}
.cs-item{padding:9px 13px;font-family:'Space Mono',monospace;font-size:.68rem;color:#ccc;cursor:pointer;border-bottom:1px solid #1e1e1e}
.cs-item:last-child{border-bottom:none}
.cs-item:hover{background:#1e1e1e}
.cs-empty{padding:9px 13px;font-family:'Space Mono',monospace;font-size:.6rem;color:#444}
.cs-selected{font-family:'Space Mono',monospace;font-size:.58rem;color:#4ade80;margin-top:4px}
.cover-preview-wrap{display:none}
.cover-preview{max-width:180px;height:auto;border-radius:7px;border:1px solid #1a1a1a;margin-top:6px}
.pc-list{display:flex;flex-direction:column;gap:12px}
.pc-card{background:#0a0a0a;border:1px solid #161616;border-radius:14px;padding:14px 15px;display:flex;flex-direction:column;gap:10px}
.pc-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.pc-left{display:flex;gap:12px;align-items:center}
.pc-cover{width:52px;height:33px;border-radius:3px;overflow:hidden;flex-shrink:0;background:#1a1a1a;display:flex;align-items:center;justify-content:center;color:#333}
.pc-cover img{width:100%;height:100%;object-fit:cover}
.pc-name{font-size:.86rem;color:#ccc}
.pc-sub{font-family:'Space Mono',monospace;font-size:.5rem;color:#333;margin-top:3px}
.pc-badges{display:flex;gap:6px;flex-wrap:wrap}
.pc-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.12em;padding:3px 8px;border-radius:6px}
.b-delivered{color:#4ade80;background:#001a08;border:1px solid #002a10}
.b-waiting{color:#facc15;background:#1a1200;border:1px solid #2a1e00}
.b-wallet-on{color:#4ade80;background:#001a08;border:1px solid #002a10}
.b-wallet-off{color:#555;background:#0d0d0d;border:1px solid #1a1a1a}
.pc-meta{display:flex;flex-wrap:wrap;gap:12px}
.pc-m{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525}
.pc-m span{color:#888}
.pc-actions{display:flex;gap:8px;flex-wrap:wrap}
.pc-btn{background:none;border:1px solid #1e1e1e;border-radius:8px;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:6px 12px;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-block}
.pc-btn:hover{border-color:#555;color:#ccc}
.pc-btn.green{border-color:#1a3a1a;color:#3a6a3a}
.pc-btn.green:hover{border-color:#4ade80;color:#4ade80}
.pc-btn.red{border-color:#2a0000;color:#555}
.pc-btn.red:hover{border-color:#f87171;color:#f87171}
.pc-bal{font-family:'Space Mono',monospace;font-size:.78rem;color:#f0f0f0}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:24px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET · ADMIN</div>
  <a href="admin.php" class="back">← Панель</a>
</div>
<div class="inner">
  <div class="pg-ttl">ПЛАСТИКОВЫЕ КАРТЫ</div>

  <?php if ($walletConnectedId): ?>
  <div class="flash-msg flash-ok">Кошелёк успешно привязан к карте #<?php echo $walletConnectedId; ?></div>
  <?php endif; ?>

  <!-- CREATE -->
  <div class="sec">
    <div class="sec-title">Выпустить пластиковую карту</div>

    <div>
      <div class="cflbl">Клиент</div>
      <div class="cs-wrap">
        <input type="text" id="clientSearch" class="cinput" placeholder="Введите никнейм или email...">
        <div class="cs-results" id="clientResults"></div>
      </div>
      <div class="cs-selected" id="clientSelected" style="display:none"></div>
    </div>

    <div>
      <div class="cflbl">Название карты</div>
      <input type="text" id="cardName" class="cinput" placeholder="Например: M1plus Platinum">
    </div>

    <div>
      <div class="cflbl">Фото карты (1011×638 px)</div>
      <input type="file" id="coverFileP" accept="image/*" class="cinput" style="padding:7px 12px;cursor:pointer">
      <div class="cover-preview-wrap" id="coverPreviewWrapP">
        <img id="coverPreviewP" class="cover-preview">
      </div>
    </div>

    <div class="grid2">
      <div>
        <div class="cflbl">Срок действия (MM/YY)</div>
        <input type="text" id="cardExpiry" class="cinput" placeholder="12/28" maxlength="5" oninput="fmtExpiryP(this)">
      </div>
      <div>
        <div class="cflbl">ПИН-код (для крупных операций)</div>
        <input type="text" id="cardCvv" class="cinput" placeholder="1234" maxlength="4" inputmode="numeric">
      </div>
    </div>

    <div>
      <div class="cflbl">Адрес доставки</div>
      <textarea id="deliveryAddress" class="cinput" placeholder="Город, улица, дом, квартира..."></textarea>
    </div>

    <div class="grid2">
      <div>
        <div class="cflbl">Дата доставки</div>
        <input type="date" id="deliveryDate" class="cinput">
      </div>
      <div>
        <div class="cflbl">Время доставки</div>
        <input type="time" id="deliveryTime" class="cinput">
      </div>
    </div>

    <div id="createResult" class="flash-msg" style="display:none"></div>
    <button class="csubmit" onclick="submitCreate()">Выпустить карту</button>
  </div>

  <!-- LIST -->
  <div class="sec">
    <div class="sec-title">Выпущенные карты</div>
    <?php if (empty($cards)): ?>
    <div class="empty">Карт нет</div>
    <?php else: ?>
    <div class="pc-list">
      <?php foreach ($cards as $c):
        $cover = $c['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $c['cover_image'] : null;
        $last4 = $c['card_number'] ? substr($c['card_number'], -4) : '0000';
        $bal   = $c['balance_cached'] !== null ? number_format((float)$c['balance_cached'], 2, '.', ' ') : '—';
      ?>
      <div class="pc-card" data-card-id="<?php echo $c['id']; ?>" data-has-rep-info="<?php echo $c['rep_name'] ? '1' : '0'; ?>">
        <div class="pc-top">
          <div class="pc-left">
            <div class="pc-cover">
              <?php if ($cover): ?><img src="<?php echo htmlspecialchars($cover); ?>" alt="">
              <?php else: ?><svg width="20" height="13" viewBox="0 0 24 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg>
              <?php endif; ?>
            </div>
            <div>
              <div class="pc-name"><?php echo htmlspecialchars($c['name']); ?> · *<?php echo htmlspecialchars($last4); ?></div>
              <div class="pc-sub">@<?php echo htmlspecialchars($c['username']); ?> &bull; <?php echo htmlspecialchars($c['email']); ?></div>
            </div>
          </div>
          <div class="pc-badges">
            <span class="pc-badge <?php echo $c['is_delivered']?'b-delivered':'b-waiting'; ?>"><?php echo $c['is_delivered']?'ВЫДАНА':'ОЖИДАЕТ ВЫДАЧИ'; ?></span>
            <span class="pc-badge <?php echo $c['yoo_wallet']?'b-wallet-on':'b-wallet-off'; ?>"><?php echo $c['yoo_wallet']?'КОШЕЛЁК ПОДКЛЮЧЁН':'КОШЕЛЁК НЕ ПОДКЛЮЧЁН'; ?></span>
          </div>
        </div>

        <?php if ($c['yoo_wallet']): ?>
        <div class="pc-m">Кошелёк карты: <span style="color:#888"><?php echo htmlspecialchars($c['yoo_wallet']); ?></span>
          <?php if ($siteWallet && $c['yoo_wallet'] === $siteWallet): ?>
            <span style="color:#f87171"> — ⚠ ЭТО ТОТ ЖЕ КОШЕЛЁК, ЧТО И ОСНОВНОЙ КОШЕЛЁК САЙТА (<?php echo htmlspecialchars($siteWallet); ?>)! Все пополнения этой карты фактически уходят на общий баланс сайта, а не на отдельный счёт клиента. Переподключи карту под ДРУГИМ YooMoney-аккаунтом.</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="pc-meta">
          <div class="pc-m">Срок: <span><?php echo htmlspecialchars($c['expiry'] ?? '—'); ?></span></div>
          <div class="pc-m">ПИН: <span><?php echo htmlspecialchars($c['pin_code'] ?? '—'); ?></span></div>
          <div class="pc-m">Баланс: <span class="pc-bal" id="pcBal<?php echo $c['id']; ?>"><?php echo $bal; ?></span> ₽</div>
          <div class="pc-m">Создана: <span><?php echo date('d.m.Y', strtotime($c['created_at'])); ?></span></div>
        </div>

        <?php if (!$c['is_delivered']): ?>
        <div class="pc-meta">
          <div class="pc-m">Адрес: <span><?php echo htmlspecialchars($c['delivery_address'] ?: '—'); ?></span></div>
          <?php if ($c['delivery_date']): ?><div class="pc-m">Дата: <span><?php echo date('d.m.Y', strtotime($c['delivery_date'])); ?></span></div><?php endif; ?>
          <?php if ($c['delivery_time']): ?><div class="pc-m">Время: <span><?php echo htmlspecialchars($c['delivery_time']); ?></span></div><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="pc-m">Выдана: <span><?php echo date('d.m.Y H:i', strtotime($c['delivered_at'])); ?></span></div>
        <?php endif; ?>

        <?php if (!$c['is_delivered']): $cStatuses = pc_delivery_statuses(); ?>
        <div style="background:#0a0a0a;border:1px solid #161616;border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:8px">
          <div class="pc-m">Статус доставки: <span style="color:#facc15"><?php echo htmlspecialchars($cStatuses[$c['delivery_status']] ?? $c['delivery_status']); ?></span></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <select id="pcStatusSel<?php echo $c['id']; ?>" class="cinput" style="width:auto;flex:1;min-width:200px" onchange="onStatusChange(<?php echo $c['id']; ?>)">
              <?php foreach ($cStatuses as $key => $label): ?>
              <option value="<?php echo $key; ?>" <?php echo $c['delivery_status']===$key?'selected':''; ?>><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="pc-btn green" onclick="saveStatus(<?php echo $c['id']; ?>)">Сохранить статус</button>
          </div>
          <?php $cNeedsRepUI = in_array($c['delivery_status'], ['with_rep','rep_enroute'], true) && !$c['rep_name']; ?>
          <div id="pcRepFields<?php echo $c['id']; ?>" style="display:<?php echo $cNeedsRepUI?'flex':'none'; ?>;flex-direction:column;gap:8px">
            <input type="text" id="pcRepName<?php echo $c['id']; ?>" class="cinput" placeholder="ФИО представителя *" value="<?php echo htmlspecialchars($c['rep_name'] ?? ''); ?>">
            <input type="text" id="pcRepPhone<?php echo $c['id']; ?>" class="cinput" placeholder="Телефон представителя *" value="<?php echo htmlspecialchars($c['rep_phone'] ?? ''); ?>">
            <input type="file" id="pcRepPhoto<?php echo $c['id']; ?>" accept="image/*" class="cinput" style="padding:7px 12px;cursor:pointer">
            <?php if ($c['rep_photo']): ?><div class="pc-m">Текущее фото представителя уже загружено ✓ (необязательно перезагружать)</div><?php endif; ?>
          </div>
          <?php if ($c['rep_name']): ?>
          <div class="pc-m">Представитель: <span style="color:#888"><?php echo htmlspecialchars($c['rep_name']); ?> · <?php echo htmlspecialchars($c['rep_phone'] ?: '—'); ?></span>
            — <a href="javascript:void(0)" onclick="document.getElementById('pcRepFields<?php echo $c['id']; ?>').style.display='flex'" style="color:#555">изменить</a>
          </div>
          <?php endif; ?>
          <div id="pcStatusErr<?php echo $c['id']; ?>" style="font-family:'Space Mono',monospace;font-size:.5rem;color:#f87171;display:none"></div>
        </div>
        <?php endif; ?>

        <div class="pc-actions">
          <?php if ($c['yoo_wallet']): ?>
          <a href="yoomoney_auth.php?connect_card=<?php echo $c['id']; ?>" class="pc-btn">Переподключить кошелёк</a>
          <a href="plastic_cards_api.php?action=debug_card&card_id=<?php echo $c['id']; ?>&debug=1" target="_blank" class="pc-btn">Диагностика кошелька</a>
          <?php else: ?>
          <a href="yoomoney_auth.php?connect_card=<?php echo $c['id']; ?>" class="pc-btn green">Подключить кошелёк</a>
          <?php endif; ?>
          <button class="pc-btn <?php echo $c['is_delivered']?'red':'green'; ?>" onclick="toggleDelivered(<?php echo $c['id']; ?>)">
            <?php echo $c['is_delivered']?'Вернуть в ожидание':'Пометить как выданную'; ?>
          </button>
          <button class="pc-btn red" onclick="deleteCard(<?php echo $c['id']; ?>)">Удалить</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
var selectedUserId = null;
var searchTimer = null;

document.getElementById('clientSearch').oninput = function(){
  var q = this.value.trim();
  selectedUserId = null;
  document.getElementById('clientSelected').style.display = 'none';
  clearTimeout(searchTimer);
  var box = document.getElementById('clientResults');
  if (q.length < 2) { box.innerHTML = ''; return; }
  searchTimer = setTimeout(function(){
    fetch('plastic_cards_admin_api.php?action=search_users&q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(d){
        box.innerHTML = '';
        if (!d.ok || !d.users.length) { box.innerHTML = '<div class="cs-empty">Не найдено</div>'; return; }
        d.users.forEach(function(u){
          var item = document.createElement('div');
          item.className = 'cs-item';
          item.textContent = '@' + u.username + ' — ' + u.email;
          item.onclick = function(){
            selectedUserId = u.id;
            document.getElementById('clientSearch').value = '@' + u.username;
            var sel = document.getElementById('clientSelected');
            sel.style.display = 'block';
            sel.textContent = 'Выбран: @' + u.username;
            box.innerHTML = '';
          };
          box.appendChild(item);
        });
      }).catch(function(){});
  }, 300);
};

document.getElementById('coverFileP').onchange = function(){
  var file = this.files[0]; if (!file) return;
  var wrap = document.getElementById('coverPreviewWrapP');
  var img = document.getElementById('coverPreviewP');
  var rd = new FileReader();
  rd.onload = function(e){ img.src = e.target.result; wrap.style.display = 'block'; };
  rd.readAsDataURL(file);
};

function fmtExpiryP(el){
  var v = el.value.replace(/\D/g,'').substring(0,4);
  el.value = v.length > 2 ? v.slice(0,2) + '/' + v.slice(2) : v;
}

function showCreateResult(ok, text){
  var res = document.getElementById('createResult');
  res.style.display = 'block';
  res.className = 'flash-msg ' + (ok ? 'flash-ok' : 'flash-err');
  res.textContent = text;
}

function submitCreate(){
  if (!selectedUserId) { showCreateResult(false, 'Выберите клиента из списка'); return; }
  var name = document.getElementById('cardName').value.trim();
  if (!name) { showCreateResult(false, 'Укажите название карты'); return; }
  var expiry = document.getElementById('cardExpiry').value.trim();
  if (!/^\d{2}\/\d{2}$/.test(expiry)) { showCreateResult(false, 'Формат срока действия: MM/YY'); return; }
  var pin = document.getElementById('cardCvv').value.trim();
  if (!/^\d{4}$/.test(pin)) { showCreateResult(false, 'ПИН-код должен быть 4 цифры'); return; }

  var fd = new FormData();
  fd.append('action', 'create');
  fd.append('user_id', selectedUserId);
  fd.append('name', name);
  fd.append('delivery_address', document.getElementById('deliveryAddress').value.trim());
  fd.append('delivery_date', document.getElementById('deliveryDate').value);
  fd.append('delivery_time', document.getElementById('deliveryTime').value);
  fd.append('expiry', expiry);
  fd.append('pin_code', pin);
  var file = document.getElementById('coverFileP').files[0];
  if (file) fd.append('cover_image', file);

  fetch('plastic_cards_admin_api.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) { showCreateResult(true, 'Карта выпущена'); setTimeout(function(){ location.reload(); }, 900); }
      else showCreateResult(false, d.error || 'Ошибка');
    }).catch(function(){ showCreateResult(false, 'Ошибка соединения'); });
}

function toggleDelivered(id){
  var fd = new FormData(); fd.append('action','toggle_delivered'); fd.append('id', id);
  fetch('plastic_cards_admin_api.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.ok) location.reload(); });
}

function onStatusChange(id){
  var sel = document.getElementById('pcStatusSel' + id);
  var repFields = document.getElementById('pcRepFields' + id);
  var card = document.querySelector('.pc-card[data-card-id="' + id + '"]');
  var hasRepInfo = card && card.dataset.hasRepInfo === '1';
  var needsRep = (sel.value === 'with_rep' || sel.value === 'rep_enroute') && !hasRepInfo;
  repFields.style.display = needsRep ? 'flex' : 'none';
}

function saveStatus(id){
  var errEl = document.getElementById('pcStatusErr' + id);
  errEl.style.display = 'none';
  var status = document.getElementById('pcStatusSel' + id).value;
  var repFields = document.getElementById('pcRepFields' + id);

  var fd = new FormData();
  fd.append('action', 'update_delivery_status');
  fd.append('id', id);
  fd.append('status', status);

  if ((status === 'with_rep' || status === 'rep_enroute') && repFields.style.display !== 'none') {
    var name  = document.getElementById('pcRepName' + id).value.trim();
    var phone = document.getElementById('pcRepPhone' + id).value.trim();
    if (!name)  { errEl.style.display='block'; errEl.textContent='Укажите ФИО представителя'; return; }
    if (!phone) { errEl.style.display='block'; errEl.textContent='Укажите номер телефона представителя'; return; }
    fd.append('rep_name', name);
    fd.append('rep_phone', phone);
    var photoFile = document.getElementById('pcRepPhoto' + id).files[0];
    if (photoFile) fd.append('rep_photo', photoFile);
  }

  fetch('plastic_cards_admin_api.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) location.reload();
      else { errEl.style.display='block'; errEl.textContent = d.error || 'Ошибка'; }
    }).catch(function(){ errEl.style.display='block'; errEl.textContent = 'Ошибка соединения'; });
}

function deleteCard(id){
  if (!confirm('Удалить эту карту без возможности восстановления?')) return;
  var fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
  fetch('plastic_cards_admin_api.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.ok) location.reload(); });
}

/* Лёгкий поллинг баланса подключённых карт для админского обзора (не так часто, как у клиента) */
var pcIds = Array.prototype.slice.call(document.querySelectorAll('.pc-card')).map(function(el){ return el.dataset.cardId; });
function pollAdminBalances(){
  pcIds.forEach(function(id){
    var el = document.getElementById('pcBal' + id);
    if (!el) return;
    fetch('plastic_cards_api.php?action=balance&card_id=' + id + '&_=' + Date.now(), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        console.log('[admin plastic balance]', id, d);
        if (!d.ok || !d.connected) return;
        if (d.balance !== null) {
          el.textContent = parseFloat(d.balance).toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2});
          el.title = '';
        }
        if (d.debug_error) {
          el.title = 'Ошибка обновления баланса: ' + d.debug_error;
          el.style.color = '#f87171';
        }
      }).catch(function(err){ console.error('[admin plastic balance] fetch failed', err); });
  });
}
if (pcIds.length) setInterval(pollAdminBalances, 3000);
</script>
</body>
</html>
