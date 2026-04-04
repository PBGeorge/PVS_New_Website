<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once 'db_config.php';

// ---- Sanitise & validate input ----
function clean(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

$name    = clean($_POST['name']    ?? '');
$company = clean($_POST['company'] ?? '');
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$service = clean($_POST['service'] ?? '');
$message = clean($_POST['message'] ?? '');
$ip      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// Required field validation
if (empty($name) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name and message are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}

// ---- Insert into database ----
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    $stmt = $pdo->prepare("
        INSERT INTO contact_submissions (name, company, email, service, message, ip_address)
        VALUES (:name, :company, :email, :service, :message, :ip)
    ");

    $stmt->execute([
        ':name'    => $name,
        ':company' => $company,
        ':email'   => $email,
        ':service' => $service,
        ':message' => $message,
        ':ip'      => substr($ip, 0, 45),
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // Don't expose DB details to the client
    error_log('PVS contact form DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again later.']);
    exit;
}

// ---- Send email notification via SMTP ----
$subject  = 'New contact form submission – ' . SITE_NAME;
$bodyText = "You have a new enquiry from the " . SITE_NAME . " website.\r\n\r\n"
          . "Name:    {$name}\r\n"
          . "Company: {$company}\r\n"
          . "Email:   {$email}\r\n"
          . "Service: {$service}\r\n\r\n"
          . "Message:\r\n{$message}\r\n\r\n"
          . "---\r\nSubmitted: " . date('Y-m-d H:i:s') . "\r\nIP: {$ip}";

smtp_send(NOTIFY_EMAIL, $subject, $bodyText, $email);

/**
 * Send an email via SMTP without any external libraries.
 * Works with cPanel / most shared hosting SMTP servers.
 */
function smtp_send(string $to, string $subject, string $body, string $replyTo = ''): void
{
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    // Build the raw email
    $msgId   = '<' . time() . '.pvs@' . $host . '>';
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
        error_log("PVS SMTP connect failed: {$errStr} ({$errNo})");
        return;
    }

    $read = function() use ($socket): string {
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $res;
    };
    $write = function(string $cmd) use ($socket): void {
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
        error_log("PVS SMTP auth failed: {$authResp}");
        fclose($socket);
        return;
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
}

// ---- Success ----
echo json_encode(['success' => true]);
