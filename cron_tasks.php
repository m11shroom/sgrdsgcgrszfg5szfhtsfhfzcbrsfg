<?php
/**
 * M1plus wallet - Cron Tasks
 * Run automated checks and notifications
 * 
 * Add to crontab:
 * * * * * * /usr/bin/php /var/www/m1plus/cron_tasks.php >> /var/log/m1plus-cron.log 2>&1
 * 
 * Run every minute for wallet polling and daily checks
 */

require_once 'config.php';
require_once 'db.php';
require_once 'delivery_mailer.php';

class CronTasks {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Run all scheduled tasks
     */
    public function runAll() {
        $this->checkWalletTransactions();
        $this->checkDailyDeliveries();
    }

    /**
     * Poll all connected wallets for new transactions
     * This simulates the "every 2 seconds" check for users with open wallet
     */
    public function checkWalletTransactions() {
        $conn = $this->db->connect();

        // Get all active wallet bindings
        $stmt = $conn->prepare(
            'SELECT id, yoo_wallet, yoo_access_token, user_id 
             FROM wallet_bindings 
             WHERE yoo_wallet IS NOT NULL AND yoo_access_token IS NOT NULL
             LIMIT 100'
        );
        $stmt->execute();
        $result = $stmt->get_result();

        $checked = 0;
        $credited = 0;

        while ($binding = $result->fetch_assoc()) {
            if ($this->checkWalletBalance($conn, $binding)) {
                $credited++;
            }
            $checked++;
        }

        $stmt->close();
        $conn->close();

        error_log("Wallet check: {$checked} wallets checked, {$credited} credits processed");
    }

    /**
     * Check single wallet balance via YooMoney API
     */
    private function checkWalletBalance($conn, $binding) {
        // TODO: Call YooMoney API
        // For demo: return false
        return false;
    }

    /**
     * Check and send daily delivery notifications
     */
    public function checkDailyDeliveries() {
        $count = DeliveryMailer::checkDailyDeliveries();
        error_log("Daily delivery check: {$count} notifications sent");
    }
}

// Execute
try {
    $cron = new CronTasks();
    $cron->runAll();
} catch (Exception $e) {
    error_log("Cron error: " . $e->getMessage());
    exit(1);
}
