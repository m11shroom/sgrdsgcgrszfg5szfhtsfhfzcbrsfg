<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid     = (int)$_SESSION['user_id'];
$isAdmin = !empty($_SESSION['is_admin']);
$db      = getDB();

// Ensure verified
try { $db->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
$vRow = $db->prepare('SELECT is_verified FROM users WHERE id=?');
$vRow->execute([$uid]); $vRow = $vRow->fetch();
if (!$isAdmin && empty($vRow['is_verified'])) { header('Location: create_link.php'); exit; }

// Create merchant table
$db->exec("CREATE TABLE IF NOT EXISTS merchants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    webhook_url VARCHAR(500) DEFAULT NULL,
    public_key VARCHAR(64) NOT NULL UNIQUE,
    secret_key VARCHAR(64) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS merchant_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    external_id VARCHAR(200) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description TEXT DEFAULT NULL,
    currency VARCHAR(10) DEFAULT 'RUB',
    buyer_email VARCHAR(255) DEFAULT NULL,
    buyer_name VARCHAR(150) DEFAULT NULL,
    buyer_phone VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    payment_method VARCHAR(20) DEFAULT NULL,
    payer_user_id INT DEFAULT NULL,
    payment_token VARCHAR(64) NOT NULL UNIQUE,
    webhook_sent TINYINT(1) DEFAULT 0,
    webhook_attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$ft = str_starts_with($flash, 'ok:') ? 'ok' : 'err';
$fm = ltrim(ltrim($flash, 'ok:'), 'err:');

// Create merchant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name    = trim(substr($_POST['name'] ?? '', 0, 100));
    $desc    = trim(substr($_POST['description'] ?? '', 0, 500));
    $webhook = trim($_POST['webhook_url'] ?? '');
    if (!$name) { $_SESSION['flash'] = 'err:Укажите название'; header('Location: merchants.php'); exit; }
    $pubKey = bin2hex(random_bytes(16)); // 32 chars
    $secKey = bin2hex(random_bytes(32)); // 64 chars
    $db->prepare('INSERT INTO merchants (user_id,name,description,webhook_url,public_key,secret_key) VALUES (?,?,?,?,?,?)')
       ->execute([$uid, $name, $desc ?: null, $webhook ?: null, $pubKey, $secKey]);
    $_SESSION['flash'] = 'ok:Мерчант создан'; header('Location: merchants.php'); exit;
}

// Regenerate secret
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regen') {
    $mid    = (int)($_POST['mid'] ?? 0);
    $newSec = bin2hex(random_bytes(32));
    $db->prepare('UPDATE merchants SET secret_key=? WHERE id=? AND user_id=?')->execute([$newSec, $mid, $uid]);
    $_SESSION['flash'] = 'ok:Secret key обновлён'; header('Location: merchants.php'); exit;
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $mid = (int)($_POST['mid'] ?? 0);
    $db->prepare('UPDATE merchants SET is_active=1-is_active WHERE id=? AND user_id=?')->execute([$mid, $uid]);
    header('Location: merchants.php'); exit;
}

$merchants = $db->prepare(
    'SELECT m.*,
            COUNT(mp.id) AS total_payments,
            COALESCE(SUM(CASE WHEN mp.status=\'paid\' THEN mp.amount ELSE 0 END),0) AS total_revenue
     FROM merchants m
     LEFT JOIN merchant_payments mp ON mp.merchant_id=m.id
     WHERE m.user_id=?
     GROUP BY m.id ORDER BY m.created_at DESC'
);
$merchants->execute([$uid]); $merchants = $merchants->fetchAll();
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Мерчанты — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 70px}
.hdr{max-width:720px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.hdr-btns{display:flex;gap:8px}
.doc-btn{background:none;border:1px solid #1a2a1a;border-radius:10px;color:#3a6a3a;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.doc-btn:hover{border-color:#4ade80;color:#4ade80}
.inner{max-width:720px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.7rem;letter-spacing:.28em;color:#444}
.flash{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.14em;padding:12px 14px;border-radius:11px}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
/* FORM */
.form-card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:13px}
.fc-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444}
.flbl{font-size:.56rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
.hint{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525;margin-top:3px}
input,textarea{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.8rem;padding:10px 13px;outline:none;display:block;-webkit-appearance:none}
textarea{resize:vertical;min-height:60px;font-family:'DM Sans',sans-serif}
input:focus,textarea:focus{border-color:#383838}
.submit-btn{background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:12px 20px;cursor:pointer;text-transform:uppercase;transition:background .2s;align-self:flex-start}
.submit-btn:hover{background:#e0e0e0}
/* MERCHANT CARD */
.mc{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:20px;display:flex;flex-direction:column;gap:14px}
.mc-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.mc-name{font-size:1rem;font-weight:500;color:#ccc}
.mc-badge{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.16em;padding:3px 9px;border-radius:20px}
.mc-active{color:#4ade80;background:#001a08;border:1px solid #002a10}
.mc-inactive{color:#555;background:#0a0a0a;border:1px solid #161616}
.mc-desc{font-size:.8rem;color:#444;line-height:1.5}
.mc-stats{display:flex;gap:12px;flex-wrap:wrap}
.mcs{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:10px 14px;flex:1;min-width:90px}
.mcs-n{font-family:'Space Mono',monospace;font-size:1.1rem;color:#f0f0f0;margin-bottom:2px}
.mcs-l{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.18em;color:#252525;text-transform:uppercase}
.key-block{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:12px 14px;display:flex;flex-direction:column;gap:8px}
.key-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.key-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.2em;color:#2a2a2a;text-transform:uppercase;flex-shrink:0;width:70px}
.key-val{font-family:'Space Mono',monospace;font-size:.62rem;color:#666;flex:1;word-break:break-all}
.key-val.pub{color:#888}
.kcp{background:none;border:1px solid #1e1e1e;border-radius:6px;color:#363636;font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.08em;padding:3px 8px;cursor:pointer;flex-shrink:0;transition:all .2s}
.kcp:hover{border-color:#555;color:#aaa}
.mc-acts{display:flex;gap:7px;flex-wrap:wrap}
.mc-btn{background:none;border:1px solid #1e1e1e;border-radius:8px;color:#444;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:6px 11px;cursor:pointer;text-decoration:none;transition:all .2s;text-align:center}
.mc-btn:hover{border-color:#555;color:#ccc}
.mc-btn.green{border-color:#1a3a1a;color:#3a6a3a}
.mc-btn.green:hover{border-color:#4ade80;color:#4ade80}
.mc-btn.red{border-color:#2a0000;color:#555}
.mc-btn.red:hover{border-color:#f87171;color:#f87171}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:24px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <div class="hdr-btns">
    <a href="api_docs.php" class="doc-btn">📄 API Docs</a>
    <a href="dashboard.php" class="back">← Назад</a>
  </div>
</div>
<div class="inner">
  <div class="pg-ttl">МЕРЧАНТЫ</div>
  <?php if ($fm): ?><div class="flash <?php echo $ft; ?>"><?php echo htmlspecialchars($fm); ?></div><?php endif; ?>

  <!-- CREATE -->
  <div class="form-card">
    <div class="fc-title">СОЗДАТЬ МЕРЧАНТА</div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div style="margin-bottom:12px">
        <div class="flbl">Название магазина</div>
        <input type="text" name="name" placeholder="Мой интернет-магазин" maxlength="100" required>
      </div>
      <div style="margin-bottom:12px">
        <div class="flbl">Описание <span style="color:#252525">(необязательно)</span></div>
        <textarea name="description" placeholder="Чем занимается магазин..." maxlength="500"></textarea>
      </div>
      <div style="margin-bottom:14px">
        <div class="flbl">Webhook URL <span style="color:#252525">(для уведомлений об оплате)</span></div>
        <input type="url" name="webhook_url" placeholder="https://yoursite.com/webhook">
        <div class="hint">POST-запрос придёт при каждой успешной оплате</div>
      </div>
      <button class="submit-btn" type="submit">Создать</button>
    </form>
  </div>

  <!-- LIST -->
  <?php if (empty($merchants)): ?>
    <div class="empty">Мерчантов нет — создайте первый</div>
  <?php else: foreach ($merchants as $m): ?>
  <div class="mc">
    <div class="mc-top">
      <div class="mc-name"><?php echo htmlspecialchars($m['name']); ?></div>
      <span class="mc-badge <?php echo $m['is_active']?'mc-active':'mc-inactive'; ?>">
        <?php echo $m['is_active']?'АКТИВЕН':'ОТКЛЮЧЁН'; ?>
      </span>
    </div>
    <?php if ($m['description']): ?><div class="mc-desc"><?php echo htmlspecialchars($m['description']); ?></div><?php endif; ?>

    <div class="mc-stats">
      <div class="mcs"><div class="mcs-n"><?php echo $m['total_payments']; ?></div><div class="mcs-l">Платежей</div></div>
      <div class="mcs"><div class="mcs-n"><?php echo number_format((float)$m['total_revenue'],0,'.',','); ?> ₽</div><div class="mcs-l">Выручка</div></div>
      <div class="mcs"><div class="mcs-n"><?php echo date('d.m.y',strtotime($m['created_at'])); ?></div><div class="mcs-l">Создан</div></div>
    </div>

    <div class="key-block">
      <div class="key-row">
        <div class="key-lbl">Public Key</div>
        <div class="key-val pub" id="pub<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['public_key']); ?></div>
        <button class="kcp" onclick="cp('pub<?php echo $m['id']; ?>',this)">copy</button>
      </div>
      <div class="key-row">
        <div class="key-lbl">Secret Key</div>
        <div class="key-val" id="sec<?php echo $m['id']; ?>" style="filter:blur(5px);cursor:pointer" onclick="this.style.filter='none'">
          <?php echo htmlspecialchars($m['secret_key']); ?>
        </div>
        <button class="kcp" onclick="cp('sec<?php echo $m['id']; ?>',this)">copy</button>
      </div>
      <?php if ($m['webhook_url']): ?>
      <div class="key-row">
        <div class="key-lbl">Webhook</div>
        <div class="key-val" style="color:#2a2a2a"><?php echo htmlspecialchars($m['webhook_url']); ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="mc-acts">
      <a href="merchant_payments.php?mid=<?php echo $m['id']; ?>" class="mc-btn green">Платежи</a>
      <a href="api_docs.php?mid=<?php echo $m['id']; ?>" class="mc-btn green">API Docs</a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="regen">
        <input type="hidden" name="mid" value="<?php echo $m['id']; ?>">
        <button class="mc-btn" type="submit" onclick="return confirm('Обновить Secret Key? Старый перестанет работать.')">🔄 Новый secret</button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="mid" value="<?php echo $m['id']; ?>">
        <button class="mc-btn <?php echo $m['is_active']?'red':''; ?>" type="submit">
          <?php echo $m['is_active']?'Отключить':'Включить'; ?>
        </button>
      </form>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<script>
function cp(id,btn){
  var el=document.getElementById(id);
  el.style.filter='none';
  var t=el.textContent.trim();
  navigator.clipboard?navigator.clipboard.writeText(t):(function(){var e=document.createElement('textarea');e.value=t;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();
  var o=btn.textContent;btn.textContent='✓';setTimeout(function(){btn.textContent=o;},1200);
}
</script>
</body>
</html>
