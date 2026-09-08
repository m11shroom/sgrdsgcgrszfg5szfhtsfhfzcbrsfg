<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])||empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
$db = getDB();

$links = $db->query(
    'SELECT pl.*, u.username AS creator,
            COUNT(plt.id) AS tx_count,
            COALESCE(SUM(CASE WHEN plt.status=\'completed\' THEN plt.amount ELSE 0 END),0) AS total_paid,
            COALESCE(SUM(CASE WHEN plt.status=\'completed\' THEN plt.creator_gets ELSE 0 END),0) AS total_earned
     FROM payment_links pl
     JOIN users u ON u.id=pl.user_id
     LEFT JOIN payment_link_txs plt ON plt.link_id=pl.id
     GROUP BY pl.id
     ORDER BY pl.created_at DESC'
)->fetchAll();
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Все платёжные ссылки — Админ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 60px}
.hdr{max-width:900px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:22px 0 26px;flex-wrap:wrap;gap:10px}
.brand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.back{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;text-decoration:none;transition:all .2s}
.back:hover{border-color:#555;color:#ccc}
.inner{max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.pg-ttl{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.26em;color:#444}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px}
.sc{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:12px 16px;flex:1;min-width:110px}
.sn{font-family:'Space Mono',monospace;font-size:1.2rem;margin-bottom:3px;color:#f0f0f0}
.sl{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.18em;color:#252525;text-transform:uppercase}
.srch{width:100%;max-width:380px;background:#111;border:1px solid #1e1e1e;border-radius:11px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.72rem;padding:9px 13px;outline:none;-webkit-appearance:none}
.srch:focus{border-color:#333}
.link-card{background:#111;border:1px solid #1e1e1e;border-radius:14px;padding:15px 16px;display:flex;flex-direction:column;gap:10px}
.lc-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.lc-name{font-size:.9rem;font-weight:500;color:#ccc}
.lc-amount{font-family:'Space Mono',monospace;font-size:.9rem;color:#f0f0f0}
.lc-meta{display:flex;flex-wrap:wrap;gap:12px}
.lc-m{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:#252525}
.lc-m span{color:#666}
.lc-tags{display:flex;gap:6px;flex-wrap:wrap}
.tag{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.1em;padding:2px 7px;border-radius:5px;border:1px solid #1e1e1e;color:#363636}
.tag.active{color:#3a6a3a;border-color:#1a3a1a;background:#0a100a}
.tag.inactive{color:#555;border-color:#1e1e1e}
.tag.onetime{color:#555}
.lc-btn{background:none;border:1px solid #1a2a1a;border-radius:8px;color:#3a6a3a;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:5px 10px;cursor:pointer;text-decoration:none;transition:all .2s}
.lc-btn:hover{border-color:#4ade80;color:#4ade80}
.empty{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:28px}
</style>
</head>
<body>
<div class="hdr">
  <div class="brand"><div class="bdot"></div>M1PLUS WALLET · ADMIN</div>
  <a href="admin.php" class="back">← Панель</a>
</div>
<div class="inner">
  <div class="pg-ttl">ВСЕ ПЛАТЁЖНЫЕ ССЫЛКИ</div>

  <?php
  $totalLinks = count($links);
  $activeLinks = count(array_filter($links,fn($l)=>$l['is_active']));
  $totalRevenue= array_sum(array_map(fn($l)=>(float)$l['total_paid'],$links));
  ?>
  <div class="stats">
    <div class="sc"><div class="sn"><?php echo $totalLinks; ?></div><div class="sl">Всего ссылок</div></div>
    <div class="sc"><div class="sn"><?php echo $activeLinks; ?></div><div class="sl">Активных</div></div>
    <div class="sc"><div class="sn"><?php echo number_format($totalRevenue,0,'.',','); ?> ₽</div><div class="sl">Оборот</div></div>
  </div>

  <input class="srch" type="text" id="srch" placeholder="Поиск по сервису или пользователю...">

  <?php if (empty($links)): ?>
    <div class="empty">Ссылок нет</div>
  <?php else: foreach ($links as $lk): ?>
  <div class="link-card" data-s="<?php echo strtolower(htmlspecialchars($lk['service_name'].' '.$lk['creator'])); ?>">
    <div class="lc-top">
      <div>
        <div class="lc-name"><?php echo htmlspecialchars($lk['service_name']); ?></div>
        <div style="font-family:'Space Mono',monospace;font-size:.52rem;color:#2a2a2a;margin-top:2px">@<?php echo htmlspecialchars($lk['creator']); ?></div>
      </div>
      <div class="lc-amount"><?php echo number_format((float)$lk['amount'],2,'.',' '); ?> ₽</div>
    </div>
    <div class="lc-meta">
      <div class="lc-m">Продаж: <span><?php echo $lk['tx_count']; ?></span></div>
      <div class="lc-m">Оборот: <span><?php echo number_format((float)$lk['total_paid'],2,'.',','); ?> ₽</span></div>
      <div class="lc-m">Выплачено: <span><?php echo number_format((float)$lk['total_earned'],2,'.',','); ?> ₽</span></div>
      <div class="lc-m">Создана: <span><?php echo date('d.m.Y',strtotime($lk['created_at'])); ?></span></div>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <div class="lc-tags">
        <span class="tag <?php echo $lk['is_active']?'active':'inactive'; ?>"><?php echo $lk['is_active']?'Активна':'Неактивна'; ?></span>
        <?php if ($lk['one_time']): ?><span class="tag onetime">Разовая</span><?php endif; ?>
      </div>
      <a href="link_info.php?id=<?php echo $lk['id']; ?>" class="lc-btn">Подробнее →</a>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>
<script>
document.getElementById('srch').oninput=function(){
  var q=this.value.toLowerCase();
  document.querySelectorAll('.link-card').forEach(c=>{c.style.display=c.dataset.s.includes(q)?'':'none';});
};
</script>
</body>
</html>
