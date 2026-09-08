<?php
declare(strict_types=1);
// M1plus wallet Merchant API
// POST /merchant_api.php
// All responses: JSON

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function apiErr(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function apiOk(array $data): never {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Auth: X-Api-Key header or api_key in body
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true) ?: [];
$apiKey  = $_SERVER['HTTP_X_API_KEY']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_GET['api_key'] ?? $body['api_key'] ?? '');
$apiKey  = str_replace('Bearer ', '', $apiKey);

if (!$apiKey) apiErr('Missing API key. Pass X-Api-Key header or api_key field.', 401);

// Look up merchant
$ms = $db->prepare("SELECT * FROM merchants WHERE public_key=? AND is_active=1");
$ms->execute([$apiKey]); $merchant = $ms->fetch();
if (!$merchant) apiErr('Invalid or inactive API key.', 401);

$endpoint = ltrim($_SERVER['PATH_INFO'] ?? ($_GET['action'] ?? ''), '/');
if (!$endpoint) $endpoint = $_GET['action'] ?? ($body['action'] ?? '');

// ── CREATE PAYMENT ──────────────────────────────────────────────────────────
if ($method === 'POST' && ($endpoint === 'payments' || $endpoint === 'create_payment' || !$endpoint)) {
    $amount      = isset($body['amount']) ? round((float)$body['amount'], 2) : 0;
    $description = trim(substr($body['description'] ?? '', 0, 500));
    $externalId  = trim(substr($body['external_id'] ?? $body['order_id'] ?? '', 0, 200));
    $buyerEmail  = trim($body['buyer_email']  ?? $body['email']  ?? '');
    $buyerName   = trim($body['buyer_name']   ?? $body['name']   ?? '');
    $buyerPhone  = trim($body['buyer_phone']  ?? $body['phone']  ?? '');
    $currency    = strtoupper(trim($body['currency'] ?? 'RUB'));
    $successUrl  = trim($body['success_url'] ?? '');
    $failUrl     = trim($body['fail_url'] ?? '');

    if ($amount < 1) apiErr('amount must be >= 1');
    if (empty($description)) apiErr('description is required');

    // Check external_id unique per merchant
    if ($externalId) {
        $dup = $db->prepare("SELECT id FROM merchant_payments WHERE merchant_id=? AND external_id=?");
        $dup->execute([$merchant['id'], $externalId]);
        if ($dup->fetch()) apiErr('external_id already exists for this merchant');
    }

    $token = bin2hex(random_bytes(24)); // unique payment token
    $meta  = json_encode([
        'success_url' => $successUrl,
        'fail_url'    => $failUrl,
    ]);

    $db->prepare(
        'INSERT INTO merchant_payments
         (merchant_id,external_id,amount,description,currency,buyer_email,buyer_name,buyer_phone,payment_token,status)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $merchant['id'], $externalId ?: null, $amount, $description,
        $currency, $buyerEmail ?: null, $buyerName ?: null, $buyerPhone ?: null,
        $token, 'pending'
    ]);
    $payId = (int)$db->lastInsertId();

    $payUrl = 'https://wallet.m1plus.ru/merchant_pay.php?token=' . $token;

    apiOk([
        'payment_id'  => $payId,
        'token'       => $token,
        'payment_url' => $payUrl,
        'amount'      => $amount,
        'currency'    => $currency,
        'status'      => 'pending',
        'expires_at'  => date('c', strtotime('+24 hours')),
    ]);
}

// ── GET PAYMENT STATUS ──────────────────────────────────────────────────────
if ($method === 'GET' && ($endpoint === 'payment' || $endpoint === 'status')) {
    $token  = $_GET['token']      ?? '';
    $extId  = $_GET['external_id'] ?? $_GET['order_id'] ?? '';
    $payId  = (int)($_GET['payment_id'] ?? 0);

    if ($token) {
        $s = $db->prepare('SELECT * FROM merchant_payments WHERE merchant_id=? AND payment_token=?');
        $s->execute([$merchant['id'], $token]);
    } elseif ($extId) {
        $s = $db->prepare('SELECT * FROM merchant_payments WHERE merchant_id=? AND external_id=?');
        $s->execute([$merchant['id'], $extId]);
    } elseif ($payId) {
        $s = $db->prepare('SELECT * FROM merchant_payments WHERE merchant_id=? AND id=?');
        $s->execute([$merchant['id'], $payId]);
    } else {
        apiErr('Provide token, external_id or payment_id');
    }

    $p = $s->fetch();
    if (!$p) apiErr('Payment not found', 404);

    apiOk([
        'payment_id'   => (int)$p['id'],
        'external_id'  => $p['external_id'],
        'token'        => $p['payment_token'],
        'amount'       => (float)$p['amount'],
        'currency'     => $p['currency'],
        'description'  => $p['description'],
        'status'       => $p['status'],
        'payment_url'  => 'https://wallet.m1plus.ru/merchant_pay.php?token=' . $p['payment_token'],
        'buyer_email'  => $p['buyer_email'],
        'buyer_name'   => $p['buyer_name'],
        'buyer_phone'  => $p['buyer_phone'],
        'created_at'   => $p['created_at'],
        'paid_at'      => $p['paid_at'],
    ]);
}

// ── LIST PAYMENTS ────────────────────────────────────────────────────────────
if ($method === 'GET' && $endpoint === 'payments') {
    $limit  = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? '';

    $where = 'WHERE merchant_id=?';
    $params = [$merchant['id']];
    if ($status) { $where .= ' AND status=?'; $params[] = $status; }

    $s = $db->prepare("SELECT * FROM merchant_payments $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $params[] = $limit; $params[] = $offset;
    $s->execute($params);
    $rows = $s->fetchAll();

    $total = $db->prepare("SELECT COUNT(*) FROM merchant_payments $where");
    $tp = [$merchant['id']]; if ($status) $tp[] = $status;
    $total->execute($tp); $totalCount = (int)$total->fetchColumn();

    apiOk([
        'payments' => array_map(fn($p) => [
            'payment_id'  => (int)$p['id'],
            'external_id' => $p['external_id'],
            'token'       => $p['payment_token'],
            'amount'      => (float)$p['amount'],
            'currency'    => $p['currency'],
            'status'      => $p['status'],
            'created_at'  => $p['created_at'],
            'paid_at'     => $p['paid_at'],
        ], $rows),
        'total'  => $totalCount,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}

// ── REFUND ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $endpoint === 'refund') {
    apiErr('Refunds are processed manually. Contact support.', 501);
}

// ── MERCHANT INFO ────────────────────────────────────────────────────────────
if ($method === 'GET' && $endpoint === 'me') {
    $total = $db->prepare("SELECT COUNT(*) FROM merchant_payments WHERE merchant_id=? AND status='paid'");
    $total->execute([$merchant['id']]); $tc = (int)$total->fetchColumn();
    $rev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM merchant_payments WHERE merchant_id=? AND status='paid'");
    $rev->execute([$merchant['id']]); $rv = (float)$rev->fetchColumn();

    apiOk([
        'merchant_id' => (int)$merchant['id'],
        'name'        => $merchant['name'],
        'is_active'   => (bool)$merchant['is_active'],
        'webhook_url' => $merchant['webhook_url'],
        'total_paid'  => $tc,
        'revenue'     => $rv,
    ]);
}

apiErr("Unknown action '$endpoint'. See docs at https://wallet.m1plus.ru/api_docs.php", 404);
