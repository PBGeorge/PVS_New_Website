<?php
// ============================================================
//  Mailer — send a plain-text email over SMTP without any
//  external libraries (same approach as the main site's
//  submit.php). Reads the SMTP_* constants from config.php.
//  Include only where email is actually sent.
// ============================================================

/**
 * Send a plain-text email via SMTP. Returns true on success.
 * Works with cPanel / most shared-hosting SMTP servers
 * (SSL on 465, STARTTLS on 587).
 */
function smtp_send(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    // Build the raw email
    $msgId   = '<' . time() . '.foodlog@' . $host . '>';
    $headers = "Date: " . date('r') . "\r\n"
             . "Message-ID: {$msgId}\r\n"
             . "From: {$fromName} <{$from}>\r\n"
             . "To: {$to}\r\n"
             . ($replyTo ? "Reply-To: {$replyTo}\r\n" : '')
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";
    $message = $headers . "\r\n" . $body;

    $errNo  = 0;
    $errStr = '';

    // Connect (TLS on 587, SSL on 465)
    $prefix = ($port === 465) ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errNo, $errStr, 10);
    if (!$socket) {
        error_log("Food Log SMTP connect failed: {$errStr} ({$errNo})");
        return false;
    }

    $read = function () use ($socket): string {
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $res;
    };
    $write = function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $read(); // 220 greeting

    // EHLO
    $write("EHLO {$host}");
    $ehlo = $read();

    // STARTTLS upgrade (port 587)
    if ($port === 587 && strpos($ehlo, 'STARTTLS') !== false) {
        $write('STARTTLS');
        $read();
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO {$host}");
        $read();
    }

    // Auth
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $authResp = $read();
    if (strpos($authResp, '235') === false) {
        error_log("Food Log SMTP auth failed: {$authResp}");
        fclose($socket);
        return false;
    }

    // Envelope
    $write("MAIL FROM:<{$from}>");
    $read();
    $write("RCPT TO:<{$to}>");
    $read();

    // Data
    $write('DATA');
    $read();
    $write($message . "\r\n.");
    $read();

    $write('QUIT');
    fclose($socket);
    return true;
}
