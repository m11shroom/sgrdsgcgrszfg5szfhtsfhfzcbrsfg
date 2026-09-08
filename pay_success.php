<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$paymentId = trim($_GET['pid'] ?? '');
if (!$paymentId) { header('Location: index.php'); exit; }

$db = getDB();
$s  = $db->prepare(
    'SELECT plt.*,
            pl.service_name, pl.description,
            u.username AS creator_username
     FROM payment_link_txs plt
     JOIN payment_links pl ON pl.id = plt.link_id
     JOIN users u ON u.id = pl.user_id
     WHERE plt.payment_id = ?'
);
$s->execute([$paymentId]);
$tx = $s->fetch();

if (!$tx) { echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="background:#080808;color:#555;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">Payment not found</body></html>'; exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment complete</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{width:400px;max-width:96vw;background:#111;border:1px solid #1e1e1e;border-radius:22px;padding:32px;display:flex;flex-direction:column;gap:20px;box-shadow:0 16px 60px rgba(0,0,0,.7)}
.success-icon{display:flex;justify-content:center;color:#4ade80}
.success-title{font-family:'Space Mono',monospace;font-size:.8rem;letter-spacing:.28em;color:#4ade80;text-align:center;margin-top:12px}
.divider{height:1px;background:#161616}
.detail-row{display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid #111;gap:12px}
.detail-row:last-child{border-bottom:none}
.d-lbl{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.22em;color:#2a2a2a;text-transform:uppercase;padding-top:2px;flex-shrink:0}
.d-val{font-family:'Space Mono',monospace;font-size:.7rem;color:#aaa;text-align:right;word-break:break-all}
.d-val.big{font-size:1.1rem;color:#f0f0f0}
.d-val.green{color:#4ade80;display:flex;align-items:center;gap:5px;justify-content:flex-end}
.pid-row{display:flex;align-items:center;gap:8px;justify-content:flex-end}
.pid-copy{background:none;border:1px solid #1e1e1e;border-radius:7px;color:#363636;font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.1em;padding:3px 8px;cursor:pointer;transition:all .2s}
.pid-copy:hover{border-color:#555;color:#aaa}
.svc-info{background:#0a0a0a;border:1px solid #161616;border-radius:13px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.svc-name{font-size:.9rem;font-weight:500;color:#ccc}
.svc-by{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.12em;color:#2a2a2a}
.svc-desc{font-size:.78rem;color:#444;line-height:1.5;margin-top:4px}
.back-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;padding:12px;cursor:pointer;text-transform:uppercase;text-decoration:none;text-align:center;display:block;transition:background .2s}
.back-btn:hover{background:#e0e0e0}
.method-row{display:flex;align-items:center;gap:5px;justify-content:flex-end}
</style>
</head>
<body>
<div class="card">
  <div>
    <div class="success-icon">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
    </div>
    <div class="success-title">PAYMENT COMPLETE</div>
  </div>

  <div class="svc-info">
    <div class="svc-name"><?php echo htmlspecialchars($tx['service_name']); ?></div>
    <div class="svc-by">Seller: @<?php echo htmlspecialchars($tx['creator_username']); ?></div>
    <?php if ($tx['description']): ?><div class="svc-desc"><?php echo htmlspecialchars($tx['description']); ?></div><?php endif; ?>
  </div>

  <div class="divider"></div>

  <div>
    <div class="detail-row">
      <div class="d-lbl">Payment ID</div>
      <div class="d-val">
        <div class="pid-row">
          <span id="pidVal"><?php echo htmlspecialchars($tx['payment_id']); ?></span>
          <button class="pid-copy" onclick="copyPid()">copy</button>
        </div>
      </div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">Amount</div>
      <div class="d-val big"><?php echo number_format((float)$tx['amount'], 2, '.', ' '); ?> &#8381;</div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">Method</div>
      <div class="d-val">
        <div class="method-row">
          <?php if ($tx['method'] === 'bank'): ?>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22h18"/><path d="M6 18V11"/><path d="M10 18V11"/><path d="M14 18V11"/><path d="M18 18V11"/><polygon points="12 2 2 7 22 7"/></svg>
            M1plus wallet
          <?php else: ?>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            YooMoney
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">Status</div>
      <div class="d-val green">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        Completed
      </div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">Date &amp; time</div>
      <div class="d-val"><?php $dt=new DateTime($tx['created_at']); echo $dt->format('d.m.Y').'<br>'.$dt->format('H:i:s'); ?></div>
    </div>
    <?php if ($tx['buyer_name']||$tx['buyer_email']||$tx['buyer_phone']): ?>
    <?php if ($tx['buyer_name']): ?><div class="detail-row"><div class="d-lbl">Name</div><div class="d-val"><?php echo htmlspecialchars($tx['buyer_name']); ?></div></div><?php endif; ?>
    <?php if ($tx['buyer_email']): ?><div class="detail-row"><div class="d-lbl">Email</div><div class="d-val"><?php echo htmlspecialchars($tx['buyer_email']); ?></div></div><?php endif; ?>
    <?php if ($tx['buyer_phone']): ?><div class="detail-row"><div class="d-lbl">Phone</div><div class="d-val"><?php echo htmlspecialchars($tx['buyer_phone']); ?></div></div><?php endif; ?>
    <?php endif; ?>
  </div>

  <a href="https://wallet.m1plus.ru" class="back-btn">&#8592; Home</a>
</div>
<script>
function copyPid(){
  var t=document.getElementById('pidVal').textContent.trim();
  navigator.clipboard?navigator.clipboard.writeText(t):(function(){var e=document.createElement('textarea');e.value=t;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();
  var b=document.querySelector('.pid-copy');var o=b.textContent;b.textContent='copied!';
  setTimeout(function(){b.textContent=o;},1500);
}
</script>
</body>
</html>
