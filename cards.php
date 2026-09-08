<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$user = $db->prepare('SELECT * FROM users WHERE id=?');
$user->execute([$uid]); $user = $user->fetch();

require_once __DIR__ . '/yoomoney_lib.php';
$siteWalletCfg = null;
try { $siteWalletCfg = ym_getConfig($db); } catch (\Throwable $e) {}
$siteWalletNum = $siteWalletCfg['wallet'] ?? null;
$acc = $db->prepare('SELECT * FROM bank_accounts WHERE user_id=?');
$acc->execute([$uid]); $acc = $acc->fetch();
$bal = (float)($acc['balance'] ?? 0);

$userCards = $db->prepare('SELECT * FROM issued_cards WHERE user_id=? ORDER BY created_at DESC');
$userCards->execute([$uid]); $userCards = $userCards->fetchAll();

$templates = $db->prepare("SELECT * FROM card_templates WHERE is_active=1 ORDER BY created_at DESC");
$templates->execute(); $templates = $templates->fetchAll();

$viewCard = null;
$viewCardTopups = [];
if (isset($_GET['id'])) {
    $cq = $db->prepare('SELECT * FROM issued_cards WHERE id=? AND user_id=?');
    $cq->execute([(int)$_GET['id'], $uid]);
    $viewCard = $cq->fetch();
    if ($viewCard && $viewCard['balance_type'] === 'zero') {
        $tq = $db->prepare('SELECT * FROM card_topups WHERE card_id=? ORDER BY created_at DESC');
        $tq->execute([(int)$viewCard['id']]);
        $viewCardTopups = $tq->fetchAll();
    }
}

/* ── Пластиковые карты (выпускаются только админом) ── */
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
$viewPlasticCard = null;
try {
    $pcs = $db->prepare('SELECT * FROM plastic_cards WHERE user_id=? ORDER BY created_at DESC');
    $pcs->execute([$uid]);
    $plasticCards = $pcs->fetchAll();

    if (isset($_GET['pcard'])) {
        $pq = $db->prepare('SELECT * FROM plastic_cards WHERE id=? AND user_id=?');
        $pq->execute([(int)$_GET['pcard'], $uid]);
        $viewPlasticCard = $pq->fetch();
    }
} catch (\Throwable $e) {
    // Таблица plastic_cards ещё не создана (migrate6.php не запускали) — просто не показываем раздел
}

$avatar = $user['avatar'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $user['avatar'] : null;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus wallet — Карты</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 80px}
.header{max-width:580px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:24px 0 26px}
.hbrand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back-btn{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.back-btn:hover{border-color:#555;color:#ccc}
.inner{max-width:580px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.sec{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px 18px;display:flex;flex-direction:column;gap:13px}
.sec-title{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.3em;color:#444;text-transform:uppercase}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#444;text-transform:uppercase;margin-bottom:5px}
input[type=text],input[type=number],select{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;display:block;-webkit-appearance:none}
input:focus,select:focus{border-color:#555}
select option{background:#111}
.sub-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;letter-spacing:.12em;padding:11px;cursor:pointer;transition:background .2s}
.sub-btn:hover{background:#e0e0e0}
.sub-btn:disabled{background:#1a1a1a;color:#333;cursor:default}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;text-align:center;padding:10px;border-radius:10px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.tmpl-grid{display:flex;flex-direction:column;gap:10px}
.tmpl-item{border:2px solid #1e1e1e;border-radius:14px;padding:14px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:13px;background:#0a0a0a}
.tmpl-item:hover{border-color:#333}
.tmpl-item.selected{border-color:#fff}
.tmpl-cover{width:56px;height:35px;border-radius:4px;overflow:hidden;flex-shrink:0;background:#1a1a1a}
.tmpl-cover img{width:100%;height:100%;object-fit:cover;border-radius:4px}
.tmpl-info{flex:1}
.tmpl-name{font-size:.9rem;color:#ccc;margin-bottom:3px}
.tmpl-cost{font-family:'Space Mono',monospace;font-size:.58rem;color:#555}
.tmpl-badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.tmpl-type{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.14em;padding:2px 7px;border-radius:5px;display:inline-block}
.tmpl-instant{color:#4ade80;background:#001a08;border:1px solid #003015}
.tmpl-wait{color:#aaa;background:#111;border:1px solid #1e1e1e}
.tmpl-qty{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.1em;padding:2px 7px;border-radius:5px;color:#facc15;background:#1a1200;border:1px solid #2a1e00}
.opt-row{display:flex;gap:8px}
.opt-btn{flex:1;background:#0a0a0a;border:2px solid #1e1e1e;border-radius:11px;color:#555;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.1em;padding:10px 8px;cursor:pointer;transition:all .2s;text-align:center}
.opt-btn:hover{border-color:#333;color:#aaa}
.opt-btn.sel{border-color:#fff;color:#fff}
.summary-box{background:#0a0a0a;border:1px solid #1a1a1a;border-radius:12px;padding:13px 15px;display:flex;flex-direction:column;gap:6px}
.sum-row{display:flex;justify-content:space-between;align-items:center}
.sum-lbl{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;color:#333}
.sum-val{font-family:'Space Mono',monospace;font-size:.68rem;color:#e0e0e0}
.sum-comm{font-family:'Space Mono',monospace;font-size:.68rem;color:#facc15}
.sum-total{font-family:'Space Mono',monospace;font-size:.9rem;color:#f0f0f0;font-weight:bold}
.sum-divider{height:1px;background:#1a1a1a;margin:3px 0}
.note-info{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;color:#333;line-height:1.5;padding:9px 12px;background:#0a0a0a;border:1px solid #161616;border-radius:10px}
.note-info a{color:#555;transition:color .2s}
.note-info a:hover{color:#ccc}
.card-visual{width:100%;max-width:340px;aspect-ratio:1.586;border-radius:14px;overflow:hidden;margin:0 auto;position:relative;background:#1a1a1a;display:block}
.card-visual img{width:100%;height:100%;object-fit:cover;border-radius:14px}
.card-visual-placeholder{width:100%;max-width:340px;aspect-ratio:1.586;border-radius:14px;margin:0 auto;background:linear-gradient(135deg,#1a1a1a,#111);border:1px solid #1e1e1e;display:flex;align-items:center;justify-content:center;color:#2a2a2a}
.card-data-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#0a0a0a;border:1px solid #161616;border-radius:12px}
.card-data-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.2em;color:#333;text-transform:uppercase}
.card-data-val{font-family:'Space Mono',monospace;font-size:.78rem;color:#e0e0e0}
.card-status{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.14em;padding:4px 10px;border-radius:7px;display:inline-block}
.st-active{color:#4ade80;background:#001a08;border:1px solid #002a10}
.st-pending{color:#aaa;background:#111;border:1px solid #1e1e1e}
.topup-hist-item{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#0a0a0a;border:1px solid #161616;border-radius:10px}
.th-date{font-family:'Space Mono',monospace;font-size:.5rem;color:#333}
.th-amt{font-family:'Space Mono',monospace;font-size:.72rem;color:#4ade80}
.reveal-btn{background:none;border:1px solid #2a2a2a;border-radius:10px;color:#555;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:9px 16px;cursor:pointer;transition:all .2s;width:100%;text-align:center}
.reveal-btn:hover{border-color:#555;color:#ccc}
.purpose-block{background:#0a0a0a;border:1px solid #161616;border-left:2px solid #2a2a2a;border-radius:10px;padding:10px 13px}
.purpose-lbl{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.18em;color:#2a2a2a;text-transform:uppercase;margin-bottom:5px}
.purpose-txt{font-size:.82rem;color:#666;line-height:1.5}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:#111;border:1px solid #1e1e1e;border-radius:20px;padding:26px;max-width:340px;width:100%;display:flex;flex-direction:column;gap:14px}
.modal-title{font-family:'Space Mono',monospace;font-size:.66rem;letter-spacing:.22em;color:#888}
.modal-desc{font-size:.82rem;color:#555;line-height:1.5}
.modal-close{background:none;border:1px solid #1e1e1e;border-radius:11px;color:#444;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.14em;padding:9px 14px;cursor:pointer;align-self:flex-end;transition:all .2s}
.modal-close:hover{border-color:#555;color:#ccc}
.modal-err{font-family:'Space Mono',monospace;font-size:.54rem;color:#f87171;min-height:14px}
#langBtn{position:fixed;bottom:20px;right:20px;background:#111;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.18em;padding:8px 14px;cursor:pointer}
#langBtn:hover{color:#ccc;border-color:#555}
</style>
</head>
<body>
<div class="header">
  <div class="hbrand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="dashboard.php" class="back-btn">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Назад
  </a>
</div>

<div class="inner">

<?php if ($viewPlasticCard): ?>
  <?php
    $pcCover     = $viewPlasticCard['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $viewPlasticCard['cover_image'] : null;
    $pcDelivered = (bool)$viewPlasticCard['is_delivered'];
    $pcLast4     = $viewPlasticCard['card_number'] ? substr($viewPlasticCard['card_number'], -4) : '0000';
  ?>
  <div class="sec-title">ПЛАСТИКОВАЯ КАРТА</div>

  <?php if (isset($_GET['topup'])): ?>
  <div class="note-info" style="border-color:#1a3a1a;background:#0a100a;color:#4ade80">Платёж отправлен в ЮMoney. Баланс обновится автоматически в течение нескольких секунд.</div>
  <?php endif; ?>

  <?php if ($pcDelivered): ?>
    <?php if ($pcCover): ?>
    <div class="card-visual"><img src="<?php echo htmlspecialchars($pcCover); ?>" alt="card"></div>
    <?php else: ?>
    <div class="card-visual-placeholder">
      <svg width="48" height="32" viewBox="0 0 24 15" fill="none" stroke="#333" stroke-width="1.2" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/><rect x="2" y="7" width="4" height="3" rx=".5"/></svg>
    </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="card-visual-placeholder">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 8v6M19 11h6"/></svg>
    </div>
  <?php endif; ?>

  <div class="sec">
    <div class="card-data-row">
      <div>
        <div class="card-data-lbl">Карта</div>
        <div class="card-data-val"><?php echo $pcDelivered ? htmlspecialchars($viewPlasticCard['name']) . ' · **** ' . htmlspecialchars($pcLast4) : 'Готовится к доставке'; ?></div>
      </div>
      <div class="card-status <?php echo $pcDelivered ? 'st-active' : 'st-pending'; ?>">
        <?php echo $pcDelivered ? 'ВЫДАНА' : 'В ДОСТАВКЕ'; ?>
      </div>
    </div>

    <div class="card-data-row">
      <div>
        <div class="card-data-lbl">Баланс</div>
        <div class="card-data-val" id="pcLiveBal" style="font-size:1rem">—</div>
        <div id="pcLiveBalErr" style="font-family:'Space Mono',monospace;font-size:.5rem;color:#f87171;margin-top:3px;display:none;word-break:break-word;line-height:1.5"></div>
      </div>
    </div>

    <?php if ($viewPlasticCard['yoo_wallet']): ?>
    <?php $pcSameWallet = $siteWalletNum && $viewPlasticCard['yoo_wallet'] === $siteWalletNum; ?>
    <button class="reveal-btn" onclick="var p=document.getElementById('pcTopupPanel'); p.style.display = p.style.display==='none' ? 'block' : 'none';" style="margin-top:2px">Пополнить баланс</button>
    <div class="purpose-block" id="pcTopupPanel" style="display:none">
      <div class="purpose-lbl">Пополнение через ЮMoney</div>
      <div class="purpose-txt" id="pcTopupFeeNote">Комиссия ЮMoney: ~3% от суммы (тариф ЮMoney за пополнение банковской картой, удерживается на их стороне).</div>
      <div class="purpose-txt" style="margin-top:6px;color:#555">Получатель: <?php echo htmlspecialchars($viewPlasticCard['yoo_wallet']); ?></div>
      <?php if ($pcSameWallet): ?>
      <div class="purpose-txt" style="margin-top:6px;color:#f87171">⚠ Это тот же кошелёк, что и основной кошелёк сайта — пополнение уйдёт на общий баланс сайта, а не на отдельный счёт этой карты. Обратитесь к администратору, чтобы переподключить карту под другим аккаунтом ЮMoney.</div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;margin-top:10px">
        <input type="number" id="pcTopupAmount" placeholder="Сумма, ₽" min="2" step="1" style="flex:1;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:9px 12px;outline:none">
        <button class="reveal-btn" style="width:auto;padding:9px 16px" onclick="startPcTopup()">Пополнить</button>
      </div>
      <div id="pcTopupErr" style="font-family:'Space Mono',monospace;font-size:.52rem;color:#f87171;margin-top:8px;display:none"></div>
    </div>
    <?php endif; ?>

    <?php if ($pcDelivered): ?>
    <div class="card-data-row">
      <div>
        <div class="card-data-lbl">Срок действия</div>
        <div class="card-data-val"><?php echo htmlspecialchars($viewPlasticCard['expiry'] ?? '—'); ?></div>
      </div>
      <div>
        <div class="card-data-lbl">ПИН-код</div>
        <div class="card-data-val"><?php echo htmlspecialchars($viewPlasticCard['pin_code'] ?? '—'); ?></div>
      </div>
    </div>
    <div class="note-info">ПИН-код запрашивается только при крупных операциях по карте — храните его отдельно от самой карты.</div>
    <?php else: ?>
    <?php
      $pcStatuses = pc_delivery_statuses();
      $pcStatusLabel = $pcStatuses[$viewPlasticCard['delivery_status']] ?? $pcStatuses['to_factory'];
      $pcIsRepEnroute = in_array($viewPlasticCard['delivery_status'], ['with_rep','rep_enroute'], true);
      $pcRepPhotoUrl = $viewPlasticCard['rep_photo'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $viewPlasticCard['rep_photo'] : null;
    ?>
    <div class="note-info">
      Статус: <b><?php echo htmlspecialchars($pcStatusLabel); ?></b>. Как только карта будет у вас на руках, здесь появятся название, фото, срок действия и ПИН-код.
    </div>

    <?php if ($pcIsRepEnroute): ?>
    <div class="purpose-block" style="border-left-color:#facc15">
      <div class="purpose-lbl">Представитель в пути</div>
      <?php if ($pcRepPhotoUrl): ?>
      <img src="<?php echo htmlspecialchars($pcRepPhotoUrl); ?>" alt="Представитель" style="width:64px;height:64px;object-fit:cover;border-radius:10px;margin:6px 0">
      <?php endif; ?>
      <?php if ($viewPlasticCard['rep_name']): ?><div class="purpose-txt"><?php echo htmlspecialchars($viewPlasticCard['rep_name']); ?></div><?php endif; ?>
      <?php if ($viewPlasticCard['rep_phone']): ?><div class="purpose-txt" style="margin-top:2px;color:#888"><?php echo htmlspecialchars($viewPlasticCard['rep_phone']); ?></div><?php endif; ?>
      <div class="purpose-txt" style="margin-top:8px;color:#facc15">Возьмите с собой оригинал паспорта РФ — представитель сфотографирует документы при встрече.</div>
    </div>

    <button class="reveal-btn" onclick="var p=document.getElementById('pcRescheduleNote'); p.style.display = p.style.display==='none' ? 'block' : 'none';" style="margin-top:2px">Отменить или перенести встречу</button>
    <div class="purpose-block" id="pcRescheduleNote" style="display:none">
      <div class="purpose-txt">Чтобы отменить или перенести встречу, свяжитесь с представителем<?php echo $viewPlasticCard['rep_phone'] ? ':' : '.'; ?></div>
      <?php if ($viewPlasticCard['rep_phone']): ?>
      <div class="purpose-txt" style="margin-top:4px;font-family:'Space Mono',monospace;color:#e0e0e0"><?php echo htmlspecialchars($viewPlasticCard['rep_phone']); ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($viewPlasticCard['delivery_address'] || $viewPlasticCard['delivery_date'] || $viewPlasticCard['delivery_time']): ?>
    <div class="purpose-block">
      <div class="purpose-lbl">Детали доставки</div>
      <?php if ($viewPlasticCard['delivery_address']): ?><div class="purpose-txt"><?php echo nl2br(htmlspecialchars($viewPlasticCard['delivery_address'])); ?></div><?php endif; ?>
      <?php if ($viewPlasticCard['delivery_date'] || $viewPlasticCard['delivery_time']): ?>
      <div class="purpose-txt" style="margin-top:6px">
        <?php if ($viewPlasticCard['delivery_date']): ?><?php echo date('d.m.Y', strtotime($viewPlasticCard['delivery_date'])); ?><?php endif; ?>
        <?php if ($viewPlasticCard['delivery_time']): ?> в <?php echo htmlspecialchars($viewPlasticCard['delivery_time']); ?><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <a href="cards.php" style="text-align:center;font-family:'Space Mono',monospace;font-size:.52rem;color:#333;text-decoration:none;padding:4px;display:block">← Все карты</a>

<?php elseif ($viewCard): ?>
  <?php
    $coverUrl  = $viewCard['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $viewCard['cover_image'] : null;
    $isPending = $viewCard['status'] === 'pending';
    $isZero    = $viewCard['balance_type'] === 'zero';
    $last4     = $viewCard['card_number'] ? substr(preg_replace('/\D/','',$viewCard['card_number']),-4) : '0000';
    $noData    = !$viewCard['card_number'];
    $currency  = $viewCard['currency'] ?? 'RUB';
    $curSym    = ['RUB'=>'₽','USD'=>'$','EUR'=>'€'][$currency] ?? '₽';
  ?>
  <div class="sec-title">ВАША КАРТА</div>

  <?php if ($coverUrl): ?>
  <div class="card-visual"><img src="<?php echo htmlspecialchars($coverUrl); ?>" alt="card"></div>
  <?php else: ?>
  <div class="card-visual-placeholder">
    <svg width="48" height="32" viewBox="0 0 24 15" fill="none" stroke="#333" stroke-width="1.2" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/><rect x="2" y="7" width="4" height="3" rx=".5"/></svg>
  </div>
  <?php endif; ?>

  <div class="sec">
    <div class="card-data-row">
      <div>
        <div class="card-data-lbl">Номер</div>
        <div class="card-data-val">**** <?php echo htmlspecialchars($last4); ?></div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <?php if ($currency !== 'RUB'): ?>
        <span style="font-family:'Space Mono',monospace;font-size:.5rem;padding:2px 7px;border-radius:5px;background:#0d0d0d;border:1px solid #1a1a1a;color:#666"><?php echo $currency; ?></span>
        <?php endif; ?>
        <div class="card-status <?php echo $isPending ? 'st-pending' : 'st-active'; ?>">
          <?php echo $isPending ? 'ОЖИДАНИЕ' : 'АКТИВНА'; ?>
        </div>
      </div>
    </div>

    <?php if ($isPending): ?>
    <div class="note-info">
      Эмитент ещё не предоставил данные карты.<br>
      <?php if ($viewCard['wait_days']): ?>
      Максимальный срок ожидания: <strong style="color:#aaa"><?php echo (int)$viewCard['wait_days']; ?> дней</strong>.
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // Show purpose set by admin
    $purposeVal = $viewCard['purpose'] ?? null;
    if ($purposeVal): ?>
    <div class="purpose-block">
      <div class="purpose-lbl">Назначение карты</div>
      <div class="purpose-txt"><?php echo htmlspecialchars($purposeVal); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!$isPending && !$isZero && $viewCard['prepaid_amount']): ?>
    <div class="card-data-row">
      <div>
        <div class="card-data-lbl">Баланс при выпуске</div>
        <div class="card-data-val"><?php echo number_format((float)$viewCard['prepaid_amount'],2,'.',' '); ?> <?php echo $curSym; ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isZero): ?>
    <div class="note-info">
      Точного баланса нет — карта с нулевым балансом.<br>
      Для пополнения напишите в <a href="https://t.me/whatwhat0" target="_blank">Telegram @whatwhat0</a> или в <a href="dashboard.php#support">поддержку</a>.
    </div>
    <?php if (!empty($viewCardTopups)): ?>
    <div class="sec-title">История пополнений</div>
    <?php foreach ($viewCardTopups as $t): ?>
    <div class="topup-hist-item">
      <div class="th-date"><?php echo date('d.m.Y H:i', strtotime($t['created_at'])); ?></div>
      <div class="th-amt">+<?php echo number_format((float)$t['amount'],0,'.',' '); ?> ₽</div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="font-family:'Space Mono',monospace;font-size:.56rem;color:#1e1e1e;text-align:center;padding:10px">Пополнений не было</div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isPending && !$noData): ?>
    <button class="reveal-btn" onclick="openRevealModal()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Данные карты
    </button>
    <?php else: ?>
    <div class="note-info" style="text-align:center">
      Данные карты будут доступны, когда эмитент предоставит их.
      <?php if ($viewCard['wait_days']): ?><br>Максимальный срок: <?php echo (int)$viewCard['wait_days']; ?> дней.<?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <a href="cards.php" style="text-align:center;font-family:'Space Mono',monospace;font-size:.52rem;color:#333;text-decoration:none;padding:4px;display:block">← Все карты</a>

<?php else: ?>

  <?php if (!empty($userCards)): ?>
  <div class="sec">
    <div class="sec-title">Мои карты</div>
    <?php foreach ($userCards as $c):
      $last4    = $c['card_number'] ? substr(preg_replace('/\D/','',$c['card_number']),-4) : '0000';
      $coverUrl = $c['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $c['cover_image'] : null;
      $cCur     = $c['currency'] ?? 'RUB';
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;background:#0a0a0a;border:1px solid #161616;border-radius:14px;padding:12px 14px;gap:10px">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:42px;height:26px;border-radius:4px;overflow:hidden;flex-shrink:0;background:#1a1a1a">
          <?php if ($coverUrl): ?>
          <img src="<?php echo htmlspecialchars($coverUrl); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:4px">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#333">
            <svg width="20" height="13" viewBox="0 0 24 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg>
          </div>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-family:'Space Mono',monospace;font-size:.82rem;color:#888">*<?php echo htmlspecialchars($last4); ?></div>
          <?php if ($cCur !== 'RUB'): ?>
          <div style="font-family:'Space Mono',monospace;font-size:.46rem;color:#444;margin-top:2px"><?php echo $cCur; ?></div>
          <?php endif; ?>
        </div>
      </div>
      <a href="cards.php?id=<?php echo (int)$c['id']; ?>" style="background:none;border:1px solid #2a2a2a;border-radius:9px;color:#555;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:6px 12px;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;display:inline-flex;align-items:center;gap:5px" onmouseover="this.style.borderColor='#555';this.style.color='#ccc'" onmouseout="this.style.borderColor='#2a2a2a';this.style.color='#555'">
        Перейти
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($plasticCards)): ?>
  <div class="sec">
    <div class="sec-title">Мои пластиковые карты</div>
    <?php foreach ($plasticCards as $pc):
      $pcDeliveredRow = (bool)$pc['is_delivered'];
      $pcCoverRow     = ($pcDeliveredRow && $pc['cover_image']) ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $pc['cover_image'] : null;
    ?>
    <div class="pcard-row" data-pc-id="<?php echo $pc['id']; ?>" style="display:flex;align-items:center;justify-content:space-between;background:#0a0a0a;border:1px solid #161616;border-radius:14px;padding:12px 14px;gap:10px">
      <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <div style="width:42px;height:26px;border-radius:4px;overflow:hidden;flex-shrink:0;background:#1a1a1a;display:flex;align-items:center;justify-content:center;color:#333">
          <?php if ($pcCoverRow): ?>
          <img src="<?php echo htmlspecialchars($pcCoverRow); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:4px">
          <?php else: ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          <?php endif; ?>
        </div>
        <div style="min-width:0">
          <div style="font-size:.82rem;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo $pcDeliveredRow ? htmlspecialchars($pc['name']) : 'Пластиковая карта'; ?></div>
          <div style="font-family:'Space Mono',monospace;font-size:.6rem;color:#4ade80;margin-top:2px" class="pcard-bal-row">
            <?php echo $pc['balance_cached'] !== null ? number_format((float)$pc['balance_cached'],2,'.',' ') : '—'; ?> ₽
          </div>
        </div>
      </div>
      <a href="cards.php?pcard=<?php echo (int)$pc['id']; ?>" style="background:none;border:1px solid #2a2a2a;border-radius:9px;color:#555;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:6px 12px;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;flex-shrink:0">
        Перейти
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="sec">
    <div class="sec-title">Выпустить карту</div>

    <?php if (empty($templates)): ?>
    <div style="font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.18em;color:#1e1e1e;text-align:center;padding:18px">Доступных карт нет</div>
    <?php else: ?>

    <div id="step1">
      <div class="flbl" style="margin-bottom:10px">Выберите карту</div>
      <div class="tmpl-grid" id="tmplGrid">
        <?php foreach ($templates as $t):
          $tCover   = $t['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $t['cover_image'] : null;
          $tPrepaid = (float)($t['prepaid_amount'] ?? 0);
          $tComm    = $tPrepaid > 0 ? round($tPrepaid * 0.07, 2) : 0;
          $tQty     = isset($t['quantity']) ? (int)$t['quantity'] : null;
          $tCur     = $t['currency'] ?? 'RUB';
          $tSym     = ['RUB'=>'₽','USD'=>'$','EUR'=>'€'][$tCur] ?? '₽';
        ?>
        <div class="tmpl-item" id="tmpl<?php echo $t['id']; ?>"
             onclick="selectTemplate(<?php echo $t['id']; ?>, <?php echo (float)$t['issue_cost']; ?>, '<?php echo $t['issue_type']; ?>', '<?php echo $t['balance_type']; ?>', <?php echo (int)($t['wait_days']??0); ?>, <?php echo $tPrepaid; ?>, '<?php echo $tCur; ?>', <?php echo $tComm; ?>)">
          <div class="tmpl-cover">
            <?php if ($tCover): ?>
            <img src="<?php echo htmlspecialchars($tCover); ?>" alt="">
            <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#333;background:#111">
              <svg width="24" height="16" viewBox="0 0 24 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg>
            </div>
            <?php endif; ?>
          </div>
          <div class="tmpl-info">
            <div class="tmpl-name"><?php echo htmlspecialchars($t['name']); ?></div>
            <div class="tmpl-cost">
              Выпуск: <?php echo $t['issue_cost'] > 0 ? number_format((float)$t['issue_cost'],0,'.',' ').' ₽' : 'Бесплатно'; ?>
              <?php if ($tPrepaid > 0): ?>
               &bull; Баланс: <?php echo number_format($tPrepaid,0,'.',' ').' '.$tSym; ?>
              <?php endif; ?>
            </div>
            <div class="tmpl-badges">
              <span class="tmpl-type <?php echo $t['issue_type']==='instant' ? 'tmpl-instant' : 'tmpl-wait'; ?>">
                <?php echo $t['issue_type']==='instant' ? 'МОМЕНТАЛЬНЫЙ' : 'ОЖИДАНИЕ '.($t['wait_days']??'?').' ДН'; ?>
              </span>
              <?php if ($tQty !== null): ?>
              <span class="tmpl-qty">ОСТАЛОСЬ: <?php echo $tQty; ?></span>
              <?php endif; ?>
              <?php if ($tCur !== 'RUB'): ?>
              <span class="tmpl-type tmpl-wait"><?php echo $tCur; ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="step2" style="display:none;flex-direction:column;gap:12px">
      <div id="balanceTypeInfo" style="display:none"></div>
      <div id="summaryBox" class="summary-box">
        <div class="sum-row"><span class="sum-lbl">Выпуск карты</span><span class="sum-val" id="sumIssue">—</span></div>
        <div class="sum-row" id="sumPrepaidRow" style="display:none"><span class="sum-lbl">Баланс на карте</span><span class="sum-val" id="sumPrepaid">—</span></div>
        <div class="sum-row" id="sumCommRow" style="display:none"><span class="sum-lbl">Комиссия 7%</span><span class="sum-comm" id="sumComm">—</span></div>
        <div class="sum-divider"></div>
        <div class="sum-row"><span class="sum-lbl">Итого к списанию</span><span class="sum-total" id="sumTotal">—</span></div>
        <div class="sum-row"><span class="sum-lbl">Ваш баланс</span><span class="sum-val" id="sumBal"><?php echo number_format($bal,2,'.',' '); ?> ₽</span></div>
        <div id="sumError" style="font-family:'Space Mono',monospace;font-size:.52rem;color:#f87171;min-height:12px"></div>
      </div>
    </div>

    <div class="flash" id="issueResult" style="display:none"></div>

    <div id="issueActions" style="display:none;flex-direction:column;gap:8px">
      <button class="sub-btn" id="issueBtn" onclick="issueCard()">Оформить</button>
      <button style="background:none;border:1px solid #1e1e1e;border-radius:12px;color:#444;font-family:'Space Mono',monospace;font-size:.56rem;padding:9px;cursor:pointer;transition:all .2s;width:100%" onclick="resetSelection()">Выбрать другую</button>
    </div>

    <?php endif; ?>
  </div>
<?php endif; ?>

</div>

<!-- CODE CONFIRM MODAL -->
<div class="modal-overlay" id="codeModal" onclick="if(event.target===this)closeCodeModal()">
  <div class="modal">
    <div class="modal-title">ПОДТВЕРЖДЕНИЕ</div>
    <div class="modal-desc" id="codeModalDesc">На вашу почту отправлен код подтверждения.</div>
    <div>
      <div class="flbl">Код из письма</div>
      <input type="text" id="codeInput" placeholder="000000" maxlength="6" style="letter-spacing:.2em;font-size:1.2rem;text-align:center" autocomplete="off">
    </div>
    <div class="modal-err" id="codeErr"></div>
    <button class="sub-btn" onclick="submitCode()">Подтвердить</button>
    <button class="modal-close" onclick="closeCodeModal()">Отмена</button>
  </div>
</div>

<!-- REVEAL DATA MODAL -->
<div class="modal-overlay" id="revealModal" onclick="if(event.target===this)closeRevealModal()">
  <div class="modal">
    <div class="modal-title">ДАННЫЕ КАРТЫ</div>
    <div class="modal-desc">Введите код из письма для просмотра данных.</div>
    <div>
      <div class="flbl">Код из письма</div>
      <input type="text" id="revealCodeInput" placeholder="000000" maxlength="6" style="letter-spacing:.2em;font-size:1.2rem;text-align:center" autocomplete="off">
    </div>
    <div class="modal-err" id="revealErr"></div>
    <div id="revealDataBox" style="display:none;flex-direction:column;gap:9px"></div>
    <div id="revealBtns" style="display:flex;flex-direction:column;gap:8px">
      <button class="sub-btn" onclick="submitRevealCode()">Получить данные</button>
      <button class="modal-close" onclick="closeRevealModal()">Закрыть</button>
    </div>
  </div>
</div>

<button id="langBtn">RU / EN</button>
<script>
var USER_BAL = <?php echo $bal; ?>;
var CARD_ID  = <?php echo isset($viewCard) && $viewCard ? (int)$viewCard['id'] : 'null'; ?>;
var PLASTIC_CARD_ID = <?php echo isset($viewPlasticCard) && $viewPlasticCard ? (int)$viewPlasticCard['id'] : 'null'; ?>;

/* ── Живое обновление баланса пластиковой карты (раз в секунду) ── */
function fmtRub(v){ return parseFloat(v).toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function pollPlasticBalance(id, targetEl, errEl){
  // Кэш-бастинг + no-store: чтобы браузер/service worker не отдавали старый ответ
  fetch('plastic_cards_api.php?action=balance&card_id=' + id + '&_=' + Date.now(), {cache:'no-store'})
    .then(function(r){ return r.json(); })
    .then(function(d){
      console.log('[plastic balance]', id, d);
      if (!d.ok || !targetEl) return;
      if (!d.connected) { targetEl.textContent = 'кошелёк не подключён'; return; }
      targetEl.textContent = d.balance !== null ? fmtRub(d.balance) + ' ₽' : '—';
      if (errEl) {
        if (d.debug_error) { errEl.style.display = 'block'; errEl.textContent = 'Ошибка обновления: ' + d.debug_error; }
        else { errEl.style.display = 'none'; }
      }
    }).catch(function(err){ console.error('[plastic balance] fetch failed', err); });
}

if (PLASTIC_CARD_ID) {
  var pcLiveBalEl = document.getElementById('pcLiveBal');
  var pcLiveBalErrEl = document.getElementById('pcLiveBalErr');
  pollPlasticBalance(PLASTIC_CARD_ID, pcLiveBalEl, pcLiveBalErrEl);
  setInterval(function(){ pollPlasticBalance(PLASTIC_CARD_ID, pcLiveBalEl, pcLiveBalErrEl); }, 1000);
}

/* ── Пополнение баланса пластиковой карты через ЮMoney (прямо на привязанный к карте кошелёк) ── */
function startPcTopup(){
  var errEl = document.getElementById('pcTopupErr');
  errEl.style.display = 'none';
  var amount = parseFloat(document.getElementById('pcTopupAmount').value);
  if (!amount || amount < 2) {
    errEl.style.display = 'block'; errEl.textContent = 'Минимальная сумма — 2 ₽';
    return;
  }
  var fd = new FormData();
  fd.append('action', 'topup_init');
  fd.append('card_id', PLASTIC_CARD_ID);
  fd.append('amount', amount);
  fetch('plastic_cards_api.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      console.log('[plastic topup_init]', d);
      if (!d.ok || !d.url) {
        errEl.style.display = 'block'; errEl.textContent = d.error || 'Ошибка';
        return;
      }
      if (d.same_as_site_wallet) {
        var proceed = confirm(
          'Внимание: кошелёк этой карты (' + d.receiver_wallet + ') совпадает с ОСНОВНЫМ кошельком сайта.\n' +
          'Пополнение уйдёт на общий баланс сайта, а не на отдельный счёт этой карты.\n\n' +
          'Продолжить всё равно?'
        );
        if (!proceed) return;
      }
      window.location.href = d.url;
    }).catch(function(){ errEl.style.display = 'block'; errEl.textContent = 'Ошибка соединения'; });
}

var pcardRows = document.querySelectorAll('.pcard-row');
if (pcardRows.length) {
  setInterval(function(){
    pcardRows.forEach(function(row){
      var id = row.dataset.pcId;
      var el = row.querySelector('.pcard-bal-row');
      if (!id || !el) return;
      fetch('plastic_cards_api.php?action=balance&card_id=' + id + '&_=' + Date.now(), {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          console.log('[plastic balance row]', id, d);
          if (d.ok && d.connected && d.balance !== null) el.textContent = fmtRub(d.balance) + ' ₽';
        }).catch(function(err){ console.error('[plastic balance row] fetch failed', err); });
    });
  }, 1000);
}
var selTmplId = null, selCost = 0, selType = '', selBalType = '', selWait = 0, selPrepaid = 0, selCurrency = 'RUB', selComm = 0;

function selectTemplate(id, cost, issueType, balType, waitDays, prepaidAmt, currency, commission) {
  document.querySelectorAll('.tmpl-item').forEach(function(el){el.classList.remove('selected');});
  var el = document.getElementById('tmpl'+id);
  if(el) el.classList.add('selected');
  selTmplId = id; selCost = cost; selType = issueType; selBalType = balType;
  selWait = waitDays; selPrepaid = prepaidAmt; selCurrency = currency; selComm = commission;

  var SYMS = {RUB:'₽', USD:'$', EUR:'€'};
  var sym = SYMS[currency] || '₽';

  document.getElementById('step2').style.display = 'flex';
  document.getElementById('issueActions').style.display = 'flex';

  var info = document.getElementById('balanceTypeInfo');
  if (balType === 'zero') {
    info.style.display = 'block';
    info.className = 'note-info';
    info.innerHTML = 'Карта с нулевым балансом. Для пополнения напишите в <a href="https://t.me/whatwhat0" target="_blank" style="color:#555">Telegram @whatwhat0</a> или в поддержку.';
  } else {
    info.style.display = 'none';
  }

  // Summary
  document.getElementById('sumIssue').textContent = cost > 0 ? cost.toLocaleString('ru')+' ₽' : 'Бесплатно';
  if (balType === 'prepaid' && prepaidAmt > 0) {
    document.getElementById('sumPrepaidRow').style.display = 'flex';
    document.getElementById('sumPrepaid').textContent = prepaidAmt.toLocaleString('ru')+' '+sym;
    document.getElementById('sumCommRow').style.display = 'flex';
    document.getElementById('sumComm').textContent = commission.toFixed(2)+' ₽';
  } else {
    document.getElementById('sumPrepaidRow').style.display = 'none';
    document.getElementById('sumCommRow').style.display = 'none';
  }

  var total = cost + prepaidAmt + commission;
  document.getElementById('sumTotal').textContent = total.toLocaleString('ru')+' ₽';

  var err = document.getElementById('sumError');
  var btn = document.getElementById('issueBtn');
  if (total > USER_BAL) {
    err.textContent = 'Недостаточно средств на балансе';
    btn.disabled = true;
  } else {
    err.textContent = '';
    btn.disabled = false;
  }
}

function resetSelection() {
  selTmplId = null;
  document.querySelectorAll('.tmpl-item').forEach(function(el){el.classList.remove('selected');});
  document.getElementById('step2').style.display = 'none';
  document.getElementById('issueActions').style.display = 'none';
  document.getElementById('issueResult').style.display = 'none';
}

function issueCard() {
  if(!selTmplId) return;
  var btn = document.getElementById('issueBtn');
  btn.disabled = true; btn.textContent = '...';
  fetch('cards_api.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'send_issue_code', template_id: selTmplId})
  }).then(function(r){return r.json();}).then(function(d){
    btn.disabled = false; btn.textContent = 'Оформить';
    if(d.ok) {
      document.getElementById('codeModalDesc').textContent = 'Код подтверждения отправлен на ' + (d.email||'вашу почту');
      document.getElementById('codeModal').classList.add('open');
      document.getElementById('codeInput').value = '';
      document.getElementById('codeErr').textContent = '';
    } else {
      var res = document.getElementById('issueResult');
      res.style.display = 'block'; res.className = 'flash err';
      res.textContent = d.error || 'Ошибка';
    }
  }).catch(function(){
    btn.disabled = false; btn.textContent = 'Оформить';
    var res = document.getElementById('issueResult');
    res.style.display = 'block'; res.className = 'flash err';
    res.textContent = 'Ошибка соединения';
  });
}

function closeCodeModal() { document.getElementById('codeModal').classList.remove('open'); }

function submitCode() {
  var code = document.getElementById('codeInput').value.trim();
  if(!code) return;
  var btn = document.querySelector('#codeModal .sub-btn');
  if(btn){ btn.disabled=true; btn.textContent='...'; }
  fetch('cards_api.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'confirm_issue', template_id: selTmplId, code: code})
  }).then(function(r){return r.json();}).then(function(d){
    if(btn){ btn.disabled=false; btn.textContent='Подтвердить'; }
    if(d.ok) {
      closeCodeModal();
      window.location.href = 'cards.php?id=' + d.card_id;
    } else {
      document.getElementById('codeErr').textContent = d.error || 'Неверный код';
    }
  }).catch(function(){
    if(btn){ btn.disabled=false; btn.textContent='Подтвердить'; }
    document.getElementById('codeErr').textContent = 'Ошибка соединения';
  });
}

function openRevealModal() {
  fetch('cards_api.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'send_reveal_code', card_id: CARD_ID})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok) {
      document.getElementById('revealModal').classList.add('open');
      document.getElementById('revealCodeInput').value = '';
      document.getElementById('revealErr').textContent = '';
      document.getElementById('revealDataBox').style.display = 'none';
    } else { alert(d.error||'Ошибка'); }
  }).catch(function(){ alert('Ошибка соединения'); });
}

function closeRevealModal() { document.getElementById('revealModal').classList.remove('open'); }

function submitRevealCode() {
  var code = document.getElementById('revealCodeInput').value.trim();
  if(!code) return;
  fetch('cards_api.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'reveal_card', card_id: CARD_ID, code: code})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok) {
      var box = document.getElementById('revealDataBox');
      box.innerHTML = ''; box.style.display = 'flex';
      [['Номер карты', d.card_number||'—'],['Срок действия', d.expiry||'—'],['CVV', d.cvv||'—']].forEach(function(r){
        var row = document.createElement('div'); row.className = 'card-data-row';
        row.innerHTML = '<div><div class="card-data-lbl">'+r[0]+'</div><div class="card-data-val" style="font-size:1rem;letter-spacing:.1em">'+r[1]+'</div></div>';
        box.appendChild(row);
      });
      document.getElementById('revealBtns').innerHTML = '<button class="modal-close" onclick="closeRevealModal()">Закрыть</button>';
    } else {
      document.getElementById('revealErr').textContent = d.error || 'Неверный код';
    }
  }).catch(function(){ document.getElementById('revealErr').textContent = 'Ошибка соединения'; });
}
document.getElementById('langBtn').onclick=function(){this.textContent=this.textContent==='RU / EN'?'EN / RU':'RU / EN';};
</script>
</body>
</html>
