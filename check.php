<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$checkId = trim($_GET['id'] ?? '');
if (!$checkId) { header('Location: index.php'); exit; }

$db = getDB();

if (!preg_match('/^CHK-(\d+)-([a-f0-9]{8})$/', $checkId, $m)) {
    http_response_code(404); header('Location: index.php'); exit;
}
$txId = (int)$m[1];
$hash = $m[2];

$s = $db->prepare('SELECT t.*, u.username FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=?');
$s->execute([$txId]);
$tx = $s->fetch();

if (!$tx) { http_response_code(404); header('Location: index.php'); exit; }

$expectedHash = substr(hash_hmac('sha256', $txId . $tx['created_at'] . $tx['amount'], 'M1plusWalletCheck'), 0, 8);
if (!hash_equals($expectedHash, $hash)) { http_response_code(404); header('Location: index.php'); exit; }

$typeLabels = [
    'card'         => 'Card transfer',
    'sbp'          => 'SBP transfer',
    'user_out'     => 'User transfer',
    'user_in'      => 'Incoming transfer',
    'payment_link' => 'Service payment',
    'payment_sent' => 'Link payment',
];
$typeLabel = $typeLabels[$tx['type'] ?? ''] ?? 'Operation';
$isIn      = in_array($tx['type'], ['user_in', 'payment_link']);
$total     = (float)$tx['amount'] + (float)($tx['commission'] ?? 0);
$dt        = new DateTime($tx['created_at']);

// SVG icons per type
$typeIcons = [
    'card'         => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
    'sbp'          => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>',
    'user_out'     => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'user_in'      => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'payment_link' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
    'payment_sent' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
];
$icon = $typeIcons[$tx['type'] ?? ''] ?? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>';

$stClass = match($tx['status'] ?? '') { 'completed'=>'st-ok','processing'=>'st-proc','declined'=>'st-dec', default=>'st-proc' };
$stLabel  = match($tx['status'] ?? '') { 'completed'=>'COMPLETED','processing'=>'PROCESSING','declined'=>'DECLINED', default=>'UNKNOWN' };
if (in_array($tx['type'] ?? '', ['user_in','user_out','payment_link','payment_sent'])) { $stClass='st-ok'; $stLabel='COMPLETED'; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Receipt #<?php echo htmlspecialchars($checkId); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;color:#f0f0f0}
.page{display:flex;flex-direction:column;align-items:center;gap:16px;width:100%;max-width:360px}
.logo{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.32em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.receipt{width:100%;background:#fff;color:#111;border-radius:4px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.8)}
.r-top{background:#080808;padding:22px 22px 18px;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative}
.r-top::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:12px;background:#fff;border-radius:60% 60% 0 0}
.r-icon{margin-bottom:2px;color:#888}
.r-status{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.22em;padding:5px 12px;border-radius:20px}
.st-ok{color:#4ade80;border:1px solid #4ade80}
.st-proc{color:#aaa;border:1px solid #444}
.st-dec{color:#f87171;border:1px solid #f87171}
.r-title{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.2em;color:#555;margin-top:4px;text-transform:uppercase}
.r-amount-block{padding:20px 22px 16px;text-align:center;border-bottom:1px dashed #e0e0e0}
.r-amount-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.28em;color:#aaa;margin-bottom:4px}
.r-amount{font-family:'Space Mono',monospace;font-size:2.4rem;letter-spacing:.02em;color:#111;line-height:1}
.r-amount .cur{font-size:1rem;color:#555;margin-left:4px}
.r-amount.in-color{color:#1a7a1a}
.r-rows{padding:14px 22px 0}
.r-row{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid #f0f0f0;gap:10px}
.r-row:last-child{border-bottom:none}
.r-lbl{font-size:.72rem;color:#888}
.r-val{font-family:'Space Mono',monospace;font-size:.72rem;color:#222;text-align:right;word-break:break-all}
.r-val.mono{letter-spacing:.06em}
.r-qr-block{padding:16px 22px;display:flex;flex-direction:column;align-items:center;gap:8px;border-top:1px dashed #e0e0e0;margin-top:4px}
.r-qr-lbl{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.2em;color:#ccc}
.r-checkid{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.12em;color:#bbb;word-break:break-all;text-align:center}
.r-perf{height:14px;background:repeating-linear-gradient(90deg,#080808 0,#080808 10px,transparent 10px,transparent 20px);margin-top:-1px}
.actions{display:flex;gap:8px;width:100%}
.act-btn{flex:1;border:none;border-radius:11px;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.14em;padding:11px;cursor:pointer;text-transform:uppercase;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px}
.act-dl{background:#fff;color:#080808}
.act-dl:hover{background:#e0e0e0}
.act-cp{background:none;border:1px solid #1e1e1e;color:#555}
.act-cp:hover{border-color:#555;color:#ccc}
.act-bk{background:none;border:1px solid #1e1e1e;color:#444;display:flex;text-align:center;text-decoration:none;align-items:center;justify-content:center;border-radius:11px;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.14em;padding:11px;transition:all .2s;width:100%}
.act-bk:hover{border-color:#555;color:#ccc}
</style>
</head>
<body>
<div class="page">
  <div class="logo"><div class="bdot"></div>M1PLUS WALLET</div>

  <div class="receipt" id="receipt">
    <div class="r-top">
      <div class="r-icon"><?php echo $icon; ?></div>
      <div class="r-status <?php echo $stClass; ?>"><?php echo $stLabel; ?></div>
      <div class="r-title"><?php echo $typeLabel; ?></div>
    </div>

    <div class="r-amount-block">
      <div class="r-amount-lbl">AMOUNT</div>
      <div class="r-amount<?php echo $isIn?' in-color':''; ?>">
        <?php echo $isIn?'+':''; ?><?php echo number_format((float)$tx['amount'], 2, '.', ' '); ?><span class="cur">&#8381;</span>
      </div>
    </div>

    <div class="r-rows">
      <div class="r-row">
        <div class="r-lbl">Payer</div>
        <div class="r-val">@<?php echo htmlspecialchars($tx['username']); ?></div>
      </div>
      <?php if ($tx['type']==='card' && $tx['card_number']): ?>
      <div class="r-row">
        <div class="r-lbl">Card</div>
        <div class="r-val mono">&#8226;&#8226;&#8226;&#8226; <?php echo substr($tx['card_number'], -4); ?></div>
      </div>
      <?php endif; ?>
      <?php if ($tx['type']==='sbp' && $tx['phone']): ?>
      <div class="r-row">
        <div class="r-lbl">Phone</div>
        <div class="r-val"><?php echo htmlspecialchars($tx['phone']); ?></div>
      </div>
      <div class="r-row">
        <div class="r-lbl">Bank</div>
        <div class="r-val"><?php echo htmlspecialchars($tx['bank_name'] ?? ''); ?></div>
      </div>
      <?php endif; ?>
      <?php if ((float)($tx['commission'] ?? 0) > 0): ?>
      <div class="r-row">
        <div class="r-lbl">Fee</div>
        <div class="r-val"><?php echo number_format((float)$tx['commission'], 2, '.', ' '); ?> &#8381;</div>
      </div>
      <div class="r-row">
        <div class="r-lbl">Total charged</div>
        <div class="r-val mono"><?php echo number_format($total, 2, '.', ' '); ?> &#8381;</div>
      </div>
      <?php endif; ?>
      <div class="r-row">
        <div class="r-lbl">Date</div>
        <div class="r-val"><?php echo $dt->format('d.m.Y'); ?></div>
      </div>
      <div class="r-row">
        <div class="r-lbl">Time</div>
        <div class="r-val mono"><?php echo $dt->format('H:i:s'); ?></div>
      </div>
    </div>

    <div class="r-qr-block">
      <div class="r-qr-lbl">RECEIPT ID</div>
      <div class="r-checkid"><?php echo htmlspecialchars($checkId); ?></div>
    </div>

    <div class="r-perf"></div>
  </div>

  <div class="actions">
    <button class="act-btn act-dl" onclick="saveImg()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Save
    </button>
    <button class="act-btn act-cp" onclick="copyLink()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      Copy link
    </button>
  </div>
  <a href="dashboard.php" class="act-bk">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="15 18 9 12 15 6"/></svg>
    Back
  </a>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function saveImg(){
  html2canvas(document.getElementById('receipt'),{backgroundColor:null,scale:3,useCORS:true}).then(function(canvas){
    var a=document.createElement('a');
    a.download='receipt_<?php echo htmlspecialchars($checkId); ?>.png';
    a.href=canvas.toDataURL('image/png');a.click();
  });
}
function copyLink(){
  var url=window.location.href;
  navigator.clipboard?navigator.clipboard.writeText(url):(function(){var e=document.createElement('textarea');e.value=url;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();
  var btn=document.querySelector('.act-cp');var o=btn.textContent;
  btn.textContent='Copied!';setTimeout(function(){btn.textContent=o;},1500);
}
</script>
</body>
</html>
