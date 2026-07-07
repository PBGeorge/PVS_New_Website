<?php
// ============================================================
//  Bootstrap — included by every page.
//  Sets timezone, forces HTTPS, starts a secure session,
//  connects to the database, creates tables on first run,
//  and defines small helpers. You should not need to edit this.
// ============================================================

require __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

// --- Force HTTPS (handles reverse-proxied setups too) ---
if (FORCE_HTTPS) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$isHttps && PHP_SAPI !== 'cli') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

// --- Secure session cookie ---
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => FORCE_HTTPS,
]);
session_start();

// --- Database connection ---
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
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check the credentials in config.php.');
}

// --- Schema (created once; harmless on later runs) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(80)  NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS meals (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    dish_name  VARCHAR(160) NOT NULL,
    location   VARCHAR(20)  NOT NULL DEFAULT 'Home',
    place      VARCHAR(160) NULL,
    eaten_at   DATETIME     NOT NULL,
    notes      TEXT         NULL,
    created_by INT          NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meal_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ingredients (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    meal_id     INT          NOT NULL,
    name        VARCHAR(160) NOT NULL,
    quantity    VARCHAR(80)  NULL,
    preparation VARCHAR(160) NULL,
    position    INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_ing_meal FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add users.email if it's missing (recovery address for password resets).
// Guarded via information_schema so it's idempotent across MySQL versions.
$hasEmail = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
)->fetchColumn();
if (!$hasEmail) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER display_name");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT       NOT NULL,
    token_hash CHAR(64)  NOT NULL,
    expires_at DATETIME  NOT NULL,
    used_at    DATETIME  NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- Helpers ---
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $path): void { header('Location: ' . $path); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            http_response_code(403);
            exit('Your session expired. Go back, reload the page, and try again.');
        }
    }
}

function current_user(): ?array {
    global $pdo;
    if (empty($_SESSION['uid'])) return null;
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_login(): void {
    if (!current_user()) redirect('login.php');
}

function users_exist(): bool {
    global $pdo;
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/**
 * Resolve an export date range from the request into
 * [$from, $to] as 'Y-m-d H:i:s' strings, or [null, null] for "all time".
 * Reads ?range=7|30|90|all|custom plus ?from=&to= (YYYY-MM-DD) for custom.
 * Uses APP_TIMEZONE (already set in this file).
 */
function export_bounds(): array {
    $range = $_GET['range'] ?? 'all';

    if (in_array($range, ['7', '30', '90'], true)) {
        $days = (int)$range;
        $from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $to   = date('Y-m-d 23:59:59');
        return [$from, $to];
    }

    if ($range === 'custom') {
        $f = trim($_GET['from'] ?? '');
        $t = trim($_GET['to'] ?? '');
        $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t);
        if ($ok) {
            if ($f > $t) { [$f, $t] = [$t, $f]; } // tolerate reversed dates
            return [$f . ' 00:00:00', $t . ' 23:59:59'];
        }
    }

    return [null, null]; // all time / unrecognised
}
