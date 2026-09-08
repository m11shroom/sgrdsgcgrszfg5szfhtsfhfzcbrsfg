<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid   = (int)$_SESSION['user_id'];
$db    = getDB();
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$newLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service  = htmlspecialchars(trim(substr($_POST['service_name'] ?? '', 0, 100)));
    $desc     = htmlspecialchars(trim(substr($_POST['description'] ?? '', 0, 300)));
    $amount   = (float)($_POST['amount'] ?? 0);
    $oneTime  = !empty($_POST['one_time']) ? 1 : 0;

    $fields = [];
    foreach (['email','phone','name'] as $f) {
        $enabled  = !empty($_POST['field_'.$f]);
        $required = !empty($_POST['req_'.$f]);
        if ($enabled) $fields[$f] = ['required' => $required];
    }

    if (!$service) { $_SESSION['flash'] = 'err:Enter a service name'; header('Location: create_link.php'); exit; }
    if ($amount < 1) { $_SESSION['flash'] = 'err:Minimum amount is 1 ₽'; header('Location: create_link.php'); exit; }

    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO payment_links (user_id,token,service_name,description,amount,one_time,buyer_fields) VALUES (?,?,?,?,?,?,?)')
       ->execute([$uid, $token, $service, $desc ?: null, $amount, $oneTime, $fields ? json_encode($fields) : null]);
    $newLink = 'https://wallet.m1plus.ru/pay.php?t=' . $token;
}

$links = $db->prepare(
    'SELECT pl.*,
            COUNT(plt.id) AS sales,
            COALESCE(SUM(CASE WHEN plt.status=\'completed\' THEN plt.creator_gets ELSE 0 END),0) AS earned
     FROM payment_links pl
     LEFT JOIN payment_link_txs plt ON plt.link_id=pl.id
     WHERE pl.user_id=? AND pl.is_active=1
     GROUP BY pl.id ORDER BY pl.created_at DESC'
);
$links->execute([$uid]); $links = $links->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>M1plus wallet — Payment links</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:640px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:640px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.28em;color:#444}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:12px 14px;border-radius:11px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.link-box{background:#0a1a08;border:1px solid #1a3a1a;border-radius:14px;padding:18px 20px;display:flex;flex-direction:column;gap:10px}
.link-lbl{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.22em;color:#4ade80;display:flex;align-items:center;gap:6px}
.link-url{font-family:'Space Mono',monospace;font-size:.72rem;color:#e0e0e0;word-break:break-all}
.copy-btn{background:#fff;color:#080808;border:none;border-radius:9px;font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.14em;padding:8px 16px;cursor:pointer;align-self:flex-start;transition:background .2s}
.copy-btn:hover{background:#e0e0e0}
.form-card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:14px}
.form-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
input[type=text],input[type=number],textarea{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;display:block;-webkit-appearance:none}
textarea{resize:vertical;min-height:65px;font-family:'DM Sans',sans-serif}
input:focus,textarea:focus{border-color:#383838}
.hint{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;line-height:1.6;margin-top:3px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;background:#0a0a0a;border:1px solid #161616;border-radius:11px}
.toggle-lbl{font-size:.8rem;color:#888}
.toggle-sub{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;margin-top:2px}
.toggle{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-sl{position:absolute;inset:0;background:#1e1e1e;border-radius:99px;cursor:pointer;transition:background .2s}
.toggle-sl:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#444;border-radius:50%;transition:transform .2s,background .2s}
.toggle input:checked+.toggle-sl{background:#2a3a2a}
.toggle input:checked+.toggle-sl:before{transform:translateX(18px);background:#4ade80}
.fields-section{display:flex;flex-direction:column;gap:8px}
.field-row{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:11px 14px;display:flex;flex-direction:column;gap:8px}
.field-row-top{display:flex;align-items:center;justify-content:space-between}
.field-name{font-size:.8rem;color:#888}
.chk-lbl{display:flex;align-items:center;gap:5px;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#363636;cursor:pointer}
.chk-lbl input{accent-color:#4ade80;width:14px;height:14px;cursor:pointer}
.req-row{display:none;padding-top:6px;border-top:1px solid #161616}
.req-row.show{display:flex;align-items:center;gap:8px}
.req-chk{display:flex;align-items:center;gap:5px;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#363636;cursor:pointer}
.req-chk input{accent-color:#f87171;width:14px;height:14px;cursor:pointer}
.submit-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:12px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.submit-btn:hover{background:#e0e0e0}
.links-card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:12px}
.link-item{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:14px 15px;display:flex;flex-direction:column;gap:8px}
.li-top{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.li-name{font-size:.88rem;font-weight:500;color:#ccc}
.li-amount{font-family:'Space Mono',monospace;font-size:.88rem;color:#f0f0f0}
.li-url{font-family:'Space Mono',monospace;font-size:.54rem;color:#2a2a2a;word-break:break-all}
.li-stats{display:flex;gap:14px;flex-wrap:wrap}
.li-stat{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.12em;color:#363636}
.li-stat span{color:#888}
.li-tags{display:flex;gap:6px;flex-wrap:wrap}
.li-tag{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.1em;padding:2px 7px;border-radius:5px;border:1px solid #1e1e1e;color:#363636}
.li-btns{display:flex;gap:7px;flex-wrap:wrap}
.li-copy{background:none;border:1px solid #1e1e1e;border-radius:8px;color:#444;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:5px 10px;cursor:pointer;transition:all .2s}
.li-copy:hover{border-color:#555;color:#ccc}
.li-del{background:none;border:1px solid #1a0000;border-radius:8px;color:#363636;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:5px 10px;cursor:pointer;transition:all .2s}
.li-del:hover{border-color:#c04040;color:#c04040}
.li-view{background:none;border:1px solid #1a2a1a;border-radius:8px;color:#3a6a3a;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:5px 10px;cursor:pointer;text-decoration:none;transition:all .2s}
.li-view:hover{border-color:#4ade80;color:#4ade80}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:18px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="dashboard.php" class="back">&#8592; Back</a>
</div>
<div class="inner">
  <div class="pg-ttl">PAYMENT LINKS</div>

  <?php if ($flash):
    $ft = str_starts_with($flash,'err:') ? 'err' : 'ok';
    $fm = preg_replace('/^(ok:|err:)/','',$flash);
  ?>
    <div class="flash <?php echo $ft; ?>"><?php echo htmlspecialchars($fm); ?></div>
  <?php endif; ?>

  <?php if ($newLink): ?>
  <div class="link-box">
    <div class="link-lbl">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      LINK CREATED
    </div>
    <div class="link-url" id="newUrl"><?php echo htmlspecialchars($newLink); ?></div>
    <button class="copy-btn" onclick="cp('newUrl',this)">Copy</button>
  </div>
  <?php endif; ?>

  <div class="form-card">
    <div class="form-title">CREATE LINK</div>
    <form method="POST" id="cForm">
      <div style="margin-bottom:12px">
        <div class="flbl">Service name</div>
        <input type="text" name="service_name" placeholder="My shop" maxlength="100" required>
        <div class="hint">Buyer sees this on the payment page</div>
      </div>
      <div style="margin-bottom:12px">
        <div class="flbl">Description <span style="color:#252525">(optional)</span></div>
        <textarea name="description" placeholder="What's included..." maxlength="300"></textarea>
      </div>
      <div style="margin-bottom:12px">
        <div class="flbl">Amount (&#8381;)</div>
        <input type="number" name="amount" placeholder="500" min="1" step="0.01" required>
        <div class="hint">You receive 90% · 10% platform fee</div>
      </div>

      <div class="toggle-row">
        <div>
          <div class="toggle-lbl">One-time link</div>
          <div class="toggle-sub">Deactivates after first payment</div>
        </div>
        <label class="toggle">
          <input type="checkbox" name="one_time" checked>
          <span class="toggle-sl"></span>
        </label>
      </div>

      <div>
        <div class="flbl" style="margin-bottom:8px">Buyer fields</div>
        <div class="hint" style="margin-bottom:10px">Choose what to ask buyers before payment.</div>
        <div class="fields-section">
          <?php foreach (['email'=>'Email','phone'=>'Phone','name'=>'Full name'] as $fk=>$fn): ?>
          <div class="field-row" id="fr_<?php echo $fk; ?>">
            <div class="field-row-top">
              <span class="field-name"><?php echo $fn; ?></span>
              <label class="chk-lbl">
                <input type="checkbox" name="field_<?php echo $fk; ?>" id="fe_<?php echo $fk; ?>" onchange="toggleField('<?php echo $fk; ?>')">
                Ask
              </label>
            </div>
            <div class="req-row" id="rr_<?php echo $fk; ?>">
              <label class="req-chk">
                <input type="checkbox" name="req_<?php echo $fk; ?>">
                Required
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="submit-btn" type="submit">Create link</button>
    </form>
  </div>

  <?php if (!empty($links)): ?>
  <div class="links-card">
    <div class="form-title">MY LINKS</div>
    <?php foreach ($links as $lk):
      $url    = 'https://wallet.m1plus.ru/pay.php?t='.htmlspecialchars($lk['token']);
      $flds   = $lk['buyer_fields'] ? json_decode($lk['buyer_fields'], true) : [];
    ?>
    <div class="link-item">
      <div class="li-top">
        <div class="li-name"><?php echo htmlspecialchars($lk['service_name']); ?></div>
        <div class="li-amount"><?php echo number_format((float)$lk['amount'],2,'.',' '); ?> &#8381;</div>
      </div>
      <div class="li-url" id="url<?php echo $lk['id']; ?>"><?php echo $url; ?></div>
      <div class="li-tags">
        <?php if ($lk['one_time']): ?><span class="li-tag">One-time</span><?php endif; ?>
        <?php foreach ($flds as $fk=>$fv): ?>
          <span class="li-tag"><?php $nm=['email'=>'Email','phone'=>'Phone','name'=>'Name']; echo $nm[$fk]??$fk; echo $fv['required']?' *':''; ?></span>
        <?php endforeach; ?>
      </div>
      <div class="li-stats">
        <div class="li-stat">Sales: <span><?php echo $lk['sales']; ?></span></div>
        <div class="li-stat">Earned: <span><?php echo number_format((float)$lk['earned'],2,'.',','); ?> &#8381;</span></div>
        <div class="li-stat">Created: <span><?php echo date('d.m.Y',strtotime($lk['created_at'])); ?></span></div>
      </div>
      <div class="li-btns">
        <button class="li-copy" onclick="cp('url<?php echo $lk['id']; ?>',this)">Copy</button>
        <a href="link_info.php?id=<?php echo $lk['id']; ?>" class="li-view">Payments</a>
        <form method="POST" action="link_delete.php" style="display:inline" onsubmit="return confirm('Deactivate this link?')">
          <input type="hidden" name="link_id" value="<?php echo $lk['id']; ?>">
          <button class="li-del" type="submit">Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script>
function cp(id,btn){
  var t=document.getElementById(id).textContent;
  navigator.clipboard?navigator.clipboard.writeText(t):(function(){var e=document.createElement('textarea');e.value=t;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();
  var o=btn.textContent;btn.textContent='Copied!';setTimeout(function(){btn.textContent=o;},1500);
}
function toggleField(key){
  var cb=document.getElementById('fe_'+key);
  var rr=document.getElementById('rr_'+key);
  rr.className='req-row'+(cb.checked?' show':'');
}
</script>
</body>
</html>
