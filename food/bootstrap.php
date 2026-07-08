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

$pdo->exec("CREATE TABLE IF NOT EXISTS activities (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    activity   VARCHAR(160) NOT NULL,
    minutes    INT          NOT NULL,
    notes      TEXT         NULL,
    done_at    DATETIME     NOT NULL,
    created_by INT          NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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

// Nutrition: a per-ingredient kcal estimate, plus a flag marking values
// the user typed by hand (so the AI never overwrites them). Added after
// the fact, so the column adds are guarded like users.email above.
$hasCalories = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredients' AND COLUMN_NAME = 'calories'"
)->fetchColumn();
if (!$hasCalories) {
    $pdo->exec("ALTER TABLE ingredients
        ADD COLUMN calories        INT       NULL,
        ADD COLUMN calories_manual TINYINT   NOT NULL DEFAULT 0");
}

// Cache of estimates, keyed by a hash of the ingredient description, so the
// same ingredient is never sent to the API twice and totals never drift.
$pdo->exec("CREATE TABLE IF NOT EXISTS nutrition_cache (
    key_hash   CHAR(64) PRIMARY KEY,
    kcal       INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

/**
 * Estimate calories for a list of ingredients.
 *
 * Checks the local nutrition_cache first and only sends the cache misses to
 * Gemini — in a single batched call. Results are written back to the cache.
 * Anything the API can't answer (or any failure at all) comes back as null,
 * so callers can always save the meal regardless.
 *
 * @param array $items  Each: ['name'=>string, 'quantity'=>?string, 'preparation'=>?string]
 * @return array        Parallel array (keys 0..n-1) of int kcal or null.
 */
function estimate_calories_batch(array $items): array {
    global $pdo;

    $items = array_values($items);
    $out   = array_fill(0, count($items), null);
    if (!$items) return $out;

    // Stable cache key per ingredient (quantity + name + preparation).
    $keys = [];
    foreach ($items as $i => $it) {
        $norm = strtolower(trim(
            ($it['quantity'] ?? '') . '|' . ($it['name'] ?? '') . '|' . ($it['preparation'] ?? '')
        ));
        $keys[$i] = hash('sha256', $norm);
    }

    // 1) Look up what we already know.
    $cached      = [];
    $uniqueKeys  = array_values(array_unique($keys));
    if ($uniqueKeys) {
        $in = implode(',', array_fill(0, count($uniqueKeys), '?'));
        $st = $pdo->prepare("SELECT key_hash, kcal FROM nutrition_cache WHERE key_hash IN ($in)");
        $st->execute($uniqueKeys);
        foreach ($st->fetchAll() as $row) $cached[$row['key_hash']] = (int)$row['kcal'];
    }

    $misses = []; // original index => item
    foreach ($items as $i => $it) {
        if (isset($cached[$keys[$i]])) $out[$i] = $cached[$keys[$i]];
        else                          $misses[$i] = $it;
    }
    if (!$misses) return $out;

    // 2) Ask Gemini for the misses (one call).
    $estimated = gemini_estimate_calories(array_values($misses));
    if ($estimated === null) return $out; // API unavailable → leave them null

    // 3) Map results back and cache them.
    $ins = $pdo->prepare(
        "INSERT INTO nutrition_cache (key_hash, kcal) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE kcal = VALUES(kcal)"
    );
    foreach (array_keys($misses) as $pos => $i) {
        $kcal = $estimated[$pos] ?? null;
        if ($kcal === null) continue;
        $kcal    = max(0, (int)$kcal);
        $out[$i] = $kcal;
        try { $ins->execute([$keys[$i], $kcal]); } catch (Throwable $e) { /* cache write is best-effort */ }
    }
    return $out;
}

/**
 * Low-level Gemini call. Given a list of ingredients, returns a parallel
 * array of integer kcal (same order), or null if the API can't be reached
 * or the response can't be parsed.
 */
function gemini_estimate_calories(array $items): ?array {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') return null;
    if (!function_exists('curl_init')) return null;

    $lines = [];
    foreach ($items as $n => $it) {
        $qty  = trim((string)($it['quantity'] ?? ''));
        $prep = trim((string)($it['preparation'] ?? ''));
        $desc = ($qty ? $qty . ' ' : '') . trim((string)($it['name'] ?? '')) . ($prep ? ", $prep" : '');
        $lines[] = ($n + 1) . '. ' . $desc;
    }

    $prompt =
        "Estimate the food energy in kilocalories (kcal) for each item below.\n" .
        "If no quantity is given, assume one typical serving.\n" .
        "Return ONLY a JSON array of integers — one per item, in the same order, no units, no text.\n\n" .
        implode("\n", $lines);

    $body = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
        ],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode(GEMINI_MODEL) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code !== 200) return null;

    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) return null;

    $nums = json_decode($text, true);
    if (!is_array($nums)) return null;

    return array_map(fn($v) => is_numeric($v) ? (int)$v : null, $nums);
}
