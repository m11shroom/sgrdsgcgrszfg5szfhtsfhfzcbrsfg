<?php
/**
 * smtp_mailer_attachment.php — отправка письма с файлом-вложением (PDF отчёт).
 * Использует те же SMTP-креды, что и smtp_mailer_bank.php (SMTP_HOST/USER/PASS
 * из db_credentials.php), но позволяет подставить свой "From" (finance@...).
 *
 * ВАЖНО: реально письмо физически отправляется через один SMTP-аккаунт
 * (SMTP_USER/SMTP_PASS). Адрес "From" может отличаться (виден получателю
 * как отправитель), но если почтовый сервер получателя строго проверяет
 * SPF/DKIM для домена в "From" — письмо может попасть в спам. Чтобы From
 * реально был другим доменом без риска спам-фильтров, на сервере должны
 * быть отдельные SMTP-аккаунты для finance@bank.m1shroom.ru и
 * finance@m1plus.pw — тогда передайте их в $senderOverride ниже.
 */

if (!function_exists('wm_smtp_send_attachment')) {

function wm_smtp_send_attachment(
    string $toEmail,
    string $subject,
    string $bodyText,
    string $attachmentData,
    string $attachmentName,
    string $fromEmail,
    string $fromName = 'M1plus wallet',
    ?array $senderOverride = null
): array {
    if (!defined('SMTP_HOST')) {
        foreach ([dirname(__DIR__) . '/db_credentials.php', dirname(dirname(__DIR__)) . '/db_credentials.php'] as $p) {
            if (file_exists($p)) { require_once $p; break; }
        }
    }
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return ['ok' => false, 'error' => 'SMTP constants not defined'];
    }

    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT') ? SMTP_PORT : 465;
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl';
    $user   = $senderOverride['user'] ?? SMTP_USER;
    $pass   = $senderOverride['pass'] ?? SMTP_PASS;

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'error' => "Connect failed: $errstr ($errno)"];
    stream_set_timeout($fp, 30);

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($fp) { fputs($fp, $c . "\r\n"); };
    $expect = function ($resp, $code) { return substr(ltrim($resp), 0, 3) === (string)$code; };

    $greet = $read();
    if (!$expect($greet, 220)) { fclose($fp); return ['ok' => false, 'error' => 'No 220: ' . trim($greet)]; }

    $hostName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd('EHLO ' . $hostName); $ehlo = $read();
    if (!$expect($ehlo, 250)) { $cmd('HELO ' . $hostName); $ehlo = $read(); if (!$expect($ehlo, 250)) { fclose($fp); return ['ok'=>false,'error'=>'EHLO failed']; } }

    $cmd('AUTH LOGIN'); $r = $read();
    if (!$expect($r, 334)) { fclose($fp); return ['ok'=>false,'error'=>'AUTH not supported']; }
    $cmd(base64_encode($user)); $r = $read();
    if (!$expect($r, 334)) { fclose($fp); return ['ok'=>false,'error'=>'Username rejected']; }
    $cmd(base64_encode($pass)); $r = $read();
    if (!$expect($r, 235)) { fclose($fp); return ['ok'=>false,'error'=>'Auth failed: ' . trim($r)]; }

    $cmd('MAIL FROM:<' . $fromEmail . '>'); $r = $read();
    if (!$expect($r, 250)) { fclose($fp); return ['ok'=>false,'error'=>'MAIL FROM rejected: ' . trim($r)]; }
    $cmd('RCPT TO:<' . $toEmail . '>'); $r = $read();
    if (!$expect($r, 250) && !$expect($r, 251)) { fclose($fp); return ['ok'=>false,'error'=>'RCPT TO rejected: ' . trim($r)]; }
    $cmd('DATA'); $r = $read();
    if (!$expect($r, 354)) { fclose($fp); return ['ok'=>false,'error'=>'DATA rejected']; }

    $boundary   = 'm1plus_' . bin2hex(random_bytes(12));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encFromNm  = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $msg  = "Date: " . date('r') . "\r\n";
    $msg .= "From: $encFromNm <$fromEmail>\r\n";
    $msg .= "To: <$toEmail>\r\n";
    $msg .= "Subject: $encSubject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($bodyText)) . "\r\n";

    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: application/pdf; name=\"$attachmentName\"\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "Content-Disposition: attachment; filename=\"$attachmentName\"\r\n\r\n";
    $msg .= chunk_split(base64_encode($attachmentData)) . "\r\n";
    $msg .= "--$boundary--\r\n";

    $msg .= "\r\n.\r\n";
    fputs($fp, $msg);
    $r = $read();
    if (!$expect($r, 250)) { fclose($fp); return ['ok'=>false,'error'=>'Message rejected: ' . trim($r)]; }

    $cmd('QUIT'); fclose($fp);
    return ['ok' => true, 'error' => ''];
}

}
