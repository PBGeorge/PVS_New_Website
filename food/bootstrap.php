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

// Optional daily macro targets (Dashboard reference lines). Blank/NULL means
// "no target set" — the dashboard simply omits the reference line.
$hasMacroTargets = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'target_protein_g'"
)->fetchColumn();
if (!$hasMacroTargets) {
    $pdo->exec("ALTER TABLE users
        ADD COLUMN target_protein_g DECIMAL(6,1) NULL,
        ADD COLUMN target_fiber_g   DECIMAL(6,1) NULL");
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

// The nutrition_cache table used to memoise Gemini estimates, but stale
// (sometimes wrong) values would then be served forever — estimates now
// always come fresh from the API, so the table is retired.
$pdo->exec("DROP TABLE IF EXISTS nutrition_cache");

// Macros: protein + fiber per ingredient (AI-estimated, display-only).
// Guarded like the columns above.
$hasProtein = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredients' AND COLUMN_NAME = 'protein_g'"
)->fetchColumn();
if (!$hasProtein) {
    $pdo->exec("ALTER TABLE ingredients
        ADD COLUMN protein_g DECIMAL(6,1) NULL,
        ADD COLUMN fiber_g   DECIMAL(6,1) NULL");
}
// Meal type (see meal_type_order(): Breakfast / Morning Snack / Lunch /
// Midday Snack / Dinner) on meals.
$hasMealType = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meals' AND COLUMN_NAME = 'meal_type'"
)->fetchColumn();
if (!$hasMealType) {
    $pdo->exec("ALTER TABLE meals ADD COLUMN meal_type VARCHAR(20) NULL AFTER location");
}

// The "Snack" meal type was renamed to "Morning Snack"; backfill old rows.
// Idempotent (matches nothing once done), so it's harmless on every load.
$pdo->exec("UPDATE meals SET meal_type = 'Morning Snack' WHERE meal_type = 'Snack'");

// Daily meal targets: a per-user calorie target for Breakfast/Lunch/Dinner,
// plus two named "go-to" variants (A/B) per meal used as quick presets when
// logging. One row per user+meal_type, and per user+meal_type+variant.
$pdo->exec("CREATE TABLE IF NOT EXISTS meal_targets (
    user_id     INT         NOT NULL,
    meal_type   VARCHAR(20) NOT NULL,
    target_kcal INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, meal_type),
    CONSTRAINT fk_mt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS meal_variants (
    user_id        INT          NOT NULL,
    meal_type      VARCHAR(20)  NOT NULL,
    variant_letter CHAR(1)      NOT NULL,
    name           VARCHAR(255) NULL,
    kcal           INT          NULL,
    PRIMARY KEY (user_id, meal_type, variant_letter),
    CONSTRAINT fk_mv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- Helpers ---
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Meal types in canonical order — used for the dropdown and for sorting. */
function meal_type_order(): array {
    return ['Breakfast', 'Morning Snack', 'Lunch', 'Midday Snack', 'Dinner'];
}

/** Sort rank for a meal type; untyped meals rank as "Other", activities last. */
function meal_type_rank(?string $type): int {
    static $rank = [
        'Breakfast' => 1, 'Morning Snack' => 2, 'Lunch' => 3,
        'Midday Snack' => 4, 'Dinner' => 5, 'Other' => 6, 'Activity' => 7,
    ];
    return $rank[$type] ?? 6;
}

/**
 * Meal types that carry a daily calorie target, each with its legend dot
 * colour (CSS custom property) and the default target/variants shown until a
 * user saves their own. Order here is the display order on the Account page.
 */
function meal_target_defaults(): array {
    return [
        'Breakfast' => ['dot' => 'gold', 'target' => 450, 'variants' => [
            'A' => ['name' => 'Greek yogurt & granola',      'kcal' => 420],
            'B' => ['name' => 'Veggie omelette & toast',      'kcal' => 480],
        ]],
        'Lunch' => ['dot' => 'blue', 'target' => 650, 'variants' => [
            'A' => ['name' => 'Grilled chicken & rice bowl',  'kcal' => 620],
            'B' => ['name' => 'Lentil soup & flatbread',      'kcal' => 580],
        ]],
        'Dinner' => ['dot' => 'teal', 'target' => 600, 'variants' => [
            'A' => ['name' => 'Salmon, greens & sweet potato', 'kcal' => 610],
            'B' => ['name' => 'Veggie stir-fry & tofu',        'kcal' => 540],
        ]],
    ];
}

/**
 * A user's saved meal targets + variants, merged over meal_target_defaults()
 * so the Account form is always fully populated. Any meal/variant the user
 * hasn't saved yet falls back to the default; a saved row (even a blank name)
 * overrides it. Returns the same shape as meal_target_defaults().
 */
function meal_targets_for(int $userId): array {
    global $pdo;
    $data = meal_target_defaults();

    $st = $pdo->prepare('SELECT meal_type, target_kcal FROM meal_targets WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $row) {
        if (isset($data[$row['meal_type']])) {
            $data[$row['meal_type']]['target'] = (int)$row['target_kcal'];
        }
    }

    $st = $pdo->prepare('SELECT meal_type, variant_letter, name, kcal FROM meal_variants WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $row) {
        $type = $row['meal_type'];
        $letter = $row['variant_letter'];
        if (isset($data[$type]['variants'][$letter])) {
            $data[$type]['variants'][$letter] = [
                'name' => (string)($row['name'] ?? ''),
                'kcal' => $row['kcal'] !== null ? (int)$row['kcal'] : null,
            ];
        }
    }
    return $data;
}

/**
 * Persist all three targets + six variants for a user in one transaction,
 * reading the flat POST shape target[Type] and variant[Type][Letter][name|kcal].
 * Values are clamped to >= 0; a blank kcal is stored as NULL.
 */
function save_meal_targets(int $userId, array $post): void {
    global $pdo;
    $pdo->beginTransaction();
    $upTarget = $pdo->prepare(
        'INSERT INTO meal_targets (user_id, meal_type, target_kcal) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE target_kcal = VALUES(target_kcal)'
    );
    $upVariant = $pdo->prepare(
        'INSERT INTO meal_variants (user_id, meal_type, variant_letter, name, kcal) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), kcal = VALUES(kcal)'
    );
    foreach (meal_target_defaults() as $type => $info) {
        $target = max(0, (int)($post['target'][$type] ?? 0));
        $upTarget->execute([$userId, $type, $target]);
        foreach ($info['variants'] as $letter => $_) {
            $name    = trim((string)($post['variant'][$type][$letter]['name'] ?? ''));
            $kcalRaw = $post['variant'][$type][$letter]['kcal'] ?? '';
            $kcal    = ($kcalRaw === '' ? null : max(0, (int)$kcalRaw));
            $upVariant->execute([$userId, $type, $letter, $name, $kcal]);
        }
    }
    $pdo->commit();
}

/**
 * Persist the optional daily protein/fiber targets (Dashboard reference
 * lines) from the same POST shape as save_meal_targets(): protein_target,
 * fiber_target. A blank or non-numeric value clears the target (NULL),
 * meaning "no reference line" rather than a target of zero.
 */
function save_daily_macro_targets(int $userId, array $post): void {
    global $pdo;
    $protein = trim((string)($post['protein_target'] ?? ''));
    $fiber   = trim((string)($post['fiber_target']   ?? ''));
    $proteinVal = ($protein !== '' && is_numeric($protein)) ? max(0, (float)$protein) : null;
    $fiberVal   = ($fiber   !== '' && is_numeric($fiber))   ? max(0, (float)$fiber)   : null;
    $st = $pdo->prepare('UPDATE users SET target_protein_g = ?, target_fiber_g = ? WHERE id = ?');
    $st->execute([$proteinVal, $fiberVal, $userId]);
}

/**
 * A user's daily calorie target for the Dashboard: the sum of their
 * Breakfast + Lunch + Dinner targets (the same per-meal-type targets
 * configured on the Account page). Always a number (falls back to the
 * meal_target_defaults() sum if the user hasn't saved their own).
 */
function daily_kcal_target(int $userId): int {
    $t = meal_targets_for($userId);
    return (int)($t['Breakfast']['target'] ?? 0) + (int)($t['Lunch']['target'] ?? 0) + (int)($t['Dinner']['target'] ?? 0);
}

/**
 * Daily kcal/protein/fiber totals for a user across [$from, $to] (inclusive,
 * 'Y-m-d' strings), one entry per calendar day — days with nothing logged
 * come back as 0 rather than being skipped, so the Dashboard charts never
 * have to guess at a missing day.
 *
 * Sums whatever is already stored per ingredient (calories/protein_g/
 * fiber_g — manual or AI-estimated, set when the meal was saved); this
 * never re-estimates anything, it only totals existing values. Activities
 * are intentionally not part of this — the Dashboard charts intake only.
 *
 * @return array{labels: string[], dates: string[], kcal: int[], protein: float[], fiber: float[]}
 */
function daily_nutrition_series(int $userId, string $from, string $to): array {
    global $pdo;

    $st = $pdo->prepare("
        SELECT DATE(m.eaten_at) AS day,
               SUM(i.calories)  AS kcal,
               SUM(i.protein_g) AS protein,
               SUM(i.fiber_g)   AS fiber
        FROM meals m
        JOIN ingredients i ON i.meal_id = m.id
        WHERE m.created_by = ? AND DATE(m.eaten_at) BETWEEN ? AND ?
        GROUP BY DATE(m.eaten_at)
    ");
    $st->execute([$userId, $from, $to]);
    $byDay = [];
    foreach ($st->fetchAll() as $row) {
        $byDay[$row['day']] = [
            'kcal'    => $row['kcal']    !== null ? (int)round((float)$row['kcal'])   : 0,
            'protein' => $row['protein'] !== null ? round((float)$row['protein'], 1)  : 0,
            'fiber'   => $row['fiber']   !== null ? round((float)$row['fiber'], 1)    : 0,
        ];
    }

    $labels = []; $dates = []; $kcal = []; $protein = []; $fiber = [];
    $cursor = strtotime($from);
    $end    = strtotime($to);
    while ($cursor <= $end) {
        $day = date('Y-m-d', $cursor);
        $labels[]  = date('D j M', $cursor);
        $dates[]   = $day;
        $kcal[]    = $byDay[$day]['kcal']    ?? 0;
        $protein[] = $byDay[$day]['protein'] ?? 0;
        $fiber[]   = $byDay[$day]['fiber']   ?? 0;
        $cursor    = strtotime('+1 day', $cursor);
    }
    return ['labels' => $labels, 'dates' => $dates, 'kcal' => $kcal, 'protein' => $protein, 'fiber' => $fiber];
}

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
 * Build the diary's day / meal-type grouping and macro totals from raw meal
 * and activity rows. Shared by the Diary page and the printable PDF export
 * so both always render the exact same structure — nothing to drift out of
 * sync.
 *
 * @param array $meals              Rows from `meals`.
 * @param array $activities         Rows from `activities`.
 * @param array $ingredientsByMeal  meal_id => list of ingredient rows.
 * @return array{items: array, byDay: array, mealMacros: array, dayMacros: array, typeOrder: array}
 */
function build_diary_view(array $meals, array $activities, array $ingredientsByMeal): array {
    // Merge meals and activities into a single timeline. Each item carries
    // its kind, the row, and a sortable timestamp so callers can group both
    // by day and render the right card.
    $items = [];
    foreach ($meals as $m) {
        $items[] = ['kind' => 'meal', 'ts' => strtotime($m['eaten_at']), 'id' => (int)$m['id'], 'row' => $m];
    }
    foreach ($activities as $a) {
        $items[] = ['kind' => 'activity', 'ts' => strtotime($a['done_at']), 'id' => (int)$a['id'], 'row' => $a];
    }
    // Day descending (newest first); within a day, time ascending so each day
    // reads morning -> evening once grouped by meal type.
    usort($items, function ($x, $y) {
        $dx = date('Y-m-d', $x['ts']);
        $dy = date('Y-m-d', $y['ts']);
        if ($dx !== $dy) return $dy <=> $dx;                  // day descending
        return $x['ts'] <=> $y['ts'] ?: $x['id'] <=> $y['id']; // within day: ascending
    });

    // Per-meal macro totals (kcal + protein + fiber), summing only ingredients
    // that carry a value, plus a running per-day total. A missing total stays
    // null so we never show a misleading "0".
    $mealMacros = [];
    foreach ($ingredientsByMeal as $mid => $rows) {
        $t   = ['kcal' => 0, 'protein' => 0, 'fiber' => 0];
        $has = ['kcal' => false, 'protein' => false, 'fiber' => false];
        foreach ($rows as $r) {
            foreach (['kcal' => 'calories', 'protein' => 'protein_g', 'fiber' => 'fiber_g'] as $k => $col) {
                if (isset($r[$col]) && $r[$col] !== null && $r[$col] !== '') { $t[$k] += (float)$r[$col]; $has[$k] = true; }
            }
        }
        $mealMacros[$mid] = [
            'kcal'    => $has['kcal']    ? (int)round($t['kcal']) : null,
            'protein' => $has['protein'] ? $t['protein']         : null,
            'fiber'   => $has['fiber']   ? $t['fiber']           : null,
        ];
    }
    $dayMacros = [];
    foreach ($items as $item) {
        if ($item['kind'] !== 'meal') continue;
        $mm = $mealMacros[$item['id']] ?? null;
        if (!$mm) continue;
        $dayKey = date('Y-m-d', $item['ts']);
        if (!isset($dayMacros[$dayKey])) $dayMacros[$dayKey] = ['kcal' => null, 'protein' => null, 'fiber' => null];
        foreach (['kcal', 'protein', 'fiber'] as $k) {
            if ($mm[$k] !== null) $dayMacros[$dayKey][$k] = ($dayMacros[$dayKey][$k] ?? 0) + $mm[$k];
        }
    }

    // Group each day's items by meal type so callers can render proper
    // sub-sections (Breakfast / Lunch / …), with untyped meals under "Other"
    // and activities in their own group. Days and items keep their newest-first
    // order; the types themselves follow the natural meal order below.
    $typeOrder = array_merge(meal_type_order(), ['Other', 'Activity']);
    $byDay = [];
    foreach ($items as $item) {
        $dayKey = date('Y-m-d', $item['ts']);
        if (!isset($byDay[$dayKey])) {
            $byDay[$dayKey] = ['label' => date('l, j M Y', $item['ts']), 'types' => []];
        }
        if ($item['kind'] === 'meal') {
            $type = $item['row']['meal_type'] ?: 'Other';
            if (!in_array($type, $typeOrder, true)) $type = 'Other';
        } else {
            $type = 'Activity';
        }
        $byDay[$dayKey]['types'][$type][] = $item;
    }

    return compact('items', 'byDay', 'mealMacros', 'dayMacros', 'typeOrder');
}

/** "~520 kcal · 31 g protein · 6 g fiber" (skips whichever parts are missing). */
function macro_summary(array $mm): string {
    $bits = [];
    if ($mm['kcal']    !== null) $bits[] = '~' . number_format($mm['kcal']) . ' kcal';
    if ($mm['protein'] !== null) $bits[] = round($mm['protein']) . ' g protein';
    if ($mm['fiber']   !== null) $bits[] = round($mm['fiber']) . ' g fiber';
    return implode(' · ', $bits);
}

/** One ingredient's display line: quantity + name + preparation, plus a muted per-ingredient macro note. */
function ingredient_line(array $ing): string {
    $parts = [];
    if ($ing['quantity'] !== null && $ing['quantity'] !== '') $parts[] = $ing['quantity'];
    $parts[] = $ing['name'];
    $line = e(implode(' ', $parts));
    if ($ing['preparation'] !== null && $ing['preparation'] !== '') {
        $line .= ' <span class="prep">— ' . e($ing['preparation']) . '</span>';
    }
    // Per-ingredient nutrition, in a smaller muted note (skips missing parts).
    $bits = [];
    if (isset($ing['calories'])  && $ing['calories']  !== null && $ing['calories']  !== '') $bits[] = '~' . number_format((int)$ing['calories']) . ' kcal';
    if (isset($ing['protein_g']) && $ing['protein_g'] !== null && $ing['protein_g'] !== '') $bits[] = round((float)$ing['protein_g']) . ' g P';
    if (isset($ing['fiber_g'])   && $ing['fiber_g']   !== null && $ing['fiber_g']   !== '') $bits[] = round((float)$ing['fiber_g']) . ' g fiber';
    if ($bits) {
        $line .= ' <span class="ing-macros">' . e(implode(' · ', $bits)) . '</span>';
    }
    return $line;
}

/**
 * Estimate nutrition (kcal + protein + fiber) for a list of ingredients.
 *
 * Sends everything to Gemini in a single batched call, every time — no
 * local caching, so saves always reflect the latest estimates. Anything
 * the API can't answer (or any failure at all) comes back as nulls, so
 * callers can always save the meal regardless.
 *
 * @param array $items  Each: ['name'=>string, 'quantity'=>?string, 'preparation'=>?string]
 * @return array        Parallel array (keys 0..n-1) of
 *                      ['kcal'=>?int, 'protein'=>?float, 'fiber'=>?float].
 */
function estimate_nutrition_batch(array $items): array {
    $items = array_values($items);
    $blank = ['kcal' => null, 'protein' => null, 'fiber' => null];
    $out   = array_fill(0, count($items), $blank);
    if (!$items) return $out;

    $estimated = gemini_estimate_nutrition($items);
    if ($estimated === null) return $out; // API unavailable → leave them null

    foreach ($items as $i => $it) {
        $m = $estimated[$i] ?? null;
        if (!is_array($m)) continue;
        $out[$i] = [
            'kcal'    => ($m['kcal']    ?? null) !== null ? max(0, (int)$m['kcal'])      : null,
            'protein' => ($m['protein'] ?? null) !== null ? max(0, (float)$m['protein']) : null,
            'fiber'   => ($m['fiber']   ?? null) !== null ? max(0, (float)$m['fiber'])   : null,
        ];
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
        "You are a nutrition estimation assistant. For each food item below, estimate its " .
        "nutrition for the quantity given (assume one typical serving if no quantity is stated).\n" .
        "Item descriptions may be written in English or Romanian, and may mix both languages " .
        "in the same line. Quantities may use abbreviated units in either language, e.g. " .
        "\"g\", \"gr\", \"gram\", \"grame\" all mean grams; \"kg\"/\"kilograme\" kilograms; " .
        "\"ml\" milliliters; \"l\"/\"litri\" liters; \"buc\"/\"bucata\"/\"bucati\" pieces; " .
        "\"lingura\"/\"lingurita\" tablespoon/teaspoon; \"felie\"/\"felii\" slice(s). If the " .
        "quantity is a count (e.g. \"2 oua\", \"3 felii\"), convert using a typical weight for " .
        "a single unit of that food.\n" .
        "Base estimates on standard nutrition reference data (e.g. USDA) for the form described, " .
        "and factor in the stated preparation method (e.g. fried vs. boiled) when it meaningfully " .
        "changes calories, protein, or fiber.\n" .
        "Treat each numbered line as one independent item; do not merge or split items.\n" .
        "For every item, always return all three fields, even when a value is zero:\n" .
        "- kcal: food energy in kilocalories (integer)\n" .
        "- protein_g: protein in grams, rounded to 1 decimal place (use 0 if the food has none or a negligible amount, never omit)\n" .
        "- fiber_g: dietary fiber in grams, rounded to 1 decimal place (use 0 if the food has none or a negligible amount, never omit)\n" .
        "Return ONLY a JSON array of objects, one per item, in the same order, with no explanation or markdown.\n\n" .
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
