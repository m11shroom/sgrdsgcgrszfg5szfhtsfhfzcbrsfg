<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }

$db = getDB();
$ICONS  = m1_icons();
$LABELS = m1_icon_labels();
$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/';
$uploadUrl = defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/';

function handleImageUpload(string $dir): ?string {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES['image'];
    $mime = mime_content_type($f['tmp_name']);
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extMap[$mime]) || $f['size'] > 8*1024*1024) return null;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'story_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], rtrim($dir,'/') . '/' . $name)) return null;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $icon  = $_POST['icon'] ?? '';
    if (!isset($ICONS[$icon])) $icon = null;
    $from  = $_POST['grad_from'] ?? '#7c3aed';
    $to    = $_POST['grad_to'] ?? '#3b0764';
    $sort  = (int)($_POST['sort_order'] ?? 0);

    if ($a === 'add' && $title && $body) {
        $imageName = handleImageUpload($uploadDir);
        $db->prepare("INSERT INTO stories (title, body, emoji, icon, grad_from, grad_to, image, sort_order) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$title, $body, '💜', $icon, $from, $to, $imageName, $sort]);
    } elseif ($a === 'edit' && $title && $body) {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("SELECT image FROM stories WHERE id=?"); $st->execute([$id]);
        $oldImg = $st->fetchColumn();
        $newImg = handleImageUpload($uploadDir);
        if (!empty($_POST['remove_image'])) {
            if ($oldImg) @unlink(rtrim($uploadDir,'/') . '/' . $oldImg);
            $imageName = $newImg; // null если новую не грузили
        } elseif ($newImg) {
            if ($oldImg) @unlink(rtrim($uploadDir,'/') . '/' . $oldImg);
            $imageName = $newImg;
        } else {
            $imageName = $oldImg ?: null;
        }
        $db->prepare("UPDATE stories SET title=?, body=?, icon=?, grad_from=?, grad_to=?, image=?, sort_order=? WHERE id=?")
           ->execute([$title, $body, $icon, $from, $to, $imageName, $sort, $id]);
    } elseif ($a === 'toggle') {
        $db->prepare("UPDATE stories SET is_active = 1 - is_active WHERE id=?")->execute([(int)$_POST['id']]);
    } elseif ($a === 'delete') {
        $st = $db->prepare("SELECT image FROM stories WHERE id=?"); $st->execute([(int)$_POST['id']]);
        $img = $st->fetchColumn();
        if ($img) @unlink(rtrim($uploadDir,'/') . '/' . $img);
        $db->prepare("DELETE FROM stories WHERE id=?")->execute([(int)$_POST['id']]);
    }
    header('Location: stories_admin.php'); exit;
}

/* Режим редактирования */
$editStory = null;
if (!empty($_GET['edit'])) {
    $st = $db->prepare("SELECT * FROM stories WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editStory = $st->fetch() ?: null;
}

$stories = $db->query("SELECT * FROM stories ORDER BY sort_order ASC, id DESC")->fetchAll();
$curIcon = $editStory['icon'] ?? 'card';
$curFrom = $editStory['grad_from'] ?? '#7c3aed';
$curTo   = $editStory['grad_to'] ?? '#3b0764';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus — Новости</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;min-height:100vh;padding:24px 16px 60px}
.wrap{max-width:640px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.head{display:flex;align-items:center;justify-content:space-between}
h1{font-size:1.2rem;font-weight:700}
a.back{color:#8f8fa3;font-size:.82rem;text-decoration:none}
.card{background:#15151f;border-radius:18px;padding:18px 16px;display:flex;flex-direction:column;gap:12px}
.card-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;justify-content:space-between}
.lbl{font-size:.72rem;color:#8f8fa3;margin-bottom:5px;font-weight:500}
input,textarea{width:100%;background:#1b1b28;border:1px solid transparent;border-radius:12px;color:#fff;font-family:inherit;font-size:.9rem;padding:11px 13px;outline:none;-webkit-appearance:none}
input:focus,textarea:focus{border-color:#8b5cf6}
textarea{min-height:90px;resize:vertical}
.row2{display:flex;gap:10px}
.row2>div{flex:1}
.btn{background:#8b5cf6;color:#fff;border:none;border-radius:12px;font-family:inherit;font-size:.88rem;font-weight:500;padding:12px;cursor:pointer;width:100%}
.btn:active{background:#6d28d9}
.cancel-edit{color:#ef4444;font-size:.74rem;text-decoration:none}
/* Icon picker */
.icon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(66px,1fr));gap:8px}
.icon-opt{background:#1b1b28;border:2px solid transparent;border-radius:13px;padding:9px 4px;display:flex;flex-direction:column;align-items:center;gap:5px;cursor:pointer;color:#8f8fa3;font-family:inherit;font-size:.6rem}
.icon-opt svg{width:22px;height:22px}
.icon-opt.sel{border-color:#8b5cf6;color:#a78bfa;background:#221a3d}
/* 3D preview */
.preview3d{position:relative;height:190px;border-radius:18px;overflow:hidden;perspective:600px}
.ic3d{display:flex;align-items:center;justify-content:center;color:#fff;background:none;border:none;box-shadow:none;position:absolute}
.ic3d svg{width:88%;height:88%}
.pv-main{width:100px;height:100px;left:32px;top:40px;animation:fM 4.5s ease-in-out infinite}
.pv-main::before{content:'';position:absolute;inset:-22%;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.28) 0%,rgba(255,255,255,.07) 45%,transparent 70%);z-index:-1}
.pv-f1{width:44px;height:44px;right:26%;top:16px;opacity:.9;animation:fA 5.5s ease-in-out infinite}
.pv-f2{width:34px;height:34px;right:8%;top:56%;opacity:.65;animation:fB 6s ease-in-out infinite}
.pv-f3{width:26px;height:26px;left:52%;bottom:10px;opacity:.4;animation:fA 4.8s ease-in-out infinite}
@keyframes fM{0%,100%{transform:rotate(-8deg) translateY(0)}50%{transform:rotate(-8deg) translateY(-9px)}}
@keyframes fA{0%,100%{transform:rotate(13deg) translateY(0)}50%{transform:rotate(13deg) translateY(-6px)}}
@keyframes fB{0%,100%{transform:rotate(-15deg) translateY(0)}50%{transform:rotate(-15deg) translateY(-5px)}}
/* Story list */
.story-row{display:flex;align-items:center;gap:12px;background:#1b1b28;border-radius:14px;padding:11px 13px}
.story-prev{width:52px;height:64px;border-radius:11px;flex-shrink:0;display:flex;flex-direction:column;justify-content:space-between;padding:6px;overflow:hidden;background-size:cover;background-position:center}
.story-prev .ic3d{position:static;width:24px;height:24px;border-radius:26%}
.story-prev .ic3d svg{width:14px;height:14px}
.story-prev-t{font-size:.5rem;font-weight:700;color:#fff;line-height:1.2;text-shadow:0 1px 2px rgba(0,0,0,.6)}
.story-meta{flex:1;min-width:0}
.story-meta-title{font-size:.88rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.story-meta-sub{font-size:.7rem;color:#55556b;margin-top:2px}
.mini-btn{background:none;border:1px solid #23233a;border-radius:9px;color:#8f8fa3;font-family:inherit;font-size:.72rem;padding:7px 11px;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block}
.mini-btn:active{border-color:#8b5cf6;color:#a78bfa}
.mini-btn.on{color:#4ade80;border-color:rgba(74,222,128,.3)}
.mini-btn.del{color:#ef4444;border-color:rgba(239,68,68,.25)}
.mini-btn.edit{color:#a78bfa;border-color:rgba(139,92,246,.35)}
form.inline{display:inline;margin:0}
.hint{font-size:.68rem;color:#55556b;margin-top:5px}
.chk-row{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#8f8fa3}
.chk-row input{width:auto}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>Новости (сторис)</h1>
    <a class="back" href="admin.php">← В админку</a>
  </div>

  <div class="card">
    <div class="card-title">
      <?php echo $editStory ? 'Редактировать: '.htmlspecialchars(mb_substr($editStory['title'],0,30)) : 'Добавить новость'; ?>
      <?php if($editStory): ?><a class="cancel-edit" href="stories_admin.php">✕ отменить</a><?php endif; ?>
    </div>
    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:11px">
      <input type="hidden" name="action" value="<?php echo $editStory?'edit':'add'; ?>">
      <?php if($editStory): ?><input type="hidden" name="id" value="<?php echo (int)$editStory['id']; ?>"><?php endif; ?>
      <input type="hidden" name="icon" id="iconInput" value="<?php echo htmlspecialchars($curIcon); ?>">

      <div><div class="lbl">Заголовок</div><input type="text" name="title" maxlength="120" required placeholder="Терминалы уже здесь" value="<?php echo htmlspecialchars($editStory['title'] ?? ''); ?>"></div>
      <div><div class="lbl">Текст новости</div><textarea name="body" required placeholder="Подробный текст..."><?php echo htmlspecialchars($editStory['body'] ?? ''); ?></textarea></div>

      <div>
        <div class="lbl">Иконка</div>
        <div class="icon-grid" id="iconGrid">
          <?php foreach ($ICONS as $key => $svg): ?>
          <button type="button" class="icon-opt <?php echo $key===$curIcon?'sel':''; ?>" data-icon="<?php echo $key; ?>" onclick="pickIcon('<?php echo $key; ?>')">
            <?php echo $svg; ?>
            <span><?php echo $LABELS[$key] ?? $key; ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <div class="lbl">Превью 3D-графики</div>
        <div class="preview3d" id="pv3d" style="background:linear-gradient(160deg,<?php echo htmlspecialchars($curFrom); ?>,<?php echo htmlspecialchars($curTo); ?>)">
          <div class="ic3d pv-main" id="pvMain"></div>
          <div class="ic3d pv-f1" id="pvF1"></div>
          <div class="ic3d pv-f2" id="pvF2"></div>
          <div class="ic3d pv-f3" id="pvF3"></div>
        </div>
      </div>

      <div class="row2">
        <div><div class="lbl">Градиент от</div><input type="color" name="grad_from" id="gFrom" value="<?php echo htmlspecialchars($curFrom); ?>" style="height:44px;padding:5px" oninput="updPrev()"></div>
        <div><div class="lbl">Градиент до</div><input type="color" name="grad_to" id="gTo" value="<?php echo htmlspecialchars($curTo); ?>" style="height:44px;padding:5px" oninput="updPrev()"></div>
      </div>

      <div>
        <div class="lbl">Картинка (необязательно — заменит 3D-графику)</div>
        <input type="file" name="image" accept="image/*" style="padding:8px 13px">
        <?php if($editStory && $editStory['image']): ?>
        <div class="hint">Сейчас: 📷 <?php echo htmlspecialchars($editStory['image']); ?></div>
        <label class="chk-row" style="margin-top:6px"><input type="checkbox" name="remove_image" value="1"> Удалить текущую картинку</label>
        <?php else: ?>
        <div class="hint">До 8 МБ. Если не загружать — покажется 3D-графика с иконкой.</div>
        <?php endif; ?>
      </div>

      <div class="row2">
        <div><div class="lbl">Порядок</div><input type="number" name="sort_order" value="<?php echo (int)($editStory['sort_order'] ?? 0); ?>"></div>
        <div style="display:flex;align-items:flex-end"><button class="btn" type="submit"><?php echo $editStory?'Сохранить':'Добавить'; ?></button></div>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-title">Все новости (<?php echo count($stories); ?>)</div>
    <?php if (empty($stories)): ?>
      <div style="font-size:.8rem;color:#55556b;text-align:center;padding:14px">Новостей нет</div>
    <?php else: foreach ($stories as $s):
      $prevStyle = !empty($s['image'])
        ? "background-image:linear-gradient(180deg,rgba(0,0,0,.1),rgba(0,0,0,.55)),url('".htmlspecialchars($uploadUrl.$s['image'])."')"
        : "background:linear-gradient(135deg,".htmlspecialchars($s['grad_from']).",".htmlspecialchars($s['grad_to']).")";
    ?>
    <div class="story-row">
      <div class="story-prev" style="<?php echo $prevStyle; ?>">
        <?php if (empty($s['image'])):
          if (!empty($s['icon']) && isset($ICONS[$s['icon']])): ?>
            <span class="ic3d"><?php echo $ICONS[$s['icon']]; ?></span>
          <?php else: ?>
            <span style="font-size:1rem"><?php echo htmlspecialchars($s['emoji']); ?></span>
          <?php endif;
        endif; ?>
        <div class="story-prev-t"><?php echo htmlspecialchars(mb_substr($s['title'],0,24)); ?></div>
      </div>
      <div class="story-meta">
        <div class="story-meta-title"><?php echo htmlspecialchars($s['title']); ?></div>
        <div class="story-meta-sub">Порядок: <?php echo (int)$s['sort_order']; ?><?php echo !empty($s['image'])?' · 📷':''; ?><?php echo !empty($s['icon'])?' · '.($LABELS[$s['icon']]??$s['icon']):''; ?></div>
      </div>
      <a class="mini-btn edit" href="?edit=<?php echo (int)$s['id']; ?>">Изм.</a>
      <form class="inline" method="POST">
        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
        <button class="mini-btn <?php echo $s['is_active']?'on':''; ?>" type="submit"><?php echo $s['is_active']?'Вкл':'Скрыта'; ?></button>
      </form>
      <form class="inline" method="POST" onsubmit="return confirm('Удалить новость?')">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
        <button class="mini-btn del" type="submit">✕</button>
      </form>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
var ICONS = <?php echo json_encode($ICONS, JSON_UNESCAPED_UNICODE); ?>;
var ICON_KEYS = Object.keys(ICONS);
var curIcon = <?php echo json_encode($curIcon); ?>;

function pickIcon(key){
  curIcon = key;
  document.getElementById('iconInput').value = key;
  document.querySelectorAll('.icon-opt').forEach(function(b){
    b.className = 'icon-opt' + (b.dataset.icon === key ? ' sel' : '');
  });
  updPrev();
}
function updPrev(){
  var f = document.getElementById('gFrom').value;
  var t = document.getElementById('gTo').value;
  document.getElementById('pv3d').style.background = 'linear-gradient(160deg,'+f+','+t+')';
  document.getElementById('pvMain').innerHTML = ICONS[curIcon] || '';
  var others = ICON_KEYS.filter(function(k){return k!==curIcon;});
  var base = ICON_KEYS.indexOf(curIcon);
  document.getElementById('pvF1').innerHTML = ICONS[others[(base+1)%others.length]];
  document.getElementById('pvF2').innerHTML = ICONS[others[(base+4)%others.length]];
  document.getElementById('pvF3').innerHTML = ICONS[others[(base+7)%others.length]];
}
updPrev();
</script>
</body>
</html>
