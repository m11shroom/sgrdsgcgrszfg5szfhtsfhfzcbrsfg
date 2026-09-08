<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid    = (int)$_SESSION['user_id'];
$txId   = (int)($_GET['id'] ?? 0);
$isAdmin= !empty($_SESSION['is_admin']);
$db     = getDB();

// Fetch transaction — user sees own, admin sees all
if ($isAdmin) {
    $s = $db->prepare('SELECT t.*, u.username FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=?');
    $s->execute([$txId]);
} else {
    $s = $db->prepare('SELECT t.*, u.username FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.user_id=?');
    $s->execute([$txId, $uid]);
}
$tx = $s->fetch();
if (!$tx) { http_response_code(404); echo 'Not found'; exit; }

// Generate PDF using FPDF (pure PHP, no dependencies)
// We'll use HTML-to-PDF via a PHP approach

$signatureB64 = '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';

// Mask helpers
function maskCard(string $s): string {
    $s = preg_replace('/\D/', '', $s);
    if (strlen($s) >= 8) return substr($s,0,4) . str_repeat('*', strlen($s)-8) . substr($s,-4);
    return $s;
}
function maskPhone(string $s): string {
    $digits = preg_replace('/\D/', '', $s);
    if (strlen($digits) >= 7) {
        $country = substr($digits, 0, strlen($digits)-10) ?: '7';
        $area    = substr($digits, -10, 3);
        $last2   = substr($digits, -2);
        return '+' . $country . $area . '*****' . $last2;
    }
    return $s;
}

$typeLabels = [
    'card'         => 'Перевод на карту',
    'sbp'          => 'Перевод СБП',
    'user_out'     => 'Перевод пользователю',
    'user_in'      => 'Входящий перевод',
    'payment_link' => 'Платёжная ссылка',
    'payment_sent' => 'Оплата по ссылке',
];
$statusLabels = ['processing'=>'В ОБРАБОТКЕ','completed'=>'ВЫПОЛНЕН','declined'=>'ОТКЛОНЁН'];
$statusColors = ['processing'=>'#B45309','completed'=>'#065F46','declined'=>'#7F1D1D'];
$statusBg     = ['processing'=>'#FEF3C7','completed'=>'#D1FAE5','declined'=>'#FEE2E2'];

$typeLabel   = $typeLabels[$tx['type']??''] ?? strtoupper($tx['type']??'');
$statusLabel = $statusLabels[$tx['status']??''] ?? strtoupper($tx['status']??'');
$statusColor = $statusColors[$tx['status']??''] ?? '#444';
$statusBgCol = $statusBg[$tx['status']??''] ?? '#f0f0f0';

$dest = '—';
if (($tx['type']??'') === 'card' && !empty($tx['card_number'])) $dest = maskCard($tx['card_number']);
elseif (($tx['type']??'') === 'sbp' && !empty($tx['phone'])) $dest = maskPhone($tx['phone']);

$amount     = number_format((float)$tx['amount'], 2, '.', ' ');
$commission = (float)($tx['commission'] ?? 0);
$refId      = str_pad((string)$tx['id'], 8, '0', STR_PAD_LEFT);

try {
    $dt = new DateTime($tx['created_at']);
    $dateStr = $dt->format('d.m.Y  H:i:s');
} catch (\Throwable $e) { $dateStr = $tx['created_at']; }

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Чек №<?php echo $refId; ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f0f0f0;font-family:'DM Sans',sans-serif;padding:24px 16px;print-color-adjust:exact;-webkit-print-color-adjust:exact}
.receipt{max-width:520px;margin:0 auto;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.12)}

/* HEADER */
.hdr{background:#0a0a0a;padding:24px 28px 22px;display:flex;justify-content:space-between;align-items:flex-start}
.bank-name{font-family:'Space Mono',monospace;font-size:1.05rem;font-weight:700;color:#fff;letter-spacing:.06em}
.bank-url{font-family:'Space Mono',monospace;font-size:.6rem;color:#555;margin-top:3px;letter-spacing:.08em}
.doc-type{text-align:right}
.doc-title{font-family:'Space Mono',monospace;font-size:.7rem;color:#fff;letter-spacing:.18em;font-weight:700}
.doc-sub{font-family:'Space Mono',monospace;font-size:.52rem;color:#444;letter-spacing:.14em;margin-top:3px}

/* META */
.meta{background:#111;padding:12px 28px;display:flex;justify-content:space-between;align-items:center}
.meta-ref{font-family:'Space Mono',monospace;font-size:.62rem;color:#666;letter-spacing:.1em}
.meta-date{font-family:'Space Mono',monospace;font-size:.62rem;color:#666;letter-spacing:.08em}
.status-pill{display:inline-block;padding:4px 12px;border-radius:20px;font-family:'Space Mono',monospace;font-size:.52rem;font-weight:700;letter-spacing:.14em;color:<?php echo $statusColor; ?>;background:<?php echo $statusBgCol; ?>}

/* AMOUNT */
.amount-block{padding:24px 28px 20px;border-bottom:1px solid #f0f0f0}
.amt-lbl{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.26em;color:#bbb;margin-bottom:6px}
.amt-val{font-family:'Space Mono',monospace;font-size:2.4rem;font-weight:700;color:#0a0a0a;letter-spacing:.02em}
.amt-currency{font-size:1.3rem;color:#444}
<?php if ($commission > 0): ?>
.amt-comm{font-size:.75rem;color:#bbb;margin-top:4px}
<?php endif; ?>

/* DETAILS */
.details{padding:8px 28px 16px}
.detail-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f5f5f5}
.detail-row:last-child{border-bottom:none}
.d-lbl{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.16em;color:#bbb;text-transform:uppercase;padding-right:12px;flex-shrink:0}
.d-val{font-size:.82rem;font-weight:500;color:#222;text-align:right;word-break:break-all}
.d-val.masked{font-family:'Space Mono',monospace;font-size:.78rem;letter-spacing:.04em}

/* SIGNATURE */
.sig-block{margin:0 28px 24px;padding:16px 20px;background:#fafafa;border:1px solid #efefef;border-radius:12px}
.sig-lbl{font-family:'Space Mono',monospace;font-size:.52rem;letter-spacing:.2em;color:#bbb;margin-bottom:10px}
.sig-row{display:flex;align-items:flex-end;justify-content:space-between}
.sig-img-wrap img{max-width:120px;max-height:55px;filter:contrast(1.1)}
.sig-name-block{text-align:right}
.sig-name{font-size:.82rem;font-weight:600;color:#222}
.sig-title{font-family:'Space Mono',monospace;font-size:.52rem;color:#888;letter-spacing:.1em;margin-top:3px}

/* FOOTER */
.footer{background:#f8f8f8;padding:12px 28px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #ebebeb}
.footer-txt{font-family:'Space Mono',monospace;font-size:.48rem;color:#ccc;letter-spacing:.08em}

/* PRINT BUTTON */
.print-bar{max-width:520px;margin:16px auto 0;display:flex;gap:10px}
.print-btn{flex:1;background:#0a0a0a;color:#fff;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:500;padding:12px;cursor:pointer;transition:background .2s}
.print-btn:hover{background:#222}
.back-btn{flex:1;background:#fff;color:#555;border:1px solid #ddd;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:.85rem;padding:12px;cursor:pointer;text-decoration:none;text-align:center;transition:all .2s}
.back-btn:hover{border-color:#aaa;color:#222}

@media print{
  body{background:#fff;padding:0}
  .receipt{box-shadow:none;border-radius:0;max-width:100%}
  .print-bar{display:none}
}
</style>
</head>
<body>

<div class="receipt">
  <!-- HEADER -->
  <div class="hdr">
    <div>
      <div class="bank-name">M1PLUS WALLET</div>
      <div class="bank-url">wallet.m1plus.ru</div>
    </div>
    <div class="doc-type">
      <div class="doc-title">ПЛАТЁЖНЫЙ ЧЕК</div>
      <div class="doc-sub">PAYMENT RECEIPT</div>
    </div>
  </div>

  <!-- META -->
  <div class="meta">
    <div class="meta-ref">№ <?php echo $refId; ?></div>
    <span class="status-pill"><?php echo $statusLabel; ?></span>
    <div class="meta-date"><?php echo $dateStr; ?></div>
  </div>

  <!-- AMOUNT -->
  <div class="amount-block">
    <div class="amt-lbl">СУММА ОПЕРАЦИИ</div>
    <div class="amt-val"><?php echo $amount; ?> <span class="amt-currency">₽</span></div>
    <?php if ($commission > 0): ?>
    <div class="amt-comm">+ <?php echo number_format($commission,2,'.',' '); ?> ₽ комиссия</div>
    <?php endif; ?>
  </div>

  <!-- DETAILS -->
  <div class="details">
    <div class="detail-row">
      <div class="d-lbl">Плательщик</div>
      <div class="d-val">@<?php echo htmlspecialchars($tx['username']??'—'); ?></div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">Вид операции</div>
      <div class="d-val"><?php echo $typeLabel; ?></div>
    </div>
    <?php if ($dest !== '—'): ?>
    <div class="detail-row">
      <div class="d-lbl"><?php echo ($tx['type']??'') === 'card' ? 'Карта получателя' : (($tx['type']??'') === 'sbp' ? 'Телефон (СБП)' : 'Получатель'); ?></div>
      <div class="d-val masked"><?php echo htmlspecialchars($dest); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($tx['bank_name'])): ?>
    <div class="detail-row">
      <div class="d-lbl">Банк</div>
      <div class="d-val"><?php echo htmlspecialchars($tx['bank_name']); ?></div>
    </div>
    <?php endif; ?>
    <div class="detail-row">
      <div class="d-lbl">Комиссия</div>
      <div class="d-val"><?php echo $commission > 0 ? number_format($commission,2,'.',' ').' ₽' : 'Без комиссии'; ?></div>
    </div>
    <div class="detail-row">
      <div class="d-lbl">ID транзакции</div>
      <div class="d-val" style="font-family:'Space Mono',monospace;font-size:.72rem"># <?php echo $refId; ?></div>
    </div>
  </div>

  <!-- SIGNATURE -->
  <div class="sig-block">
    <div class="sig-lbl">ПОДПИСЬ УПОЛНОМОЧЕННОГО ЛИЦА</div>
    <div class="sig-row">
      <div class="sig-img-wrap">
        <img src="data:image/png;base64,<?php echo $signatureB64; ?>" alt="Подпись">
      </div>
      <div class="sig-name-block">
        <div class="sig-name">Сухоставцев Марк Олегович</div>
        <div class="sig-title">CEO · M1plus wallet</div>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="footer">
    <div class="footer-txt">Электронный документ · Сформирован: <?php echo date('d.m.Y H:i:s'); ?></div>
    <div class="footer-txt">M1PLUS WALLET</div>
  </div>
</div>

<div class="print-bar">
  <button class="print-btn" onclick="window.print()">🖨 Распечатать / Сохранить PDF</button>
  <a href="dashboard.php" class="back-btn">← Назад</a>
</div>

</body>
</html>
