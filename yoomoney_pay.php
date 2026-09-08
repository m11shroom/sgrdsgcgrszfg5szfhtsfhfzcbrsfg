<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) { die('Не указан заказ'); }

$db = getDB();

// Получаем заказ и карту
$stmt = $db->prepare("SELECT o.*, pc.id as card_id, pc.yoo_access_token, pc.name as card_name, pc.payment_status
                       FROM card_orders o
                       LEFT JOIN plastic_cards pc ON pc.user_id = o.user_id AND pc.created_at = (SELECT MAX(created_at) FROM plastic_cards WHERE user_id = o.user_id)
                       WHERE o.id = ? AND o.user_id = ?");
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();
if (!$order) { die('Заказ не найден'); }

$price = (float)$order['amount'] ?? 0; // нужно добавить amount в card_orders или брать из шаблона
// Получим цену из шаблона
$stmt = $db->prepare("SELECT price FROM card_templates WHERE id = ?");
$stmt->execute([$order['template_id']]);
$price = (float)$stmt->fetchColumn();

$cardId = $order['card_id'];
$hasToken = !empty($order['yoo_access_token']);

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Оплата карты — M1plus wallet</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#15151f;border-radius:22px;padding:30px;max-width:480px;width:100%;text-align:center}
.card h2{font-size:1.5rem;font-weight:700;margin-bottom:10px}
.card p{color:#8f8fa3;font-size:.95rem;line-height:1.6;margin-bottom:20px}
.btn{background:#8b5cf6;color:#fff;border:none;border-radius:14px;font-family:inherit;font-size:1rem;font-weight:500;padding:14px 30px;cursor:pointer;text-decoration:none;display:inline-block;transition:background .15s}
.btn:hover{background:#7c3aed}
.btn:disabled{opacity:.4;cursor:default}
.status{font-size:.9rem;color:#facc15;margin-top:15px}
.paid{color:#4ade80}
.fail{color:#f87171}
</style>
</head>
<body>
<div class="card">
    <h2>💳 Оплата карты</h2>
    <p>Заказ #<?php echo $orderId; ?><br>Сумма: <strong><?php echo number_format($price, 2, ',', ' '); ?> ₽</strong></p>
    <p style="font-size:.85rem;color:#8f8fa3">Оплата через ЮMoney</p>

    <?php if (!$hasToken): ?>
        <p>Для оплаты необходимо привязать кошелёк ЮMoney.</p>
        <a href="yoomoney_auth.php?connect_card=<?php echo $cardId; ?>&redirect=yoomoney_pay.php?order_id=<?php echo $orderId; ?>" class="btn">Привязать кошелёк</a>
    <?php else: ?>
        <p>Кошелёк привязан, ожидаем поступление платежа...</p>
        <div id="paymentStatus" class="status">Ожидание оплаты...</div>
        <button class="btn" id="checkPaymentBtn" onclick="checkPayment()" style="margin-top:15px;">Проверить сейчас</button>
    <?php endif; ?>
</div>

<script>
<?php if ($hasToken): ?>
var cardId = <?php echo json_encode($cardId); ?>;
var orderId = <?php echo json_encode($orderId); ?>;
var checkInterval = null;

function checkPayment() {
    var statusEl = document.getElementById('paymentStatus');
    statusEl.textContent = 'Проверка...';
    fetch('yoomoney_check_payment.php?card_id=' + cardId + '&order_id=' + orderId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.paid) {
                statusEl.className = 'status paid';
                statusEl.textContent = '✅ Оплата получена! Карта будет доставлена.';
                clearInterval(checkInterval);
                document.getElementById('checkPaymentBtn').disabled = true;
                // Перенаправление на дашборд через 2 сек
                setTimeout(function() { window.location.href = 'dashboard.php'; }, 2000);
            } else {
                statusEl.className = 'status';
                statusEl.textContent = '⏳ Платеж не найден, ожидаем...';
            }
        })
        .catch(function() {
            statusEl.className = 'status fail';
            statusEl.textContent = '❌ Ошибка проверки. Попробуйте позже.';
        });
}

// Запускаем поллинг каждую секунду
checkInterval = setInterval(checkPayment, 1000);
// Первый запуск сразу
checkPayment();
<?php endif; ?>
</script>
</body>
</html>