<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Each service sets its own origin/rpId constants in config.php
$wa = new WebAuthn(WA_RPID, WA_RPNAME, WA_ORIGIN);
$db = getDB();

/* ── BEGIN AUTH ── */
if ($action === 'begin') {
    $challenge = $wa->generateChallenge();
    $_SESSION['webauthn_auth_challenge'] = $challenge;

    // Get all credential IDs (or for specific user if email given)
    $email = trim($_POST['email'] ?? '');
    $allowList = [];
    if ($email) {
        $u = $db->prepare('SELECT u.id FROM users u WHERE u.email=?');
        $u->execute([$email]);
        $row = $u->fetch();
        if ($row) {
            $s = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=?');
            $s->execute([$row['id']]);
            $allowList = array_map(fn($r)=>['id'=>$r['credential_id'],'type'=>'public-key'], $s->fetchAll());
        }
    }

    echo json_encode([
        'challenge'        => $challenge,
        'rpId'             => WA_RPID,
        'timeout'          => 60000,
        'userVerification' => 'preferred',
        'allowCredentials' => $allowList,
    ]);
    exit;
}

/* ── COMPLETE AUTH ── */
if ($action === 'complete') {
    $challenge = $_SESSION['webauthn_auth_challenge'] ?? '';
    unset($_SESSION['webauthn_auth_challenge']);
    $cred = json_decode($_POST['credential'] ?? '{}', true);

    $credId = $cred['id'] ?? '';
    if (!$credId) { echo json_encode(['error'=>'No credential']); exit; }

    $s = $db->prepare('SELECT wc.*, u.* FROM webauthn_credentials wc JOIN users u ON u.id=wc.user_id WHERE wc.credential_id=?');
    $s->execute([$credId]);
    $row = $s->fetch();
    if (!$row) { echo json_encode(['error'=>'Passkey не найден']); exit; }

    try {
        $newCount = $wa->verifyAssertion($cred, $challenge, $row['public_key'], (int)$row['sign_count']);
        $db->prepare('UPDATE webauthn_credentials SET sign_count=?,last_used=NOW() WHERE id=?')->execute([$newCount,$row['id']]);

        $_SESSION['user_id']  = $row['user_id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['is_admin'] = $row['is_admin'];

        // Ensure bank account
        $db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$row['user_id']]);

        echo json_encode(['ok'=>1, 'redirect'=> $row['is_admin'] ? 'admin.php' : 'dashboard.php']);
    } catch (\Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['error'=>'unknown']);
