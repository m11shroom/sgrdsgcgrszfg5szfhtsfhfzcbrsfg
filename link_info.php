<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid    = (int)$_SESSION['user_id'];
$isAdmin= !empty($_SESSION['is_admin']);
$db     = getDB();

$linkId = (int)($_GET['id'] ?? 0);

if ($isAdmin) {
    $s = $db->prepare('SELECT pl.*, u.username AS creator FROM payment_links pl JOIN users u ON u.id=pl.user_id WHERE pl.id=?');
} else {
    $s = $db->prepare('SELECT pl.*, u.username AS creator FROM payment_links pl JOIN users u ON u.id=pl.user_id WHERE pl.id=? AND pl.user_id=?');
}
$params = $isAdmin ? [$linkId] : [$linkId, $uid];
$s->execute($params); $link = $s->fetch();

if (!$link) { header('Location: '.($isAdmin?'admin.php':'create_link.php')); exit; }

$txs = $db->prepare(
    'SELECT plt.*, u.username AS payer_username
     FROM payment_link_txs plt
     LEFT JOIN users u ON u.id=plt.payer_user_id
     WHERE plt.link_id=? ORDER BY plt.created_at DESC'
);
$txs->execute([$linkId]); $txs = $txs->fetchAll();

$fields      = $link['buyer_fields'] ? json_decode($link['buyer_fields'], true) : [];
$fieldLabels = ['email'=>'Email','phone'=>'Phone','name'=>'Full name'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Link payments</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:760px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.info-card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px;display:flex;flex-direction:column;gap:10px}
.ic-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444}
.ic-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;padding:8px 0;border-bottom:1px solid #161616}
.ic-row:last-child{border-bottom:none}
.ic-lbl{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;color:#2a2a2a}
.ic-val{font-family:'Space Mono',monospace;font-size:.62rem;color:#888;display:flex;align-items:center;gap:5px}
.ic-val.big{font-size:.9rem;color:#f0f0f0}
.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot-green{background:#4ade80}
.dot-red{background:#f87171}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.sc{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:12px 16px;flex:1;min-width:100px}
.sn{font-family:'Space Mono',monospace;font-size:1.2rem;margin-bottom:3px;color:#f0f0f0}
.sl{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.18em;color:#252525;text-transform:uppercase}
.tx-list{display:flex;flex-direction:column;gap:8px}
.tx-item{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:14px 15px;display:flex;flex-direction:column;gap:8px}
.tx-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.pid{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.1em;color:#888;display:flex;align-items:center;gap:6px}
.pid-val{color:#f0f0f0}
.copy-pid{background:none;border:1px solid #1e1e1e;border-radius:6px;color:#363636;font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.08em;padding:2px 7px;cursor:pointer;transition:all .2s}
.copy-pid:hover{border-color:#555;color:#aaa}
.tx-status{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.14em;padding:3px 8px;border-radius:6px}
.st-completed{color:#4ade80;background:#001a08;border:1px solid #002a10}
.st-pending{color:#aaa;background:#111;border:1px solid #1e1e1e}
.tx-details{display:flex;flex-wrap:wrap;gap:10px}
.tx-det{display:flex;flex-direction:column;gap:2px}
.tx-det-lbl{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.12em;color:#1e1e1e;text-transform:uppercase}
.tx-det-val{font-size:.78rem;color:#666}
.tx-amounts{display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.tx-am{font-family:'Space Mono',monospace;font-size:.7rem;color:#888}
.tx-am span{color:#f0f0f0}
.tx-date{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;margin-left:auto}
.method-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.12em;padding:2px 8px;border-radius:5px;background:#111;border:1px solid #1e1e1e;color:#444;display:flex;align-items:center;gap:4px}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:24px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <a href="<?php echo $isAdmin?'admin.php':'create_link.php'; ?>" class="back">&#8592; Back</a>
</div>
<div class="inner">
  <div class="pg-ttl">LINK PAYMENTS</div>

  <div class="info-card">
    <div class="ic-title">LINK INFO</div>
    <div class="ic-row"><span class="ic-lbl">SERVICE</span><span class="ic-val big"><?php echo htmlspecialchars($link['service_name']); ?></span></div>
    <div class="ic-row"><span class="ic-lbl">CREATOR</span><span class="ic-val">@<?php echo htmlspecialchars($link['creator']); ?></span></div>
    <div class="ic-row"><span class="ic-lbl">AMOUNT</span><span class="ic-val big"><?php echo number_format((float)$link['amount'],2,'.',' '); ?> &#8381;</span></div>
    <div class="ic-row"><span class="ic-lbl">TYPE</span><span class="ic-val"><?php echo $link['one_time']?'One-time':'Reusable'; ?></span></div>
    <div class="ic-row">
      <span class="ic-lbl">STATUS</span>
      <span class="ic-val">
        <span class="status-dot <?php echo $link['is_active']?'dot-green':'dot-red'; ?>"></span>
        <?php echo $link['is_active']?'Active':'Inactive'; ?>
      </span>
    </div>
    <?php if (!empty($fields)): ?>
    <div class="ic-row"><span class="ic-lbl">BUYER FIELDS</span><span class="ic-val"><?php echo implode(', ', array_map(fn($k,$v)=>($fieldLabels[$k]??$k).(!empty($v['required'])?' *':''), array_keys($fields), $fields)); ?></span></div>
    <?php endif; ?>
    <div class="ic-row"><span class="ic-lbl">CREATED</span><span class="ic-val"><?php echo date('d.m.Y H:i', strtotime($link['created_at'])); ?></span></div>
  </div>

  <?php
  $totalSales  = count(array_filter($txs, fn($t)=>$t['status']==='completed'));
  $totalEarned = array_sum(array_map(fn($t)=>$t['status']==='completed'?(float)$t['creator_gets']:0, $txs));
  $pending     = count(array_filter($txs, fn($t)=>$t['status']==='pending'));
  ?>
  <div class="stats">
    <div class="sc"><div class="sn"><?php echo $totalSales; ?></div><div class="sl">Sales</div></div>
    <div class="sc"><div class="sn"><?php echo number_format($totalEarned,2,'.',','); ?> &#8381;</div><div class="sl">Earned</div></div>
    <div class="sc"><div class="sn"><?php echo $pending; ?></div><div class="sl">Pending</div></div>
  </div>

  <div class="info-card">
    <div class="ic-title">PAYMENTS</div>
    <?php if (empty($txs)): ?>
      <div class="empty">No payments yet</div>
    <?php else: ?>
    <div class="tx-list">
    <?php foreach ($txs as $tx): ?>
    <div class="tx-item">
      <div class="tx-top">
        <div class="pid">
          ID: <span class="pid-val" id="pid_<?php echo $tx['id']; ?>"><?php echo htmlspecialchars($tx['payment_id']); ?></span>
          <button class="copy-pid" onclick="cpid('pid_<?php echo $tx['id']; ?>',this)">copy</button>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="method-badge">
            <?php if ($tx['method']==='bank'): ?>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22h18"/><path d="M6 18V11"/><path d="M10 18V11"/><path d="M14 18V11"/><path d="M18 18V11"/><polygon points="12 2 2 7 22 7"/></svg>
              Bank
            <?php else: ?>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              YooMoney
            <?php endif; ?>
          </span>
          <span class="tx-status st-<?php echo $tx['status']; ?>"><?php echo $tx['status']==='completed'?'PAID':'PENDING'; ?></span>
        </div>
      </div>
      <?php if ($tx['buyer_email']||$tx['buyer_phone']||$tx['buyer_name']||$tx['payer_username']): ?>
      <div class="tx-details">
        <?php if ($tx['payer_username']): ?><div class="tx-det"><div class="tx-det-lbl">Account</div><div class="tx-det-val">@<?php echo htmlspecialchars($tx['payer_username']); ?></div></div><?php endif; ?>
        <?php if ($tx['buyer_name']): ?><div class="tx-det"><div class="tx-det-lbl">Name</div><div class="tx-det-val"><?php echo htmlspecialchars($tx['buyer_name']); ?></div></div><?php endif; ?>
        <?php if ($tx['buyer_email']): ?><div class="tx-det"><div class="tx-det-lbl">Email</div><div class="tx-det-val"><?php echo htmlspecialchars($tx['buyer_email']); ?></div></div><?php endif; ?>
        <?php if ($tx['buyer_phone']): ?><div class="tx-det"><div class="tx-det-lbl">Phone</div><div class="tx-det-val"><?php echo htmlspecialchars($tx['buyer_phone']); ?></div></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="tx-amounts">
        <div class="tx-am">Amount: <span><?php echo number_format((float)$tx['amount'],2,'.',' '); ?> &#8381;</span></div>
        <div class="tx-am">Credited: <span><?php echo number_format((float)$tx['creator_gets'],2,'.',' '); ?> &#8381;</span></div>
        <div class="tx-date"><?php echo date('d.m.Y H:i', strtotime($tx['created_at'])); ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
function cpid(id,btn){
  var t=document.getElementById(id).textContent.trim();
  navigator.clipboard?navigator.clipboard.writeText(t):(function(){var e=document.createElement('textarea');e.value=t;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();
  var o=btn.textContent;btn.textContent='✓';setTimeout(function(){btn.textContent=o;},1200);
}
</script>
</body>
</html>
