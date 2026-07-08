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

// Macros: protein + fiber per ingredient (AI-estimated, display-only), plus
// the same two columns on the cache. Guarded like the columns above.
$hasProtein = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredients' AND COLUMN_NAME = 'protein_g'"
)->fetchColumn();
if (!$hasProtein) {
    $pdo->exec("ALTER TABLE ingredients
        ADD COLUMN protein_g DECIMAL(6,1) NULL,
        ADD COLUMN fiber_g   DECIMAL(6,1) NULL");
}
$hasCacheProtein = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nutrition_cache' AND COLUMN_NAME = 'protein_g'"
)->fetchColumn();
if (!$hasCacheProtein) {
    $pdo->exec("ALTER TABLE nutrition_cache
        ADD COLUMN protein_g DECIMAL(6,1) NULL,
        ADD COLUMN fiber_g   DECIMAL(6,1) NULL");
}

// Meal type (Breakfast / Lunch / Dinner / Snack) on meals.
$hasMealType = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meals' AND COLUMN_NAME = 'meal_type'"
)->fetchColumn();
if (!$hasMealType) {
    $pdo->exec("ALTER TABLE meals ADD COLUMN meal_type VARCHAR(20) NULL AFTER location");
}

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
 * Estimate nutrition (kcal + protein + fiber) for a list of ingredients.
 *
 * Checks the local nutrition_cache first and only sends the cache misses to
 * Gemini — in a single batched call. Results are written back to the cache.
 * Anything the API can't answer (or any failure at all) comes back as nulls,
 * so callers can always save the meal regardless.
 *
 * @param array $items  Each: ['name'=>string, 'quantity'=>?string, 'preparation'=>?string]
 * @return array        Parallel array (keys 0..n-1) of
 *                      ['kcal'=>?int, 'protein'=>?float, 'fiber'=>?float].
 */
function estimate_nutrition_batch(array $items): array {
    global $pdo;

    $items = array_values($items);
    $blank = ['kcal' => null, 'protein' => null, 'fiber' => null];
    $out   = array_fill(0, count($items), $blank);
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
        $st = $pdo->prepare("SELECT key_hash, kcal, protein_g, fiber_g FROM nutrition_cache WHERE key_hash IN ($in)");
        $st->execute($uniqueKeys);
        foreach ($st->fetchAll() as $row) {
            $cached[$row['key_hash']] = [
                'kcal'    => $row['kcal']      !== null ? (int)$row['kcal']       : null,
                'protein' => $row['protein_g'] !== null ? (float)$row['protein_g'] : null,
                'fiber'   => $row['fiber_g']   !== null ? (float)$row['fiber_g']   : null,
            ];
        }
    }

    $misses = []; // original index => item
    foreach ($items as $i => $it) {
        if (isset($cached[$keys[$i]])) $out[$i] = $cached[$keys[$i]];
        else                           $misses[$i] = $it;
    }
    if (!$misses) return $out;

    // 2) Ask Gemini for the misses (one call).
    $estimated = gemini_estimate_nutrition(array_values($misses));
    if ($estimated === null) return $out; // API unavailable → leave them null

    // 3) Map results back and cache them.
    $ins = $pdo->prepare(
        "INSERT INTO nutrition_cache (key_hash, kcal, protein_g, fiber_g) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE kcal = VALUES(kcal), protein_g = VALUES(protein_g), fiber_g = VALUES(fiber_g)"
    );
    foreach (array_keys($misses) as $pos => $i) {
        $m = $estimated[$pos] ?? null;
        if (!is_array($m)) continue;
        $kcal    = ($m['kcal']    ?? null) !== null ? max(0, (int)$m['kcal'])      : null;
        $protein = ($m['protein'] ?? null) !== null ? max(0, (float)$m['protein']) : null;
        $fiber   = ($m['fiber']   ?? null) !== null ? max(0, (float)$m['fiber'])   : null;
        if ($kcal === null && $protein === null && $fiber === null) continue;
        $out[$i] = ['kcal' => $kcal, 'protein' => $protein, 'fiber' => $fiber];
        if ($kcal !== null) { // cache requires a kcal value
            try { $ins->execute([$keys[$i], $kcal, $protein, $fiber]); } catch (Throwable $e) { /* best-effort */ }
        }
    }
    return $out;
}

/**
 * Low-level Gemini call. Given a list of ingredients, returns a parallel
 * array (same order) of ['kcal'=>?int,'protein'=>?float,'fiber'=>?float],
 * or null if the API can't be reached or the response can't be parsed.
 */
function gemini_estimate_nutrition(array $items): ?array {
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
        "For each food item below, estimate its nutrition for the quantity given " .
        "(assume one typical serving if no quantity is stated):\n" .
        "- kcal: food energy in kilocalories (integer)\n" .
        "- protein_g: protein in grams\n" .
        "- fiber_g: dietary fiber in grams\n" .
        "Return ONLY a JSON array of objects, one per item, in the same order.\n\n" .
        implode("\n", $lines);

    $body = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'kcal'      => ['type' => 'INTEGER'],
                        'protein_g' => ['type' => 'NUMBER'],
                        'fiber_g'   => ['type' => 'NUMBER'],
                    ],
                    'required'         => ['kcal', 'protein_g', 'fiber_g'],
                    'propertyOrdering' => ['kcal', 'protein_g', 'fiber_g'],
                ],
            ],
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

    // Concatenate the text from every part. Thinking models can return a
    // "thought" part before the answer, so we can't assume it's parts[0].
    $data  = json_decode($resp, true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    foreach ($parts as $p) {
        if (isset($p['text'])) $text .= $p['text'];
    }
    if ($text === '') return null;

    $arr = json_decode($text, true);
    if (!is_array($arr)) {
        // Last resort: pull the first [...] block out of the text.
        if (preg_match('/\[.*\]/s', $text, $m)) $arr = json_decode($m[0], true);
    }
    if (!is_array($arr)) return null;

    $result = [];
    foreach ($arr as $o) {
        if (!is_array($o)) { $result[] = null; continue; }
        $result[] = [
            'kcal'    => isset($o['kcal'])      && is_numeric($o['kcal'])      ? (int)$o['kcal']        : null,
            'protein' => isset($o['protein_g']) && is_numeric($o['protein_g']) ? (float)$o['protein_g'] : null,
            'fiber'   => isset($o['fiber_g'])   && is_numeric($o['fiber_g'])   ? (float)$o['fiber_g']   : null,
        ];
    }
    return $result;
}
