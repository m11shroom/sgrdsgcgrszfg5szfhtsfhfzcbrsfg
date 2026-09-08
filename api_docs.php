<?php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid = (int)$_SESSION['user_id'];
$db  = getDB();
$merchants = $db->prepare('SELECT * FROM merchants WHERE user_id=? ORDER BY created_at ASC LIMIT 1');
$merchants->execute([$uid]); $merchant = $merchants->fetch();
$apiKey = $merchant ? htmlspecialchars($merchant['api_key']) : 'ВАШ_API_КЛЮЧ';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1PLUS WALLET — Документация API</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#080808;color:#e8e8e8;font-family:'DM Sans',sans-serif;min-height:100vh;padding:0}

/* TOP BAR */
.topbar{background:#0d0d0d;border-bottom:1px solid #181818;padding:0 24px;position:sticky;top:0;z-index:50}
.topbar-inner{max-width:1000px;margin:0 auto;height:54px;display:flex;align-items:center;justify-content:space-between}
.brand{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.3em;color:#333;display:flex;align-items:center;gap:7px}
.bdot{width:5px;height:5px;background:#6366f1;border-radius:50%}
.back-btn{background:none;border:1px solid #1a1a1a;border-radius:9px;color:#555;font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.14em;padding:7px 14px;cursor:pointer;text-decoration:none;transition:all .2s}
.back-btn:hover{border-color:#6366f1;color:#e8e8e8}

.layout{max-width:1000px;margin:0 auto;display:grid;grid-template-columns:220px 1fr;gap:0;min-height:calc(100vh - 54px)}

/* SIDEBAR */
.sidebar{border-right:1px solid #111;padding:28px 0;position:sticky;top:54px;height:calc(100vh - 54px);overflow-y:auto}
.sidebar::-webkit-scrollbar{width:3px}
.sidebar::-webkit-scrollbar-thumb{background:#1a1a1a;border-radius:2px}
.nav-section{margin-bottom:22px}
.nav-section-title{font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.3em;color:#2a2a2a;padding:0 20px;margin-bottom:8px;text-transform:uppercase}
.nav-item{display:block;padding:7px 20px;font-size:.8rem;color:#555;text-decoration:none;transition:all .15s;border-left:2px solid transparent}
.nav-item:hover{color:#e8e8e8;background:#0d0d0d}
.nav-item.active{color:#a5b4fc;border-left-color:#6366f1;background:#0a0a14}
.nav-badge{font-family:'Space Mono',monospace;font-size:.42rem;letter-spacing:.1em;padding:1px 6px;border-radius:4px;margin-left:6px;vertical-align:middle}
.nb-post{background:#1a2a0a;border:1px solid #2a4010;color:#86efac}
.nb-get{background:#0a1a2a;border:1px solid #103040;color:#7dd3fc}

/* CONTENT */
.content{padding:36px 40px 80px}
.section-anchor{padding-top:0;margin-top:0}

h2{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;margin-bottom:8px;padding-top:40px;border-top:1px solid #111}
h2:first-child{border-top:none;padding-top:0}
h3{font-size:1rem;font-weight:600;margin-bottom:10px;padding-top:28px;display:flex;align-items:center;gap:10px}
.method{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.1em;padding:3px 8px;border-radius:5px}
.method.post{background:#1a2a0a;border:1px solid #2a4010;color:#86efac}
.method.get{background:#0a1a2a;border:1px solid #103040;color:#7dd3fc}

p{font-size:.9rem;color:#888;line-height:1.7;margin-bottom:14px}
.lead{font-size:1rem;color:#aaa;line-height:1.7;margin-bottom:24px}

/* CODE */
pre{background:#080808;border:1px solid #181818;border-radius:13px;padding:20px;overflow-x:auto;margin:14px 0 20px;position:relative}
pre code{font-family:'Space Mono',monospace;font-size:.72rem;line-height:1.9;color:#ccc}
.c-kw{color:#c084fc}.c-str{color:#86efac}.c-key{color:#7dd3fc}.c-num{color:#fbbf24}.c-cm{color:#374151}.c-url{color:#f9a8d4}
.copy-pre{position:absolute;top:10px;right:12px;background:#181818;border:1px solid #222;border-radius:7px;color:#555;font-family:'Space Mono',monospace;font-size:.44rem;letter-spacing:.1em;padding:4px 9px;cursor:pointer;transition:all .2s}
.copy-pre:hover{border-color:#6366f1;color:#a5b4fc}

/* TABLE */
table{width:100%;border-collapse:collapse;margin:14px 0 20px;font-size:.84rem}
th{font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.2em;color:#333;text-align:left;padding:9px 12px;border-bottom:1px solid #141414}
td{padding:10px 12px;border-bottom:1px solid #0f0f0f;color:#888;vertical-align:top}
td:first-child{font-family:'Space Mono',monospace;font-size:.72rem;color:#a5b4fc}
td code{font-family:'Space Mono',monospace;font-size:.7rem;background:#0d0d0d;border:1px solid #181818;border-radius:5px;padding:1px 6px;color:#e8e8e8}
tr:hover td{background:#0a0a0a}

/* ALERT */
.alert{border-radius:12px;padding:14px 16px;margin:14px 0 20px;font-size:.85rem;line-height:1.6}
.alert-info{background:#0a0a1a;border:1px solid #1e1e4a;color:#a5b4fc}
.alert-warn{background:#1a1400;border:1px solid #2a2200;color:#fbbf24}
.alert-ok{background:#001a08;border:1px solid #002a0e;color:#4ade80}
.alert strong{font-weight:600}

/* KEY BOX */
.key-box{background:#080808;border:1px solid #181818;border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 20px}
.key-val{font-family:'Space Mono',monospace;font-size:.7rem;color:#555;word-break:break-all;flex:1}
.key-val.has-key{color:#a5b4fc}
.copy-btn{background:#1a1a1a;border:1px solid #222;border-radius:8px;color:#666;font-family:'Space Mono',monospace;font-size:.46rem;letter-spacing:.1em;padding:5px 10px;cursor:pointer;flex-shrink:0;transition:all .2s}
.copy-btn:hover{border-color:#6366f1;color:#a5b4fc}

/* TAG */
.tag{font-family:'Space Mono',monospace;font-size:.5rem;letter-spacing:.18em;padding:3px 8px;border-radius:6px;display:inline-block;margin-bottom:6px}
.tag-req{background:#1a0808;border:1px solid #2a1010;color:#fca5a5}
.tag-opt{background:#0a0a0a;border:1px solid #181818;color:#555}
.tag-str{background:#0a1000;border:1px solid #182000;color:#86efac}

@media(max-width:680px){
  .layout{grid-template-columns:1fr}
  .sidebar{display:none}
  .content{padding:24px 20px 60px}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <div class="brand"><div class="bdot"></div>M1PLUS WALLET · API DOCS</div>
    <a href="dashboard.php" class="back-btn">← Вернуться в кабинет</a>
  </div>
</div>

<div class="layout">
  <!-- SIDEBAR NAV -->
  <nav class="sidebar">
    <div class="nav-section">
      <div class="nav-section-title">Начало работы</div>
      <a href="#intro"    class="nav-item active">Введение</a>
      <a href="#auth"     class="nav-item">Аутентификация</a>
      <a href="#base-url" class="nav-item">Base URL</a>
      <a href="#errors"   class="nav-item">Ошибки</a>
    </div>
    <div class="nav-section">
      <div class="nav-section-title">Платежи</div>
      <a href="#create" class="nav-item"><span class="nav-badge nb-post">POST</span> Создать платёж</a>
      <a href="#status" class="nav-item"><span class="nav-badge nb-get">GET</span> Статус платежа</a>
    </div>
    <div class="nav-section">
      <div class="nav-section-title">Вебхуки</div>
      <a href="#webhooks"    class="nav-item">Настройка</a>
      <a href="#wh-payload"  class="nav-item">Структура</a>
      <a href="#wh-verify"   class="nav-item">Верификация</a>
    </div>
    <div class="nav-section">
      <div class="nav-section-title">Примеры</div>
      <a href="#ex-php"    class="nav-item">PHP</a>
      <a href="#ex-js"     class="nav-item">JavaScript / Node</a>
      <a href="#ex-python" class="nav-item">Python</a>
    </div>
  </nav>

  <!-- MAIN CONTENT -->
  <main class="content">

    <!-- INTRO -->
    <div id="intro">
      <h2>Документация API</h2>
      <p class="lead">M1PLUS WALLET Merchant API позволяет создавать платежи, получать их статус и принимать уведомления об оплате в реальном времени через вебхуки. Интеграция занимает около 5 минут.</p>
      <div class="alert alert-ok">
        <strong>Статус API:</strong> Работает · v1 · Все запросы через HTTPS
      </div>
    </div>

    <!-- AUTH -->
    <div id="auth">
      <h2>Аутентификация</h2>
      <p>Все запросы к API требуют API-ключа вашего магазина. Передавайте его в заголовке <code>X-API-Key</code>.</p>
      <?php if ($merchant): ?>
      <p style="font-size:.82rem;color:#555;margin-bottom:6px">Ваш текущий API-ключ:</p>
      <div class="key-box">
        <span class="key-val has-key" id="apiKeyDisplay"><?php echo $apiKey; ?></span>
        <button class="copy-btn" onclick="copyEl('apiKeyDisplay')">Копировать</button>
      </div>
      <?php else: ?>
      <div class="alert alert-warn"><strong>У вас нет магазина.</strong> Создайте магазин в личном кабинете, чтобы получить API-ключ.</div>
      <?php endif; ?>
      <pre><code><span class="c-cm"># Передавайте ключ в каждом запросе</span>
<span class="c-key">X-API-Key</span>: <span class="c-str"><?php echo $apiKey; ?></span></code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
      <div class="alert alert-warn"><strong>Важно:</strong> Никогда не публикуйте API-ключ в открытом коде на клиентской стороне (браузер, мобильное приложение). Используйте только на сервере.</div>
    </div>

    <!-- BASE URL -->
    <div id="base-url">
      <h2>Base URL</h2>
      <pre><code><span class="c-url">https://pay.m1plus.pw/api.php</span></code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
      <table>
        <tr><th>Параметр</th><th>Значение</th></tr>
        <tr><td>Протокол</td><td>HTTPS обязателен</td></tr>
        <tr><td>Формат данных</td><td>JSON (предпочтительно) или form-data</td></tr>
        <tr><td>Кодировка</td><td>UTF-8</td></tr>
        <tr><td>Content-Type</td><td><code>application/json</code> или <code>multipart/form-data</code></td></tr>
      </table>
    </div>

    <!-- ERRORS -->
    <div id="errors">
      <h2>Коды ошибок</h2>
      <p>При ошибке API возвращает JSON с полями <code>error</code> и <code>code</code>.</p>
      <table>
        <tr><th>HTTP</th><th>code</th><th>Описание</th></tr>
        <tr><td>401</td><td><code>unauthorized</code></td><td>Неверный или отсутствующий API-ключ</td></tr>
        <tr><td>400</td><td><code>invalid_amount</code></td><td>Сумма меньше 1 ₽</td></tr>
        <tr><td>404</td><td><code>not_found</code></td><td>Платёж не найден</td></tr>
        <tr><td>405</td><td>—</td><td>Метод не поддерживается</td></tr>
      </table>
      <pre><code><span class="c-cm">// Пример ошибки</span>
{
  <span class="c-key">"error"</span>: <span class="c-str">"Invalid API key"</span>,
  <span class="c-key">"code"</span>:  <span class="c-str">"unauthorized"</span>
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
    </div>

    <!-- CREATE PAYMENT -->
    <div id="create">
      <h2>Создать платёж</h2>
      <h3><span class="method post">POST</span> /api.php</h3>
      <p>Создаёт новый платёж и возвращает <code>payment_url</code> для перенаправления покупателя на страницу оплаты.</p>

      <p style="font-weight:600;margin-bottom:8px;color:#ccc">Тело запроса:</p>
      <table>
        <tr><th>Поле</th><th>Тип</th><th>Обязательно</th><th>Описание</th></tr>
        <tr>
          <td>amount</td>
          <td><code>number</code></td>
          <td><span class="tag tag-req">обязательно</span></td>
          <td>Сумма в рублях. Минимум 1 ₽.</td>
        </tr>
        <tr>
          <td>description</td>
          <td><code>string</code></td>
          <td><span class="tag tag-opt">необязательно</span></td>
          <td>Описание заказа, отображается покупателю.</td>
        </tr>
        <tr>
          <td>metadata</td>
          <td><code>object</code></td>
          <td><span class="tag tag-opt">необязательно</span></td>
          <td>Произвольный JSON-объект. Возвращается в вебхуке без изменений.</td>
        </tr>
      </table>

      <pre><code><span class="c-kw">POST</span> https://pay.m1plus.pw/api.php
<span class="c-key">Content-Type</span>: application/json
<span class="c-key">X-API-Key</span>: <span class="c-str"><?php echo $apiKey; ?></span>

{
  <span class="c-key">"amount"</span>:      <span class="c-num">1500</span>,
  <span class="c-key">"description"</span>: <span class="c-str">"Заказ #42 — Подписка PRO"</span>,
  <span class="c-key">"metadata"</span>: {
    <span class="c-key">"order_id"</span>:  <span class="c-num">42</span>,
    <span class="c-key">"user_id"</span>:   <span class="c-num">1337</span>,
    <span class="c-key">"plan"</span>:      <span class="c-str">"pro"</span>
  }
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>

      <p style="font-weight:600;margin-bottom:8px;color:#ccc">Ответ (200 OK):</p>
      <pre><code>{
  <span class="c-key">"ok"</span>:          <span class="c-kw">true</span>,
  <span class="c-key">"payment_id"</span>:  <span class="c-str">"a1b2c3d4e5f6..."</span>,
  <span class="c-key">"payment_url"</span>: <span class="c-str">"https://pay.m1plus.pw/pay.php?pid=a1b2c3d4e5f6..."</span>,
  <span class="c-key">"amount"</span>:      <span class="c-num">1500</span>,
  <span class="c-key">"currency"</span>:    <span class="c-str">"RUB"</span>,
  <span class="c-key">"status"</span>:      <span class="c-str">"pending"</span>,
  <span class="c-key">"created_at"</span>:  <span class="c-str">"2025-06-01T12:34:56+03:00"</span>
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>

      <div class="alert alert-info">После получения ответа перенаправьте покупателя на <code>payment_url</code>. После оплаты M1PLUS WALLET отправит вебхук и перенаправит покупателя на ваш <code>success_url</code>.</div>
    </div>

    <!-- STATUS -->
    <div id="status">
      <h2>Статус платежа</h2>
      <h3><span class="method get">GET</span> /api.php?payment_id=...</h3>
      <p>Возвращает текущее состояние платежа по его <code>payment_id</code>.</p>

      <pre><code><span class="c-kw">GET</span> https://pay.m1plus.pw/api.php?payment_id=<span class="c-str">a1b2c3d4e5f6...</span>
<span class="c-key">X-API-Key</span>: <span class="c-str"><?php echo $apiKey; ?></span></code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>

      <p style="font-weight:600;margin-bottom:8px;color:#ccc">Ответ:</p>
      <pre><code>{
  <span class="c-key">"payment_id"</span>:  <span class="c-str">"a1b2c3d4e5f6..."</span>,
  <span class="c-key">"amount"</span>:      <span class="c-num">1500</span>,
  <span class="c-key">"currency"</span>:    <span class="c-str">"RUB"</span>,
  <span class="c-key">"status"</span>:      <span class="c-str">"paid"</span>,
  <span class="c-key">"method"</span>:      <span class="c-str">"yoomoney"</span>,
  <span class="c-key">"description"</span>: <span class="c-str">"Заказ #42"</span>,
  <span class="c-key">"metadata"</span>:    { <span class="c-key">"order_id"</span>: <span class="c-num">42</span> },
  <span class="c-key">"paid_at"</span>:     <span class="c-str">"2025-06-01T12:35:10+03:00"</span>,
  <span class="c-key">"created_at"</span>:  <span class="c-str">"2025-06-01T12:34:56+03:00"</span>
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>

      <table>
        <tr><th>Статус</th><th>Описание</th></tr>
        <tr><td><code>pending</code></td><td>Ожидает оплаты</td></tr>
        <tr><td><code>paid</code></td><td>Успешно оплачен</td></tr>
        <tr><td><code>expired</code></td><td>Истёк (зарезервировано)</td></tr>
      </table>
    </div>

    <!-- WEBHOOKS -->
    <div id="webhooks">
      <h2>Вебхуки</h2>
      <p>После успешной оплаты M1PLUS WALLET отправляет POST-запрос на ваш <code>webhook_url</code>, указанный в настройках магазина.</p>
      <div class="alert alert-info">Настройте <strong>webhook_url</strong> в разделе «Магазин» → редактирование. Убедитесь что ваш сервер возвращает HTTP 200, иначе будет повторная попытка.</div>
    </div>

    <!-- WEBHOOK PAYLOAD -->
    <div id="wh-payload">
      <h3>Структура вебхука</h3>
      <pre><code><span class="c-cm">// POST на ваш webhook_url</span>
{
  <span class="c-key">"event"</span>:       <span class="c-str">"payment.paid"</span>,
  <span class="c-key">"payment_id"</span>:  <span class="c-str">"a1b2c3d4e5f6..."</span>,
  <span class="c-key">"amount"</span>:      <span class="c-num">1500</span>,
  <span class="c-key">"currency"</span>:    <span class="c-str">"RUB"</span>,
  <span class="c-key">"status"</span>:      <span class="c-str">"paid"</span>,
  <span class="c-key">"method"</span>:      <span class="c-str">"yoomoney"</span>,
  <span class="c-key">"description"</span>: <span class="c-str">"Заказ #42"</span>,
  <span class="c-key">"metadata"</span>:    { <span class="c-key">"order_id"</span>: <span class="c-num">42</span> },
  <span class="c-key">"paid_at"</span>:     <span class="c-str">"2025-06-01T12:35:10+03:00"</span>,
  <span class="c-key">"created_at"</span>:  <span class="c-str">"2025-06-01T12:34:56+03:00"</span>
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>

      <p style="font-weight:600;margin-bottom:6px;color:#ccc">Заголовки запроса:</p>
      <table>
        <tr><th>Заголовок</th><th>Описание</th></tr>
        <tr><td><code>X-M1Kassa-Signature</code></td><td>HMAC-SHA256 подпись тела запроса (ключ — ваш API key)</td></tr>
        <tr><td><code>X-M1Kassa-Event</code></td><td>Тип события: <code>payment.paid</code></td></tr>
        <tr><td><code>Content-Type</code></td><td><code>application/json</code></td></tr>
        <tr><td><code>User-Agent</code></td><td><code>M1Kassa-Webhook/1.0</code></td></tr>
      </table>
    </div>

    <!-- WEBHOOK VERIFY -->
    <div id="wh-verify">
      <h3>Верификация подписи</h3>
      <p>Всегда проверяйте подпись входящего вебхука — это гарантирует что запрос пришёл от M1PLUS WALLET, а не от злоумышленника.</p>
      <pre><code><span class="c-cm">// PHP</span>
<span class="c-kw">$payload</span>   = file_get_contents(<span class="c-str">'php://input'</span>);
<span class="c-kw">$signature</span> = <span class="c-kw">$_SERVER</span>[<span class="c-str">'HTTP_X_M1PLUS WALLET_SIGNATURE'</span>] ?? <span class="c-str">''</span>;
<span class="c-kw">$expected</span>  = hash_hmac(<span class="c-str">'sha256'</span>, <span class="c-kw">$payload</span>, <span class="c-str">'<?php echo $apiKey; ?>'</span>);

<span class="c-kw">if</span> (!hash_equals(<span class="c-kw">$expected</span>, <span class="c-kw">$signature</span>)) {
    http_response_code(<span class="c-num">403</span>); exit;
}

<span class="c-kw">$data</span> = json_decode(<span class="c-kw">$payload</span>, <span class="c-kw">true</span>);
<span class="c-cm">// Обрабатываем $data['payment_id'], $data['amount']</span>
http_response_code(<span class="c-num">200</span>); echo <span class="c-str">'OK'</span>;</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
    </div>

    <!-- PHP EXAMPLE -->
    <div id="ex-php">
      <h2>Пример: PHP</h2>
      <pre><code><span class="c-cm">// Создать платёж и перенаправить покупателя</span>
<span class="c-kw">$response</span> = file_get_contents(<span class="c-str">'https://pay.m1plus.pw/api.php'</span>, <span class="c-kw">false</span>,
    stream_context_create([<span class="c-str">'http'</span> => [
        <span class="c-str">'method'</span>  => <span class="c-str">'POST'</span>,
        <span class="c-str">'header'</span>  => implode(<span class="c-str">"\r\n"</span>, [
            <span class="c-str">'Content-Type: application/json'</span>,
            <span class="c-str">'X-API-Key: <?php echo $apiKey; ?>'</span>,
        ]),
        <span class="c-str">'content'</span> => json_encode([
            <span class="c-str">'amount'</span>      => <span class="c-num">1500</span>,
            <span class="c-str">'description'</span> => <span class="c-str">'Заказ #42'</span>,
            <span class="c-str">'metadata'</span>    => [<span class="c-str">'order_id'</span> => <span class="c-num">42</span>],
        ]),
    ]]
));

<span class="c-kw">$data</span> = json_decode(<span class="c-kw">$response</span>, <span class="c-kw">true</span>);
<span class="c-kw">if</span> (<span class="c-kw">$data</span>[<span class="c-str">'ok'</span>]) {
    header(<span class="c-str">'Location: '</span> . <span class="c-kw">$data</span>[<span class="c-str">'payment_url'</span>]); exit;
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
    </div>

    <!-- JS EXAMPLE -->
    <div id="ex-js">
      <h2>Пример: JavaScript / Node.js</h2>
      <pre><code><span class="c-kw">const</span> response = <span class="c-kw">await</span> fetch(<span class="c-str">'https://pay.m1plus.pw/api.php'</span>, {
  method:  <span class="c-str">'POST'</span>,
  headers: {
    <span class="c-str">'Content-Type'</span>: <span class="c-str">'application/json'</span>,
    <span class="c-str">'X-API-Key'</span>:     <span class="c-str">'<?php echo $apiKey; ?>'</span>,
  },
  body: JSON.stringify({
    amount:      <span class="c-num">1500</span>,
    description: <span class="c-str">'Заказ #42'</span>,
    metadata:    { order_id: <span class="c-num">42</span> },
  }),
});

<span class="c-kw">const</span> data = <span class="c-kw">await</span> response.json();
<span class="c-kw">if</span> (data.ok) {
  <span class="c-cm">// Перенаправить пользователя</span>
  window.location.href = data.payment_url;
}

<span class="c-cm">// Верификация вебхука (Node.js)</span>
<span class="c-kw">const</span> crypto  = require(<span class="c-str">'crypto'</span>);
<span class="c-kw">const</span> payload  = JSON.stringify(req.body);
<span class="c-kw">const</span> expected = crypto
  .createHmac(<span class="c-str">'sha256'</span>, <span class="c-str">'<?php echo $apiKey; ?>'</span>)
  .update(payload)
  .digest(<span class="c-str">'hex'</span>);

<span class="c-kw">if</span> (req.headers[<span class="c-str">'x-m1plus-signature'</span>] !== expected) {
  <span class="c-kw">return</span> res.status(<span class="c-num">403</span>).send(<span class="c-str">'Forbidden'</span>);
}</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
    </div>

    <!-- PYTHON EXAMPLE -->
    <div id="ex-python">
      <h2>Пример: Python</h2>
      <pre><code><span class="c-kw">import</span> requests, hmac, hashlib, json

API_KEY = <span class="c-str">'<?php echo $apiKey; ?>'</span>
BASE    = <span class="c-str">'https://pay.m1plus.pw/api.php'</span>

<span class="c-cm"># Создать платёж</span>
r = requests.post(BASE,
    headers={<span class="c-str">'X-API-Key'</span>: API_KEY, <span class="c-str">'Content-Type'</span>: <span class="c-str">'application/json'</span>},
    json={<span class="c-str">'amount'</span>: <span class="c-num">1500</span>, <span class="c-str">'description'</span>: <span class="c-str">'Заказ #42'</span>,
          <span class="c-str">'metadata'</span>: {<span class="c-str">'order_id'</span>: <span class="c-num">42</span>}}
)
data = r.json()
<span class="c-kw">print</span>(data[<span class="c-str">'payment_url'</span>])  <span class="c-cm"># Перенаправить покупателя</span>

<span class="c-cm"># Верификация вебхука (Flask)</span>
<span class="c-kw">from</span> flask <span class="c-kw">import</span> request, abort

payload   = request.get_data()
signature = request.headers.get(<span class="c-str">'X-M1Kassa-Signature'</span>, <span class="c-str">''</span>)
expected  = hmac.new(API_KEY.encode(), payload, hashlib.sha256).hexdigest()

<span class="c-kw">if not</span> hmac.compare_digest(expected, signature):
    abort(<span class="c-num">403</span>)</code><button class="copy-pre" onclick="copyPre(this)">копировать</button></pre>
    </div>

  </main>
</div>

<script>
// Sidebar active link on scroll
var sections = document.querySelectorAll('[id]');
var navLinks  = document.querySelectorAll('.nav-item');
window.addEventListener('scroll', function() {
  var scrollY = window.scrollY + 80;
  sections.forEach(function(s) {
    if (s.offsetTop <= scrollY && s.offsetTop + s.offsetHeight > scrollY) {
      navLinks.forEach(function(l) {
        l.classList.toggle('active', l.getAttribute('href') === '#' + s.id);
      });
    }
  });
}, {passive:true});

// Smooth scroll
document.querySelectorAll('.nav-item').forEach(function(a) {
  a.addEventListener('click', function(e) {
    var target = document.querySelector(this.getAttribute('href'));
    if (target) { e.preventDefault(); target.scrollIntoView({behavior:'smooth', block:'start'}); }
  });
});

function copyEl(id) {
  var el = document.getElementById(id);
  navigator.clipboard.writeText(el.textContent.trim()).then(function() {
    el.style.color = '#4ade80';
    setTimeout(function(){el.style.color='';}, 1400);
  });
}

function copyPre(btn) {
  var pre = btn.closest('pre');
  var text = pre.querySelector('code').innerText;
  navigator.clipboard.writeText(text.trim()).then(function() {
    btn.textContent = 'скопировано';
    setTimeout(function(){btn.textContent='копировать';}, 1400);
  });
}
</script>
</body>
</html>
