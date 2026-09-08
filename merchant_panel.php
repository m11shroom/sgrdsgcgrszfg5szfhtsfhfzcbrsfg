<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/merchant_db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid     = (int)$_SESSION['user_id'];
$isAdmin = !empty($_SESSION['is_admin']);
$db      = getDB();

try { $db->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
$vRow = $db->prepare('SELECT is_verified FROM users WHERE id=?');
$vRow->execute([$uid]); $vRow = $vRow->fetch();
if (!$isAdmin && empty($vRow['is_verified'])) {
    header('Location: didit_start.php'); exit;
}

setupMerchantTables($db);

$flash = ''; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREATE
    if ($action === 'create') {
        $name    = trim(substr($_POST['name'] ?? '', 0, 100));
        $desc    = trim(substr($_POST['description'] ?? '', 0, 300));
        $website = trim(substr($_POST['website'] ?? '', 0, 255));
        $webhook = trim(substr($_POST['webhook_url'] ?? '', 0, 500));
        $success = trim(substr($_POST['success_url'] ?? '', 0, 500));
        $fail    = trim(substr($_POST['fail_url'] ?? '', 0, 500));
        if (!$name) { $flash = 'Укажите название'; $flashType='err'; }
        else {
            $apiKey = 'mk_' . bin2hex(random_bytes(24));
            $db->prepare('INSERT INTO merchants (user_id,name,description,website,api_key,webhook_url,success_url,fail_url) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$uid,$name,$desc?:null,$website?:null,$apiKey,$webhook?:null,$success?:null,$fail?:null]);
            $_SESSION['flash_type'] = 'ok';
            $_SESSION['flash'] = 'Мерчант создан! API ключ: '.$apiKey;
            header('Location: merchant_panel.php'); exit;
        }
    }

    // UPDATE (webhook, urls, name)
    if ($action === 'update') {
        $mid     = (int)($_POST['merchant_id'] ?? 0);
        $name    = trim(substr($_POST['name'] ?? '', 0, 100));
        $webhook = trim(substr($_POST['webhook_url'] ?? '', 0, 500));
        $success = trim(substr($_POST['success_url'] ?? '', 0, 500));
        $fail    = trim(substr($_POST['fail_url'] ?? '', 0, 500));
        $website = trim(substr($_POST['website'] ?? '', 0, 255));
        $db->prepare('UPDATE merchants SET name=?,website=?,webhook_url=?,success_url=?,fail_url=? WHERE id=? AND user_id=?')
           ->execute([$name,$website?:null,$webhook?:null,$success?:null,$fail?:null,$mid,$uid]);
        $_SESSION['flash'] = 'Настройки сохранены'; header('Location: merchant_panel.php'); exit;
    }

    // REGEN API KEY
    if ($action === 'regen_key') {
        $mid    = (int)($_POST['merchant_id'] ?? 0);
        $newKey = 'mk_' . bin2hex(random_bytes(24));
        $db->prepare('UPDATE merchants SET api_key=? WHERE id=? AND user_id=?')->execute([$newKey,$mid,$uid]);
        $_SESSION['flash_type'] = 'ok';
        $_SESSION['flash'] = 'Новый API ключ: '.$newKey;
        header('Location: merchant_panel.php'); exit;
    }

    // TOGGLE
    if ($action === 'toggle') {
        $mid = (int)($_POST['merchant_id'] ?? 0);
        $db->prepare('UPDATE merchants SET is_active=NOT is_active WHERE id=? AND user_id=?')->execute([$mid,$uid]);
        header('Location: merchant_panel.php'); exit;
    }

    // DELETE FULLY
    if ($action === 'delete') {
        $mid = (int)($_POST['merchant_id'] ?? 0);
        $db->prepare('DELETE FROM merchant_payments WHERE merchant_id=?')->execute([$mid]);
        $db->prepare('DELETE FROM merchants WHERE id=? AND user_id=?')->execute([$mid,$uid]);
        $_SESSION['flash'] = 'Мерчант полностью удалён';
        header('Location: merchant_panel.php'); exit;
    }
}

$flashS = $_SESSION['flash'] ?? $flash; unset($_SESSION['flash']);
$flashT = $_SESSION['flash_type'] ?? $flashType; unset($_SESSION['flash_type']);

// Load merchants with stats
$merchants = $db->prepare(
    'SELECT m.*,
            COUNT(mp.id) AS total_payments,
            COALESCE(SUM(CASE WHEN mp.status=\'paid\' THEN mp.amount ELSE 0 END),0) AS total_earned
     FROM merchants m
     LEFT JOIN merchant_payments mp ON mp.merchant_id=m.id
     WHERE m.user_id=?
     GROUP BY m.id ORDER BY m.created_at DESC'
);
$merchants->execute([$uid]); $merchants = $merchants->fetchAll();

// Editing?
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    foreach ($merchants as $m) { if ($m['id'] === $editId) { $editing = $m; break; } }
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Мерчанты — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:700px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.hdr-btns{display:flex;gap:8px;flex-wrap:wrap}
.hbtn{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.hbtn:hover{border-color:#555;color:#ccc}
.inner{max-width:700px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
.flash{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.14em;padding:12px 15px;border-radius:11px;word-break:break-all}
.flash.ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash.err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
/* FORM */
.form-card{background:#111;border:1px solid #1e1e1e;border-radius:18px;padding:22px 20px;display:flex;flex-direction:column;gap:13px}
.form-title{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.24em;color:#444;display:flex;align-items:center;justify-content:space-between}
.flbl{font-size:.54rem;letter-spacing:.2em;color:#363636;text-transform:uppercase;margin-bottom:5px}
.hint{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.08em;color:#1a1a1a;margin-top:3px;line-height:1.6}
input[type=text],input[type=url],textarea{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.78rem;padding:10px 13px;outline:none;display:block;-webkit-appearance:none}
textarea{resize:vertical;min-height:60px;font-family:'DM Sans',sans-serif}
input:focus,textarea:focus{border-color:#383838}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sub-btn{width:100%;background:#fff;color:#080808;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;letter-spacing:.14em;padding:12px;cursor:pointer;text-transform:uppercase;transition:background .2s}
.sub-btn:hover{background:#e0e0e0}
.cancel-btn{width:100%;background:none;border:1px solid #1e1e1e;border-radius:12px;color:#444;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.14em;padding:11px;cursor:pointer;text-decoration:none;display:block;text-align:center}
.cancel-btn:hover{border-color:#555;color:#ccc}
/* MERCHANT CARD */
.mc{background:#111;border:1px solid #1e1e1e;border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:12px}
.mc.editing{border-color:#383838}
.mc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.mc-name{font-size:.95rem;font-weight:500;color:#ccc;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.badge{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.14em;padding:2px 8px;border-radius:5px}
.b-on{color:#4ade80;background:#001a08;border:1px solid #002a10}
.b-off{color:#555;background:#111;border:1px solid #1e1e1e}
.mc-site{font-family:'Space Mono',monospace;font-size:.5rem;color:#1e1e1e;margin-top:2px}
.mc-stats{display:flex;gap:14px;flex-wrap:wrap}
.mc-st{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525}
.mc-st span{color:#666}
.api-block{background:#0a0a0a;border:1px solid #161616;border-radius:11px;padding:12px 14px;display:flex;flex-direction:column;gap:7px}
.api-lbl{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.22em;color:#1e1e1e}
.api-row{display:flex;align-items:center;gap:7px}
.api-val{font-family:'Space Mono',monospace;font-size:.6rem;color:#555;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.api-mini{background:none;border:1px solid #1e1e1e;border-radius:7px;color:#363636;font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.1em;padding:3px 8px;cursor:pointer;flex-shrink:0}
.api-mini:hover{border-color:#555;color:#888}
.mc-acts{display:flex;gap:7px;flex-wrap:wrap}
.mc-btn{background:none;border:1px solid #1e1e1e;border-radius:8px;color:#444;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:6px 11px;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-block;text-align:center}
.mc-btn:hover{border-color:#555;color:#ccc}
.mc-btn.g{color:#3a6a3a;border-color:#1a3a1a}
.mc-btn.g:hover{border-color:#4ade80;color:#4ade80}
.mc-btn.r:hover{border-color:#c04040;color:#c04040}
.wh-info{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.1em;color:#1a1a1a;word-break:break-all}
.wh-info span{color:#2a2a2a}
@media(max-width:480px){.g2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET</div>
  <div class="hdr-btns">
    <a href="merchant_docs.php" class="hbtn">API Docs</a>
    <a href="dashboard.php" class="hbtn">← Назад</a>
  </div>
</div>
<div class="inner">
  <div class="pg-ttl">МЕРЧАНТЫ</div>

  <?php if ($flashS): ?>
    <div class="flash <?php echo $flashT; ?>"><?php echo htmlspecialchars($flashS); ?></div>
  <?php endif; ?>

  <!-- CREATE FORM (hidden when editing) -->
  <?php if (!$editing): ?>
  <div class="form-card">
    <div class="form-title">НОВЫЙ МЕРЧАНТ</div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div style="margin-bottom:12px">
        <div class="flbl">Название *</div>
        <input type="text" name="name" placeholder="Мой магазин" maxlength="100" required>
      </div>
      <div style="margin-bottom:12px">
        <div class="flbl">Описание</div>
        <textarea name="description" placeholder="Что продаёт магазин..." maxlength="300"></textarea>
      </div>
      <div class="g2" style="margin-bottom:12px">
        <div><div class="flbl">Сайт</div><input type="url" name="website" placeholder="https://myshop.com"></div>
        <div>
          <div class="flbl">Webhook URL</div>
          <input type="url" name="webhook_url" placeholder="https://myshop.com/webhook">
          <div class="hint">POST при оплате</div>
        </div>
      </div>
      <div class="g2" style="margin-bottom:14px">
        <div><div class="flbl">Success URL</div><input type="url" name="success_url" placeholder="https://myshop.com/success"></div>
        <div><div class="flbl">Fail URL</div><input type="url" name="fail_url" placeholder="https://myshop.com/fail"></div>
      </div>
      <button class="sub-btn" type="submit">Создать мерчант</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- EDIT FORM -->
  <?php if ($editing): ?>
  <div class="form-card editing">
    <div class="form-title">
      РЕДАКТИРОВАНИЕ — <?php echo htmlspecialchars($editing['name']); ?>
      <a href="merchant_panel.php" style="font-size:.52rem;color:#555;text-decoration:none">✕ Отмена</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="merchant_id" value="<?php echo $editing['id']; ?>">
      <div style="margin-bottom:12px">
        <div class="flbl">Название *</div>
        <input type="text" name="name" value="<?php echo htmlspecialchars($editing['name']); ?>" maxlength="100" required>
      </div>
      <div class="g2" style="margin-bottom:12px">
        <div><div class="flbl">Сайт</div><input type="url" name="website" value="<?php echo htmlspecialchars($editing['website']??''); ?>" placeholder="https://myshop.com"></div>
        <div>
          <div class="flbl">Webhook URL</div>
          <input type="url" name="webhook_url" value="<?php echo htmlspecialchars($editing['webhook_url']??''); ?>" placeholder="https://myshop.com/webhook">
          <div class="hint">POST при оплате — можно изменить в любое время</div>
        </div>
      </div>
      <div class="g2" style="margin-bottom:14px">
        <div><div class="flbl">Success URL</div><input type="url" name="success_url" value="<?php echo htmlspecialchars($editing['success_url']??''); ?>" placeholder="https://myshop.com/success"></div>
        <div><div class="flbl">Fail URL</div><input type="url" name="fail_url" value="<?php echo htmlspecialchars($editing['fail_url']??''); ?>" placeholder="https://myshop.com/fail"></div>
      </div>
      <button class="sub-btn" type="submit" style="margin-bottom:8px">Сохранить</button>
    </form>
    <a href="merchant_panel.php" class="cancel-btn">Отмена</a>
  </div>
  <?php endif; ?>

  <!-- MERCHANT CARDS -->
  <?php foreach ($merchants as $m): ?>
  <div class="mc <?php echo ($editing && $editing['id']===$m['id'])?'editing':''; ?>">
    <div class="mc-top">
      <div>
        <div class="mc-name">
          <?php echo htmlspecialchars($m['name']); ?>
          <span class="badge <?php echo $m['is_active']?'b-on':'b-off'; ?>"><?php echo $m['is_active']?'ON':'OFF'; ?></span>
        </div>
        <?php if ($m['website']): ?><div class="mc-site"><?php echo htmlspecialchars($m['website']); ?></div><?php endif; ?>
      </div>
    </div>

    <div class="mc-stats">
      <div class="mc-st">Платежей: <span><?php echo $m['total_payments']; ?></span></div>
      <div class="mc-st">Оборот: <span><?php echo number_format((float)$m['total_earned'],2,'.',','); ?> ₽</span></div>
      <div class="mc-st">Создан: <span><?php echo date('d.m.Y',strtotime($m['created_at'])); ?></span></div>
    </div>

    <div class="api-block">
      <div class="api-lbl">API KEY</div>
      <div class="api-row">
        <div class="api-val" id="k<?php echo $m['id']; ?>">••••••••••••••••••••••••••••</div>
        <button class="api-mini" onclick="showKey(<?php echo $m['id'];?>,<?php echo json_encode($m['api_key']);?>)">Показать</button>
        <button class="api-mini" onclick="cpKey(<?php echo json_encode($m['api_key']);?>,this)">Копировать</button>
      </div>
      <?php if ($m['webhook_url']): ?>
        <div class="wh-info">Webhook: <span><?php echo htmlspecialchars($m['webhook_url']); ?></span></div>
      <?php endif; ?>
    </div>

    <div class="mc-acts">
      <a href="merchant_payments.php?mid=<?php echo $m['id']; ?>" class="mc-btn g">Платежи →</a>
      <a href="merchant_panel.php?edit=<?php echo $m['id']; ?>" class="mc-btn">Редактировать</a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="regen_key">
        <input type="hidden" name="merchant_id" value="<?php echo $m['id']; ?>">
        <button class="mc-btn" type="submit" onclick="return confirm('Сгенерировать новый API ключ? Старый перестанет работать.')">Новый ключ</button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="merchant_id" value="<?php echo $m['id']; ?>">
        <button class="mc-btn" type="submit"><?php echo $m['is_active']?'Отключить':'Включить'; ?></button>
      </form>
      <form method="POST" style="display:inline" onsubmit="return confirm('Удалить мерчант ПОЛНОСТЬЮ включая все платежи? Это необратимо.')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="merchant_id" value="<?php echo $m['id']; ?>">
        <button class="mc-btn r" type="submit">Удалить</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (empty($merchants) && !$editing): ?>
    <div style="font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:28px">Мерчантов нет</div>
  <?php endif; ?>
</div>

<script>
function showKey(id,key){var el=document.getElementById('k'+id);if(el.textContent.includes('•')){el.textContent=key;el.style.color='#888';}else{el.textContent='••••••••••••••••••••••••••••';el.style.color='';}}
function cpKey(key,btn){navigator.clipboard?navigator.clipboard.writeText(key):(function(){var e=document.createElement('textarea');e.value=key;document.body.appendChild(e);e.select();document.execCommand('copy');document.body.removeChild(e);})();var o=btn.textContent;btn.textContent='✓';setTimeout(function(){btn.textContent=o;},1300);}
</script>
</body>
</html>
