<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['is_admin'] ? 'admin.php' : 'dashboard.php'));
    exit;
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1shroom wallet</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#030303;--surface:#0c0c0c;--surface2:#141414;--border:#1c1c1c;--border2:#252525;--accent:#c8ff00;--accent-dim:#8aad00;--text:#f5f5f5;--muted:#3a3a3a;--muted2:#555;--danger:#ff4444}
body{background:var(--bg);color:var(--text);font-family:'Syne',sans-serif;min-height:100vh;display:grid;grid-template-columns:1fr 1fr;overflow:hidden}
.left{position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:40px;border-right:1px solid var(--border);overflow:hidden}
.bg-canvas{position:absolute;inset:0;z-index:0}
.left-content{position:relative;z-index:1;display:flex;flex-direction:column;height:100%;justify-content:space-between}
.logo-area{display:flex;align-items:center;gap:10px}
.logo-mark{width:32px;height:32px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-mark svg{fill:var(--bg)}
.logo-name{font-size:.7rem;font-weight:700;letter-spacing:.35em;color:var(--text);text-transform:uppercase}
.hero-text{flex:1;display:flex;flex-direction:column;justify-content:center;padding:60px 0 40px}
.hero-eyebrow{font-family:'DM Mono',monospace;font-size:.55rem;letter-spacing:.4em;color:var(--accent);text-transform:uppercase;margin-bottom:20px;opacity:0;animation:fadeUp .8s ease .3s forwards}
.hero-title{font-size:clamp(2.4rem,4.5vw,3.8rem);font-weight:800;line-height:1.05;letter-spacing:-.03em;margin-bottom:24px;opacity:0;animation:fadeUp .9s ease .5s forwards}
.hero-title span{color:var(--accent)}
.hero-sub{font-family:'DM Mono',monospace;font-size:.62rem;line-height:1.8;color:var(--muted2);letter-spacing:.05em;max-width:340px;opacity:0;animation:fadeUp .9s ease .7s forwards}
.stats-row{display:flex;gap:0;border-top:1px solid var(--border);padding-top:32px;opacity:0;animation:fadeUp .9s ease 1s forwards}
.stat{flex:1;padding-right:24px}
.stat:not(:last-child){border-right:1px solid var(--border);margin-right:24px}
.stat-val{font-size:1.6rem;font-weight:800;letter-spacing:-.04em;color:var(--text);line-height:1;margin-bottom:4px}
.stat-val span{color:var(--accent)}
.stat-label{font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.3em;color:var(--muted);text-transform:uppercase}
.right{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 48px;position:relative;overflow:hidden;background:var(--surface)}
.right::before{content:'';position:absolute;top:-200px;right:-200px;width:500px;height:500px;background:radial-gradient(circle, rgba(200,255,0,.03) 0%, transparent 70%);pointer-events:none}
.form-card{width:100%;max-width:380px;opacity:0;animation:fadeUp 1s ease .4s forwards}
.form-header{margin-bottom:36px}
.form-tag{font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.45em;color:var(--muted);text-transform:uppercase;margin-bottom:10px}
.form-title{font-size:1.6rem;font-weight:800;letter-spacing:-.03em;line-height:1.1}
.form-title span{color:var(--accent)}
.field{margin-bottom:18px}
.field-label{font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.3em;color:var(--muted);text-transform:uppercase;margin-bottom:8px;display:block;transition:color .2s}
.field:focus-within .field-label{color:var(--accent)}
.field-wrap{position:relative}
.field-wrap input{width:100%;background:var(--surface2);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:'DM Mono',monospace;font-size:.78rem;padding:14px 16px 14px 44px;outline:none;display:block;transition:border-color .2s, background .2s;-webkit-appearance:none}
.field-wrap input:focus{border-color:var(--accent);background:#0f0f0f}
.field-wrap input::placeholder{color:var(--muted)}
.field-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;transition:color .2s}
.field:focus-within .field-icon{color:var(--accent)}
.btn-primary{width:100%;background:var(--accent);color:var(--bg);border:none;border-radius:10px;font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.12em;padding:15px;cursor:pointer;text-transform:uppercase;transition:background .2s, transform .15s;position:relative;overflow:hidden;margin-top:4px}
.btn-primary::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg, transparent 0%, rgba(255,255,255,.18) 50%, transparent 100%);transform:translateX(-100%);transition:transform .5s ease}
.btn-primary:hover{background:#d8ff1a}
.btn-primary:hover::after{transform:translateX(100%)}
.btn-primary:active{transform:scale(.98)}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}
.divider span{font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.25em;color:var(--muted)}
.btn-passkey{width:100%;background:transparent;color:var(--muted2);border:1px solid var(--border2);border-radius:10px;font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.12em;padding:13px;cursor:pointer;text-transform:uppercase;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:10px}
.btn-passkey:hover{border-color:var(--muted2);color:var(--text);background:var(--surface2)}
.pk-err{font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.15em;color:var(--danger);text-align:center;min-height:16px;margin-top:8px}
.flash-err{font-family:'DM Mono',monospace;font-size:.52rem;letter-spacing:.12em;color:var(--danger);text-align:center;min-height:14px;margin-bottom:12px;padding:10px 14px;background:rgba(255,68,68,.06);border:1px solid rgba(255,68,68,.15);border-radius:8px}
.reg-note{text-align:center;font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.1em;color:var(--muted);line-height:1.8;margin-top:20px}
.reg-note a{color:var(--muted2);text-decoration:none;border-bottom:1px solid var(--border2);transition:color .2s, border-color .2s}
.reg-note a:hover{color:var(--accent);border-color:var(--accent)}
#langBtn{position:fixed;bottom:20px;right:20px;background:var(--surface2);border:1px solid var(--border2);border-radius:8px;color:var(--muted);font-family:'DM Mono',monospace;font-size:.52rem;letter-spacing:.2em;padding:8px 14px;cursor:pointer;z-index:100;transition:all .2s}
#langBtn:hover{color:var(--text);border-color:var(--muted)}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
#cursor{position:fixed;width:32px;height:32px;border:1px solid rgba(200,255,0,.4);border-radius:50%;pointer-events:none;z-index:9999;transform:translate(-50%,-50%);transition:transform .08s ease,width .2s,height .2s,border-color .2s;mix-blend-mode:normal}
@media(max-width:768px){body{grid-template-columns:1fr;grid-template-rows:auto 1fr;overflow:auto}.left{padding:28px 24px 32px;border-right:none;border-bottom:1px solid var(--border)}.hero-title{font-size:2rem}.right{padding:40px 24px}#cursor{display:none}}
</style>
</head>
<body>

<div id="cursor"></div>

<!-- LEFT PANEL -->
<div class="left">
  <canvas class="bg-canvas" id="bgCanvas"></canvas>
  <div class="left-content">
    <div class="logo-area">
      <div class="logo-mark"><svg width="16" height="16" viewBox="0 0 16 16"><path d="M8 1C4.13 1 1 4.13 1 8s3.13 7 7 7 7-3.13 7-7-3.13-7-7-7zm0 2.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 8.75c-1.75 0-3.3-.9-4.2-2.26.02-1.39 2.8-2.15 4.2-2.15 1.4 0 4.18.76 4.2 2.15-.9 1.36-2.45 2.26-4.2 2.26z"/></svg></div>
      <div class="logo-name">M1shroom wallet</div>
    </div>
    <div class="hero-text">
      <div class="hero-eyebrow" data-i18n="hero_eyebrow">Secure · Private · Fast</div>
      <h1 class="hero-title" data-i18n="hero_title">Your money.<br><span>Fully</span><br>under control.</h1>
      <p class="hero-sub" data-i18n="hero_sub">wallet.m1shroom.ru — платформа для тех,<br>кто ценит контроль над каждой транзакцией.</p>
    </div>
    <div class="stats-row">
      <div class="stat"><div class="stat-val">99<span>.9%</span></div><div class="stat-label" data-i18n="stat_uptime_label">Uptime</div></div>
      <div class="stat"><div class="stat-val"><span>&lt;</span>50ms</div><div class="stat-label" data-i18n="stat_latency_label">Latency</div></div>
      <div class="stat"><div class="stat-val">256<span>bit</span></div><div class="stat-label" data-i18n="stat_encryption_label">Encryption</div></div>
    </div>
  </div>
</div>

<!-- RIGHT PANEL -->
<div class="right">
  <div class="form-card">
    <div class="form-header">
      <div class="form-tag" data-i18n="form_tag">Access portal</div>
      <div class="form-title" data-i18n="form_title">Sign in to your<br><span>account</span></div>
    </div>

    <form method="POST" action="auth.php">
      <input type="hidden" name="action" value="login">

      <div class="field">
        <label class="field-label" for="emailInput" data-i18n="email_label">Email address</label>
        <div class="field-wrap">
          <div class="field-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7L13 13a2 2 0 01-2 0L2 7"/></svg></div>
          <input type="email" name="email" id="emailInput" placeholder="user@m1shroom.ru" required autocomplete="off" data-i18n-placeholder="email_placeholder">
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="passInput" data-i18n="password_label">Password</label>
        <div class="field-wrap">
          <div class="field-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></div>
          <input type="password" name="password" id="passInput" placeholder="••••••••" required data-i18n-placeholder="password_placeholder">
        </div>
      </div>

      <?php if ($flash): ?>
      <div class="flash-err"><?php echo htmlspecialchars($flash); ?></div>
      <?php endif; ?>

      <button class="btn-primary" type="submit" data-i18n="signin_button">Sign in →</button>
    </form>

    <div class="divider"><span data-i18n="divider_text">or continue with</span></div>

    <button class="btn-passkey" onclick="startPasskeyAuth()" id="pkBtn" data-i18n="passkey_button">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2L11 12"/><path d="M15 6l3 3"/></svg>
      Passkey authentication
    </button>

    <div class="pk-err" id="pkErr"></div>

    <div class="reg-note" data-i18n="reg_note">
      No account yet? Register at <a href="https://m1shroom.ru" target="_blank" data-i18n-link="reg_link">m1shroom.ru</a>
    </div>
  </div>
</div>

<button id="langBtn">EN</button>

<script>
/* ===== ПЕРЕВОДЫ ===== */
const translations = {
  ru: {
    hero_eyebrow: "Безопасно · Приватно · Быстро",
    hero_title: "Ваши деньги.<br><span>Полностью</span><br>под контролем.",
    hero_sub: "wallet.m1shroom.ru — платформа для тех,<br>кто ценит контроль над каждой транзакцией.",
    stat_uptime_label: "Доступность",
    stat_latency_label: "Задержка",
    stat_encryption_label: "Шифрование",
    form_tag: "Портал доступа",
    form_title: "Войдите в свой<br><span>аккаунт</span>",
    email_label: "Электронная почта",
    email_placeholder: "user@m1shroom.ru",
    password_label: "Пароль",
    password_placeholder: "••••••••",
    signin_button: "Войти →",
    divider_text: "или продолжить с помощью",
    passkey_button: "Аутентификация по ключу доступа",
    reg_note: "Нет аккаунта? Зарегистрируйтесь на <a href=\"https://m1shroom.ru\" target=\"_blank\">m1shroom.ru</a>",
  },
  en: {
    hero_eyebrow: "Secure · Private · Fast",
    hero_title: "Your money.<br><span>Fully</span><br>under control.",
    hero_sub: "wallet.m1shroom.ru — a platform for those<br>who value control over every transaction.",
    stat_uptime_label: "Uptime",
    stat_latency_label: "Latency",
    stat_encryption_label: "Encryption",
    form_tag: "Access portal",
    form_title: "Sign in to your<br><span>account</span>",
    email_label: "Email address",
    email_placeholder: "user@m1shroom.ru",
    password_label: "Password",
    password_placeholder: "••••••••",
    signin_button: "Sign in →",
    divider_text: "or continue with",
    passkey_button: "Passkey authentication",
    reg_note: "No account yet? Register at <a href=\"https://m1shroom.ru\" target=\"_blank\">m1shroom.ru</a>",
  }
};

let currentLang = 'ru';
function setLanguage(lang) {
  if (!translations[lang]) return;
  currentLang = lang;
  const dict = translations[lang];
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.getAttribute('data-i18n');
    if (dict[key] !== undefined) {
      if (key === 'hero_title' || key === 'form_title' || key === 'reg_note') {
        el.innerHTML = dict[key];
      } else {
        el.textContent = dict[key];
      }
    }
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
    const key = el.getAttribute('data-i18n-placeholder');
    if (dict[key] !== undefined) {
      el.placeholder = dict[key];
    }
  });
  const pkBtn = document.getElementById('pkBtn');
  if (pkBtn && dict.passkey_button) {
    const svg = pkBtn.querySelector('svg');
    pkBtn.innerHTML = '';
    if (svg) pkBtn.appendChild(svg);
    pkBtn.appendChild(document.createTextNode(' ' + dict.passkey_button));
  }
}

function toggleLanguage() {
  if (currentLang === 'ru') {
    setLanguage('en');
    document.getElementById('langBtn').textContent = 'RU';
  } else {
    setLanguage('ru');
    document.getElementById('langBtn').textContent = 'EN';
  }
}
setLanguage('ru');
document.getElementById('langBtn').textContent = 'EN';
document.getElementById('langBtn').onclick = toggleLanguage;

/* ===== PASSKEY ===== */
function b64uDec(s){return Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));}
function b64uEnc(buf){return btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');}

async function startPasskeyAuth(){
  if(!window.PublicKeyCredential){
    alert(currentLang === 'ru' ? 'Парольный ключ не поддерживается в этом браузере' : 'Passkey not supported in this browser');
    return;
  }
  var errEl=document.getElementById('pkErr');
  errEl.textContent='';
  try{
    var opts=await fetch('passkey_auth_api.php?action=begin').then(r=>r.json());
    if(opts.error){errEl.textContent=opts.error;return;}
    var cred=await navigator.credentials.get({publicKey:{
      challenge:b64uDec(opts.challenge),
      rpId:opts.rpId,
      allowCredentials:(opts.allowCredentials||[]).map(c=>({...c,id:b64uDec(c.id)})),
      userVerification:'preferred',
      timeout:60000
    }});
    var credJSON={id:cred.id,rawId:b64uEnc(cred.rawId),type:cred.type,response:{authenticatorData:b64uEnc(cred.response.authenticatorData),clientDataJSON:b64uEnc(cred.response.clientDataJSON),signature:b64uEnc(cred.response.signature)}};
    var fd=new FormData();fd.append('action','complete');fd.append('credential',JSON.stringify(credJSON));
    var res=await fetch('passkey_auth_api.php',{method:'POST',body:fd}).then(r=>r.json());
    if(res.ok)window.location.href=res.redirect;
    else errEl.textContent=(currentLang === 'ru' ? 'Ошибка: ' : 'Error: ') + (res.error||(currentLang === 'ru' ? 'Неизвестно' : 'Unknown'));
  }catch(e){if(e.name!=='NotAllowedError')errEl.textContent=(currentLang === 'ru' ? 'Ошибка: ' : 'Error: ')+e.message;}
}

/* ===== CANVAS ===== */
(function(){
  var cv=document.getElementById('bgCanvas'), ctx=cv.getContext('2d'), W,H,pts,grid;
  function resize(){ W=cv.width=cv.offsetWidth; H=cv.height=cv.offsetHeight; build(); }
  function build(){
    pts=[]; var cols=Math.ceil(W/60)+1, rows=Math.ceil(H/60)+1; grid={cols,rows,w:W/(cols-1),h:H/(rows-1)};
    for(var r=0;r<rows;r++) for(var c=0;c<cols;c++) pts.push({bx:c*grid.w, by:r*grid.h, ox:(Math.random()-.5)*22, oy:(Math.random()-.5)*22, ox2:0, oy2:0, vx:0, vy:0, phase:Math.random()*Math.PI*2, speed:.4+Math.random()*.4});
  }
  var mouse={x:W/2,y:H/2};
  window.addEventListener('mousemove',e=>{var r=cv.getBoundingClientRect(); mouse.x=e.clientX-r.left; mouse.y=e.clientY-r.top;});
  var t=0;
  function draw(){
    ctx.clearRect(0,0,W,H); t+=.007;
    for(var i=0;i<pts.length;i++){ var p=pts[i]; p.ox2+=(Math.sin(t*.7+p.phase)*18-p.ox2)*.03; p.oy2+=(Math.cos(t*.5+p.phase)*18-p.oy2)*.03; var dx=mouse.x-(p.bx+p.ox), dy=mouse.y-(p.by+p.oy), dist=Math.sqrt(dx*dx+dy*dy), rep=Math.max(0,1-dist/130)*14; p.ox2-=dx/dist*rep*.3; p.oy2-=dy/dist*rep*.3; }
    ctx.lineWidth=.5;
    for(var r=0;r<grid.rows;r++) for(var c=0;c<grid.cols;c++){ var i=r*grid.cols+c, p=pts[i], px=p.bx+p.ox+p.ox2, py=p.by+p.oy+p.oy2;
      if(c<grid.cols-1){ var p2=pts[i+1], px2=p2.bx+p2.ox+p2.ox2, py2=p2.by+p2.oy+p2.oy2, g=ctx.createLinearGradient(px,py,px2,py2); g.addColorStop(0,'rgba(200,255,0,.07)'); g.addColorStop(1,'rgba(200,255,0,.04)'); ctx.strokeStyle=g; ctx.beginPath(); ctx.moveTo(px,py); ctx.lineTo(px2,py2); ctx.stroke(); }
      if(r<grid.rows-1){ var p3=pts[i+grid.cols], px3=p3.bx+p3.ox+p3.ox2, py3=p3.by+p3.oy+p3.oy2, g2=ctx.createLinearGradient(px,py,px3,py3); g2.addColorStop(0,'rgba(200,255,0,.07)'); g2.addColorStop(1,'rgba(200,255,0,.02)'); ctx.strokeStyle=g2; ctx.beginPath(); ctx.moveTo(px,py); ctx.lineTo(px3,py3); ctx.stroke(); }
      var a=.08+Math.abs(Math.sin(t+p.phase))*.15; ctx.fillStyle=`rgba(200,255,0,${a.toFixed(2)})`; ctx.beginPath(); ctx.arc(px,py,1.5,0,Math.PI*2); ctx.fill();
    }
    requestAnimationFrame(draw);
  }
  resize(); window.addEventListener('resize',resize); draw();
})();

/* ===== CURSOR ===== */
(function(){
  var ring=document.getElementById('cursor'); if(!ring)return;
  var mx=window.innerWidth/2, my=window.innerHeight/2, rx=mx, ry=my;
  document.addEventListener('mousemove',e=>{mx=e.clientX;my=e.clientY;});
  var hov=false;
  document.querySelectorAll('button,a,input').forEach(el=>{el.addEventListener('mouseenter',()=>{hov=true;}); el.addEventListener('mouseleave',()=>{hov=false;});});
  function tick(){ rx+=(mx-rx)*.18; ry+=(my-ry)*.18; ring.style.left=rx+'px'; ring.style.top=ry+'px'; if(hov){ ring.style.width='48px'; ring.style.height='48px'; ring.style.borderColor='rgba(200,255,0,.7)'; } else { ring.style.width='32px'; ring.style.height='32px'; ring.style.borderColor='rgba(200,255,0,.35)'; } requestAnimationFrame(tick); } tick();
})();

/* ===== INPUT RIPPLE ===== */
document.querySelectorAll('input').forEach(inp=>{
  inp.addEventListener('focus',function(){ this.parentElement.style.transform='scale(1.01)'; this.parentElement.style.transition='transform .2s ease'; });
  inp.addEventListener('blur',function(){ this.parentElement.style.transform='scale(1)'; });
});
</script>
</body>
</html>