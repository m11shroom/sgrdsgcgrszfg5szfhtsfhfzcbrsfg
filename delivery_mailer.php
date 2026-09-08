<?php
/**
 * M1plus wallet - Delivery Email Service
 * Sends order and representative info to user on delivery day
 */

require_once 'config.php';
require_once 'db.php';

class DeliveryMailer {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Send delivery notification email
     */
    public function sendDeliveryNotification($order_id) {
        $conn = $this->db->connect();

        // Get order and user info
        $stmt = $conn->prepare(
            'SELECT o.*, u.email, u.name, t.name as template_name
             FROM card_orders o
             LEFT JOIN users u ON o.user_id = u.id
             LEFT JOIN card_templates t ON o.card_template_id = t.id
             WHERE o.id = ?'
        );
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || !$order['email']) {
            error_log("Cannot send delivery email: order not found or no email");
            return false;
        }

        $to = $order['email'];
        $subject = "🚚 Ваша карта " . BRAND_SHORT . " будет доставлена сегодня!";

        $html = $this->buildDeliveryEmail($order);

        $headers = [
            'From: ' . MAIL_FROM,
            'Reply-To: ' . ADMIN_EMAIL,
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: ' . BRAND_FULL
        ];

        return mail($to, $subject, $html, implode("\r\n", $headers));
    }

    /**
     * Build HTML email content
     */
    private function buildDeliveryEmail($order) {
        $user_name = explode(' ', $order['name'])[0] ?? 'Пользователь';
        $rep_name = $order['representative_name'] ?? 'Представитель';
        $rep_phone = $order['representative_phone'] ?? 'Контакт не указан';
        $address = $order['delivery_address'];
        $date = date('d.m.Y', strtotime($order['delivery_date_actual']));

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; padding: 30px; border-radius: 12px; text-align: center; }
        .content { background: #f5f5f5; padding: 30px; margin-top: 20px; border-radius: 12px; }
        .info-block { background: white; padding: 20px; margin: 15px 0; border-left: 4px solid #7c3aed; border-radius: 8px; }
        .info-label { color: #7c3aed; font-weight: bold; font-size: 12px; text-transform: uppercase; }
        .info-value { font-size: 16px; margin-top: 8px; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 30px; }
        .btn { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>🎉 Ваша пластиковая карта прибывает!</h2>
            <p>Доставка сегодня, {$date}</p>
        </div>

        <div class="content">
            <p>Привет, {$user_name}!</p>
            <p>Отличные новости! Ваша пластиковая карта <strong>{$order['template_name']}</strong> будет доставлена к вам сегодня.</p>

            <div class="info-block">
                <div class="info-label">📍 Адрес доставки</div>
                <div class="info-value">{$address}</div>
            </div>

            <div class="info-block">
                <div class="info-label">👤 Ваш представитель</div>
                <div class="info-value">{$rep_name}</div>
                <div class="info-label" style="margin-top: 15px;">📞 Номер телефона</div>
                <div class="info-value">{$rep_phone}</div>
            </div>

            <div class="info-block">
                <div class="info-label">✅ Номер заказа</div>
                <div class="info-value">{$order['confirmation_code']}</div>
            </div>

            <p style="margin-top: 25px; color: #666;">Представитель позвонит вам перед доставкой, чтобы согласовать удобное время.</p>

            <a href="" class="btn">Отследить статус →</a>
        </div>

        <div class="footer">
            <p>© {date('Y')} {BRAND_FULL} — Цифровая платежная система</p>
            <p>{ADMIN_EMAIL}</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Send daily delivery check (should be run via cron)
     */
    public static function checkDailyDeliveries() {
        $conn = (new Database())->connect();

        // Find orders with delivery date = today and status = confirmed
        $today = date('Y-m-d');
        $status = 'confirmed';

        $stmt = $conn->prepare(
            'SELECT id FROM card_orders 
             WHERE delivery_date_actual = ? AND status = ?'
        );
        $stmt->bind_param('ss', $today, $status);
        $stmt->execute();
        $result = $stmt->get_result();

        $mailer = new self();
        $count = 0;

        while ($row = $result->fetch_assoc()) {
            if ($mailer->sendDeliveryNotification($row['id'])) {
                $count++;

                // Update status to 'shipped'
                $shipped = 'shipped';
                $upd = $conn->prepare('UPDATE card_orders SET status = ? WHERE id = ?');
                $upd->bind_param('si', $shipped, $row['id']);
                $upd->execute();
                $upd->close();
            }
        }

        $stmt->close();
        $conn->close();

        error_log("Daily delivery check completed: {$count} emails sent");
        return $count;
    }
}

// CLI execution (for cron)
if (php_sapi_name() === 'cli') {
    $count = DeliveryMailer::checkDailyDeliveries();
    echo "Sent {$count} delivery notification emails\n";
}
