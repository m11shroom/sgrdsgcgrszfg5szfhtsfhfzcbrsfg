<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])||empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
$db  = getDB();
$txs = $db->query('SELECT t.*,u.username,u.email FROM transactions t JOIN users u ON t.user_id=u.id ORDER BY t.created_at DESC')->fetchAll();
$total=$pending=$completed=$declined=0;
foreach($txs as $tx){$total++;if($tx['status']==='processing')$pending++;elseif($tx['status']==='completed')$completed++;else $declined++;}

$cardTemplates = $db->query('SELECT * FROM card_templates ORDER BY created_at DESC')->fetchAll();

$pendingCards = $db->query("
  SELECT ic.*, u.username, u.email, ct.name as template_name, ct.balance_type as tmpl_balance_type, ct.issue_type as tmpl_issue_type
  FROM issued_cards ic
  JOIN users u ON ic.user_id = u.id
  LEFT JOIN card_templates ct ON ic.template_id = ct.id
  WHERE ic.status = 'pending'
  ORDER BY ic.created_at DESC
")->fetchAll();
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus wallet — Админ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=Space+Mono&display=swap" rel="stylesheet">
<style>
/* (стили такие же, как в предыдущем варианте, не меняются) */
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#f0f0f0;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0 16px 80px}
.header{max-width:960px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:24px 0 26px}
.hbrand{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.28em;color:#363636;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#fff;border-radius:50%}
.hright{display:flex;align-items:center;gap:10px}
.adm{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.2em;color:#888;background:#0a0a0a;border:1px solid #222;border-radius:6px;padding:4px 8px}
.out-btn{background:none;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.18em;padding:7px 13px;cursor:pointer;text-transform:uppercase;transition:all .2s}
.out-btn:hover{border-color:#555;color:#ccc}
.inner{max-width:960px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
.ptitle{font-family:'Space Mono',monospace;font-size:.68rem;letter-spacing:.3em;color:#444}
.main-tabs{display:flex;gap:10px;flex-wrap:wrap}
.main-tab{background:none;border:1px solid #1e1e1e;border-radius:11px;color:#444;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.16em;padding:9px 18px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:7px}
.main-tab.active{background:#fff;border-color:#fff;color:#080808}
.main-tab.terminal-tab{border-color:#1a2a1a;color:#2a5a2a}
.main-tab.terminal-tab:hover{border-color:#4ade80;color:#4ade80}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.sc{background:#111;border:1px solid #1e1e1e;border-radius:14px;padding:13px 18px;flex:1;min-width:100px}
.sn{font-family:'Space Mono',monospace;font-size:1.4rem;margin-bottom:3px}
.sn.b{color:#f0f0f0}.sn.y{color:#aaa}.sn.g{color:#4ade80}.sn.r{color:#f87171}
.sl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.18em;color:#2a2a2a;text-transform:uppercase}
.search{width:100%;background:#111;border:1px solid #1e1e1e;border-radius:12px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.72rem;padding:10px 13px;outline:none}
.search:focus{border-color:#444}
.tx-card{background:#111;border:1px solid #1e1e1e;border-radius:14px;padding:15px 16px;display:flex;flex-direction:column;gap:11px}
.tx-card:hover{border-color:#2a2a2a}
.tx-hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.tx-user{font-size:.8rem;color:#888}
.tx-user span{font-family:'Space Mono',monospace;font-size:.6rem;color:#444;margin-left:6px}
.tx-date{font-family:'Space Mono',monospace;font-size:.52rem;color:#222}
.tx-body{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px}
.tbadge{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.14em;padding:3px 7px;border-radius:5px;display:inline-block;margin-bottom:3px}
.tb-c{color:#ccc;background:#0a0a0a;border:1px solid #222}
.drow{display:flex;align-items:center;gap:8px}
.dval{font-family:'Space Mono',monospace;font-size:.7rem;color:#e0e0e0;letter-spacing:.06em}
.copy-btn{background:none;border:1px solid #1e1e1e;border-radius:7px;color:#444;font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.1em;padding:3px 8px;cursor:pointer;transition:all .2s}
.copy-btn:hover{border-color:#555;color:#ccc}
.tx-bank{font-family:'Space Mono',monospace;font-size:.58rem;color:#333}
.tx-ftr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.tx-amt{font-family:'Space Mono',monospace;font-size:1.05rem;color:#f0f0f0}
.tx-comm{font-family:'Space Mono',monospace;font-size:.52rem;color:#2a2a2a;margin-left:5px}
.st-form{display:flex;align-items:center;gap:8px}
.st-sel{background:#080808;border:1px solid #1e1e1e;border-radius:9px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.58rem;padding:7px 10px;outline:none;cursor:pointer}
.st-sel:focus{border-color:#444}
.st-sel option{background:#111}
.upd-btn{background:#fff;color:#080808;border:none;border-radius:9px;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.1em;padding:7px 13px;cursor:pointer;transition:background .2s}
.upd-btn:hover{background:#e0e0e0}
.tx-status{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.14em;padding:3px 8px;border-radius:6px}
.st-processing{color:#aaa;background:#111;border:1px solid #1e1e1e}
.st-completed{color:#4ade80;background:#001a08;border:1px solid #002a10}
.st-declined{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.sup-panel{display:flex;background:#111;border:1px solid #1e1e1e;border-radius:16px;overflow:hidden;min-height:500px}
.ul{width:220px;border-right:1px solid #1a1a1a;display:flex;flex-direction:column;flex-shrink:0}
.ul-hdr{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.22em;color:#333;padding:13px 13px 10px;border-bottom:1px solid #161616}
.ul-scr{overflow-y:auto;flex:1}
.ul-scr::-webkit-scrollbar{width:4px}
.ul-scr::-webkit-scrollbar-thumb{background:#1a1a1a;border-radius:2px}
.u-item{display:flex;align-items:center;gap:9px;padding:10px 12px;cursor:pointer;border-bottom:1px solid #0d0d0d;transition:background .15s}
.u-item:hover{background:#0d0d0d}
.u-item.active{background:#161616}
.u-av{width:30px;height:30px;border-radius:50%;border:1px solid #1a1a1a;background:#0a0a0a;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.8rem;overflow:hidden}
.u-av img{width:100%;height:100%;object-fit:cover}
.u-nm{font-size:.78rem;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0}
.chat-hdr{padding:12px 15px;border-bottom:1px solid #161616;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.16em;color:#444}
.chat-msgs{flex:1;overflow-y:auto;padding:13px;display:flex;flex-direction:column;gap:10px;min-height:300px}
.chat-msgs::-webkit-scrollbar{width:4px}
.chat-msgs::-webkit-scrollbar-thumb{background:#1a1a1a;border-radius:2px}
.msg-row{display:flex;align-items:flex-end;gap:8px}
.msg-row.admin-msg{flex-direction:row-reverse}
.msg-av{width:26px;height:26px;border-radius:50%;border:1px solid #1a1a1a;background:#0a0a0a;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.75rem;overflow:hidden}
.msg-av img{width:100%;height:100%;object-fit:cover}
.msg-bub{max-width:75%;padding:8px 12px;border-radius:13px;font-size:.8rem;line-height:1.4;word-break:break-word}
.msg-bub.user-bub{background:#0a0a0a;border:1px solid #161616;color:#aaa;border-bottom-left-radius:4px}
.msg-bub.admin-bub{background:#1e1e1e;border:1px solid #2a2a2a;color:#f0f0f0;border-bottom-right-radius:4px}
.msg-t{font-family:'Space Mono',monospace;font-size:.44rem;color:#222;margin-top:2px}
.chat-inp-wrap{display:flex;gap:8px;padding:11px 13px;border-top:1px solid #161616}
.chat-inp{flex:1;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'DM Sans',sans-serif;font-size:.8rem;padding:9px 12px;outline:none;resize:none}
.chat-inp:focus{border-color:#333}
.chat-send{background:#fff;color:#080808;border:none;border-radius:10px;padding:9px 14px;cursor:pointer;font-size:.9rem;flex-shrink:0;transition:background .2s}
.chat-send:hover{background:#e0e0e0}
.no-chat{display:flex;align-items:center;justify-content:center;flex:1;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.18em;color:#1a1a1a}
.no-tx{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.18em;color:#1a1a1a;text-align:center;padding:28px}
.card-admin-grid{display:flex;flex-direction:column;gap:14px}
.card-admin-sec{background:#111;border:1px solid #1e1e1e;border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:14px}
.card-admin-sec-title{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.28em;color:#444;text-transform:uppercase}
.cform{display:flex;flex-direction:column;gap:10px}
.cflbl{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;color:#333;text-transform:uppercase;margin-bottom:4px}
.cinput{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'Space Mono',monospace;font-size:.76rem;padding:9px 12px;outline:none;-webkit-appearance:none}
.cinput:focus{border-color:#444}
.cinput option{background:#111}
.cinput-ta{width:100%;background:#080808;border:1px solid #1e1e1e;border-radius:10px;color:#e0e0e0;font-family:'DM Sans',sans-serif;font-size:.82rem;padding:9px 12px;outline:none;resize:vertical;min-height:60px}
.cinput-ta:focus{border-color:#444}
.csubmit{background:#fff;color:#080808;border:none;border-radius:10px;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.1em;padding:9px 16px;cursor:pointer;transition:background .2s}
.csubmit:hover{background:#e0e0e0}
.tmpl-row{display:flex;align-items:center;justify-content:space-between;background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:12px 14px;gap:12px;flex-wrap:wrap}
.tmpl-cover{width:52px;height:33px;border-radius:3px;overflow:hidden;flex-shrink:0;background:#1a1a1a}
.tmpl-cover img{width:100%;height:100%;object-fit:cover;border-radius:3px}
.tmpl-meta{flex:1;min-width:120px}
.tmpl-meta-name{font-size:.82rem;color:#ccc;margin-bottom:2px}
.tmpl-meta-sub{font-family:'Space Mono',monospace;font-size:.5rem;color:#333}
.pend-badge{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.14em;padding:3px 8px;border-radius:6px;color:#facc15;background:#1a1200;border:1px solid #2a1e00}
.fill-btn{background:none;border:1px solid #333;border-radius:9px;color:#666;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.1em;padding:6px 12px;cursor:pointer;transition:all .2s;white-space:nowrap}
.fill-btn:hover{border-color:#aaa;color:#f0f0f0}
.opt-row{display:flex;gap:8px;flex-wrap:wrap}
.opt-btn{flex:1;background:#0a0a0a;border:2px solid #1e1e1e;border-radius:10px;color:#555;font-family:'Space Mono',monospace;font-size:.52rem;padding:8px 6px;cursor:pointer;transition:all .2s;text-align:center;min-width:80px}
.opt-btn:hover{border-color:#333;color:#aaa}
.opt-btn.sel{border-color:#fff;color:#fff}
.topup-form-row{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.topup-form-row .cinput{flex:1;min-width:120px}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:500;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:#111;border:1px solid #1e1e1e;border-radius:20px;padding:26px;max-width:400px;width:100%;display:flex;flex-direction:column;gap:14px;max-height:90vh;overflow-y:auto}
.modal-title{font-family:'Space Mono',monospace;font-size:.64rem;letter-spacing:.22em;color:#888}
.modal-close{background:none;border:1px solid #1e1e1e;border-radius:11px;color:#444;font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.12em;padding:8px 13px;cursor:pointer;align-self:flex-end;transition:all .2s}
.modal-close:hover{border-color:#555;color:#ccc}
.flash-msg{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.12em;padding:9px;border-radius:9px;min-height:14px}
.flash-ok{color:#4ade80;background:#001a08;border:1px solid #002a10}
.flash-err{color:#f87171;background:#1a0000;border:1px solid #2a0000}
.term-sec{background:#111;border:1px solid #1a2a1a;border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:14px}
.term-sec-title{font-family:'Space Mono',monospace;font-size:.54rem;letter-spacing:.28em;color:#2a5a2a;text-transform:uppercase}
.term-link-row{background:#0a0a0a;border:1px solid #161616;border-radius:12px;padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.term-link-lbl{font-family:'Space Mono',monospace;font-size:.48rem;letter-spacing:.18em;color:#2a2a2a;text-transform:uppercase;margin-bottom:4px}
.term-link-url{font-family:'Space Mono',monospace;font-size:.6rem;color:#444;word-break:break-all}
.term-lbtn{background:none;border:1px solid #1e1e1e;border-radius:9px;color:#555;font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.1em;padding:6px 11px;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.term-lbtn:hover{border-color:#555;color:#ccc}
.term-lbtn.primary{background:#4ade80;color:#001a08;border-color:#4ade80}
.term-lbtn.primary:hover{background:#22c55e}
.term-status-bar{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:8px 13px;border-radius:9px;text-align:center}
.tst-waiting{color:#555;background:#0a0a0a;border:1px solid #161616}
.tst-pending{color:#facc15;background:#1a1200;border:1px solid #2a1e00}
.tst-paid{color:#4ade80;background:#001a08;border:1px solid #002a10}
.qr-wrap-admin{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
.qr-box-admin{background:#fff;border-radius:12px;padding:10px;flex-shrink:0}
#langBtn{position:fixed;bottom:20px;right:20px;background:#111;border:1px solid #1e1e1e;border-radius:10px;color:#444;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.18em;padding:8px 14px;cursor:pointer}
#langBtn:hover{color:#ccc;border-color:#555}
</style>
</head>
<body>
<div class="header">
  <div class="hbrand"><div class="bdot"></div>M1PLUS WALLET</div>
  <div class="hright">
    <div class="adm">ADMIN</div>
    <a href="admin_plastic.php" class="out-btn" style="text-decoration:none">Пластик. карты</a>
    <a href="admin_delivery_cards.php" class="out-btn" style="text-decoration:none">Доставка карт</a>
    <form method="POST" action="auth.php" style="display:inline">
      <input type="hidden" name="action" value="logout">
      <button class="out-btn" type="submit">Выйти</button>
    </form>
  </div>
</div>
<div class="inner">
  <div class="ptitle">ПАНЕЛЬ АДМИНИСТРАТОРА</div>
  <div class="main-tabs">
    <button class="main-tab active" id="tabTx" onclick="show('tx')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Транзакции
    </button>
    <button class="main-tab" id="tabSup" onclick="show('sup')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Поддержка
    </button>
    <button class="main-tab" id="tabCards" onclick="show('cards')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      Карты
      <?php if (!empty($pendingCards)): ?>
      <span style="background:#facc15;color:#080808;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:.44rem;font-family:'Space Mono',monospace"><?php echo count($pendingCards); ?></span>
      <?php endif; ?>
    </button>
    <button class="main-tab terminal-tab" id="tabTerminal" onclick="show('terminal')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      Терминал
    </button>
  </div>

  <!-- TRANSACTIONS -->
  <div id="secTx">
    <div class="stats">
      <div class="sc"><div class="sn b"><?php echo $total;?></div><div class="sl">Всего</div></div>
      <div class="sc"><div class="sn y"><?php echo $pending;?></div><div class="sl">В обработке</div></div>
      <div class="sc"><div class="sn g"><?php echo $completed;?></div><div class="sl">Выполнено</div></div>
      <div class="sc"><div class="sn r"><?php echo $declined;?></div><div class="sl">Отклонено</div></div>
    </div>
    <input class="search" id="srch" placeholder="Поиск по пользователю, сумме, номеру...">
    <div style="display:flex;flex-direction:column;gap:10px;margin-top:10px">
    <?php if(empty($txs)):?>
    <div class="no-tx">Транзакций нет</div>
    <?php else:foreach($txs as $tx):?>
    <div class="tx-card" data-s="<?php echo strtolower(htmlspecialchars($tx['username'].' '.$tx['amount'].' '.($tx['card_number']??'').' '.($tx['phone']??''))); ?>">
      <div class="tx-hdr">
        <div class="tx-user"><?php echo htmlspecialchars($tx['username']);?><span><?php echo htmlspecialchars($tx['email']);?></span></div>
        <div class="tx-date"><?php echo date('d.m.Y H:i',strtotime($tx['created_at']));?></div>
      </div>
      <div class="tx-body">
        <div>
          <div class="tbadge tb-c"><?php $tl=['card'=>'КАРТА','sbp'=>'СБП','user_out'=>'ПЕРЕВОД','user_in'=>'ВХОДЯЩИЙ','payment_link'=>'ССЫЛКА','payment_sent'=>'ОПЛАТА'];echo $tl[$tx['type']]??strtoupper($tx['type']);?></div>
          <?php if($tx['type']==='card'):?>
            <div class="drow"><div class="dval" id="cv<?php echo $tx['id'];?>"><?php echo htmlspecialchars($tx['card_number']??'');?></div><button class="copy-btn" onclick="cp('cv<?php echo $tx['id'];?>')">копировать</button></div>
          <?php elseif($tx['type']==='sbp'):?>
            <div class="drow"><div class="dval" id="pv<?php echo $tx['id'];?>"><?php echo htmlspecialchars($tx['phone']??'');?></div><button class="copy-btn" onclick="cp('pv<?php echo $tx['id'];?>')">копировать</button></div>
            <div class="tx-bank"><?php echo htmlspecialchars($tx['bank_name']??'');?></div>
          <?php endif;?>
        </div>
        <div style="text-align:right">
          <div class="tx-amt"><?php echo number_format((float)$tx['amount'],0,'.',' ');?> ₽<span class="tx-comm"><?php if((float)$tx['commission']>0)echo '+'.intval($tx['commission']).' ком.';?></span></div>
          <div class="tx-status st-<?php echo $tx['status'];?>" style="margin-top:5px"><?php $sm=['processing'=>'В ОБРАБОТКЕ','completed'=>'ВЫПОЛНЕН','declined'=>'ОТКЛОНЁН'];echo $sm[$tx['status']]??$tx['status'];?></div>
          <?php if($tx['refunded']):?><div style="font-family:'Space Mono',monospace;font-size:.44rem;color:#2a2a2a;margin-top:3px">↩ возврат</div><?php endif;?>
        </div>
      </div>
      <div class="tx-ftr">
        <form method="POST" action="auth.php" class="st-form">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="tx_id" value="<?php echo $tx['id'];?>">
          <select name="status" class="st-sel">
            <option value="processing" <?php echo $tx['status']==='processing'?'selected':'';?>>В обработке</option>
            <option value="completed"  <?php echo $tx['status']==='completed'?'selected':'';?>>Выполнен</option>
            <option value="declined"   <?php echo $tx['status']==='declined'?'selected':'';?>>Отклонён</option>
          </select>
          <button class="upd-btn" type="submit">Обновить</button>
        </form>
      </div>
    </div>
    <?php endforeach;endif;?>
    </div>
  </div>

  <!-- SUPPORT -->
  <div id="secSup" style="display:none">
    <div class="sup-panel">
      <div class="ul">
        <div class="ul-hdr">ПОЛЬЗОВАТЕЛИ</div>
        <div class="ul-scr" id="ulDiv"><div style="font-family:'Space Mono',monospace;font-size:.52rem;color:#222;padding:14px;text-align:center">Загрузка...</div></div>
      </div>
      <div class="chat-area">
        <div class="chat-hdr" id="chatHdrName">Выберите пользователя</div>
        <div class="chat-msgs" id="aChatMsgs"><div class="no-chat">← Выберите пользователя</div></div>
        <div class="chat-inp-wrap" id="chatInpWrap" style="display:none">
          <textarea class="chat-inp" id="aChatInp" rows="1" placeholder="Ответить..."></textarea>
          <button class="chat-send" onclick="adminSend()">&#9658;</button>
        </div>
      </div>
    </div>
  </div>

  <!-- CARDS ADMIN -->
  <div id="secCards" style="display:none">
    <div class="card-admin-grid">
      <div class="card-admin-sec">
        <div class="card-admin-sec-title">Добавить карту</div>
        <div class="cform" id="addTmplForm">
          <div>
            <div class="cflbl">Обложка карты (1011×638 px)</div>
            <input type="file" id="coverFile" accept="image/*" class="cinput" style="padding:7px 12px;cursor:pointer">
          </div>
          <div id="coverPreviewWrap" style="display:none">
            <img id="coverPreview" style="max-width:200px;height:auto;border-radius:8px;border:1px solid #1a1a1a">
          </div>
          <div>
            <div class="cflbl">Название карты</div>
            <input type="text" id="tmplName" class="cinput" placeholder="Например: M1plus Стандарт">
          </div>
          <div>
            <div class="cflbl">Стоимость выпуска (₽)</div>
            <input type="number" id="tmplCost" class="cinput" placeholder="0" min="0" value="0">
          </div>
          <div>
            <div class="cflbl">Валюта карты</div>
            <select id="tmplCurrency" class="cinput">
              <option value="RUB">₽ Рубль (RUB)</option>
              <option value="USD">$ Доллар (USD)</option>
              <option value="EUR">€ Евро (EUR)</option>
            </select>
          </div>
          <div>
            <div class="cflbl">Количество карт <span style="color:#2a2a2a;letter-spacing:0">(пусто = неограничено)</span></div>
            <input type="number" id="tmplQuantity" class="cinput" placeholder="Неограничено" min="1">
          </div>
          <div>
            <div class="cflbl">Тип выпуска</div>
            <div class="opt-row" id="issueTypeRow">
              <button class="opt-btn sel" id="itInstant" onclick="setIssueType('instant')">Моментальный</button>
              <button class="opt-btn" id="itWait" onclick="setIssueType('wait')">С ожиданием</button>
            </div>
          </div>
          <div id="waitDaysWrap" style="display:none">
            <div class="cflbl">Максимальное ожидание (дней)</div>
            <input type="number" id="tmplWaitDays" class="cinput" placeholder="7" min="1">
          </div>
          <div>
            <div class="cflbl">Баланс карты</div>
            <div class="opt-row" id="balTypeRow">
              <button class="opt-btn sel" id="btZero" onclick="setBalType('zero')">Нулевой</button>
              <button class="opt-btn" id="btPrepaid" onclick="setBalType('prepaid')">Предоплаченный</button>
            </div>
          </div>
          <div id="prepaidWrap" style="display:none">
            <div class="cflbl">Сумма на карте</div>
            <input type="number" id="tmplPrepaidAmt" class="cinput" placeholder="5000" min="0">
          </div>
          <div id="tmplFormResult" class="flash-msg" style="display:none"></div>
          <button class="csubmit" onclick="submitAddTemplate()">Добавить карту</button>
        </div>
      </div>

      <div class="card-admin-sec">
        <div class="card-admin-sec-title">Карты в системе</div>
        <?php if(empty($cardTemplates)): ?>
        <div style="font-family:'Space Mono',monospace;font-size:.56rem;color:#1a1a1a;text-align:center;padding:18px">Карт нет</div>
        <?php else: foreach($cardTemplates as $ct):
          $ctCover = $ct['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $ct['cover_image'] : null;
          $ctQty   = isset($ct['quantity']) ? (int)$ct['quantity'] : null;
          $ctCur   = $ct['currency'] ?? 'RUB';
          $ctPre   = isset($ct['prepaid_amount']) ? (float)$ct['prepaid_amount'] : 0;
        ?>
        <div class="tmpl-row">
          <div class="tmpl-cover">
            <?php if($ctCover): ?><img src="<?php echo htmlspecialchars($ctCover); ?>" alt="">
            <?php else: ?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#333;background:#111"><svg width="22" height="14" viewBox="0 0 24 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="1" y="1" width="22" height="13" rx="2"/><line x1="1" y1="5" x2="23" y2="5"/></svg></div>
            <?php endif; ?>
          </div>
          <div class="tmpl-meta">
            <div class="tmpl-meta-name"><?php echo htmlspecialchars($ct['name']); ?></div>
            <div class="tmpl-meta-sub">
              Выпуск: <?php echo $ct['issue_cost']>0?number_format((float)$ct['issue_cost'],0,'.',' ').' ₽':'Бесплатно'; ?> &bull;
              <?php echo $ct['issue_type']==='instant'?'Моментальный':'Ожидание '.(int)($ct['wait_days']??0).' дн'; ?> &bull;
              <?php echo $ct['balance_type']==='zero'?'Нулевой':'Предоплата '.($ctPre>0?number_format($ctPre,0,'.',' ').' '.$ctCur:''); ?> &bull;
              <?php echo $ctCur; ?>
              <?php if ($ctQty !== null): ?> &bull; Осталось: <?php echo $ctQty; ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
            <button class="fill-btn" style="color:<?php echo $ct['is_active']?'#4ade80':'#555';?>" onclick="toggleTemplate(<?php echo $ct['id']; ?>)">
              <?php echo $ct['is_active']?'Активна':'Скрыта'; ?>
            </button>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if(!empty($pendingCards)): ?>
      <div class="card-admin-sec">
        <div class="card-admin-sec-title">Заявки на карты <span class="pend-badge"><?php echo count($pendingCards); ?></span></div>
        <?php foreach($pendingCards as $pc):
          $pcCur  = $pc['currency'] ?? 'RUB';
          $pcSym  = ['RUB'=>'₽','USD'=>'$','EUR'=>'€'][$pcCur] ?? '₽';
          $pcComm = isset($pc['commission_amount']) ? (float)$pc['commission_amount'] : 0;
        ?>
        <div class="tmpl-row" style="flex-direction:column;align-items:flex-start;gap:10px">
          <div style="display:flex;justify-content:space-between;width:100%;align-items:center;flex-wrap:wrap;gap:8px">
            <div>
              <div style="font-size:.82rem;color:#ccc"><?php echo htmlspecialchars($pc['username']); ?></div>
              <div style="font-family:'Space Mono',monospace;font-size:.5rem;color:#333;margin-top:2px">
                <?php echo htmlspecialchars($pc['email']); ?> &bull; <?php echo htmlspecialchars($pc['template_name']??'Карта'); ?>
              </div>
              <div style="font-family:'Space Mono',monospace;font-size:.5rem;color:#333;margin-top:3px;display:flex;flex-wrap:wrap;gap:6px">
                <span><?php echo $pc['balance_type']==='zero'?'Нулевой баланс':'Предоплата'; ?></span>
                <span style="padding:1px 6px;border-radius:4px;background:#0d0d0d;border:1px solid #1a1a1a"><?php echo $pcCur; ?></span>
                <span><?php echo date('d.m.Y H:i',strtotime($pc['created_at'])); ?></span>
                <?php if($pc['balance_type']==='prepaid' && $pc['prepaid_amount']): ?>
                <span style="color:#facc15">Баланс: <?php echo number_format((float)$pc['prepaid_amount'],2,'.',' '); ?> <?php echo $pcSym; ?></span>
                <?php endif; ?>
                <?php if($pcComm > 0): ?>
                <span style="color:#facc15">Комиссия: <?php echo number_format($pcComm,2,'.',' '); ?> ₽</span>
                <?php endif; ?>
              </div>
            </div>
            <button class="fill-btn" onclick="openFillModal(<?php echo $pc['id']; ?>, '<?php echo addslashes($pc['balance_type']); ?>', <?php echo (float)($pc['prepaid_amount']??0); ?>, '<?php echo $pcCur; ?>')">
              Заполнить данные
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="card-admin-sec">
        <div class="card-admin-sec-title">Пополнить карту</div>
        <div class="cform">
          <div>
            <div class="cflbl">Номер карты</div>
            <input type="text" id="topupCardNum" class="cinput" placeholder="0000 0000 0000 0000" maxlength="19" oninput="fmtAdminCard(this)">
          </div>
          <div>
            <div class="cflbl">Сумма пополнения (₽)</div>
            <input type="number" id="topupCardAmt" class="cinput" placeholder="1000" min="1">
          </div>
          <div id="topupCardResult" class="flash-msg" style="display:none"></div>
          <button class="csubmit" onclick="submitTopupCard()">Пополнить</button>
        </div>
      </div>
    </div>
  </div>

  <!-- TERMINAL (исправлено: автообновление токена после оплаты) -->
  <div id="secTerminal" style="display:none">
    <div style="display:flex;flex-direction:column;gap:14px">
      <div class="term-sec" id="termStepCreate">
        <div class="term-sec-title">Новый терминал</div>
        <div>
          <div class="cflbl">Название организации</div>
          <input type="text" id="termOrgName" class="cinput" placeholder="ООО Ромашка / Кофе Бар / ИП Иванов">
        </div>
        <div id="termCreateResult" class="flash-msg" style="display:none"></div>
        <button class="csubmit" style="background:#4ade80;color:#001a08" onclick="termCreate()">Создать терминал</button>
      </div>

      <div class="term-sec" id="termStepSession" style="display:none">
        <div class="term-sec-title">Терминал активен</div>
        <div class="qr-wrap-admin">
          <div class="qr-box-admin"><div id="termQrCode"></div></div>
          <div style="flex:1;min-width:160px">
            <div style="font-size:1rem;font-weight:500;color:#f0f0f0;margin-bottom:4px" id="termSessOrg"></div>
            <div style="font-family:'Space Mono',monospace;font-size:.52rem;color:#2a2a2a">M1PLUS WALLET</div>
            <div style="font-family:'Space Mono',monospace;font-size:.5rem;color:#1a1a1a;margin-top:10px">Клиент сканирует QR камерой в приложении</div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <div class="term-link-row">
            <div style="flex:1;min-width:0">
              <div class="term-link-lbl">Экран покупателя (на весь монитор)</div>
              <div class="term-link-url" id="termDisplayUrl"></div>
            </div>
            <div style="display:flex;gap:7px;flex-shrink:0">
              <button class="term-lbtn" onclick="copyTermLink('termDisplayUrl')">Копировать</button>
              <a class="term-lbtn primary" id="termDisplayOpen" href="#" target="_blank">Открыть ↗</a>
            </div>
          </div>
          <div class="term-link-row">
            <div style="flex:1;min-width:0">
              <div class="term-link-lbl">Ссылка на эту кассу</div>
              <div class="term-link-url" id="termCashierUrl"></div>
            </div>
            <button class="term-lbtn" onclick="copyTermLink('termCashierUrl')">Копировать</button>
          </div>
        </div>
        <div class="term-status-bar tst-waiting" id="termStatusBar">ОЖИДАНИЕ ЗАПРОСА ОПЛАТЫ</div>
        <div id="termAmtForm" style="display:flex;flex-direction:column;gap:10px">
          <div class="cflbl" style="margin-bottom:0">Выставить счёт</div>
          <div style="display:flex;gap:10px;align-items:flex-end">
            <div style="flex:1"><input type="number" id="termPayAmt" class="cinput" placeholder="1 000 ₽" min="1" step="1"></div>
            <button class="csubmit" style="background:#4ade80;color:#001a08;white-space:nowrap" onclick="termRequestPay()">Выставить</button>
          </div>
          <div id="termAmtResult" class="flash-msg" style="display:none"></div>
        </div>
        <button class="csubmit" id="termCancelBtn" style="display:none;background:none;border:1px solid #2a0000;color:#f87171" onclick="termCancel()">Отменить запрос</button>
        <button class="csubmit" style="background:none;border:1px solid #1e1e1e;color:#444;margin-top:4px" onclick="termReset()">+ Новый терминал</button>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="fillModal" onclick="if(event.target===this)closeFillModal()">
  <div class="modal">
    <div class="modal-title">ДАННЫЕ КАРТЫ</div>
    <input type="hidden" id="fillCardId">
    <div id="fillModalContent" style="display:flex;flex-direction:column;gap:12px">
      <div><div class="cflbl">Номер карты (16 цифр)</div><input type="text" id="fillCardNum" class="cinput" placeholder="0000 0000 0000 0000" maxlength="19" oninput="fmtAdminCard(this)"></div>
      <div><div class="cflbl">Срок действия</div><input type="text" id="fillExpiry" class="cinput" placeholder="MM/YY" maxlength="5" oninput="fmtExpiry(this)"></div>
      <div><div class="cflbl">CVV</div><input type="text" id="fillCvv" class="cinput" placeholder="000" maxlength="3"></div>
      <div id="fillPrepaidWrap" style="display:none"><div class="cflbl" id="fillPrepaidLbl">Сумма на карте</div><input type="number" id="fillPrepaidAmt" class="cinput" placeholder="5000" min="0"></div>
      <div><div class="cflbl">Назначение карты <span style="color:#2a2a2a;letter-spacing:0">(необязательно)</span></div><textarea id="fillPurpose" class="cinput-ta" placeholder="Например: для онлайн-покупок в США" maxlength="255"></textarea></div>
    </div>
    <div class="flash-msg" id="fillResult" style="display:none"></div>
    <button class="csubmit" style="width:100%" onclick="submitFillCard()">Сохранить и активировать</button>
    <button class="modal-close" onclick="closeFillModal()">Отмена</button>
  </div>
</div>

<button id="langBtn">RU / EN</button>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var curUid=null;
var issueType='instant', balType='zero';
var termToken=null, termPollInt=null, termCurrentStatus='waiting';
var termRenewTimer = null;

function show(s){
  ['tx','sup','cards','terminal'].forEach(function(k){
    var sec=document.getElementById('sec'+k.charAt(0).toUpperCase()+k.slice(1));
    if(sec) sec.style.display = s===k?'':'none';
    var tab=document.getElementById('tab'+k.charAt(0).toUpperCase()+k.slice(1));
    if(tab) tab.className='main-tab'+(s===k?' active':'')+(k==='terminal'?' terminal-tab':'');
  });
  if(s==='sup') loadUsers();
  if(s==='terminal' && termToken) termStartPoll();
}

function cp(id){
  var el=document.getElementById(id);if(!el)return;
  var txt=el.textContent.replace(/\s/g,'');
  navigator.clipboard?navigator.clipboard.writeText(txt):(function(){var t=document.createElement('textarea');t.value=txt;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);})();
  var btn=el.parentNode.querySelector('.copy-btn');
  if(btn){btn.textContent='скопировано!';setTimeout(function(){btn.textContent='копировать';},1500);}
}

document.getElementById('srch').oninput=function(){
  var q=this.value.toLowerCase();
  document.querySelectorAll('.tx-card').forEach(function(c){c.style.display=c.dataset.s.includes(q)?'':'none';});
};

document.getElementById('coverFile').onchange=function(){
  var file=this.files[0]; if(!file)return;
  var wrap=document.getElementById('coverPreviewWrap');
  var img=document.getElementById('coverPreview');
  var rd=new FileReader();
  rd.onload=function(e){img.src=e.target.result;wrap.style.display='block';};
  rd.readAsDataURL(file);
};

function setIssueType(t){
  issueType=t;
  document.getElementById('itInstant').className='opt-btn'+(t==='instant'?' sel':'');
  document.getElementById('itWait').className='opt-btn'+(t==='wait'?' sel':'');
  document.getElementById('waitDaysWrap').style.display=t==='wait'?'block':'none';
}
function setBalType(t){
  balType=t;
  document.getElementById('btZero').className='opt-btn'+(t==='zero'?' sel':'');
  document.getElementById('btPrepaid').className='opt-btn'+(t==='prepaid'?' sel':'');
  document.getElementById('prepaidWrap').style.display=t==='prepaid'?'block':'none';
}

function submitAddTemplate(){
  var file=document.getElementById('coverFile').files[0];
  var name=document.getElementById('tmplName').value.trim();
  var cost=parseFloat(document.getElementById('tmplCost').value)||0;
  var waitDays=parseInt(document.getElementById('tmplWaitDays').value)||0;
  var currency=document.getElementById('tmplCurrency').value;
  var quantity=document.getElementById('tmplQuantity').value.trim();
  var prepaidAmt=balType==='prepaid'?(parseFloat(document.getElementById('tmplPrepaidAmt').value)||0):0;
  var res=document.getElementById('tmplFormResult');
  if(!name){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите название';return;}
  if(issueType==='wait'&&!waitDays){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Укажите срок ожидания';return;}
  var fd=new FormData();
  fd.append('action','add_template');fd.append('name',name);fd.append('issue_cost',cost);
  fd.append('issue_type',issueType);fd.append('balance_type',balType);fd.append('wait_days',waitDays);
  fd.append('currency',currency);fd.append('prepaid_amount',prepaidAmt);
  if(quantity) fd.append('quantity',quantity);
  if(file) fd.append('cover_image',file);
  fetch('cards_admin_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      res.style.display='block';
      if(d.ok){res.className='flash-msg flash-ok';res.textContent='Карта добавлена';setTimeout(function(){location.reload();},1200);}
      else{res.className='flash-msg flash-err';res.textContent=d.error||'Ошибка';}
    }).catch(function(){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Ошибка соединения';});
}

function toggleTemplate(id){
  var fd=new FormData();fd.append('action','toggle_template');fd.append('id',id);
  fetch('cards_admin_api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){if(d.ok)location.reload();});
}

var fillBalType='zero';
function openFillModal(cardId,bt,prepaidAmt,currency){
  fillBalType=bt;
  document.getElementById('fillCardId').value=cardId;
  document.getElementById('fillCardNum').value='';
  document.getElementById('fillExpiry').value='';
  document.getElementById('fillCvv').value='';
  document.getElementById('fillPurpose').value='';
  document.getElementById('fillResult').style.display='none';
  var pw=document.getElementById('fillPrepaidWrap');
  pw.style.display=bt==='prepaid'?'block':'none';
  if(bt==='prepaid'){
    document.getElementById('fillPrepaidAmt').value=prepaidAmt||'';
    document.getElementById('fillPrepaidLbl').textContent='Сумма на карте ('+(currency||'RUB')+') — клиент уже оплатил';
  }
  document.getElementById('fillModal').classList.add('open');
}
function closeFillModal(){document.getElementById('fillModal').classList.remove('open');}

function fmtAdminCard(el){var v=el.value.replace(/\D/g,'').substring(0,16);var o='';for(var i=0;i<v.length;i++){if(i>0&&i%4===0)o+=' ';o+=v[i];}el.value=o;}
function fmtExpiry(el){var v=el.value.replace(/\D/g,'').substring(0,4);el.value=v.length>2?v.slice(0,2)+'/'+v.slice(2):v;}

function submitFillCard(){
  var cardId=document.getElementById('fillCardId').value;
  var num=document.getElementById('fillCardNum').value.replace(/\s/g,'');
  var exp=document.getElementById('fillExpiry').value;
  var cvv=document.getElementById('fillCvv').value;
  var prepaid=fillBalType==='prepaid'?parseFloat(document.getElementById('fillPrepaidAmt').value)||0:0;
  var purpose=document.getElementById('fillPurpose').value.trim();
  var res=document.getElementById('fillResult');
  if(num.length!==16){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите 16 цифр номера';return;}
  if(!exp.match(/^\d{2}\/\d{2}$/)){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Формат срока: MM/YY';return;}
  if(cvv.length<3){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите CVV (3 цифры)';return;}
  var fd=new FormData();
  fd.append('action','fill_card');fd.append('card_id',cardId);
  fd.append('card_number',num);fd.append('expiry',exp);fd.append('cvv',cvv);
  fd.append('purpose',purpose);
  if(fillBalType==='prepaid') fd.append('prepaid_amount',prepaid);
  fetch('cards_admin_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      res.style.display='block';
      if(d.ok){res.className='flash-msg flash-ok';res.textContent='Данные сохранены';setTimeout(function(){location.reload();},1200);}
      else{res.className='flash-msg flash-err';res.textContent=d.error||'Ошибка';}
    }).catch(function(){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Ошибка';});
}

function submitTopupCard(){
  var num=document.getElementById('topupCardNum').value.replace(/\s/g,'');
  var amt=parseFloat(document.getElementById('topupCardAmt').value)||0;
  var res=document.getElementById('topupCardResult');
  if(num.length<4){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите номер карты';return;}
  if(amt<1){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите сумму';return;}
  var fd=new FormData();fd.append('action','topup_card');fd.append('card_number',num);fd.append('amount',amt);
  fetch('cards_admin_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      res.style.display='block';
      if(d.ok){res.className='flash-msg flash-ok';res.textContent='Пополнение записано';document.getElementById('topupCardNum').value='';document.getElementById('topupCardAmt').value='';}
      else{res.className='flash-msg flash-err';res.textContent=d.error||'Ошибка';}
    }).catch(function(){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Ошибка';});
}

/* ---- TERMINAL С АВТОМАТИЧЕСКИМ ОБНОВЛЕНИЕМ ТОКЕНА ---- */
function termCreate(){
  var org=document.getElementById('termOrgName').value.trim();
  var res=document.getElementById('termCreateResult');
  res.style.display='none';
  if(!org){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите название организации';return;}
  var fd=new FormData();fd.append('action','create_session');fd.append('org_name',org);
  fetch('terminal_api.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){
        termToken=d.token;
        termInitSession(org,d.token);
      }else{
        res.style.display='block';res.className='flash-msg flash-err';res.textContent=d.error||'Ошибка';
      }
    });
}

function termInitSession(org,token){
  document.getElementById('termStepCreate').style.display='none';
  document.getElementById('termStepSession').style.display='';
  document.getElementById('termSessOrg').textContent=org;
  var base=window.location.origin+window.location.pathname.replace(/[^\/]*$/,'');
  var scanUrl=base+'terminal_scan.php?token='+token;
  var displayUrl=base+'terminal_display.php?token='+token;
  var cashierUrl=base+'terminal.php?token='+token;
  document.getElementById('termDisplayUrl').textContent=displayUrl;
  document.getElementById('termCashierUrl').textContent=cashierUrl;
  document.getElementById('termDisplayOpen').href=displayUrl;
  var qrDiv=document.getElementById('termQrCode');
  qrDiv.innerHTML='';
  new QRCode(qrDiv,{text:scanUrl,width:130,height:130,colorDark:'#080808',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
  termStartPoll();
}

function termStartPoll(){
  clearInterval(termPollInt);
  termPollInt=setInterval(termPoll,1000);
}

function termPoll(){
  if(!termToken)return;
  fetch('terminal_api.php?action=get_status&token='+encodeURIComponent(termToken))
    .then(function(r){return r.json();})
    .then(function(d){if(d.ok)termUpdateUI(d.session.status,d.session.amount);});
}

function termUpdateUI(status,amount){
  if(status===termCurrentStatus && amount===termLastAmount)return;
  termCurrentStatus=status; termLastAmount=amount;
  var bar=document.getElementById('termStatusBar');
  var cancelBtn=document.getElementById('termCancelBtn');
  var amtForm=document.getElementById('termAmtForm');
  var labels={waiting:'ОЖИДАНИЕ ЗАПРОСА ОПЛАТЫ',pending:'ОЖИДАНИЕ ОПЛАТЫ — '+fmtAmt(amount)+' ₽',paid:'ОПЛАЧЕНО ✓',declined:'ОТКЛОНЕНО'};
  bar.textContent=labels[status]||status;
  bar.className='term-status-bar tst-'+(status==='pending'?'pending':status==='paid'?'paid':'waiting');
  cancelBtn.style.display=status==='pending'?'':'none';
  amtForm.style.display=status==='paid'?'none':'flex';
  
  // Если оплата прошла — через 2 секунды автоматически создаём новый токен (обновляем сессию)
  if(status==='paid' && !termRenewTimer){
    termRenewTimer = setTimeout(function(){
      termAutoRenew();
    }, 2000);
  }
  if(status!=='paid' && termRenewTimer){
    clearTimeout(termRenewTimer);
    termRenewTimer = null;
  }
}

var termLastAmount=null;
function fmtAmt(a){return a?parseFloat(a).toLocaleString('ru-RU'):'';}

function termRequestPay(){
  if(termCurrentStatus !== 'waiting'){
    var res=document.getElementById('termAmtResult');
    res.style.display='block';res.className='flash-msg flash-err';res.textContent='Дождитесь завершения текущего платежа';
    return;
  }
  var amt=parseFloat(document.getElementById('termPayAmt').value)||0;
  var res=document.getElementById('termAmtResult');
  res.style.display='none';
  if(amt<1){res.style.display='block';res.className='flash-msg flash-err';res.textContent='Введите сумму';return;}
  var fd=new FormData();fd.append('action','set_amount');fd.append('token',termToken);fd.append('amount',amt);
  fetch('terminal_api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){res.style.display='block';res.className='flash-msg flash-err';res.textContent=d.error||'Ошибка';}
  });
}

function termCancel(){
  var fd=new FormData();fd.append('action','cancel');fd.append('token',termToken);
  fetch('terminal_api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    if(d.ok){termUpdateUI('waiting',null);document.getElementById('termPayAmt').value='';}
  });
}

function termAutoRenew(){
  termRenewTimer = null;
  var orgName = document.getElementById('termSessOrg').innerText;
  if(!orgName) return;
  // Отменяем старую сессию (чтобы не висела)
  var fdCancel = new FormData();
  fdCancel.append('action','cancel');
  fdCancel.append('token',termToken);
  fetch('terminal_api.php',{method:'POST',body:fdCancel}).finally(function(){
    // Создаём новую сессию с тем же именем организации
    var fdCreate = new FormData();
    fdCreate.append('action','create_session');
    fdCreate.append('org_name',orgName);
    fetch('terminal_api.php',{method:'POST',body:fdCreate})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.ok){
          // Останавливаем старый poll
          clearInterval(termPollInt);
          termToken = d.token;
          termInitSession(orgName, d.token);
          termCurrentStatus = 'waiting';
          termLastAmount = null;
          document.getElementById('termPayAmt').value = '';
        } else {
          // Если не удалось создать, просто сбрасываем интерфейс
          termReset();
        }
      });
  });
}

function termReset(){
  if(termRenewTimer){ clearTimeout(termRenewTimer); termRenewTimer=null; }
  clearInterval(termPollInt);
  termToken=null;
  termCurrentStatus='waiting';
  termLastAmount=null;
  document.getElementById('termStepSession').style.display='none';
  document.getElementById('termStepCreate').style.display='';
  document.getElementById('termOrgName').value='';
  document.getElementById('termPayAmt').value='';
}

function copyTermLink(id){
  var txt=document.getElementById(id).textContent;
  navigator.clipboard&&navigator.clipboard.writeText(txt);
  var el=document.getElementById(id);
  var orig=el.textContent;
  el.textContent='Скопировано!';
  setTimeout(function(){el.textContent=orig;},1500);
}

// Не восстанавливаем сессию из URL
(function(){
  if(window.location.search.includes('token')) window.history.replaceState({}, document.title, window.location.pathname);
})();

/* ---- SUPPORT ---- */
function mkAv(avatar){var el=document.createElement('div');el.className='msg-av';if(avatar){var img=document.createElement('img');img.src=avatar;el.appendChild(img);}else el.textContent='🍄';return el;}
function loadUsers(){
  fetch('support_api.php?action=users').then(function(r){return r.json();}).then(function(d){
    var div=document.getElementById('ulDiv');div.innerHTML='';
    if(!d.users||!d.users.length){div.innerHTML='<div style="font-family:Space Mono,monospace;font-size:.52rem;color:#222;padding:14px;text-align:center">Нет обращений</div>';return;}
    d.users.forEach(function(u){
      var item=document.createElement('div');item.className='u-item'+(curUid===u.user_id?' active':'');
      var av=document.createElement('div');av.className='u-av';
      if(u.avatar){var img=document.createElement('img');img.src=u.avatar;av.appendChild(img);}else av.textContent='🍄';
      var nm=document.createElement('div');nm.className='u-nm';nm.textContent=u.username;
      item.appendChild(av);item.appendChild(nm);
      item.onclick=function(){curUid=u.user_id;document.getElementById('chatHdrName').textContent=u.username;document.getElementById('chatInpWrap').style.display='flex';loadAdminMsgs();loadUsers();};
      div.appendChild(item);
    });
  });
}
function loadAdminMsgs(){
  if(!curUid)return;
  fetch('support_api.php?action=get&user_id='+curUid).then(function(r){return r.json();}).then(function(d){
    var box=document.getElementById('aChatMsgs');
    if(!d.messages||!d.messages.length){box.innerHTML='<div class="no-chat">Нет сообщений</div>';return;}
    box.innerHTML='';
    d.messages.forEach(function(m){
      var row=document.createElement('div');row.className='msg-row'+(m.is_admin?' admin-msg':'');
      var right=document.createElement('div');
      var bub=document.createElement('div');bub.className='msg-bub '+(m.is_admin?'admin-bub':'user-bub');bub.textContent=m.message;
      var tm=document.createElement('div');tm.className='msg-t';tm.textContent=(m.is_admin?'Поддержка':m.username)+' · '+m.time;
      right.appendChild(bub);right.appendChild(tm);
      var av=mkAv(m.avatar);
      if(m.is_admin){row.appendChild(right);row.appendChild(av);}else{row.appendChild(av);row.appendChild(right);}
      box.appendChild(row);
    });
    box.scrollTop=box.scrollHeight;
  });
}
function adminSend(){
  var inp=document.getElementById('aChatInp');var msg=inp.value.trim();if(!msg||!curUid)return;
  var fd=new FormData();fd.append('action','admin_reply');fd.append('message',msg);fd.append('user_id',curUid);
  inp.value='';
  fetch('support_api.php',{method:'POST',body:fd}).then(loadAdminMsgs);
}
setInterval(function(){if(curUid)loadAdminMsgs();},4000);
document.getElementById('langBtn').onclick=function(){this.textContent=this.textContent==='RU / EN'?'EN / RU':'RU / EN';};
</script>
</body>
</html>