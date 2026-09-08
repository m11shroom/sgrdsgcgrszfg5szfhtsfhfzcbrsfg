<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

$db = getDB();
// Таблицы создаются через migrate.php — запусти его один раз перед использованием

/* ---- Device / PIN gate ---- */
$deviceRow = null;
$rawDevToken = $_COOKIE['m1_device'] ?? '';
if ($rawDevToken) {
    $st = $db->prepare("SELECT dt.*, u.username AS dt_username, u.avatar AS dt_avatar FROM device_tokens dt JOIN users u ON u.id=dt.user_id WHERE dt.token_hash=?");
    $st->execute([hash('sha256', $rawDevToken)]);
    $deviceRow = $st->fetch() ?: null;
}

$hasSession = !empty($_SESSION['user_id']);

if (!$hasSession && !$deviceRow) { header('Location: index.php'); exit; }

$locked = (!$hasSession && $deviceRow);      // show PIN unlock screen
$needPinSetup = ($hasSession && !$deviceRow); // prompt to create PIN

if ($locked):
$lockName   = $deviceRow['dt_username'];
$lockAvatar = $deviceRow['dt_avatar'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $deviceRow['dt_avatar'] : null;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="M1plus">
<meta name="theme-color" content="#0a0a12">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<title>M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top) + 40px) 20px calc(env(safe-area-inset-bottom) + 24px);min-height:100vh;user-select:none;-webkit-user-select:none}
.lock-top{display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:20px}
.lock-av{width:74px;height:74px;border-radius:50%;background:#1b1230;border:2px solid #2d1f52;display:flex;align-items:center;justify-content:center;font-size:1.7rem;font-weight:700;color:#a78bfa;overflow:hidden}
.lock-av img{width:100%;height:100%;object-fit:cover}
.lock-hi{font-size:1.15rem;font-weight:500;color:#fff}
.lock-sub{font-size:.85rem;color:#8f8fa3}
.dots{display:flex;gap:18px;margin:26px 0 6px}
.dot{width:14px;height:14px;border-radius:50%;background:#232336;transition:all .15s}
.dot.on{background:#8b5cf6;transform:scale(1.15)}
.dots.shake{animation:shake .4s}
.dots.err .dot{background:#ef4444}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-9px)}40%{transform:translateX(9px)}60%{transform:translateX(-6px)}80%{transform:translateX(6px)}}
.err-txt{font-size:.8rem;color:#ef4444;min-height:20px;text-align:center}
.pad{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 26px;width:100%;max-width:300px;margin-bottom:8px}
.key{width:76px;height:76px;border-radius:50%;background:#15151f;border:none;color:#fff;font-family:'DM Sans',sans-serif;font-size:1.7rem;font-weight:500;cursor:pointer;transition:background .12s;justify-self:center;display:flex;align-items:center;justify-content:center}
.key:active{background:#2d1f52}
.key.ghost{background:none;font-size:.78rem;font-weight:500;color:#8f8fa3}
.key.ghost:active{background:#15151f}
</style>
</head>
<body>
<div class="lock-top">
  <div class="lock-av"><?php if($lockAvatar): ?><img src="<?php echo htmlspecialchars($lockAvatar); ?>"><?php else: echo mb_strtoupper(mb_substr($lockName,0,1)); endif; ?></div>
  <div class="lock-hi">Привет, <?php echo htmlspecialchars($lockName); ?></div>
  <div class="lock-sub">Введите код доступа</div>
  <div class="dots" id="dots"><div class="dot"></div><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>
  <div class="err-txt" id="errTxt"></div>
</div>
<div class="pad">
  <button class="key" onclick="k(1)">1</button><button class="key" onclick="k(2)">2</button><button class="key" onclick="k(3)">3</button>
  <button class="key" onclick="k(4)">4</button><button class="key" onclick="k(5)">5</button><button class="key" onclick="k(6)">6</button>
  <button class="key" onclick="k(7)">7</button><button class="key" onclick="k(8)">8</button><button class="key" onclick="k(9)">9</button>
  <button class="key ghost" onclick="forgetDevice()">Выйти</button>
  <button class="key" onclick="k(0)">0</button>
  <button class="key ghost" onclick="back()">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#8f8fa3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/></svg>
  </button>
</div>
<script>
var pin='', busy=false;
function render(){
  var dots=document.querySelectorAll('.dot');
  dots.forEach(function(d,i){d.className='dot'+(i<pin.length?' on':'');});
}
function k(n){
  if(busy||pin.length>=4)return;
  pin+=n; render();
  if(pin.length===4) submit();
}
function back(){ if(busy)return; pin=pin.slice(0,-1); render(); document.getElementById('errTxt').textContent=''; }
function submit(){
  busy=true;
  var fd=new FormData(); fd.append('action','unlock'); fd.append('pin',pin);
  fetch('pin_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){ location.reload(); return; }
      var box=document.getElementById('dots');
      var err=document.getElementById('errTxt');
      if(d.error==='device_gone'){ location.href='index.php'; return; }
      box.classList.add('shake','err');
      err.textContent='Неверный код. Осталось попыток: '+(d.left!=null?d.left:'');
      if(navigator.vibrate)navigator.vibrate(200);
      setTimeout(function(){ pin=''; busy=false; box.classList.remove('shake','err'); render(); },500);
    })
    .catch(function(){ busy=false; });
}
function forgetDevice(){
  if(!confirm('Выйти из аккаунта на этом устройстве?'))return;
  var fd=new FormData(); fd.append('action','forget');
  fetch('pin_api.php',{method:'POST',body:fd}).then(function(){ location.href='index.php'; });
}
</script>
</body>
</html>
<?php exit; endif;

/* ================= NORMAL APP ================= */
$uid = (int)$_SESSION['user_id'];
$db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$uid]);

$acc  = $db->prepare('SELECT * FROM bank_accounts WHERE user_id=?');
$acc->execute([$uid]); $acc = $acc->fetch();

$user = $db->prepare('SELECT * FROM users WHERE id=?');
$user->execute([$uid]); $user = $user->fetch();

$txs  = $db->prepare('SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
$txs->execute([$uid]); $txs = $txs->fetchAll();

$userCards = $db->prepare('SELECT * FROM issued_cards WHERE user_id=? ORDER BY created_at DESC');
$userCards->execute([$uid]); $userCards = $userCards->fetchAll();

/* ── Пластиковые карты: список + история операций подключённых кошельков ── */
require_once __DIR__ . '/yoomoney_lib.php';
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
$plasticCards = [];
try {
    $pcs = $db->prepare('SELECT * FROM plastic_cards WHERE user_id=? ORDER BY created_at DESC');
    $pcs->execute([$uid]);
    $plasticCards = $pcs->fetchAll();

    $walletTxs = [];
    foreach ($plasticCards as $pc) {
        if (empty($pc['yoo_access_token']) || empty($pc['wallet_connected_at'])) continue;
        $sinceTs = strtotime($pc['wallet_connected_at']);
        $hist = ym_operationHistory($pc['yoo_access_token'], ['records' => 30]);
        if (empty($hist['operations'])) {
            if (isset($hist['error'])) {
                error_log('dashboard plastic wallet history: card ' . $pc['id'] . ' error: ' . json_encode($hist));
            }
            continue;
        }

        foreach ($hist['operations'] as $op) {
            if (($op['status'] ?? '') !== 'success') continue;
            // Убираем фильтр, который исключал входящие операции (пополнения)
            $opTs = isset($op['datetime']) ? strtotime($op['datetime']) : 0;
            if (!$opTs || $opTs < $sinceTs) continue; // только после привязки

            $direction = $op['direction'] ?? 'out';
            // Для входящих операций ставим общее название "Пополнение"
            $title = ($direction === 'in') ? 'Пополнение' : ($op['title'] ?? 'Операция');

            $walletTxs[] = [
                '_external'  => true,
                'card_name'  => $pc['name'], // оставляем для служебных нужд, но не выводим
                'title'      => $title,
                'amount'     => (float)($op['amount'] ?? 0),
                'direction'  => $direction,
                'created_at' => date('Y-m-d H:i:s', $opTs),
            ];
        }
    }

    if ($walletTxs) {
        $txs = array_merge($txs, $walletTxs);
        usort($txs, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        $txs = array_slice($txs, 0, 40);
    }
} catch (\Throwable $e) {
    // Таблица plastic_cards ещё не создана (migrate6.php не запускали) — просто пропускаем
}

require_once __DIR__ . '/icons.php';
$ICONS = m1_icons();

$stories = [];
try { $stories = $db->query("SELECT * FROM stories WHERE is_active=1 ORDER BY sort_order ASC, id DESC")->fetchAll(); } catch (Throwable $e) {}
if (empty($stories)) {
    $stories = [
        ['title'=>'Терминалы уже здесь','body'=>"Оплачивайте покупки по QR-коду прямо с баланса M1plus wallet.\n\nНажмите «Терминал» на главном экране, наведите камеру на QR — и готово. Деньги списываются мгновенно, без комиссии.",'emoji'=>'✌️','icon'=>'qr','grad_from'=>'#7c3aed','grad_to'=>'#3b0764','image'=>null],
        ['title'=>'Выпускайте карты','body'=>"Виртуальные карты M1plus — с нулевым балансом или предоплаченные, в рублях, долларах и евро.\n\nЗайдите в раздел «Карты» и выберите подходящую.",'emoji'=>'💳','icon'=>'card','grad_from'=>'#0d9488','grad_to'=>'#134e4a','image'=>null],
        ['title'=>'Переводы без комиссии','body'=>"Переводите деньги другим пользователям M1plus wallet по никнейму — комиссия 0 ₽, зачисление мгновенно.",'emoji'=>'⚡','icon'=>'bolt','grad_from'=>'#d97706','grad_to'=>'#7c2d12','image'=>null],
        ['title'=>'Платёжные ссылки','body'=>"Создавайте ссылки для приёма платежей — отправьте ссылку, и вам переведут деньги в один клик.\n\nРаздел «Ссылки» на главном экране.",'emoji'=>'🔗','icon'=>'link','grad_from'=>'#db2777','grad_to'=>'#831843','image'=>null],
    ];
}
$storyUploadUrl = defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/';

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$ft = 'err';
if (str_starts_with($flash, 'success:')) { $flash = substr($flash, 8); $ft = 'ok'; }
if (str_starts_with($flash, 'ok:'))      { $flash = substr($flash, 3); $ft = 'ok'; }

$avatar = $user['avatar'] ? UPLOAD_URL . $user['avatar'] : null;
$bal    = (float)$acc['balance'];

function makeCheckId(int $txId, string $createdAt, string $amount): string {
    $hash = substr(hash_hmac('sha256', $txId . $createdAt . $amount, 'M1plusWalletCheck'), 0, 8);
    return 'CHK-' . $txId . '-' . $hash;
}
$hour = (int)date('G');
$greet = $hour < 5 ? 'Доброй ночи' : ($hour < 12 ? 'Доброе утро' : ($hour < 18 ? 'Добрый день' : 'Добрый вечер'));
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="M1plus">
<meta name="theme-color" content="#0a0a12">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<title>M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#0a0a12; --card:#15151f; --card2:#1b1b28; --stroke:#23233a;
  --vio:#8b5cf6; --vio-deep:#6d28d9; --vio-soft:#2d1f52; --vio-lite:#a78bfa;
  --txt:#fff; --muted:#8f8fa3; --dim:#55556b;
  --ok:#4ade80; --err:#ef4444; --warn:#facc15;
}
body{background:var(--bg);color:var(--txt);font-family:'DM Sans',sans-serif;min-height:100vh;padding:calc(env(safe-area-inset-top) + 12px) 0 calc(env(safe-area-inset-bottom) + 88px)}
.wrap{max-width:560px;margin:0 auto;padding:0 16px;display:flex;flex-direction:column;gap:16px}

/* HEADER */
.top{display:flex;align-items:center;justify-content:space-between;padding:6px 0 2px}
.top-left{display:flex;align-items:center;gap:12px}
.top-av{width:44px;height:44px;border-radius:50%;background:var(--vio-soft);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--vio-lite);font-size:1.05rem;overflow:hidden;flex-shrink:0}
.top-av img{width:100%;height:100%;object-fit:cover}
.top-greet{font-size:.78rem;color:var(--muted)}
.top-name{font-size:1.02rem;font-weight:500}
.top-icons{display:flex;gap:8px}
.icn-btn{width:40px;height:40px;border-radius:50%;background:var(--card);border:none;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer}
.icn-btn:active{background:var(--vio-soft);color:var(--vio-lite)}

/* QUICK ACTIONS */
.qa-row{display:flex;gap:8px;overflow-x:auto;padding:4px 0 2px;scrollbar-width:none}
.qa-row::-webkit-scrollbar{display:none}
.qa{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:76px;flex-shrink:0;background:none;border:none;cursor:pointer;text-decoration:none}
.qa-circle{width:54px;height:54px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;color:var(--vio-lite);transition:background .15s}
.qa:active .qa-circle{background:var(--vio-soft)}
.qa-lbl{font-size:.68rem;color:var(--muted);text-align:center;line-height:1.2}

/* SECTION HEADERS */
.sec-h{display:flex;align-items:center;justify-content:space-between;padding:2px 2px 0}
.sec-h-title{font-size:1.06rem;font-weight:700}
.sec-h-sum{font-size:.92rem;font-weight:500;color:var(--muted)}

/* ACCOUNT CARD */
.acct{background:linear-gradient(135deg,#1b1230 0%,#15151f 60%);border:1px solid var(--vio-soft);border-radius:22px;padding:18px;display:flex;flex-direction:column;gap:14px;position:relative;overflow:hidden}
.acct::after{content:'';position:absolute;top:-70px;right:-70px;width:190px;height:190px;background:radial-gradient(circle,rgba(139,92,246,.16) 0%,transparent 70%);border-radius:50%;pointer-events:none}
.acct-top{display:flex;align-items:center;justify-content:space-between}
.acct-name{font-size:.86rem;color:var(--muted)}
.acct-bal{font-size:1.9rem;font-weight:700;letter-spacing:-.01em}
.acct-bal span{font-size:1.1rem;color:var(--muted);font-weight:500}
.acct-cards{display:flex;gap:8px;flex-wrap:wrap}
.pc-delivery-banner{display:flex;align-items:center;gap:14px;background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.25);border-radius:16px;padding:14px 18px;cursor:pointer;margin-bottom:14px;min-height:0}
.pc-delivery-banner:active{background:rgba(139,92,246,.16)}
.pc-delivery-icon{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;background:var(--vio,#8b5cf6)}
.pc-delivery-text{min-width:0;flex:1}
.pc-delivery-title{font-size:.92rem;color:#e0e0ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3}
.pc-delivery-addr{font-size:.72rem;color:#8f8fa3;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3}
.mini-card{display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.05);border:1px solid var(--stroke);border-radius:12px;padding:7px 11px;text-decoration:none}
.mini-card-img{width:30px;height:19px;border-radius:3px;overflow:hidden;background:var(--vio-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mini-card-img img{width:100%;height:100%;object-fit:cover}
.mini-card-num{font-size:.78rem;color:#d9d9e8;font-weight:500}
.mini-card.add{color:var(--vio-lite);font-size:.78rem;font-weight:500}
.acct-actions{display:flex;gap:8px}
.acct-btn{flex:1;background:var(--vio);color:#fff;border:none;border-radius:13px;font-family:inherit;font-size:.84rem;font-weight:500;padding:11px;cursor:pointer;transition:background .15s;text-align:center;text-decoration:none}
.acct-btn:active{background:var(--vio-deep)}
.acct-btn.ghost{background:rgba(255,255,255,.07);color:#fff}
.acct-btn.ghost:active{background:rgba(255,255,255,.13)}

/* GENERIC CARD */
.card{background:var(--card);border-radius:20px;padding:18px 16px;display:flex;flex-direction:column;gap:13px}
.card-title{font-size:1rem;font-weight:700}

/* FLASH */
.flash{font-size:.82rem;text-align:center;padding:11px;border-radius:13px;font-weight:500}
.flash.ok{color:var(--ok);background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2)}
.flash.err{color:var(--err);background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2)}

/* TABS */
.tabs{display:flex;gap:7px;flex-wrap:wrap}
.tab{background:var(--card2);border:none;border-radius:11px;color:var(--muted);font-family:inherit;font-size:.78rem;font-weight:500;padding:9px 14px;cursor:pointer;transition:all .15s;white-space:nowrap}
.tab.active{background:var(--vio);color:#fff}

/* FORM */
.flbl{font-size:.72rem;color:var(--muted);margin-bottom:6px;font-weight:500}
input[type=text],input[type=number],input[type=tel],select{width:100%;background:var(--card2);border:1px solid transparent;border-radius:13px;color:#fff;font-family:inherit;font-size:.94rem;padding:13px 14px;outline:none;display:block;-webkit-appearance:none;-moz-appearance:none}
input:focus,select:focus{border-color:var(--vio)}
input::placeholder{color:var(--dim)}
select option{background:var(--card2)}
.comm-note{font-size:.72rem;color:var(--dim)}
.sub-btn{width:100%;background:var(--vio);color:#fff;border:none;border-radius:14px;font-family:inherit;font-size:.92rem;font-weight:500;padding:14px;cursor:pointer;transition:background .15s}
.sub-btn:active{background:var(--vio-deep)}
.sub-btn:disabled{background:var(--card2);color:var(--dim)}
.lookup-info{font-size:.76rem;min-height:18px;margin-top:6px;color:var(--dim)}
.lookup-ok{color:var(--ok)}
.lookup-err{color:var(--err)}

/* TX LIST */
.tx-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 2px}
.tx-item + .tx-item{border-top:1px solid rgba(255,255,255,.05)}
.tx-left{display:flex;align-items:center;gap:12px;min-width:0}
.tx-icon{width:42px;height:42px;border-radius:50%;background:var(--card2);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--vio-lite)}
.tx-type{font-size:.88rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tx-detail{font-size:.72rem;color:var(--dim);margin-top:2px}
.tx-amount{font-size:.9rem;font-weight:700;text-align:right;white-space:nowrap}
.tx-amount.in{color:var(--ok)}
.tx-st{font-size:.64rem;font-weight:500;margin-top:3px;text-align:right}
.tx-st.processing{color:var(--warn)}
.tx-st.completed{color:var(--ok)}
.tx-st.declined{color:var(--err)}
.tx-check{font-size:.64rem;color:var(--dim);text-decoration:none;display:block;text-align:right;margin-top:2px}
.tx-empty{font-size:.8rem;color:var(--dim);text-align:center;padding:20px}

/* CHAT */
.chat-messages{display:flex;flex-direction:column;gap:10px;max-height:340px;overflow-y:auto;padding:4px 0}
.chat-messages::-webkit-scrollbar{width:4px}
.chat-messages::-webkit-scrollbar-thumb{background:var(--stroke);border-radius:2px}
.msg-row{display:flex;align-items:flex-end;gap:8px}
.msg-row.mine{flex-direction:row-reverse}
.msg-av{width:28px;height:28px;border-radius:50%;flex-shrink:0;background:var(--card2);overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--dim)}
.msg-av img{width:100%;height:100%;object-fit:cover}
.msg-bubble{max-width:75%;padding:10px 14px;border-radius:16px;font-size:.86rem;line-height:1.45;word-break:break-word}
.msg-bubble.theirs{background:var(--card2);color:#e4e4f0;border-bottom-left-radius:5px}
.msg-bubble.mine{background:var(--vio);color:#fff;border-bottom-right-radius:5px}
.msg-time{font-size:.62rem;color:var(--dim);margin-top:3px}
.chat-input-row{display:flex;gap:8px}
.chat-input{flex:1;background:var(--card2);border:1px solid transparent;border-radius:14px;color:#fff;font-family:inherit;font-size:.88rem;padding:12px 14px;outline:none;resize:none;display:block;width:100%}
.chat-input:focus{border-color:var(--vio)}
.chat-send{background:var(--vio);color:#fff;border:none;border-radius:14px;padding:10px 15px;cursor:pointer;flex-shrink:0;display:flex;align-items:center}
.chat-send:active{background:var(--vio-deep)}

/* BOTTOM NAV */
.bnav{position:fixed;bottom:0;left:0;right:0;background:rgba(14,14,22,.92);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-around;padding:8px 8px calc(env(safe-area-inset-bottom) + 8px);z-index:400}
.bnav-item{display:flex;flex-direction:column;align-items:center;gap:3px;background:none;border:none;color:var(--dim);font-family:inherit;font-size:.62rem;font-weight:500;cursor:pointer;padding:4px 12px;text-decoration:none;min-width:60px}
.bnav-item.active{color:var(--vio-lite)}

/* MODALS */
.modal-overlay{position:fixed;inset:0;background:rgba(5,5,10,.85);z-index:500;display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border-radius:26px 26px 0 0;padding:24px 20px calc(env(safe-area-inset-bottom) + 24px);max-width:560px;width:100%;display:flex;flex-direction:column;gap:14px;max-height:88vh;overflow-y:auto}
.modal-grab{width:38px;height:4px;border-radius:2px;background:var(--stroke);align-self:center;margin-bottom:2px}
.modal-title{font-size:1.06rem;font-weight:700}
.modal-desc{font-size:.84rem;color:var(--muted);line-height:1.5}
.modal-err{font-size:.76rem;color:var(--err);min-height:16px}
.modal-close{background:var(--card2);border:none;border-radius:13px;color:var(--muted);font-family:inherit;font-size:.84rem;font-weight:500;padding:12px;cursor:pointer;width:100%}
.credit-note{font-size:.74rem;color:var(--dim)}
.credit-note.ok{color:var(--ok)}

/* TERMINAL SCANNER */
.term-overlay{position:fixed;inset:0;background:rgba(5,5,10,.96);z-index:600;display:none;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:20px}
.term-overlay.open{display:flex}
.scanner-frame{position:relative;width:270px;height:270px;border-radius:24px;overflow:hidden;background:#000;flex-shrink:0}
.scanner-frame video{width:100%;height:100%;object-fit:cover;display:block}
.sc-c{position:absolute;width:38px;height:38px;z-index:2}
.sc-c.tl{top:10px;left:10px;border-top:3px solid var(--vio);border-left:3px solid var(--vio);border-radius:8px 0 0 0}
.sc-c.tr{top:10px;right:10px;border-top:3px solid var(--vio);border-right:3px solid var(--vio);border-radius:0 8px 0 0}
.sc-c.bl{bottom:10px;left:10px;border-bottom:3px solid var(--vio);border-left:3px solid var(--vio);border-radius:0 0 0 8px}
.sc-c.br{bottom:10px;right:10px;border-bottom:3px solid var(--vio);border-right:3px solid var(--vio);border-radius:0 0 8px 0}
.scan-line{position:absolute;left:14px;right:14px;height:2px;background:linear-gradient(90deg,transparent,var(--vio-lite),transparent);animation:scanline 2s ease-in-out infinite;z-index:2;top:50%}
@keyframes scanline{0%,100%{top:15%}50%{top:85%}}
.term-label{font-size:.78rem;color:var(--muted)}
.term-status-txt{font-size:.76rem;color:var(--err);min-height:16px;text-align:center}
.term-pay-card{background:var(--card);border-radius:22px;padding:22px 20px;max-width:310px;width:100%;display:flex;flex-direction:column;gap:14px}
.term-org-name{font-size:1.15rem;font-weight:700}
.term-pay-amt{font-size:2rem;font-weight:700}
.term-close-btn{background:var(--card);border:none;border-radius:13px;color:var(--muted);font-family:inherit;font-size:.82rem;font-weight:500;padding:11px 22px;cursor:pointer}
@keyframes pulse3{0%,100%{opacity:.25}50%{opacity:1}}

/* STORIES */
.stories-row{display:flex;gap:10px;overflow-x:auto;padding:2px 0 4px;scrollbar-width:none}
.stories-row::-webkit-scrollbar{display:none}
.story{position:relative;width:106px;height:132px;border-radius:18px;flex-shrink:0;cursor:pointer;overflow:hidden;padding:10px;display:flex;flex-direction:column;justify-content:space-between;border:none;font-family:inherit;text-align:left}
.story::before{content:'';position:absolute;top:-30px;right:-30px;width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.14)}
.story::after{content:'';position:absolute;bottom:-24px;left:-24px;width:70px;height:70px;border-radius:50%;background:rgba(0,0,0,.14)}
.story-emoji{font-size:1.7rem;position:relative;z-index:1}
.story-title{font-size:.72rem;font-weight:700;color:#fff;line-height:1.25;position:relative;z-index:1;text-shadow:0 1px 3px rgba(0,0,0,.35)}
.story.has-img::before,.story.has-img::after{display:none}
/* 3D icon composition — без плиток и без filter (drop-shadow в Safari даёт квадратный артефакт) */
.ic3d{display:flex;align-items:center;justify-content:center;color:#fff;background:none;border:none;box-shadow:none;border-radius:0}
.ic3d svg{width:88%;height:88%}
.ic3d-sm{width:38px;height:38px}
.sv-3d{position:relative;height:200px;margin:4px 0;display:none}
.sv-badge{position:absolute}
.sv-badge.main{width:140px;height:140px;left:14px;top:24px;animation:fMain 4.5s ease-in-out infinite}
.sv-badge.main::before{content:'';position:absolute;inset:-22%;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.28) 0%,rgba(255,255,255,.07) 45%,transparent 70%);z-index:-1}
.sv-badge.f1{width:56px;height:56px;right:22%;top:2px;opacity:.9;animation:fF1 5.5s ease-in-out infinite}
.sv-badge.f2{width:42px;height:42px;right:5%;top:54%;opacity:.65;animation:fF2 6s ease-in-out infinite}
.sv-badge.f3{width:32px;height:32px;left:56%;bottom:-2px;opacity:.4;animation:fF1 4.8s ease-in-out infinite}
@keyframes fMain{0%,100%{transform:rotate(-8deg) translateY(0)}50%{transform:rotate(-8deg) translateY(-11px)}}
@keyframes fF1{0%,100%{transform:rotate(13deg) translateY(0)}50%{transform:rotate(13deg) translateY(-8px)}}
@keyframes fF2{0%,100%{transform:rotate(-15deg) translateY(0)}50%{transform:rotate(-15deg) translateY(-6px)}}
.sv-img{width:100%;max-height:290px;border-radius:20px;overflow:hidden;display:none}
.sv-img img{width:100%;height:100%;object-fit:cover;display:block}
.sv-graphic{position:absolute;bottom:calc(env(safe-area-inset-bottom) + 30px);left:0;right:0;pointer-events:none;opacity:.5;z-index:1}
/* STORY VIEWER */
.story-view{position:fixed;inset:0;z-index:800;display:none;flex-direction:column;padding:calc(env(safe-area-inset-top) + 14px) 16px calc(env(safe-area-inset-bottom) + 20px)}
.story-view.open{display:flex}
.sv-bars{display:flex;gap:5px;margin-bottom:12px}
.sv-bar{flex:1;height:3px;border-radius:2px;background:rgba(255,255,255,.25);overflow:hidden}
.sv-bar-fill{height:100%;width:0;background:#fff;transition:width .1s linear}
.sv-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:auto}
.sv-brand{font-size:.76rem;font-weight:700;color:rgba(255,255,255,.9)}
.sv-close{background:rgba(0,0,0,.25);border:none;border-radius:50%;width:34px;height:34px;color:#fff;font-size:1.05rem;cursor:pointer;display:flex;align-items:center;justify-content:center}
.sv-body{display:flex;flex-direction:column;gap:16px;margin-bottom:auto;position:relative;z-index:2}
.sv-emoji{font-size:4rem}
.sv-title{font-size:1.7rem;font-weight:700;line-height:1.2;color:#fff}
.sv-text{font-size:1rem;line-height:1.55;color:rgba(255,255,255,.92)}
.sv-tap{position:absolute;top:70px;bottom:0;width:34%;z-index:3}
.sv-tap.left{left:0}
.sv-tap.right{right:0;width:66%}
.sv-deco1{position:absolute;top:16%;right:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.1);pointer-events:none}
.sv-deco2{position:absolute;bottom:-60px;left:-60px;width:200px;height:200px;border-radius:50%;background:rgba(0,0,0,.16);pointer-events:none}
/* PROFILE */
.profile-view{position:fixed;inset:0;background:var(--bg);z-index:650;display:none;flex-direction:column;overflow-y:auto;padding:calc(env(safe-area-inset-top) + 14px) 16px calc(env(safe-area-inset-bottom) + 100px)}
.profile-view.open{display:flex}
.profile-view > *{flex-shrink:0}
.pf-head{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.pf-back{width:38px;height:38px;border-radius:50%;background:var(--card);border:none;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
.pf-head-title{font-size:1.2rem;font-weight:700}
.pf-avatar-wrap{display:flex;flex-direction:column;align-items:center;gap:12px;margin-bottom:26px}
.pf-avatar{position:relative;width:104px;height:104px;border-radius:50%;background:var(--vio-soft);display:flex;align-items:center;justify-content:center;font-size:2.4rem;font-weight:700;color:var(--vio-lite);overflow:visible;cursor:pointer}
.pf-avatar-img{width:104px;height:104px;border-radius:50%;object-fit:cover;display:block}
.pf-avatar-badge{position:absolute;bottom:0;right:0;width:34px;height:34px;border-radius:50%;background:var(--vio);border:3px solid var(--bg);display:flex;align-items:center;justify-content:center;color:#fff}
.pf-name{font-size:1.25rem;font-weight:700}
.pf-nick{font-size:.84rem;color:var(--muted)}
.pf-group{background:var(--card);border-radius:18px;overflow:hidden;margin-bottom:14px}
.pf-row{display:flex;align-items:center;gap:13px;padding:15px 16px;background:none;border:none;width:100%;font-family:inherit;color:#fff;cursor:pointer;text-align:left;text-decoration:none}
.pf-row + .pf-row{border-top:1px solid rgba(255,255,255,.05)}
.pf-row:active{background:var(--card2)}
.pf-row-icon{width:38px;height:38px;border-radius:12px;background:var(--vio-soft);display:flex;align-items:center;justify-content:center;color:var(--vio-lite);flex-shrink:0}
.pf-row-icon.red{background:rgba(239,68,68,.12);color:var(--err)}
.pf-row-main{flex:1;min-width:0}
.pf-row-title{font-size:.9rem;font-weight:500}
.pf-row-sub{font-size:.72rem;color:var(--dim);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-row-arr{color:var(--dim);flex-shrink:0}
.pf-upload-note{font-size:.74rem;min-height:18px;text-align:center}
.pf-upload-note.ok{color:var(--ok)}
.pf-upload-note.err{color:var(--err)}
/* PIN SETUP OVERLAY */
.pin-setup{position:fixed;inset:0;background:var(--bg);z-index:700;display:none;flex-direction:column;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top) + 50px) 20px calc(env(safe-area-inset-bottom) + 24px)}
.pin-setup.open{display:flex}
.ps-title{font-size:1.2rem;font-weight:700;text-align:center}
.ps-sub{font-size:.84rem;color:var(--muted);text-align:center;margin-top:8px}
.ps-dots{display:flex;gap:18px;margin:30px 0 6px;justify-content:center}
.ps-dot{width:14px;height:14px;border-radius:50%;background:#232336;transition:all .15s}
.ps-dot.on{background:var(--vio);transform:scale(1.15)}
.ps-dots.shake{animation:shake .4s}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-9px)}40%{transform:translateX(9px)}60%{transform:translateX(-6px)}80%{transform:translateX(6px)}}
.ps-err{font-size:.78rem;color:var(--err);min-height:18px;text-align:center}
.ps-pad{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 26px;width:100%;max-width:300px}
.ps-key{width:76px;height:76px;border-radius:50%;background:var(--card);border:none;color:#fff;font-family:inherit;font-size:1.7rem;font-weight:500;cursor:pointer;justify-self:center;display:flex;align-items:center;justify-content:center}
.ps-key:active{background:var(--vio-soft)}
.ps-key.ghost{background:none;font-size:.76rem;color:var(--muted)}
.ps-key.ghost:active{background:var(--card)}
</style>
</head>
<body>

<div class="wrap" id="top">
  <!-- HEADER -->
  <div class="top">
    <div class="top-left">
      <div class="top-av"><?php if($avatar): ?><img src="<?php echo htmlspecialchars($avatar); ?>"><?php else: echo mb_strtoupper(mb_substr($user['username'],0,1)); endif; ?></div>
      <div>
        <div class="top-greet"><?php echo $greet; ?></div>
        <div class="top-name"><?php echo htmlspecialchars($user['username']); ?></div>
      </div>
    </div>
    <div class="top-icons">
      <form method="POST" action="auth.php" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <button class="icn-btn" type="submit" title="Выйти">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </button>
      </form>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="flash <?php echo $ft; ?>"><?php echo htmlspecialchars($flash); ?></div>
  <?php endif; ?>

  <!-- STORIES -->
  <div class="stories-row">
    <?php foreach ($stories as $i => $s):
      $sImg = !empty($s['image']) ? $storyUploadUrl . $s['image'] : null;
      $bgStyle = $sImg
        ? "background:linear-gradient(180deg,rgba(0,0,0,.05) 40%,rgba(0,0,0,.65)),url('".htmlspecialchars($sImg)."') center/cover no-repeat"
        : "background:linear-gradient(135deg,".htmlspecialchars($s['grad_from']).",".htmlspecialchars($s['grad_to']).")";
    ?>
    <button class="story <?php echo $sImg?'has-img':''; ?>" style="<?php echo $bgStyle; ?>" onclick="openStory(<?php echo $i; ?>)">
      <div class="story-emoji"><?php
        if ($sImg) { echo ''; }
        elseif (!empty($s['icon']) && isset($ICONS[$s['icon']])) { echo '<span class="ic3d ic3d-sm">'.$ICONS[$s['icon']].'</span>'; }
        else { echo htmlspecialchars($s['emoji']); }
      ?></div>
      <div class="story-title"><?php echo htmlspecialchars($s['title']); ?></div>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- QUICK ACTIONS -->
  <div class="qa-row">
    <button class="qa" onclick="document.getElementById('topupModal').classList.add('open')">
      <div class="qa-circle"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
      <div class="qa-lbl">Пополнить</div>
    </button>
    <button class="qa" onclick="openTerminalScanner()">
      <div class="qa-circle"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><path d="M21 16h-3a2 2 0 0 0-2 2v3M21 21v.01M12 7v3a2 2 0 0 1-2 2H7M3 12h.01M12 3h.01M12 12h.01M12 21v-4"/></svg></div>
      <div class="qa-lbl">Терминал</div>
    </button>
    <a class="qa" href="create_link.php">
      <div class="qa-circle"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
      <div class="qa-lbl">Ссылки</div>
    </a>
    <a class="qa" href="merchant_panel.php">
      <div class="qa-circle"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
      <div class="qa-lbl">Мерчанты</div>
    </a>
  </div>

  <!-- ACCOUNTS -->
  <div class="sec-h">
    <div class="sec-h-title">Все счета</div>
    <div class="sec-h-sum"><?php echo number_format($bal, 0, '.', ' '); ?> ₽</div>
  </div>

  <?php foreach ($plasticCards as $pc): if ($pc['is_delivered']) continue; ?>
  <div class="pc-delivery-banner" onclick="document.getElementById('pcDeliveryModal<?php echo $pc['id']; ?>').classList.add('open')">
    <div class="pc-delivery-icon">
      <svg width="24" height="14" viewBox="0 0 34 20" fill="none">
        <line x1="0" y1="5" x2="7" y2="5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" opacity=".5"/>
        <line x1="1" y1="10" x2="9" y2="10" stroke="#fff" stroke-width="1.8" stroke-linecap="round" opacity=".85"/>
        <line x1="0" y1="15" x2="7" y2="15" stroke="#fff" stroke-width="1.8" stroke-linecap="round" opacity=".5"/>
        <rect x="11" y="2" width="21" height="16" rx="3" fill="none" stroke="#fff" stroke-width="1.8"/>
        <line x1="11" y1="7.5" x2="32" y2="7.5" stroke="#fff" stroke-width="1.8"/>
      </svg>
    </div>
    <div class="pc-delivery-text">
      <div class="pc-delivery-title">Доставка дебетовой карты: <?php echo htmlspecialchars($pc['name']); ?><?php echo $pc['delivery_time'] ? ', с ' . htmlspecialchars($pc['delivery_time']) : ''; ?></div>
      <?php if ($pc['delivery_address']): ?><div class="pc-delivery-addr"><?php echo htmlspecialchars($pc['delivery_address']); ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="acct">
    <div class="acct-cards">
      <?php foreach ($userCards as $c):
        $last4 = $c['card_number'] ? substr(preg_replace('/\D/','',$c['card_number']), -4) : '••••';
        $coverUrl = $c['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $c['cover_image'] : null;
      ?>
      <a class="mini-card" href="cards.php?id=<?php echo (int)$c['id']; ?>">
        <div class="mini-card-img">
          <?php if ($coverUrl): ?><img src="<?php echo htmlspecialchars($coverUrl); ?>">
          <?php else: ?><svg width="16" height="11" viewBox="0 0 24 15" fill="none" stroke="#a78bfa" stroke-width="1.5"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg><?php endif; ?>
        </div>
        <div class="mini-card-num">•<?php echo htmlspecialchars($last4); ?></div>
      </a>
      <?php endforeach; ?>
      <?php foreach ($plasticCards as $pc):
        $pcDeliveredMini = (bool)$pc['is_delivered'];
        $pcLast4Mini = $pc['card_number'] ? substr($pc['card_number'], -4) : '••••';
        $pcCoverMini = ($pcDeliveredMini && $pc['cover_image']) ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $pc['cover_image'] : null;
      ?>
      <a class="mini-card" href="cards.php?pcard=<?php echo (int)$pc['id']; ?>">
        <div class="mini-card-img">
          <?php if ($pcCoverMini): ?><img src="<?php echo htmlspecialchars($pcCoverMini); ?>">
          <?php else: ?><svg width="16" height="11" viewBox="0 0 24 15" fill="none" stroke="#a78bfa" stroke-width="1.5"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg><?php endif; ?>
        </div>
        <div class="mini-card-num">•<?php echo htmlspecialchars($pcLast4Mini); ?></div>
      </a>
      <?php endforeach; ?>
      <a class="mini-card add" href="cards.php">+ Карта</a>
      <a class="mini-card add" href="mycard">📦 Заказать пластик</a>
    </div>
  </div>

  <!-- HISTORY -->
  <div class="card" id="historySec">
    <div class="card-title">История</div>
    <div>
    <?php if (empty($txs)): ?>
      <div class="tx-empty">Операций пока нет</div>
    <?php else: foreach ($txs as $tx):
      if (!empty($tx['_external'])):
        $isIn = ($tx['direction'] ?? 'out') === 'in';
      ?>
    <div class="tx-item">
      <div class="tx-left">
        <div class="tx-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
        <div style="min-width:0">
          <div class="tx-type"><?php echo htmlspecialchars($tx['title']); ?></div>
          <div class="tx-detail"><?php echo date('d.m H:i', strtotime($tx['created_at'])); ?></div>
        </div>
      </div>
      <div>
        <div class="tx-amount <?php echo $isIn ? 'in' : ''; ?>">
          <?php echo ($isIn ? '+' : '−') . number_format(abs((float)$tx['amount']), 0, '.', ' '); ?> ₽
        </div>
        <div class="tx-st completed">Выполнен</div>
      </div>
    </div>
      <?php continue; endif;
      $type = $tx['type'] ?? '';
      $icons = [
        'card'         => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        'sbp'          => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>',
        'user_out'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>',
        'user_in'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="17" y1="7" x2="7" y2="17"/><polyline points="17 17 7 17 7 7"/></svg>',
        'payment_link' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'payment_sent' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><path d="M21 16h-3a2 2 0 0 0-2 2v3M12 7v3a2 2 0 0 1-2 2H7"/></svg>',
      ];
      $icon = $icons[$type] ?? $icons['card'];
      $typeLabel = match($type) {
        'card'         => 'На карту',
        'sbp'          => 'СБП — ' . htmlspecialchars($tx['bank_name'] ?? ''),
        'user_out'     => 'Перевод пользователю',
        'user_in'      => 'Входящий перевод',
        'payment_link' => 'Платёжная ссылка',
        'payment_sent' => 'Оплата',
        'topup'        => 'Пополнение',
        default        => htmlspecialchars($type)
      };
      $detail = match($type) {
        'card' => '•••• ' . substr($tx['card_number'] ?? '', -4),
        'sbp'  => htmlspecialchars($tx['phone'] ?? ''),
        default => ''
      };
      $isIn   = in_array($type, ['user_in', 'payment_link', 'topup']);
      $checkId = makeCheckId((int)$tx['id'], $tx['created_at'], (string)$tx['amount']);
      $autoDone = in_array($type, ['user_in','user_out','payment_link','payment_sent']);
      $stCls = $autoDone ? 'completed' : $tx['status'];
      $sm = ['processing'=>'В обработке','completed'=>'Выполнен','declined'=>'Отклонён'];
      $stTxt = $autoDone ? 'Выполнен' : ($sm[$tx['status']] ?? $tx['status']);
    ?>
    <div class="tx-item">
      <div class="tx-left">
        <div class="tx-icon"><?php echo $icon; ?></div>
        <div style="min-width:0">
          <div class="tx-type"><?php echo $typeLabel; ?></div>
          <div class="tx-detail"><?php if ($detail) echo $detail . ' · '; ?><?php echo date('d.m H:i', strtotime($tx['created_at'])); ?></div>
        </div>
      </div>
      <div>
        <div class="tx-amount <?php echo $isIn ? 'in' : ''; ?>">
          <?php echo ($isIn ? '+' : '−') . number_format((float)$tx['amount'], 0, '.', ' '); ?> ₽
          <?php if ((float)($tx['commission'] ?? 0) > 0): ?><span style="font-size:.66rem;color:var(--dim)">+<?php echo intval($tx['commission']); ?></span><?php endif; ?>
        </div>
        <div class="tx-st <?php echo $stCls; ?>"><?php echo $stTxt; ?></div>
        <a href="check.php?id=<?php echo urlencode($checkId); ?>" target="_blank" class="tx-check">чек</a>
      </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- SUPPORT -->
  <div class="card" id="chatSec">
    <div class="card-title">Поддержка</div>
    <div class="chat-messages" id="chatBox"></div>
    <div class="chat-input-row">
      <textarea class="chat-input" id="chatInput" rows="1" placeholder="Написать сообщение..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMsg();}"></textarea>
      <button class="chat-send" onclick="sendMsg()">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </div>
  </div>
</div>

<?php foreach ($plasticCards as $pc): if ($pc['is_delivered']) continue;
  $pcModalStatuses = pc_delivery_statuses();
  $pcModalStatusLabel = $pcModalStatuses[$pc['delivery_status']] ?? $pcModalStatuses['to_factory'];
  $pcModalIsRepEnroute = in_array($pc['delivery_status'], ['with_rep','rep_enroute'], true);
  $pcModalRepPhotoUrl = $pc['rep_photo'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $pc['rep_photo'] : null;
?>
<div class="modal-overlay" id="pcDeliveryModal<?php echo $pc['id']; ?>" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-grab"></div>
    <div class="modal-title">Доставка: <?php echo htmlspecialchars($pc['name']); ?></div>
    <div class="modal-desc">Статус: <b><?php echo htmlspecialchars($pcModalStatusLabel); ?></b></div>

    <?php if ($pcModalIsRepEnroute): ?>
    <div style="background:var(--card2);border-radius:14px;padding:14px">
      <?php if ($pcModalRepPhotoUrl): ?>
        <img src="<?php echo htmlspecialchars($pcModalRepPhotoUrl); ?>" alt="Представитель" style="width:72px;height:72px;object-fit:cover;border-radius:12px;margin-bottom:8px">
      <?php elseif ($pc['rep_name']): ?>
        <div style="font-size:.9rem;color:#e0e0ff;margin-bottom:4px"><?php echo htmlspecialchars($pc['rep_name']); ?></div>
      <?php endif; ?>
      <?php if ($pcModalRepPhotoUrl && $pc['rep_name']): ?>
      <div style="font-size:.86rem;color:#e0e0ff"><?php echo htmlspecialchars($pc['rep_name']); ?></div>
      <?php endif; ?>
      <?php if ($pc['rep_phone']): ?>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px"><?php echo htmlspecialchars($pc['rep_phone']); ?></div>
      <?php endif; ?>
      <div style="font-size:.76rem;color:#facc15;margin-top:10px;line-height:1.5">Возьмите с собой оригинал паспорта РФ — представитель сфотографирует документы при встрече.</div>
    </div>
    <?php endif; ?>

    <?php if ($pc['delivery_address'] || $pc['delivery_date'] || $pc['delivery_time']): ?>
    <div>
      <div class="flbl">Адрес доставки</div>
      <div class="modal-desc" style="margin-top:2px">
        <?php echo $pc['delivery_address'] ? nl2br(htmlspecialchars($pc['delivery_address'])) : '—'; ?>
        <?php if ($pc['delivery_date'] || $pc['delivery_time']): ?>
        <br><?php if ($pc['delivery_date']): ?><?php echo date('d.m.Y', strtotime($pc['delivery_date'])); ?><?php endif; ?>
        <?php if ($pc['delivery_time']): ?> в <?php echo htmlspecialchars($pc['delivery_time']); ?><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($pcModalIsRepEnroute): ?>
    <button class="acct-btn ghost" onclick="var p=document.getElementById('pcReschedule<?php echo $pc['id']; ?>'); p.style.display = p.style.display==='none' ? 'block' : 'none';">Отменить или перенести встречу</button>
    <div class="modal-desc" id="pcReschedule<?php echo $pc['id']; ?>" style="display:none">
      Чтобы отменить или перенести встречу, свяжитесь с представителем<?php echo $pc['rep_phone'] ? ': ' . htmlspecialchars($pc['rep_phone']) : '.'; ?>
    </div>
    <?php endif; ?>

    <button class="modal-close" onclick="document.getElementById('pcDeliveryModal<?php echo $pc['id']; ?>').classList.remove('open')">Закрыть</button>
  </div>
</div>
<?php endforeach; ?>

<!-- BOTTOM NAV -->
<div class="bnav">
  <button class="bnav-item active" data-nav onclick="navGo(this,'top')">
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
    Главная
  </button>
  <button class="bnav-item" data-nav onclick="navGo(this,'transferSec')">
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>
    Платежи
  </button>
  <button class="bnav-item" data-nav onclick="navGo(this,'historySec')">
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    История
  </button>
  <button class="bnav-item" data-nav onclick="navGo(this,'chatSec')">
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    Чат
  </button>
  <button class="bnav-item" data-nav id="navProfile" onclick="openProfile(this)">
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>
    Профиль
  </button>
</div>

<!-- TOPUP MODAL -->
<div class="modal-overlay" id="topupModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-grab"></div>
    <div class="modal-title">Пополнение баланса</div>
    <div class="modal-desc">Оплата через YooMoney. Комиссия 5% — вводите сумму к оплате, на баланс зачислится 95%.</div>
    <div>
      <div class="flbl">Сумма к оплате (мин. 200 ₽)</div>
      <input type="number" id="topupAmt" min="200" step="1" placeholder="500" oninput="updateCredit()">
      <div class="credit-note" id="creditNote" style="margin-top:7px">Зачислится: —</div>
    </div>
    <div class="modal-err" id="topupErr"></div>
    <button class="sub-btn" id="topupPayBtn" onclick="startTopup()">Оплатить</button>
    <button class="modal-close" onclick="document.getElementById('topupModal').classList.remove('open')">Отмена</button>
  </div>
</div>

<!-- TERMINAL SCANNER OVERLAY -->
<div class="term-overlay" id="termOverlay">
  <div style="font-size:.82rem;font-weight:700;color:var(--vio-lite)">Оплата по терминалу</div>

  <div id="termViewScan" style="display:flex;flex-direction:column;align-items:center;gap:14px">
    <div class="scanner-frame">
      <video id="termVideo" autoplay playsinline muted></video>
      <div class="sc-c tl"></div><div class="sc-c tr"></div><div class="sc-c bl"></div><div class="sc-c br"></div>
      <div class="scan-line"></div>
    </div>
    <div class="term-label">Наведите на QR-код на терминале M1plus wallet</div>
    <div style="font-size:.68rem;color:var(--dim);text-align:center;max-width:260px;line-height:1.4">Оплата возможна только по QR-коду терминала M1plus wallet</div>
    <div class="term-status-txt" id="termScanErr"></div>
  </div>

  <div id="termViewWait" style="display:none;flex-direction:column;align-items:center;gap:14px">
    <div class="term-pay-card" style="text-align:center;align-items:center">
      <div style="font-size:.72rem;color:var(--muted)">Получатель</div>
      <div class="term-org-name" id="termWaitOrgName"></div>
      <div style="font-size:.68rem;color:var(--dim)">M1plus wallet</div>
      <div style="font-size:.8rem;color:var(--muted);padding:8px 0">Ожидание суммы...</div>
      <div style="display:flex;gap:6px;justify-content:center">
        <span style="width:6px;height:6px;border-radius:50%;background:var(--vio);animation:pulse3 1.5s ease-in-out infinite"></span>
        <span style="width:6px;height:6px;border-radius:50%;background:var(--vio);animation:pulse3 1.5s ease-in-out infinite .3s"></span>
        <span style="width:6px;height:6px;border-radius:50%;background:var(--vio);animation:pulse3 1.5s ease-in-out infinite .6s"></span>
      </div>
    </div>
  </div>

  <div id="termViewPay" style="display:none;flex-direction:column;align-items:center;gap:14px">
    <div class="term-pay-card">
      <div>
        <div style="font-size:.72rem;color:var(--muted);margin-bottom:5px">Получатель</div>
        <div class="term-org-name" id="termPayOrgName"></div>
        <div style="font-size:.68rem;color:var(--dim);margin-top:3px">M1plus wallet</div>
      </div>
      <div style="height:1px;background:rgba(255,255,255,.07)"></div>
      <div>
        <div style="font-size:.72rem;color:var(--muted);margin-bottom:4px">К оплате</div>
        <div class="term-pay-amt" id="termPayAmtDisplay"></div>
      </div>
      <div style="font-size:.74rem;color:var(--dim)" id="termBalNote"></div>
      <div class="flash" id="termPayResult" style="display:none"></div>
      <button class="sub-btn" id="termPayConfirmBtn" onclick="termDoPay()">Оплатить</button>
    </div>
  </div>

  <button class="term-close-btn" onclick="closeTerminal()">Закрыть</button>
</div>

<!-- STORY VIEWER -->
<div class="story-view" id="storyView">
  <div class="sv-deco1"></div>
  <div class="sv-deco2"></div>
  <div class="sv-bars" id="svBars"></div>
  <div class="sv-head">
    <div class="sv-brand">M1plus wallet</div>
    <button class="sv-close" onclick="closeStory()">✕</button>
  </div>
  <div class="sv-body">
    <div class="sv-img" id="svImgWrap"><img id="svImg" alt=""></div>
    <div class="sv-3d" id="sv3d">
      <div class="sv-badge main ic3d" id="svBadgeMain"></div>
      <div class="sv-badge f1 ic3d" id="svBadgeF1"></div>
      <div class="sv-badge f2 ic3d" id="svBadgeF2"></div>
      <div class="sv-badge f3 ic3d" id="svBadgeF3"></div>
    </div>
    <div class="sv-emoji" id="svEmoji"></div>
    <div class="sv-title" id="svTitle"></div>
    <div class="sv-text" id="svText"></div>
  </div>
  <svg class="sv-graphic" viewBox="0 0 400 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
    <rect x="30"  y="72" width="26" height="48" rx="5" fill="rgba(255,255,255,.22)"/>
    <rect x="72"  y="54" width="26" height="66" rx="5" fill="rgba(255,255,255,.30)"/>
    <rect x="114" y="64" width="26" height="56" rx="5" fill="rgba(255,255,255,.22)"/>
    <rect x="156" y="38" width="26" height="82" rx="5" fill="rgba(255,255,255,.38)"/>
    <rect x="198" y="50" width="26" height="70" rx="5" fill="rgba(255,255,255,.26)"/>
    <rect x="240" y="24" width="26" height="96" rx="5" fill="rgba(255,255,255,.44)"/>
    <path d="M30 80 L85 62 L127 70 L169 42 L211 56 L253 26 L330 12" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="330" cy="12" r="6" fill="#fff"/>
  </svg>
  <div style="height:40px"></div>
  <div class="sv-tap left" onclick="storyPrev()"></div>
  <div class="sv-tap right" onclick="storyNext()"></div>
</div>

<!-- PROFILE -->
<div class="profile-view" id="profileView">
  <div class="pf-head">
    <button class="pf-back" onclick="closeProfile()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    </button>
    <div class="pf-head-title">Профиль</div>
  </div>

  <div class="pf-avatar-wrap">
    <div class="pf-avatar" onclick="pickAvatar()">
      <?php if($avatar): ?>
        <img src="<?php echo htmlspecialchars($avatar); ?>" class="pf-avatar-img" id="pfAvatarImg">
      <?php else: ?>
        <span id="pfAvatarLetter"><?php echo mb_strtoupper(mb_substr($user['username'],0,1)); ?></span>
      <?php endif; ?>
      <div class="pf-avatar-badge">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      </div>
    </div>
    <input type="file" id="avFile" accept="image/*" style="display:none">
    <div class="pf-name"><?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></div>
    <div class="pf-nick">@<?php echo htmlspecialchars($user['username']); ?></div>
    <div class="pf-upload-note" id="avNote"></div>
  </div>

  <div class="pf-group">
    <div class="pf-row" style="cursor:default">
      <div class="pf-row-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
      <div class="pf-row-main">
        <div class="pf-row-title">Почта</div>
        <div class="pf-row-sub"><?php echo htmlspecialchars($user['email'] ?? '—'); ?></div>
      </div>
    </div>
    <div class="pf-row" style="cursor:default">
      <div class="pf-row-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      </div>
      <div class="pf-row-main">
        <div class="pf-row-title">Рублёвый счёт</div>
        <div class="pf-row-sub"><?php echo number_format($bal, 2, ',', ' '); ?> ₽</div>
      </div>
    </div>
  </div>

  <div class="pf-group">
    <button class="pf-row" onclick="changePinFromProfile()">
      <div class="pf-row-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div class="pf-row-main">
        <div class="pf-row-title">Сменить код доступа</div>
        <div class="pf-row-sub">4-значный код для входа</div>
      </div>
      <div class="pf-row-arr"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></div>
    </button>
    <a class="pf-row" href="cards.php">
      <div class="pf-row-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      </div>
      <div class="pf-row-main">
        <div class="pf-row-title">Мои карты</div>
        <div class="pf-row-sub"><?php echo count($userCards); ?> шт.</div>
      </div>
      <div class="pf-row-arr"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></div>
    </a>
  </div>

  <div class="pf-group">
    <button class="pf-row" onclick="forgetThisDevice()">
      <div class="pf-row-icon red">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
      </div>
      <div class="pf-row-main">
        <div class="pf-row-title" style="color:var(--err)">Выйти на этом устройстве</div>
        <div class="pf-row-sub">Забыть код и устройство</div>
      </div>
    </button>
    <form method="POST" action="auth.php" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <button class="pf-row" type="submit">
        <div class="pf-row-icon red">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </div>
        <div class="pf-row-main">
          <div class="pf-row-title" style="color:var(--err)">Выйти из аккаунта</div>
        </div>
      </button>
    </form>
  </div>
</div>

<!-- PIN SETUP -->
<div class="pin-setup <?php echo $needPinSetup ? 'open' : ''; ?>" id="pinSetup">
  <div>
    <div class="ps-title" id="psTitle">Придумайте код</div>
    <div class="ps-sub" id="psSub">4 цифры для быстрого входа в приложение</div>
    <div class="ps-dots" id="psDots"><div class="ps-dot"></div><div class="ps-dot"></div><div class="ps-dot"></div><div class="ps-dot"></div></div>
    <div class="ps-err" id="psErr"></div>
  </div>
  <div class="ps-pad">
    <button class="ps-key" onclick="pk(1)">1</button><button class="ps-key" onclick="pk(2)">2</button><button class="ps-key" onclick="pk(3)">3</button>
    <button class="ps-key" onclick="pk(4)">4</button><button class="ps-key" onclick="pk(5)">5</button><button class="ps-key" onclick="pk(6)">6</button>
    <button class="ps-key" onclick="pk(7)">7</button><button class="ps-key" onclick="pk(8)">8</button><button class="ps-key" onclick="pk(9)">9</button>
    <button class="ps-key ghost" onclick="skipPin()">Позже</button>
    <button class="ps-key" onclick="pk(0)">0</button>
    <button class="ps-key ghost" onclick="pback()">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/></svg>
    </button>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jsQR/1.4.0/jsQR.min.js"></script>
<script>
var MY_BALANCE = <?php echo json_encode($bal); ?>;

/* ===== PIN SETUP ===== */
var psPin='', psFirst=null, psBusy=false;
function psRender(){
  document.querySelectorAll('.ps-dot').forEach(function(d,i){d.className='ps-dot'+(i<psPin.length?' on':'');});
}
function pk(n){
  if(psBusy||psPin.length>=4)return;
  psPin+=n; psRender();
  if(psPin.length===4) psStep();
}
function pback(){ if(psBusy)return; psPin=psPin.slice(0,-1); psRender(); }
function psStep(){
  if(psFirst===null){
    psFirst=psPin; psPin='';
    document.getElementById('psTitle').textContent='Повторите код';
    document.getElementById('psSub').textContent='Введите код ещё раз';
    document.getElementById('psErr').textContent='';
    setTimeout(psRender,150);
  } else if(psPin===psFirst){
    psBusy=true;
    var fd=new FormData(); fd.append('action','setup'); fd.append('pin',psPin);
    fetch('pin_api.php',{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.ok){ document.getElementById('pinSetup').classList.remove('open'); }
        else { document.getElementById('psErr').textContent=d.error||'Ошибка'; psBusy=false; psReset(); }
      })
      .catch(function(){ psBusy=false; });
  } else {
    var box=document.getElementById('psDots');
    box.classList.add('shake');
    document.getElementById('psErr').textContent='Коды не совпадают';
    if(navigator.vibrate)navigator.vibrate(200);
    setTimeout(function(){ box.classList.remove('shake'); psReset(); },500);
  }
}
function psReset(){
  psPin=''; psFirst=null;
  document.getElementById('psTitle').textContent='Придумайте код';
  document.getElementById('psSub').textContent='4 цифры для быстрого входа в приложение';
  psRender();
}
function skipPin(){ document.getElementById('pinSetup').classList.remove('open'); }

/* ===== NAV ===== */
function navGo(btn,id){
  document.querySelectorAll('[data-nav]').forEach(function(b){b.classList.remove('active');});
  btn.classList.add('active');
  scrollToId(id);
}
function scrollToId(id){
  var el=document.getElementById(id);
  if(el) el.scrollIntoView({behavior:'smooth',block:'start'});
}

/* ===== TABS ===== */
function switchTab(t) {
  document.getElementById('userForm').style.display = t==='user' ? 'flex' : 'none';
  document.getElementById('cardForm').style.display = t==='card' ? 'block' : 'none';
  document.getElementById('sbpForm').style.display  = t==='sbp'  ? 'block' : 'none';
  ['user','card','sbp'].forEach(function(id){
    document.getElementById('tab'+id.charAt(0).toUpperCase()+id.slice(1)).className = 'tab' + (id===t?' active':'');
  });
}
function fmtCard(el){var v=el.value.replace(/\D/g,'').substring(0,16);var o='';for(var i=0;i<v.length;i++){if(i>0&&i%4===0)o+=' ';o+=v[i];}el.value=o;}

/* ===== USER TRANSFER ===== */
var lookupTimer=null, lookupUid=null;
function lookupUser(){
  clearTimeout(lookupTimer);
  var q=document.getElementById('userUsername').value.trim().replace(/^@/,'');
  var info=document.getElementById('userInfo');
  lookupUid=null; info.textContent='';
  if(!q)return;
  lookupTimer=setTimeout(function(){
    fetch('user_lookup.php?username='+encodeURIComponent(q))
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.found){lookupUid=d.id;info.className='lookup-info lookup-ok';info.textContent='✓ '+d.username+(d.display_name&&d.display_name!==d.username?' ('+d.display_name+')':'');}
        else{lookupUid=null;info.className='lookup-info lookup-err';info.textContent='Пользователь не найден';}
      });
  },350);
}
function sendUserTransfer(){
  var amt=parseFloat(document.getElementById('userAmount').value)||0;
  var res=document.getElementById('userResult');
  var btn=document.getElementById('userBtn');
  res.style.display='none';
  if(!lookupUid){res.style.display='block';res.className='flash err';res.textContent='Выберите получателя';return;}
  if(amt<1){res.style.display='block';res.className='flash err';res.textContent='Минимальная сумма 1 ₽';return;}
  btn.disabled=true;btn.textContent='...';
  var fd=new FormData();fd.append('action','transfer_user');fd.append('to_user_id',lookupUid);fd.append('amount',amt);
  fetch('auth.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled=false;btn.textContent='Перевести';
      if(d.ok){
        if(d.check_id){window.location.href='check.php?id='+d.check_id;return;}
        res.style.display='block';res.className='flash ok';
        res.textContent='✓ Переведено '+amt.toFixed(2)+' ₽ → @'+d.to_username;
        document.getElementById('userAmount').value='';
        document.getElementById('userUsername').value='';
        document.getElementById('userInfo').textContent='';
        lookupUid=null;
        setTimeout(function(){location.reload();},1500);
      } else {res.style.display='block';res.className='flash err';res.textContent=d.error||'Ошибка';}
    })
    .catch(function(){btn.disabled=false;res.style.display='block';res.className='flash err';res.textContent='Ошибка соединения';});
}

/* ===== TOPUP ===== */
function updateCredit(){
  var amt=parseFloat(document.getElementById('topupAmt').value)||0;
  var n=document.getElementById('creditNote');
  if(amt>=200){n.className='credit-note ok';n.textContent='Зачислится: '+(Math.round(amt*0.95*100)/100).toFixed(2).replace('.',',')+' ₽';}
  else{n.className='credit-note';n.textContent='Зачислится: —';}
}
function startTopup(){
  var amt=parseFloat(document.getElementById('topupAmt').value)||0;
  var err=document.getElementById('topupErr');
  var btn=document.getElementById('topupPayBtn');
  err.textContent='';
  if(amt<200){err.textContent='Минимальная сумма 200 ₽';return;}
  btn.disabled=true;btn.textContent='...';
  var fd=new FormData();fd.append('amount',amt);
  fetch('topup_init.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.url)window.location.href=d.url;
      else{err.textContent=d.error||'Ошибка';btn.disabled=false;btn.textContent='Оплатить';}
    })
    .catch(function(){err.textContent='Ошибка соединения';btn.disabled=false;btn.textContent='Оплатить';});
}

/* ===== CHAT ===== */
function mkAvatar(avatar){
  var el=document.createElement('div');el.className='msg-av';
  if(avatar){var img=document.createElement('img');img.src=avatar;el.appendChild(img);}
  else{el.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>';}
  return el;
}
function loadMsgs(){
  fetch('support_api.php?action=get').then(function(r){return r.json();}).then(function(d){
    var box=document.getElementById('chatBox');
    if(!d.messages||!d.messages.length){box.innerHTML='<div style="font-size:.78rem;color:var(--dim);text-align:center;padding:16px">Нет сообщений</div>';return;}
    box.innerHTML='';
    d.messages.forEach(function(m){
      var isMe=!m.is_admin;
      var row=document.createElement('div');row.className='msg-row'+(isMe?' mine':'');
      var right=document.createElement('div');
      var bub=document.createElement('div');bub.className='msg-bubble '+(isMe?'mine':'theirs');bub.textContent=m.message;
      var tm=document.createElement('div');tm.className='msg-time';tm.textContent=(m.is_admin?'Поддержка':m.username)+' · '+m.time;
      right.appendChild(bub);right.appendChild(tm);
      var av=mkAvatar(m.avatar);
      if(isMe){row.appendChild(right);row.appendChild(av);}else{row.appendChild(av);row.appendChild(right);}
      box.appendChild(row);
    });
    box.scrollTop=box.scrollHeight;
  });
}
function sendMsg(){
  var inp=document.getElementById('chatInput');var msg=inp.value.trim();if(!msg)return;
  var fd=new FormData();fd.append('action','send');fd.append('message',msg);inp.value='';
  fetch('support_api.php',{method:'POST',body:fd}).then(loadMsgs);
}
loadMsgs();setInterval(loadMsgs,5000);

/* ===== TERMINAL SCANNER ===== */
var termStream=null, termToken=null, termScanning=false, termPollInt=null;

function showTermView(v){
  document.getElementById('termViewScan').style.display=v==='scan'?'flex':'none';
  document.getElementById('termViewWait').style.display=v==='wait'?'flex':'none';
  document.getElementById('termViewPay').style.display=v==='pay'?'flex':'none';
}
function openTerminalScanner(){
  termToken=null; termScanning=true;
  clearInterval(termPollInt);
  document.getElementById('termScanErr').textContent='';
  showTermView('scan');
  document.getElementById('termOverlay').classList.add('open');
  startTermCamera();
}
function closeTerminal(){
  document.getElementById('termOverlay').classList.remove('open');
  stopTermCamera();
  clearInterval(termPollInt);
  termScanning=false; termToken=null;
}
function startTermCamera(){
  if(termStream) return;
  navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}})
    .then(function(s){
      termStream=s;
      var v=document.getElementById('termVideo');
      v.srcObject=s; v.play();
      requestAnimationFrame(doScanFrame);
    })
    .catch(function(){document.getElementById('termScanErr').textContent='Нет доступа к камере';});
}
function stopTermCamera(){
  if(termStream){termStream.getTracks().forEach(function(t){t.stop();});termStream=null;}
}
function doScanFrame(){
  if(!termScanning) return;
  var v=document.getElementById('termVideo');
  if(!v||v.readyState!==v.HAVE_ENOUGH_DATA){requestAnimationFrame(doScanFrame);return;}
  var c=document.createElement('canvas');c.width=v.videoWidth;c.height=v.videoHeight;
  var ctx=c.getContext('2d');ctx.drawImage(v,0,0);
  var img=ctx.getImageData(0,0,c.width,c.height);
  var code=jsQR(img.data,img.width,img.height);
  if(code&&code.data){
    if(code.data.indexOf('terminal_scan.php?token=')>=0){
      var m=code.data.match(/token=([a-f0-9]+)/);
      if(m){
        termScanning=false;
        document.getElementById('termScanErr').textContent='';
        termToken=m[1];
        termCheckStatus();
        return;
      }
    }
    // Чужой QR — не терминал M1plus wallet
    document.getElementById('termScanErr').textContent='Это не QR-код терминала M1plus wallet';
    if(!doScanFrame._errT){
      doScanFrame._errT=setTimeout(function(){
        document.getElementById('termScanErr').textContent='';
        doScanFrame._errT=null;
      },2500);
    }
  }
  requestAnimationFrame(doScanFrame);
}
function termCheckStatus(){
  fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(termToken))
    .then(function(r){return r.json();})
    .then(function(d){
      if(!d.ok){
        document.getElementById('termScanErr').textContent='Терминал не найден';
        termScanning=true; requestAnimationFrame(doScanFrame); return;
      }
      var s=d.session;
      if(s.status==='pending'&&s.amount){
        termShowPay(s.org_name,s.amount);
      } else {
        document.getElementById('termWaitOrgName').textContent=s.org_name;
        showTermView('wait');
        clearInterval(termPollInt);
        termPollInt=setInterval(function(){
          fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(termToken))
            .then(function(r){return r.json();})
            .then(function(d2){
              if(d2.ok&&d2.session.status==='pending'&&d2.session.amount){
                clearInterval(termPollInt);
                termShowPay(d2.session.org_name,d2.session.amount);
              }
            });
        },1000);
      }
    });
}
function termShowPay(org,amount){
  document.getElementById('termPayOrgName').textContent=org;
  document.getElementById('termPayAmtDisplay').textContent=parseFloat(amount).toLocaleString('ru-RU')+' ₽';
  document.getElementById('termBalNote').textContent='Ваш баланс: '+MY_BALANCE.toLocaleString('ru-RU')+' ₽';
  var btn=document.getElementById('termPayConfirmBtn');
  btn.disabled=parseFloat(amount)>MY_BALANCE;
  btn.textContent=parseFloat(amount)>MY_BALANCE?'Недостаточно средств':'Оплатить';
  document.getElementById('termPayResult').style.display='none';
  showTermView('pay');
}
function termDoPay(){
  var btn=document.getElementById('termPayConfirmBtn');
  var res=document.getElementById('termPayResult');
  btn.disabled=true; btn.textContent='...';
  var fd=new FormData();fd.append('action','pay');fd.append('token',termToken);
  fetch('terminal_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){
        res.style.display='block';res.className='flash ok';
        res.textContent='✌️ Оплачено '+parseFloat(d.amount).toLocaleString('ru-RU')+' ₽';
        btn.style.display='none';
        setTimeout(function(){closeTerminal();location.reload();},2200);
      } else {
        btn.disabled=false;btn.textContent='Оплатить';
        res.style.display='block';res.className='flash err';res.textContent=d.error||'Ошибка';
      }
    });
}

/* ===== STORIES ===== */
var ICONS = <?php echo json_encode($ICONS, JSON_UNESCAPED_UNICODE); ?>;
var ICON_KEYS = Object.keys(ICONS);
var STORIES = <?php echo json_encode(array_map(function($s) use ($storyUploadUrl){
  return ['title'=>$s['title'],'body'=>$s['body'],'emoji'=>$s['emoji'],'icon'=>$s['icon'] ?? null,
          'from'=>$s['grad_from'],'to'=>$s['grad_to'],
          'img'=>!empty($s['image']) ? $storyUploadUrl.$s['image'] : null];
}, $stories), JSON_UNESCAPED_UNICODE); ?>;
var svIdx=0, svTimer=null, svProg=0;
var SV_DURATION=6000, SV_TICK=50;

function openStory(i){
  svIdx=i;
  renderStoryBars();
  showStorySlide();
  document.getElementById('storyView').classList.add('open');
}
function closeStory(){
  clearInterval(svTimer);
  document.getElementById('storyView').classList.remove('open');
}
function renderStoryBars(){
  var bars=document.getElementById('svBars'); bars.innerHTML='';
  STORIES.forEach(function(_,i){
    var b=document.createElement('div');b.className='sv-bar';
    var f=document.createElement('div');f.className='sv-bar-fill';f.id='svFill'+i;
    if(i<svIdx)f.style.width='100%';
    b.appendChild(f);bars.appendChild(b);
  });
}
function showStorySlide(){
  clearInterval(svTimer); svProg=0;
  var s=STORIES[svIdx];
  var view=document.getElementById('storyView');
  view.style.background='linear-gradient(160deg,'+s.from+','+s.to+')';
  var imgWrap=document.getElementById('svImgWrap');
  var img=document.getElementById('svImg');
  var d3=document.getElementById('sv3d');
  var em=document.getElementById('svEmoji');
  imgWrap.style.display='none'; d3.style.display='none'; em.style.display='none';
  if(s.img){
    img.src=s.img;
    imgWrap.style.display='block';
  } else if(s.icon && ICONS[s.icon]){
    document.getElementById('svBadgeMain').innerHTML=ICONS[s.icon];
    var others=ICON_KEYS.filter(function(k){return k!==s.icon;});
    var base=ICON_KEYS.indexOf(s.icon);
    document.getElementById('svBadgeF1').innerHTML=ICONS[others[(base+1)%others.length]];
    document.getElementById('svBadgeF2').innerHTML=ICONS[others[(base+4)%others.length]];
    document.getElementById('svBadgeF3').innerHTML=ICONS[others[(base+7)%others.length]];
    d3.style.display='block';
  } else {
    em.style.display='block';
  }
  document.getElementById('svEmoji').textContent=s.emoji;
  document.getElementById('svTitle').textContent=s.title;
  document.getElementById('svText').textContent=s.body;
  STORIES.forEach(function(_,i){
    var f=document.getElementById('svFill'+i);
    if(f)f.style.width=i<svIdx?'100%':'0';
  });
  svTimer=setInterval(function(){
    svProg+=SV_TICK;
    var f=document.getElementById('svFill'+svIdx);
    if(f)f.style.width=Math.min(100,svProg/SV_DURATION*100)+'%';
    if(svProg>=SV_DURATION)storyNext();
  },SV_TICK);
}
function storyNext(){
  if(svIdx<STORIES.length-1){svIdx++;showStorySlide();}
  else closeStory();
}
function storyPrev(){
  if(svIdx>0){svIdx--;showStorySlide();}
  else{svProg=0;showStorySlide();}
}

/* ===== PROFILE ===== */
function openProfile(btn){
  document.querySelectorAll('[data-nav]').forEach(function(b){b.classList.remove('active');});
  if(btn)btn.classList.add('active');
  document.getElementById('profileView').classList.add('open');
}
function closeProfile(){
  document.getElementById('profileView').classList.remove('open');
  document.querySelectorAll('[data-nav]').forEach(function(b){b.classList.remove('active');});
  document.querySelector('[data-nav]').classList.add('active');
}
function changePinFromProfile(){
  closeProfile();
  psReset();
  document.getElementById('pinSetup').classList.add('open');
}
function forgetThisDevice(){
  if(!confirm('Выйти на этом устройстве? Понадобится полный вход по паролю.'))return;
  var fd=new FormData();fd.append('action','forget');
  fetch('pin_api.php',{method:'POST',body:fd}).then(function(){location.href='index.php';});
}

/* ===== AVATAR UPLOAD ===== */
function pickAvatar(){document.getElementById('avFile').click();}
document.getElementById('avFile').onchange=function(){
  var f=this.files[0]; if(!f)return;
  var note=document.getElementById('avNote');
  if(f.size>5*1024*1024){note.className='pf-upload-note err';note.textContent='Файл больше 5 МБ';return;}
  note.className='pf-upload-note';note.textContent='Загрузка...';
  var fd=new FormData();fd.append('action','avatar');fd.append('avatar',f);
  fetch('profile_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){
        note.className='pf-upload-note ok';note.textContent='✓ Аватар обновлён';
        setTimeout(function(){location.reload();},900);
      } else {
        note.className='pf-upload-note err';note.textContent=d.error||'Ошибка';
      }
    })
    .catch(function(){note.className='pf-upload-note err';note.textContent='Ошибка соединения';});
};

/* PWA service worker */
if('serviceWorker' in navigator){ navigator.serviceWorker.register('sw.js').catch(function(){}); }
</script>
</body>
</html>