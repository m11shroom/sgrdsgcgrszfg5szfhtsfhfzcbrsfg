<?php
/**
 * smtp_mailer.php — отправка писем через SMTP (чистый PHP, без библиотек).
 *
 * SMTP-константы (SMTP_HOST, SMTP_USER, SMTP_PASS и т.д.) берутся из
 * db_credentials.php, который уже подключается в основном конфиге сайта.
 *
 * Использование:
 *   require_once 'smtp_mailer.php';
 *   $r = wm_smtp_send('user@mail.com', 'Тема', 'Текст письма');
 *   if ($r['ok']) { ... } else { echo $r['error']; }
 */

if (!function_exists('wm_smtp_send')) {

function wm_smtp_send($toEmail, $subject, $body, $sender = null) {
    // Если SMTP-константы ещё не определены — пробуем загрузить db_credentials.php (выше корня)
    if (!defined('SMTP_HOST')) {
        $tryPaths = array(
            dirname(__DIR__) . '/db_credentials.php',
            dirname(dirname(__DIR__)) . '/db_credentials.php',
        );
        foreach ($tryPaths as $p) {
            if (file_exists($p)) { require_once $p; break; }
        }
    }

    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return array('ok' => false, 'error' => 'SMTP constants not defined (add SMTP block to db_credentials.php)');
    }

    $host   = SMTP_HOST;
    $port   = SMTP_PORT;
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl';

    if (is_array($sender)) {
        $user   = $sender['user'];
        $pass   = $sender['pass'];
        $from   = isset($sender['from']) ? $sender['from'] : $user;
        $fromNm = isset($sender['from_name']) ? $sender['from_name'] : 'Mail';
    } else {
        $user   = SMTP_USER;
        $pass   = SMTP_PASS;
        $from   = defined('SMTP_FROM') ? SMTP_FROM : $user;
        $fromNm = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Mail';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $errno = 0; $errstr = '';
    $ctx = stream_context_create(array(
        'ssl' => array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        )
    ));

    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return array('ok' => false, 'error' => "Connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 20);

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
    if (!$expect($greet, 220)) { fclose($fp); return array('ok' => false, 'error' => 'No 220 greeting: ' . trim($greet)); }

    $hostName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';

    $cmd('EHLO ' . $hostName);
    $ehlo = $read();
    if (!$expect($ehlo, 250)) {
        $cmd('HELO ' . $hostName);
        $helo = $read();
        if (!$expect($helo, 250)) { fclose($fp); return array('ok' => false, 'error' => 'EHLO/HELO failed: ' . trim($ehlo)); }
    }

    $cmd('AUTH LOGIN');
    $r = $read();
    if (!$expect($r, 334)) { fclose($fp); return array('ok' => false, 'error' => 'AUTH not supported: ' . trim($r)); }

    $cmd(base64_encode($user));
    $r = $read();
    if (!$expect($r, 334)) { fclose($fp); return array('ok' => false, 'error' => 'Username rejected: ' . trim($r)); }

    $cmd(base64_encode($pass));
    $r = $read();
    if (!$expect($r, 235)) { fclose($fp); return array('ok' => false, 'error' => 'Auth failed (wrong password?): ' . trim($r)); }

    $cmd('MAIL FROM:<' . $from . '>');
    $r = $read();
    if (!$expect($r, 250)) { fclose($fp); return array('ok' => false, 'error' => 'MAIL FROM rejected: ' . trim($r)); }

    $cmd('RCPT TO:<' . $toEmail . '>');
    $r = $read();
    if (!$expect($r, 250) && !$expect($r, 251)) { fclose($fp); return array('ok' => false, 'error' => 'RCPT TO rejected: ' . trim($r)); }

    $cmd('DATA');
    $r = $read();
    if (!$expect($r, 354)) { fclose($fp); return array('ok' => false, 'error' => 'DATA rejected: ' . trim($r)); }

    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encFromNm  = '=?UTF-8?B?' . base64_encode($fromNm) . '?=';
    $date = date('r');

    $msg  = "Date: $date\r\n";
    $msg .= "From: $encFromNm <$from>\r\n";
    $msg .= "To: <$toEmail>\r\n";
    $msg .= "Subject: $encSubject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= chunk_split(base64_encode($body));
    $msg .= "\r\n.\r\n";

    fputs($fp, $msg);
    $r = $read();
    if (!$expect($r, 250)) { fclose($fp); return array('ok' => false, 'error' => 'Message rejected: ' . trim($r)); }

    $cmd('QUIT');
    fclose($fp);

    return array('ok' => true, 'error' => '');
}

} // end if !function_exists
