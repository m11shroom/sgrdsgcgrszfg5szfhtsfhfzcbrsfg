<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = trim($_GET['t'] ?? '');
if (!$token) { header('Location: index.php'); exit; }

$db = getDB();
$s  = $db->prepare('SELECT pl.*, u.username AS creator_username FROM payment_links pl JOIN users u ON u.id=pl.user_id WHERE pl.token=? AND pl.is_active=1');
$s->execute([$token]); $link = $s->fetch();

if (!$link) { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unavailable</title></head>
<body style="background:#080808;color:#555;font-family:'Space Mono',monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;padding:20px">
<div><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="1.5" style="margin-bottom:16px;display:block;margin-left:auto;margin-right:auto"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="2" y1="2" x2="22" y2="22" stroke="#c04040"/></svg>Link not found or inactive</div>
</body></html>
<?php exit; }

if ($link['one_time']) {
    $c = $db->prepare("SELECT id FROM payment_link_txs WHERE link_id=? AND status='completed' LIMIT 1");
    $c->execute([$link['id']]);
    if ($c->fetch()) { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paid</title></head>
<body style="background:#080808;color:#4ade80;font-family:'Space Mono',monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;padding:20px">
<div><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5" style="margin-bottom:16px;display:block;margin-left:auto;margin-right:auto"><polyline points="20 6 9 17 4 12"/></svg>Already paid — one-time link.</div>
</body></html>
<?php exit; } }

$amount   = (float)$link['amount'];
$service  = htmlspecialchars($link['service_name']);
$creator  = htmlspecialchars($link['creator_username']);
$desc     = $link['description'] ? htmlspecialchars($link['description']) : null;
$fields   = $link['buyer_fields'] ? json_decode($link['buyer_fields'], true) : [];
$hasFields = !empty($fields);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $service; ?> — Payment</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{width:400px;max-width:96vw;background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:28px;display:flex;flex-direction:column;gap:14px;box-shadow:0 16px 60px rgba(0,0,0,.7)}
.brand{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.3em;color:#2a2a2a}
.svc-name{font-size:1.3rem;font-weight:600;color:#f0f0f0;letter-spacing:-.01em;margin-top:2px}
.svc-desc{font-size:.82rem;color:#555;line-height:1.5;margin-top:4px}
.creator-row{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.12em;color:#252525;display:flex;align-items:center;gap:5px}
.creator-row span{color:#555}
.amount-box{background:#0a0a0a;border:1px solid #1a1a1a;border-radius:14px;padding:16px 18px;text-align:center}
.amt-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.28em;color:#1e1e1e;margin-bottom:5px}
.amt-val{font-family:'Space Mono',monospace;font-size:2rem;letter-spacing:.04em;color:#f0f0f0}
.divider{height:1px;background:#161616}
.sec-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.28em;color:#1e1e1e;text-transform:uppercase}
.buyer-fields{display:flex;flex-direction:column;gap:10px}
.bf-group{display:flex;flex-direction:column;gap:5px}
.bf-lbl{font-size:.54rem;letter-spacing:.16em;color:#363636;text-transform:uppercase;display:flex;align-items:center;gap:5px}
.bf-req{color:#f87171;font-size:.5rem}
.bf-inp{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.82rem;padding:10px 13px;outline:none;-webkit-appearance:none}
.bf-inp:focus{border-color:#383838}
.pay-btn{width:100%;border:none;border-radius:13px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:500;letter-spacing:.06em;padding:14px 16px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:9px}
.pay-btn.white{background:#fff;color:#080808}.pay-btn.white:hover{background:#e8e8e8}
.pay-btn.dark{background:#1a1a1a;color:#ccc;border:1px solid #2a2a2a}.pay-btn.dark:hover{background:#222;color:#fff}
.pay-btn.green{background:#2d6a2d;color:#fff}.pay-btn.green:hover{background:#3a7a3a}
.pay-btn.ghost{background:none;border:1px solid #1e1e1e;color:#444;font-size:.72rem}.pay-btn.ghost:hover{border-color:#555;color:#ccc}
.pay-btn:disabled{opacity:.35;cursor:default}
.err-msg{font-family:'Space Mono',monospace;font-size:.52rem;color:#f87171;text-align:center;min-height:14px}

/* STEP USERNAME */
.step-ttl{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.22em;color:#444;margin-bottom:4px}
.step-sub{font-size:.78rem;color:#555;line-height:1.5;margin-bottom:10px}
.inp-wrap{position:relative}
.inp-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.3;pointer-events:none}
.txt-inp{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.9rem;padding:11px 13px 11px 36px;outline:none;-webkit-appearance:none;transition:border-color .2s}
.txt-inp:focus{border-color:#4ade80}
.back-link{background:none;border:none;color:#333;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;cursor:pointer;text-align:center;padding:4px;transition:color .2s;display:block;width:100%}
.back-link:hover{color:#888}

/* STEP VERIFY */
.verify-header{text-align:center;padding-bottom:14px;border-bottom:1px solid #161616}
.verify-bank-lbl{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.28em;color:#2a2a2a;margin-bottom:5px}
.verify-sub{font-size:.8rem;color:#444;line-height:1.4}
.verify-shop{background:#0a0a0a;border:1px solid #1a1a1a;border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.verify-shop-icon{width:34px;height:34px;background:#111;border:1px solid #1e1e1e;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#555}
.verify-shop-name{font-size:.88rem;font-weight:500;color:#ccc}
.verify-shop-by{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.12em;color:#2a2a2a;margin-top:2px}

/* @ animation — Feather Icons geometry, drawn stroke by stroke */
.at-wrap{display:flex;flex-direction:column;align-items:center;gap:12px;padding:6px 0}
.at-svg{overflow:visible}

/* Inner circle: r=4, circumference=25.13 */
.at-inner{
  fill:none;stroke:#4ade80;stroke-width:1.6;stroke-linecap:round;
  stroke-dasharray:25.2;stroke-dashoffset:25.2;
  animation:atD1 .65s cubic-bezier(.4,0,.2,1) .05s forwards;
}
/* Outer path length ≈ 51 units */
.at-outer{
  fill:none;stroke:#4ade80;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;
  stroke-dasharray:55;stroke-dashoffset:55;
  animation:atD2 1.1s cubic-bezier(.35,0,.2,1) .75s forwards;
}
@keyframes atD1{to{stroke-dashoffset:0}}
@keyframes atD2{to{stroke-dashoffset:0}}
@keyframes atGlow{
  0%,100%{filter:drop-shadow(0 0 2px rgba(74,222,128,.15))}
  50%    {filter:drop-shadow(0 0 12px rgba(74,222,128,.55))}
}
.at-svg{animation:atGlow 3s ease-in-out 2s infinite}

.verify-msg{font-size:.82rem;color:#666;text-align:center;line-height:1.5}
.verify-email-hint{font-family:'Space Mono',monospace;font-size:.6rem;color:#4ade80;text-align:center;margin-top:2px;min-height:14px}
.code-inp{width:100%;background:#080808;border:2px solid #1e1e1e;border-radius:12px;color:#f0f0f0;font-family:'Space Mono',monospace;font-size:1.8rem;letter-spacing:.5em;padding:13px 16px;text-align:center;outline:none;-webkit-appearance:none;transition:border-color .2s}
.code-inp:focus{border-color:#4ade80}
.resend-btn{background:none;border:none;color:#252525;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.12em;cursor:pointer;display:flex;align-items:center;gap:5px;padding:4px 8px;border-radius:7px;transition:color .2s;margin:0 auto}
.resend-btn:hover{color:#888}
</style>
</head>
<body>
<div class="card">

  <!-- ══ STEP MAIN ══ -->
  <div id="stepMain" style="display:flex;flex-direction:column;gap:14px">
    <div>
      <div class="brand">M1PLUS WALLET · PAYMENT</div>
      <div class="svc-name"><?php echo $service; ?></div>
      <?php if ($desc): ?><div class="svc-desc"><?php echo $desc; ?></div><?php endif; ?>
    </div>
    <div class="creator-row">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Seller: <span>@<?php echo $creator; ?></span>
    </div>
    <div class="amount-box">
      <div class="amt-lbl">AMOUNT</div>
      <div class="amt-val"><?php echo number_format($amount,2,'.',' '); ?> &#8381;</div>
    </div>

    <?php if ($hasFields): ?>
    <div>
      <div class="sec-lbl" style="margin-bottom:10px">Your details</div>
      <div class="buyer-fields">
        <?php foreach ($fields as $fk=>$fv):
          $lbl=['email'=>'Email','phone'=>'Phone','name'=>'Full name'][$fk]??$fk;
          $req=!empty($fv['required']);
          $tp=['email'=>'email','phone'=>'tel','name'=>'text'][$fk]??'text';
          $ph=['email'=>'user@example.com','phone'=>'+7 900 000-00-00','name'=>'John Doe'][$fk]??'';
        ?>
        <div class="bf-group">
          <div class="bf-lbl"><?php echo $lbl; ?><?php if($req):?><span class="bf-req">*</span><?php endif;?></div>
          <input class="bf-inp" type="<?php echo $tp;?>" id="bf_<?php echo $fk;?>" placeholder="<?php echo $ph;?>" <?php if($req):?>required<?php endif;?>>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="divider"></div>
    <?php endif; ?>

    <div class="sec-lbl">Payment method</div>

    <button class="pay-btn white" onclick="payYoo()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      Card / SberPay / YooMoney
    </button>

    <div class="divider"></div>

    <button class="pay-btn dark" onclick="startBankFlow()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22h18"/><path d="M6 18V11"/><path d="M10 18V11"/><path d="M14 18V11"/><path d="M18 18V11"/><polygon points="12 2 2 7 22 7"/></svg>
      Pay with M1plus wallet
    </button>
  </div>

  <!-- ══ STEP USERNAME ══ -->
  <div id="stepUsername" style="display:none;flex-direction:column;gap:12px">
    <div>
      <div class="step-ttl">ENTER YOUR USERNAME</div>
      <div class="step-sub">Enter your M1plus wallet username to receive a verification code by email.</div>
    </div>
    <div class="inp-wrap">
      <svg class="inp-ico" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>
      <input class="txt-inp" type="text" id="usernameInput" placeholder="username" autocomplete="username" spellcheck="false">
    </div>
    <div class="err-msg" id="usernameErr"></div>
    <button class="pay-btn green" onclick="submitUsername()">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Send code
    </button>
    <button class="back-link" onclick="showStep('main')">&#8592; Back</button>
  </div>

  <!-- ══ STEP VERIFY ══ -->
  <div id="stepVerify" style="display:none;flex-direction:column;gap:14px">
    <div class="verify-header">
      <div class="verify-bank-lbl">M1PLUS WALLET</div>
      <div class="verify-sub">Paying with the one tap in the button</div>
    </div>

    <div class="verify-shop">
      <div class="verify-shop-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      </div>
      <div>
        <div class="verify-shop-name"><?php echo $service; ?></div>
        <div class="verify-shop-by">@<?php echo $creator; ?></div>
      </div>
    </div>

    <!-- Animated @ -->
    <div class="at-wrap">
      <!-- Feather Icons @ — exact paths, 24x24 viewBox scaled to 72x72
           Stage 1: inner circle cx=12,cy=12,r=4 (circumference=25.1)
           Stage 2: right arm up → small hook → large outer arc wrapping left
      -->
      <svg class="at-svg" id="atSvg" width="72" height="72" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle class="at-inner" cx="12" cy="12" r="4"/>
        <path class="at-outer" d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/>
      </svg>
      <div class="verify-msg">We just mailed you, enter the code we sent</div>
      <div class="verify-email-hint" id="emailHintText"></div>
    </div>

    <input class="code-inp" type="text" id="codeInput" maxlength="6" placeholder="——————" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')">
    <div class="err-msg" id="codeErr"></div>

    <button class="pay-btn green" id="confirmBtn" onclick="submitCode()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      Confirm payment
    </button>

    <button class="resend-btn" id="resendBtn" onclick="resendCode()">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Send new
    </button>

    <button class="back-link" onclick="showStep('username')">&#8592; Back</button>
  </div>

</div>
<script>
var TOKEN     = <?php echo json_encode($token); ?>;
var HAS_FIELDS = <?php echo $hasFields?'true':'false'; ?>;
var FIELD_KEYS = <?php echo json_encode(array_keys($fields)); ?>;
var FIELD_REQ  = <?php echo json_encode(array_map(fn($v)=>!empty($v['required']),$fields)); ?>;

var storedBuyerData = {};
var storedUsername  = '';

function showStep(name) {
  ['stepMain','stepUsername','stepVerify'].forEach(function(id){
    var el = document.getElementById(id);
    el.style.display = 'none';
  });
  var target = document.getElementById('step' + name.charAt(0).toUpperCase() + name.slice(1));
  target.style.display = 'flex';
  target.style.flexDirection = 'column';
  target.style.gap = '14px';

  if (name === 'verify') {
    // Clone SVG to restart CSS animations
    var oldSvg = document.getElementById('atSvg');
    if (oldSvg) {
      var newSvg = oldSvg.cloneNode(true);
      newSvg.id = 'atSvg';
      oldSvg.parentNode.replaceChild(newSvg, oldSvg);
      // Measure actual path length after clone, set dasharray precisely
      var outerPath = newSvg.querySelector('.at-outer');
      if (outerPath) {
        var len = Math.ceil(outerPath.getTotalLength());
        outerPath.style.strokeDasharray = len;
        outerPath.style.strokeDashoffset = len;
      }
      var innerCircle = newSvg.querySelector('.at-inner');
      if (innerCircle) {
        var r = parseFloat(innerCircle.getAttribute('r'));
        var cLen = Math.ceil(2 * Math.PI * r);
        innerCircle.style.strokeDasharray = cLen;
        innerCircle.style.strokeDashoffset = cLen;
      }
    }
    document.getElementById('codeInput').value = '';
    document.getElementById('codeErr').textContent = '';
  }
}

function getBuyerData() {
  var data = {};
  if (!HAS_FIELDS) return data;
  for (var i = 0; i < FIELD_KEYS.length; i++) {
    var k = FIELD_KEYS[i], el = document.getElementById('bf_'+k);
    if (!el) continue;
    var val = el.value.trim();
    if (FIELD_REQ[i] && !val) { el.style.borderColor='#c04040'; el.focus(); return null; }
    el.style.borderColor='';
    if (val) data[k] = val;
  }
  return data;
}

function payYoo() {
  var bd = getBuyerData(); if (bd === null) return;
  var fd = new FormData(); fd.append('token',TOKEN); fd.append('buyer_data',JSON.stringify(bd));
  fetch('pay_yoo_init.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if (d.url) window.location.href=d.url; else alert(d.error||'Error');
  });
}

function startBankFlow() {
  var bd = getBuyerData(); if (bd === null) return;
  storedBuyerData = bd;
  document.getElementById('usernameErr').textContent='';
  showStep('username');
}

function submitUsername() {
  var username = document.getElementById('usernameInput').value.trim();
  var errEl = document.getElementById('usernameErr');
  if (!username) { errEl.textContent='Enter your username'; return; }
  errEl.textContent='';
  var btn = document.querySelector('#stepUsername .pay-btn'); btn.disabled=true;
  storedUsername = username;
  var fd = new FormData(); fd.append('token',TOKEN); fd.append('username',username);
  fetch('pay_bank_send.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false;
    if (d.ok) {
      document.getElementById('emailHintText').textContent = d.email_hint||'';
      showStep('verify');
    } else { errEl.textContent = d.error||'Error sending code'; }
  }).catch(()=>{ btn.disabled=false; errEl.textContent='Network error'; });
}

function submitCode() {
  var code = document.getElementById('codeInput').value.trim();
  var errEl = document.getElementById('codeErr');
  if (code.length !== 6) { errEl.textContent='Enter the 6-digit code'; return; }
  errEl.textContent='';
  var btn = document.getElementById('confirmBtn'); btn.disabled=true;
  var fd = new FormData();
  fd.append('token',TOKEN); fd.append('code',code);
  fd.append('username',storedUsername);
  fd.append('buyer_data',JSON.stringify(storedBuyerData));
  fetch('pay_bank.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false;
    if (d.ok) { window.location.href = d.redirect||'pay_success.php?pid='+d.payment_id; }
    else { errEl.textContent = d.error||'Error'; document.getElementById('codeInput').value=''; }
  }).catch(()=>{ btn.disabled=false; errEl.textContent='Network error'; });
}

function resendCode() {
  var btn = document.getElementById('resendBtn'); btn.disabled=true;
  var fd = new FormData(); fd.append('token',TOKEN); fd.append('username',storedUsername);
  fetch('pay_bank_send.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if (d.ok) {
      document.getElementById('emailHintText').textContent = d.email_hint||'';
      document.getElementById('codeErr').textContent='';
      // Restart animation
      var svg = document.getElementById('atSvg');
      if (svg) { var c=svg.cloneNode(true); c.id='atSvg'; svg.parentNode.replaceChild(c,svg); }
      btn.textContent='Sent!';
    } else { document.getElementById('codeErr').textContent=d.error||'Error'; }
    setTimeout(()=>{ btn.innerHTML='<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Send new'; btn.disabled=false; },3000);
  }).catch(()=>{ btn.disabled=false; });
}
</script>
</body>
</html>
