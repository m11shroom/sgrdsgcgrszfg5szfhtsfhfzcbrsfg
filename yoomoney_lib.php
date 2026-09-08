<?php
declare(strict_types=1);
// yoomoney_lib.php — общие функции для работы с YooMoney OAuth API

function ym_getConfig(PDO $db): ?array {
    try {
        $row = $db->query("SELECT * FROM yoomoney_oauth WHERE id=1")->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function ym_saveConfig(PDO $db, array $data): void {
    $cur = ym_getConfig($db);
    if ($cur) {
        $sets = []; $vals = [];
        foreach ($data as $k => $v) { $sets[] = "`$k`=?"; $vals[] = $v; }
        $vals[] = 1;
        $db->prepare("UPDATE yoomoney_oauth SET " . implode(',', $sets) . ", updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute($vals);
    } else {
        $data['id'] = 1;
        $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($data)));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO yoomoney_oauth ($cols) VALUES ($ph)")->execute(array_values($data));
    }
}

/* POST-запрос к YooMoney */
function ym_post(string $url, array $params, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => '', // важно: без этого curl не распаковывает gzip/br-ответы, и json_decode падает на "мусоре"
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err     = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return ['error' => 'curl: ' . $err, 'http_code' => $httpCode];
    $json = json_decode($resp, true);
    return is_array($json) ? $json : ['error' => 'bad_json', 'http_code' => $httpCode, 'raw' => substr((string)$resp, 0, 500)];
}

/* Обмен authorization code на access_token */
function ym_exchangeCode(string $code, string $clientId, string $redirectUri, string $clientSecret = ''): array {
    $params = [
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'client_id'    => $clientId,
        'redirect_uri' => $redirectUri,
    ];
    if ($clientSecret !== '') $params['client_secret'] = $clientSecret;
    return ym_post('https://yoomoney.ru/oauth/token', $params);
}

/* Информация о счёте (номер кошелька, баланс) */
function ym_accountInfo(string $token): array {
    return ym_post('https://yoomoney.ru/api/account-info', [], $token);
}

/* История операций; можно фильтровать по label */
function ym_operationHistory(string $token, array $params = []): array {
    return ym_post('https://yoomoney.ru/api/operation-history', $params, $token);
}

/* Найти успешную входящую операцию по метке */
function ym_findPaidOperation(string $token, string $label): ?array {
    $resp = ym_operationHistory($token, ['label' => $label, 'records' => 5]);
    if (empty($resp['operations'])) return null;
    foreach ($resp['operations'] as $op) {
        if (($op['label'] ?? '') === $label
            && ($op['status'] ?? '') === 'success'
            && ($op['direction'] ?? '') === 'in') {
            return $op;
        }
    }
    return null;
}
