<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


// Подключаем db_credentials из папки выше сайта
require_once __DIR__ . '/db.php';

if (!isset($db)) {
    die('Ошибка: $db не создана в db_credentials.php');
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
$isAdmin = false;

try {
    $st = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $isAdmin = ($row && (int)($row['is_admin'] ?? 0) === 1) || $uid === 1;
} catch (Throwable $e) {
    $isAdmin = ($uid === 1);
}

if (!$isAdmin) {
    http_response_code(403);
    exit('Доступ запрещён. Ваш ID: ' . $uid);
}

$host = $_SERVER['HTTP_HOST'] ?? 'm1plus.pw';
if (strpos($host, 'bank.m1shroom.ru') !== false) {
    $recipientEmail = 'finance@bank.m1shroom.ru';
    $siteName = 'Bank M1Shroom';
} else {
    $recipientEmail = 'finance@m1plus.pw';
    $siteName = 'M1Plus';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Админ — <?php echo htmlspecialchars($siteName); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;min-height:100vh;padding:20px}
.wrap{max-width:1200px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.header h1{font-size:1.5rem;font-weight:700;background:linear-gradient(135deg,#8b5cf6,#a78bfa);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.back-btn{background:#15151f;border:none;color:#8f8fa3;padding:10px 16px;border-radius:10px;cursor:pointer}
.back-btn:hover{background:#2d1f52;color:#a78bfa}
.filters{background:#15151f;border-radius:16px;padding:16px;margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap}
.filter-btn{background:#1b1b28;border:1px solid transparent;color:#8f8fa3;padding:9px 16px;border-radius:10px;cursor:pointer}
.filter-btn.active{background:#8b5cf6;color:#fff}
.date-input{background:#1b1b28;border:1px solid #23233a;color:#fff;padding:9px 12px;border-radius:10px}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:#15151f;border-radius:16px;padding:18px;border:1px solid #23233a}
.stat-label{font-size:.75rem;color:#8f8fa3;margin-bottom:6px}
.stat-value{font-size:1.6rem;font-weight:700;color:#a78bfa}
.charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
@media(max-width:768px){.charts-grid{grid-template-columns:1fr}}
.chart-card{background:#15151f;border-radius:16px;padding:18px;border:1px solid #23233a}
.chart-title{font-size:1rem;font-weight:700;margin-bottom:14px}
.chart-wrap{position:relative;height:280px}
.send-section{background:#15151f;border-radius:16px;padding:20px;border:1px solid #23233a;margin-bottom:20px}
.send-section h2{font-size:1.1rem;font-weight:700;margin-bottom:14px}
.send-form{display:flex;flex-direction:column;gap:12px}
.send-row{display:flex;gap:10px;flex-wrap:wrap}
.send-input{flex:1;min-width:200px;background:#1b1b28;border:1px solid #23233a;color:#fff;padding:12px;border-radius:12px}
.send-btn{background:#8b5cf6;color:#fff;border:none;padding:12px 24px;border-radius:12px;cursor:pointer;font-weight:600}
.send-btn:hover{background:#6d28d9}
.modal-overlay{position:fixed;inset:0;background:rgba(5,5,10,.85);z-index:500;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#15151f;border-radius:20px;padding:24px;max-width:420px;width:90%;border:1px solid #23233a}
.modal h3{font-size:1.1rem;font-weight:700;margin-bottom:12px}
.modal p{font-size:.88rem;color:#8f8fa3;margin-bottom:16px}
.modal input{width:100%;background:#1b1b28;border:1px solid #23233a;color:#fff;padding:12px;border-radius:10px;margin-bottom:12px}
.modal-actions{display:flex;gap:10px}
.modal-actions button{flex:1;padding:11px;border:none;border-radius:10px;cursor:pointer}
.modal-cancel{background:#1b1b28;color:#8f8fa3}
.modal-confirm{background:#8b5cf6;color:#fff}
.toast{position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-100px);background:#15151f;color:#fff;padding:14px 22px;border-radius:12px;border:1px solid #8b5cf6;z-index:1000;transition:transform .4s}
.toast.show{transform:translateX(-50%) translateY(0)}
</style>
</head>
<body>
<div class="wrap">
<div class="header"><h1>📊 Аналитика — <?php echo htmlspecialchars($siteName); ?></h1><button class="back-btn" onclick="location.href='../dashboard.php'">← Назад</button></div>
<div class="filters">
<button class="filter-btn active" onclick="setPeriod('month',this)">Месяц</button>
<button class="filter-btn" onclick="setPeriod('year',this)">Год</button>
<button class="filter-btn" onclick="setPeriod('all',this)">Всё время</button>
<button class="filter-btn" onclick="setPeriod('custom',this)">Свой период</button>
<div id="customDates" style="display:none;gap:8px;align-items:center">
<input type="date" class="date-input" id="dateFrom"><span style="color:#555">—</span><input type="date" class="date-input" id="dateTo">
<button class="filter-btn" onclick="applyCustom()">Применить</button></div></div>
<div class="stats-grid">
<div class="stat-card"><div class="stat-label">Выручка</div><div class="stat-value" id="statRevenue">—</div></div>
<div class="stat-card"><div class="stat-label">Транзакции</div><div class="stat-value" id="statTx">—</div></div>
<div class="stat-card"><div class="stat-label">Пользователи</div><div class="stat-value" id="statUsers">—</div></div>
<div class="stat-card"><div class="stat-label">Карты</div><div class="stat-value" id="statCards">—</div></div></div>
<div class="charts-grid">
<div class="chart-card"><div class="chart-title">Распределение</div><div class="chart-wrap"><canvas id="pieChart"></canvas></div></div>
<div class="chart-card"><div class="chart-title">Динамика</div><div class="chart-wrap"><canvas id="barChart"></canvas></div></div></div>
<div class="send-section"><h2>📧 Отправить отчёт на <?php echo htmlspecialchars($recipientEmail); ?></h2>
<div class="send-form"><div class="send-row"><input type="date" class="send-input" id="sendFrom"><input type="date" class="send-input" id="sendTo"></div>
<button class="send-btn" onclick="openPasswordModal()">Отправить PDF</button></div></div></div>
<div class="modal-overlay" id="passwordModal"><div class="modal">
<h3>🔒 Подтверждение</h3><p>Введите пароль для отправки на <b><?php echo htmlspecialchars($recipientEmail); ?></b></p>
<input type="password" id="adminPassword" placeholder="Пароль">
<div class="modal-actions"><button class="modal-cancel" onclick="closePasswordModal()">Отмена</button><button class="modal-confirm" id="confirmSendBtn" onclick="confirmSend()">Отправить</button></div></div></div>
<div class="toast" id="toast"></div>
<script>
let pieChart,barChart;
function showToast(msg,type){type=type||'success';const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.classList.remove('show'),3500);}
function setPeriod(period,btn){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');document.getElementById('customDates').style.display=period==='custom'?'flex':'none';if(period!=='custom')loadAnalytics(period);}
function applyCustom(){const from=document.getElementById('dateFrom').value;const to=document.getElementById('dateTo').value;if(!from||!to){showToast('Выберите даты','error');return;}loadAnalytics('custom',from,to);}
async function loadAnalytics(period,from,to){try{const params=new URLSearchParams({period});if(from)params.set('from',from);if(to)params.set('to',to);const res=await fetch('analytics_api.php?'+params);const data=await res.json();if(data.error){showToast(data.error,'error');return;}document.getElementById('statRevenue').textContent=(Math.round(data.stats.revenue)||0).toLocaleString('ru-RU')+' ₽';document.getElementById('statTx').textContent=data.stats.transactions;document.getElementById('statUsers').textContent=data.stats.users;document.getElementById('statCards').textContent=data.stats.cards;renderPieChart(data.pie);renderBarChart(data.bar);}catch(e){showToast('Ошибка: '+e.message,'error');}}
function renderPieChart(data){const ctx=document.getElementById('pieChart').getContext('2d');if(pieChart)pieChart.destroy();pieChart=new Chart(ctx,{type:'doughnut',data:{labels:data.labels||[],datasets:[{data:data.values||[],backgroundColor:['#8b5cf6','#06b6d4','#f59e0b','#10b981','#ef4444','#ec4899'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:'#8f8fa3',font:{family:'DM Sans',size:11}}}}}});}
function renderBarChart(data){const ctx=document.getElementById('barChart').getContext('2d');if(barChart)barChart.destroy();barChart=new Chart(ctx,{type:'bar',data:{labels:data.labels||[],datasets:[{label:'Выручка',data:data.values||[],backgroundColor:'rgba(139,92,246,0.7)',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#8f8fa3'},grid:{color:'rgba(255,255,255,0.05)'}},y:{ticks:{color:'#8f8fa3'},grid:{color:'rgba(255,255,255,0.05)'}}}}});}
function openPasswordModal(){const from=document.getElementById('sendFrom').value;const to=document.getElementById('sendTo').value;if(!from||!to){showToast('Выберите период','error');return;}document.getElementById('passwordModal').classList.add('open');document.getElementById('adminPassword').value='';document.getElementById('adminPassword').focus();}
function closePasswordModal(){document.getElementById('passwordModal').classList.remove('open');}
async function confirmSend(){const password=document.getElementById('adminPassword').value;const from=document.getElementById('sendFrom').value;const to=document.getElementById('sendTo').value;const btn=document.getElementById('confirmSendBtn');if(!password){showToast('Введите пароль','error');return;}btn.disabled=true;btn.textContent='Отправка...';try{const res=await fetch('send_report.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password,from,to})});const data=await res.json();if(data.ok){showToast('✓ Отправлено на '+data.email);closePasswordModal();}else{showToast(data.error||'Ошибка','error');}}catch(e){showToast('Ошибка: '+e.message,'error');}finally{btn.disabled=false;btn.textContent='Отправить';}}
document.getElementById('adminPassword').addEventListener('keydown',e=>{if(e.key==='Enter')confirmSend();});
document.getElementById('passwordModal').addEventListener('click',e=>{if(e.target.id==='passwordModal')closePasswordModal();});
loadAnalytics('month');
</script>
</body>
</html>