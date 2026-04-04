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

// ---- Send email notification ----
$subject  = 'New contact form submission – ' . SITE_NAME;
$bodyText = "You have a new enquiry from the " . SITE_NAME . " website.\n\n"
          . "Name:    {$name}\n"
          . "Company: {$company}\n"
          . "Email:   {$email}\n"
          . "Service: {$service}\n\n"
          . "Message:\n{$message}\n\n"
          . "---\nSubmitted: " . date('Y-m-d H:i:s') . "\nIP: {$ip}";

$headers  = "From: no-reply@powervantagesolutions.com\r\n"
          . "Reply-To: {$email}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "X-Mailer: PHP/" . phpversion();

mail(NOTIFY_EMAIL, $subject, $bodyText, $headers);

// ---- Success ----
echo json_encode(['success' => true]);
