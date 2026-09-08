<?php
declare(strict_types=1);

// ─── DIDIT CREDENTIALS ───────────────────────────────────────────────────────
define('DIDIT_APP_ID',         '6b74450a-cc54-4c3f-aebc-e4ab6ba64c37');
define('DIDIT_API_KEY',        '1ivjvwj4Rhxw3pLaOGcK2xCbjoKK4ors_v3LbpMXiFU');
// WEBHOOK_SECRET — скопировать из business.didit.me → Settings → Webhook Secret Key
define('DIDIT_WEBHOOK_SECRET', 'y9cCPHM0MsqPoxR2vWiXR2IJ7gIP4HXvPxlH2rcI6Mw');
// WORKFLOW_ID — скопировать из business.didit.me → Workflows → твой workflow → ID
define('DIDIT_WORKFLOW_ID',    '6a008971-a3fd-49ea-b34a-2791b42cb188');
define('DIDIT_BASE_URL',       'https://verification.didit.me');
define('DIDIT_CALLBACK_URL',   'https://wallet.m1plus.ru/didit_callback.php');

function diditCreateSession(int $userId, string $userEmail = ''): array {
    if (!DIDIT_WORKFLOW_ID) return ['error' => 'DIDIT_WORKFLOW_ID не указан в didit.php'];

    $payload = [
        'workflow_id' => DIDIT_WORKFLOW_ID,
        'vendor_data' => (string)$userId,
        'callback'    => DIDIT_CALLBACK_URL,
        'language'    => 'ru',
    ];
    if ($userEmail) {
        $payload['contact_details'] = ['email' => $userEmail, 'send_notification_emails' => false];
    }

    $ch = curl_init(DIDIT_BASE_URL . '/v3/session/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . DIDIT_API_KEY],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'cURL: ' . $err];
    if (!$resp) return ['error' => 'Пустой ответ от Didit'];
    $data = json_decode($resp, true);
    if (!$data) return ['error' => 'Неверный JSON от Didit'];
    if (!in_array($status, [200, 201])) {
        return ['error' => 'Didit ' . $status . ': ' . ($data['detail'] ?? $data['message'] ?? json_encode($data))];
    }
    return $data;
}

function diditVerifyWebhook(string $rawBody, array $headers): bool {
    $secret    = DIDIT_WEBHOOK_SECRET;
    $sigSimple = $headers['x-signature-simple'] ?? '';
    $sigV2     = $headers['x-signature-v2']     ?? '';
    $sigRaw    = $headers['x-signature']         ?? '';
    $timestamp = $headers['x-timestamp']         ?? '';

    if ($timestamp && abs(time() - (int)$timestamp) > 300) return false;

    if ($sigSimple) {
        $p   = json_decode($rawBody, true) ?? [];
        $msg = ($p['session_id'] ?? '') . '|' . ($p['status'] ?? '') . '|' . ($p['created_at'] ?? '');
        if (hash_equals(hash_hmac('sha256', $msg, $secret), $sigSimple)) return true;
    }
    if ($sigV2) {
        $p = json_decode($rawBody, true);
        if ($p !== null) {
            $re = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (hash_equals(hash_hmac('sha256', $re, $secret), $sigV2)) return true;
        }
    }
    if ($sigRaw) {
        if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $sigRaw)) return true;
    }
    if (!$sigSimple && !$sigV2 && !$sigRaw) return true;
    return false;
}