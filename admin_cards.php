<?php
/**
 * admin_cards.php — раздел управления картами (включить в admin.php или использовать отдельно)
 * Показывает: валюту, назначение, комиссию, начальный баланс
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

// Защита: только администраторы
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); exit('Forbidden');
}

try {
    $db = getDB();
} catch (Exception $e) {
    die('Ошибка соединения с БД');
}

/* ─── Действия ─────────────────────────────────────────── */
$msg = '';

// Добавить шаблон
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'add_tmpl') {
    $name      = trim($_POST['name'] ?? '');
    $cost      = max(0, (float)($_POST['issue_cost'] ?? 0));
    $itype     = in_array($_POST['issue_type'] ?? '', ['instant','wait']) ? $_POST['issue_type'] : 'instant';
    $wdays     = $itype === 'wait' ? max(1, (int)($_POST['wait_days'] ?? 7)) : null;
    $btype     = in_array($_POST['balance_type'] ?? '', ['zero','prepaid']) ? $_POST['balance_type'] : 'zero';
    $currency  = in_array($_POST['currency'] ?? '', ['RUB','EUR','USD']) ? $_POST['currency'] : 'RUB';
    $cover     = '';

    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $ext   = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (in_array($ext, $allowed)) {
            $fname = uniqid('card_') . '.' . $ext;
            $dest  = __DIR__ . '/uploads/' . $fname;
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
                $cover = $fname;
            }
        }
    }

    if ($name) {
        $db->prepare('INSERT INTO card_templates (name,cover_image,issue_cost,issue_type,wait_days,balance_type,currency) VALUES (?,?,?,?,?,?,?)')
           ->execute([$name, $cover ?: null, $cost, $itype, $wdays, $btype, $currency]);
        $msg = 'success|Шаблон добавлен';
    } else {
        $msg = 'err|Укажите название';
    }
}

// Активировать/деактивировать шаблон
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'toggle_tmpl') {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare('UPDATE card_templates SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
    $msg = 'success|Статус изменён';
}

// Активировать карту и вписать реквизиты
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'activate_card') {
    $cid  = (int)($_POST['card_id'] ?? 0);
    $num  = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $exp  = trim($_POST['expiry'] ?? '');
    $cvv  = trim($_POST['cvv'] ?? '');

    if ($cid && strlen($num) >= 13 && $exp && $cvv) {
        $db->prepare("UPDATE issued_cards SET status='active', card_number=?, expiry=?, cvv=?, activated_at=NOW() WHERE id=?")
           ->execute([$num, $exp, $cvv, $cid]);
        $msg = 'success|Карта активирована';
    } else {
        $msg = 'err|Заполните все поля';
    }
}

// Обновить назначение
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'set_purpose') {
    $cid     = (int)($_POST['card_id'] ?? 0);
    $purpose = mb_substr(trim($_POST['purpose'] ?? ''), 0, 255);
    $db->prepare('UPDATE issued_cards SET purpose=? WHERE id=?')->execute([$purpose ?: null, $cid]);
    $msg = 'success|Назначение обновлено';
}

/* ─── Данные ────────────────────────────────────────────── */
$templates = $db->query('SELECT * FROM card_templates ORDER BY id DESC')->fetchAll();
$cards = $db->query("
    SELECT ic.*, u.name AS uname, u.email AS uemail, ct.name AS tname
    FROM issued_cards ic
    LEFT JOIN users u ON u.id = ic.user_id
    LEFT JOIN card_templates ct ON ct.id = ic.template_id
    ORDER BY ic.id DESC
")->fetchAll();

[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Карты</title>
<style>
:root {
  --bg:#0b0e14; --surface:#141820; --card:#1c2230; --border:#252d3d;
  --accent:#4f8ef7; --green:#27c97a; --red:#f75f5f; --yellow:#f7c34f;
  --text:#e8eaf0; --muted:#6b7590; --radius:12px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:.9rem}
.wrap{max-width:1200px;margin:0 auto;padding:28px 16px 60px}
h1{font-size:1.5rem;margin-bottom:24px}
h2{font-size:1.1rem;margin-bottom:16px;color:var(--accent)}
.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:28px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-row{margin-bottom:13px}
.form-row label{display:block;font-size:.78rem;color:var(--muted);margin-bottom:5px}
.form-row input,.form-row select,.form-row textarea{
  width:100%;background:var(--surface);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:9px 11px;font-size:.88rem;outline:none;
  font-family:inherit; resize:vertical;
}
.form-row input:focus,.form-row select:focus,.form-row textarea:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;transition:opacity .2s}
.btn-primary{background:var(--accent);color:#fff}
.btn-success{background:var(--green);color:#fff}
.btn-warn{background:var(--yellow);color:#000}
.btn-sm{padding:5px 12px;font-size:.78rem}
.btn:hover{opacity:.85}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:18px;font-size:.85rem}
.alert-ok{background:rgba(39,201,122,.15);border:1px solid rgba(39,201,122,.3);color:var(--green)}
.alert-err{background:rgba(247,95,95,.15);border:1px solid rgba(247,95,95,.3);color:var(--red)}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:top}
th{font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px}
tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;padding:2px 8px;border-radius:12px}
.b-pend{background:rgba(247,195,79,.15);color:var(--yellow)}
.b-act{background:rgba(39,201,122,.15);color:var(--green)}
.b-inst{background:rgba(39,201,122,.1);color:var(--green)}
.b-wait{background:rgba(247,195,79,.1);color:var(--yellow)}
.b-rub{background:rgba(79,142,247,.1);color:var(--accent)}
.b-usd{background:rgba(39,201,122,.1);color:var(--green)}
.b-eur{background:rgba(247,195,79,.1);color:var(--yellow)}
.b-off{background:rgba(107,117,144,.15);color:var(--muted)}
.purpose-cell{max-width:180px;word-break:break-word;font-size:.8rem;color:var(--muted)}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;z-index:100;padding:16px}
.modal-overlay.show{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;max-width:440px;width:100%}
.modal h3{margin-bottom:18px}
.modal-close{float:right;cursor:pointer;color:var(--muted);font-size:1.3rem;margin-top:-4px}
</style>
</head>
<body>
<div class="wrap">
  <h1>⚙️ Управление картами</h1>

  <?php if ($msgText): ?>
  <div class="alert alert-<?= $msgType==='success'?'ok':'err' ?>"><?= htmlspecialchars($msgText) ?></div>
  <?php endif; ?>

  <!-- ─── Добавить шаблон ─── -->
  <div class="panel">
    <h2>➕ Новый шаблон карты</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="act" value="add_tmpl">
      <div class="grid2">
        <div class="form-row">
          <label>Название</label>
          <input type="text" name="name" required placeholder="Visa Classic">
        </div>
        <div class="form-row">
          <label>Стоимость выпуска (₽)</label>
          <input type="number" name="issue_cost" min="0" step="0.01" value="0">
        </div>
        <div class="form-row">
          <label>Тип выпуска</label>
          <select name="issue_type" onchange="document.getElementById('wait-days-row').style.display=this.value==='wait'?'block':'none'">
            <option value="instant">⚡ Мгновенный</option>
            <option value="wait">⏳ С ожиданием</option>
          </select>
        </div>
        <div class="form-row" id="wait-days-row" style="display:none">
          <label>Максимум дней ожидания</label>
          <input type="number" name="wait_days" min="1" max="365" value="7">
        </div>
        <div class="form-row">
          <label>Тип баланса</label>
          <select name="balance_type">
            <option value="zero">○ Нулевой</option>
            <option value="prepaid">💰 Предоплата</option>
          </select>
        </div>
        <div class="form-row">
          <label>Валюта карты</label>
          <select name="currency">
            <option value="RUB">₽ Российский рубль (RUB)</option>
            <option value="USD">$ Доллар США (USD)</option>
            <option value="EUR">€ Евро (EUR)</option>
          </select>
        </div>
        <div class="form-row" style="grid-column:1/-1">
          <label>Обложка карты (jpg/png/webp)</label>
          <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.webp">
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Создать шаблон</button>
    </form>
  </div>

  <!-- ─── Шаблоны ─── -->
  <div class="panel">
    <h2>📋 Шаблоны карт</h2>
    <?php if (empty($templates)): ?>
      <p style="color:var(--muted)">Нет шаблонов</p>
    <?php else: ?>
    <table>
      <tr><th>ID</th><th>Название</th><th>Выпуск</th><th>Тип</th><th>Баланс</th><th>Валюта</th><th>Стоимость</th><th>Статус</th><th></th></tr>
      <?php foreach ($templates as $t): ?>
      <tr>
        <td style="color:var(--muted)">#<?= $t['id'] ?></td>
        <td><?= htmlspecialchars($t['name']) ?>
          <?php if ($t['cover_image']): ?>
            <br><img src="/uploads/<?= htmlspecialchars($t['cover_image']) ?>" style="height:28px;border-radius:4px;margin-top:4px">
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $t['issue_type']==='instant'?'b-inst':'b-wait' ?>"><?= $t['issue_type']==='instant'?'⚡ Instant':'⏳ Wait '.$t['wait_days'].'d' ?></span></td>
        <td><?= $t['balance_type'] ?></td>
        <td><span class="badge b-<?= strtolower($t['currency']) ?>"><?= $t['currency'] ?></span></td>
        <td><?= number_format((float)$t['issue_cost'],2) ?> ₽</td>
        <td><span class="badge <?= $t['is_active']?'b-act':'b-off' ?>"><?= $t['is_active']?'Активен':'Откл' ?></span></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="act" value="toggle_tmpl">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-sm <?= $t['is_active']?'btn-warn':'btn-success' ?>" type="submit">
              <?= $t['is_active']?'Откл':'Вкл' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- ─── Заявки на карты ─── -->
  <div class="panel">
    <h2>💳 Заявки на карты</h2>
    <?php if (empty($cards)): ?>
      <p style="color:var(--muted)">Нет заявок</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <tr>
        <th>ID</th><th>Клиент</th><th>Карта</th><th>Статус</th><th>Валюта</th>
        <th>Баланс / Запрос</th><th>Комиссия</th><th>Выпуск оплачен</th>
        <th>Назначение</th><th>Дата</th><th>Действие</th>
      </tr>
      <?php foreach ($cards as $c):
        $sym = $c['currency']==='USD'?'$':($c['currency']==='EUR'?'€':'₽');
      ?>
      <tr>
        <td style="color:var(--muted)">#<?= $c['id'] ?></td>
        <td>
          <b><?= htmlspecialchars($c['uname'] ?? '?') ?></b><br>
          <span style="color:var(--muted);font-size:.78rem"><?= htmlspecialchars($c['uemail'] ?? '') ?></span>
        </td>
        <td><?= htmlspecialchars($c['tname'] ?? 'Custom') ?></td>
        <td><span class="badge <?= $c['status']==='active'?'b-act':'b-pend' ?>"><?= $c['status'] ?></span></td>
        <td><span class="badge b-<?= strtolower($c['currency']) ?>"><?= $c['currency'] ?></span></td>
        <td>
          <?php if ($c['prepaid_amount']): ?>
            💰 <?= number_format((float)$c['prepaid_amount'],2) ?> <?= $sym ?>
          <?php elseif ($c['requested_amount']): ?>
            📋 <?= number_format((float)$c['requested_amount'],2) ?> <?= $sym ?> <em style="color:var(--muted);font-size:.75rem">(запрос)</em>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($c['commission_amount'] > 0): ?>
            <span style="color:var(--yellow)"><?= number_format((float)$c['commission_amount'],2) ?> ₽</span>
          <?php else: ?>
            <span style="color:var(--muted)">0</span>
          <?php endif; ?>
        </td>
        <td><?= number_format((float)$c['issue_cost_paid'],2) ?> ₽</td>
        <td class="purpose-cell">
          <?php if (!empty($c['purpose'])): ?>
            <span style="color:var(--text)"><?= htmlspecialchars($c['purpose']) ?></span>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
          <br>
          <a href="#" onclick="openPurpose(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['purpose']??'')) ?>); return false"
             style="font-size:.75rem; color:var(--accent)">✏️ изменить</a>
        </td>
        <td style="font-size:.78rem; color:var(--muted)"><?= date('d.m.y H:i', strtotime($c['created_at'])) ?></td>
        <td>
          <?php if ($c['status'] === 'pending'): ?>
          <button class="btn btn-success btn-sm" onclick="openActivate(<?= $c['id'] ?>)">Активировать</button>
          <?php else: ?>
          <span style="color:var(--green);font-size:.78rem">✔ Активна<br><?= date('d.m.y', strtotime($c['activated_at'])) ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ─── Activate modal ─── -->
<div class="modal-overlay" id="act-modal">
  <div class="modal">
    <span class="modal-close" onclick="closeActivate()">✕</span>
    <h3>Активировать карту</h3>
    <form method="post" id="act-form">
      <input type="hidden" name="act" value="activate_card">
      <input type="hidden" name="card_id" id="act-card-id">
      <div class="form-row">
        <label>Номер карты (16-18 цифр)</label>
        <input type="text" name="card_number" placeholder="4111 1111 1111 1111" maxlength="19" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-row">
          <label>Срок (MM/YY)</label>
          <input type="text" name="expiry" placeholder="12/28" maxlength="5" required>
        </div>
        <div class="form-row">
          <label>CVV</label>
          <input type="text" name="cvv" placeholder="123" maxlength="4" required>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:4px">
        <button type="button" class="btn" style="background:var(--surface);border:1px solid var(--border);color:var(--muted)" onclick="closeActivate()">Отмена</button>
        <button type="submit" class="btn btn-success">Активировать</button>
      </div>
    </form>
  </div>
</div>

<!-- ─── Purpose modal ─── -->
<div class="modal-overlay" id="purpose-modal">
  <div class="modal">
    <span class="modal-close" onclick="closePurpose()">✕</span>
    <h3>📋 Назначение карты</h3>
    <form method="post">
      <input type="hidden" name="act" value="set_purpose">
      <input type="hidden" name="card_id" id="purp-card-id">
      <div class="form-row">
        <label>Назначение (видит клиент)</label>
        <textarea name="purpose" id="purp-text" maxlength="255" rows="3" placeholder="Необязательно…"></textarea>
      </div>
      <div style="display:flex;gap:10px">
        <button type="button" class="btn" style="background:var(--surface);border:1px solid var(--border);color:var(--muted)" onclick="closePurpose()">Отмена</button>
        <button type="submit" class="btn btn-primary">Сохранить</button>
      </div>
    </form>
  </div>
</div>

<script>
function openActivate(id) {
  document.getElementById('act-card-id').value = id;
  document.getElementById('act-modal').classList.add('show');
}
function closeActivate() { document.getElementById('act-modal').classList.remove('show'); }

function openPurpose(id, text) {
  document.getElementById('purp-card-id').value = id;
  document.getElementById('purp-text').value = text || '';
  document.getElementById('purpose-modal').classList.add('show');
}
function closePurpose() { document.getElementById('purpose-modal').classList.remove('show'); }
</script>
</body>
</html>
