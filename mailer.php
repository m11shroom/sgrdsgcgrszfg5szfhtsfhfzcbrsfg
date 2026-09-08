<?php
// Simple SMTP mailer — no dependencies
function sendMail(string $to, string $subject, string $body): bool {
    $host   = MAIL_HOST;
    $port   = MAIL_PORT;
    $user   = MAIL_USER;
    $pass   = MAIL_PASS;
    $from   = MAIL_FROM;
    $name   = MAIL_FROM_NAME;

    try {
        $errno = 0; $errstr = '';
        $scheme = ($port === 465) ? 'ssl' : 'tcp';
        $sock = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, 15);
        if (!$sock) {
            error_log("SMTP connect failed: $errstr ($errno)");
            return _fallbackMail($to, $subject, $body, $from, $name);
        }
        stream_set_timeout($sock, 15);

        $read = function() use ($sock) {
            $out = '';
            while ($line = fgets($sock, 512)) {
                $out .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $out;
        };
        $send = function(string $cmd) use ($sock, $read) {
            fwrite($sock, $cmd . "\r\n");
            return $read();
        };

        $read(); // greeting
        $send("EHLO m1plus.pw");
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $resp = $send(base64_encode($pass));
        if (strpos($resp, '235') === false) {
            fclose($sock);
            error_log("SMTP auth failed: $resp");
            return _fallbackMail($to, $subject, $body, $from, $name);
        }

        $send("MAIL FROM:<{$from}>");
        $send("RCPT TO:<{$to}>");
        $send("DATA");

        $boundary = md5(uniqid());
        $msg  = "From: {$name} <{$from}>\r\n";
        $msg .= "To: {$to}\r\n";
        $msg .= "Subject: =?UTF-8?B?".base64_encode($subject)."?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
        $msg .= "\r\n.";
        $send($msg);
        $send("QUIT");
        fclose($sock);
        return true;
    } catch (\Throwable $e) {
        error_log("SMTP exception: ".$e->getMessage());
        return _fallbackMail($to, $subject, $body, $from, $name);
    }
}

function sendBuyerConfirmation(string $to, string $serviceName, float $amount, string $paymentId, string $creatorUsername): bool {
    $subject = "Оплата подтверждена — " . $serviceName;
    $amtStr  = number_format($amount, 2, '.', ' ') . ' ₽';
    $date    = date('d.m.Y H:i');
    $body = <<<HTML
<!DOCTYPE html><html><body style="background:#0a0a0a;font-family:'DM Sans',Arial,sans-serif;margin:0;padding:40px 20px">
<div style="max-width:460px;margin:0 auto;background:#111;border:1px solid #1e1e1e;border-radius:20px;padding:36px">
  <div style="font-family:'Courier New',monospace;font-size:11px;letter-spacing:3px;color:#444;margin-bottom:24px">M1PLUS WALLET</div>

  <div style="font-size:22px;font-weight:600;color:#f0f0f0;margin-bottom:6px">Оплата прошла успешно ✅</div>
  <div style="font-size:14px;color:#666;margin-bottom:28px">Ваш платёж подтверждён</div>

  <div style="background:#0a0a0a;border:1px solid #1e1e1e;border-radius:14px;padding:20px;margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #151515;padding-bottom:10px;margin-bottom:10px">
      <span style="font-size:13px;color:#555">Услуга</span>
      <span style="font-size:13px;color:#ccc;font-weight:500">{$serviceName}</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #151515;padding-bottom:10px;margin-bottom:10px">
      <span style="font-size:13px;color:#555">Продавец</span>
      <span style="font-size:13px;color:#ccc">@{$creatorUsername}</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #151515;padding-bottom:10px;margin-bottom:10px">
      <span style="font-size:13px;color:#555">Сумма</span>
      <span style="font-family:'Courier New',monospace;font-size:15px;color:#a5b4fc;font-weight:bold">{$amtStr}</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #151515;padding-bottom:10px;margin-bottom:10px">
      <span style="font-size:13px;color:#555">Дата</span>
      <span style="font-size:13px;color:#ccc">{$date}</span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span style="font-size:13px;color:#555">ID платежа</span>
      <span style="font-family:'Courier New',monospace;font-size:11px;color:#444">{$paymentId}</span>
    </div>
  </div>

  <a href="https://t.me/markioxs" style="display:block;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:14px;text-align:center;text-decoration:none;color:#f87171;font-size:13px;font-weight:500;margin-bottom:16px">
    ⚠️ Оспорить операцию
  </a>

  <div style="font-size:11px;color:#2a2a2a;line-height:1.7;font-family:'Courier New',monospace">
    Кнопка «Оспорить» свяжет вас с администрацией M1PLUS WALLET.<br>
    Возврат средств рассматривается в течение 72 часов.
  </div>
</div>
</body></html>
HTML;
    return sendMail($to, $subject, $body);
}

function _fallbackMail(string $to, string $subject, string $body, string $from, string $name): bool {
    $headers  = "From: {$name} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

function sendCodeEmail(string $to, string $code, string $reason = 'login'): bool {
    $reasonText = $reason === 'register' ? 'регистрации' : 'входа с нового IP-адреса';
    $subject = "Код подтверждения — M1PLUS WALLET";
    $body = <<<HTML
<!DOCTYPE html><html><body style="background:#0a0a0a;font-family:'DM Sans',Arial,sans-serif;margin:0;padding:40px 20px">
<div style="max-width:440px;margin:0 auto;background:#111;border:1px solid #1e1e1e;border-radius:20px;padding:36px">
  <div style="font-family:'Courier New',monospace;font-size:11px;letter-spacing:3px;color:#444;margin-bottom:24px">M1PLUS WALLET</div>
  <div style="font-size:22px;font-weight:600;color:#f0f0f0;margin-bottom:10px">Код подтверждения</div>
  <div style="font-size:14px;color:#666;margin-bottom:28px;line-height:1.5">Запрошен для {$reasonText}. Код действителен 10 минут.</div>
  <div style="background:#0a0a0a;border:1px solid #222;border-radius:14px;padding:24px;text-align:center;margin-bottom:24px">
    <div style="font-family:'Courier New',monospace;font-size:36px;font-weight:bold;letter-spacing:10px;color:#6366f1">{$code}</div>
  </div>
  <div style="font-size:12px;color:#333;line-height:1.6">Если вы не запрашивали этот код — проигнорируйте письмо.</div>
</div>
</body></html>
HTML;
    return sendMail($to, $subject, $body);
}
