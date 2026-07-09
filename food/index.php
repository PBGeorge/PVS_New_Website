<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

// Load this user's meals with their ingredients, newest first.
$st = $pdo->prepare("
    SELECT m.*
    FROM meals m
    WHERE m.created_by = ?
    ORDER BY m.eaten_at DESC, m.id DESC
");
$st->execute([$me['id']]);
$meals = $st->fetchAll();

$ingredientsByMeal = [];
if ($meals) {
    $ids = array_column($meals, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT * FROM ingredients WHERE meal_id IN ($in) ORDER BY position, id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $ingredientsByMeal[$row['meal_id']][] = $row;
    }
}

// Load this user's activities, newest first.
$st = $pdo->prepare("SELECT * FROM activities WHERE created_by = ? ORDER BY done_at DESC, id DESC");
$st->execute([$me['id']]);
$activities = $st->fetchAll();

// Merge meals and activities into a single timeline, newest first. Each
// item carries its kind, the row, and a sortable timestamp so the diary
// can group both by day and render the right card.
$items = [];
foreach ($meals as $m) {
    $items[] = ['kind' => 'meal', 'ts' => strtotime($m['eaten_at']), 'id' => (int)$m['id'], 'row' => $m];
}
foreach ($activities as $a) {
    $items[] = ['kind' => 'activity', 'ts' => strtotime($a['done_at']), 'id' => (int)$a['id'], 'row' => $a];
}
usort($items, function ($x, $y) {
    return $y['ts'] <=> $x['ts'] ?: $y['id'] <=> $x['id'];
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

// Group each day's items by meal type so the diary can render proper
// sub-sections (Breakfast / Lunch / …), with untyped meals under "Other"
// and activities in their own group. Days and items keep their newest-first
// order; the types themselves follow the natural meal order below.
$typeOrder = ['Breakfast', 'Lunch', 'Dinner', 'Snack', 'Other', 'Activity'];
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

// "~520 kcal · 31 g protein · 6 g fiber" (skips whichever parts are missing).
function macro_summary(array $mm): string {
    $bits = [];
    if ($mm['kcal']    !== null) $bits[] = '~' . number_format($mm['kcal']) . ' kcal';
    if ($mm['protein'] !== null) $bits[] = round($mm['protein']) . ' g protein';
    if ($mm['fiber']   !== null) $bits[] = round($mm['fiber']) . ' g fiber';
    return implode(' · ', $bits);
}

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

$PAGE_TITLE = 'Diary';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Diary</h1>
  <a class="btn" href="add.php">Add entry</a>
</div>

<?php if (!$items): ?>
  <div class="empty card">
    <p>Nothing logged yet.</p>
    <a class="btn" href="add.php">Log your first entry</a>
  </div>
<?php else: ?>
  <?php foreach ($byDay as $dayKey => $day): ?>
    <?php
      $dt = $dayMacros[$dayKey] ?? null;
      $dayLabel = e($day['label']);
      if ($dt && ($dt['kcal'] !== null || $dt['protein'] !== null || $dt['fiber'] !== null)) {
          $dayLabel .= ' <span class="day-kcal">' . e(macro_summary($dt)) . '</span>';
      }
    ?>
    <div class="day-label"><?= $dayLabel ?></div>

    <?php foreach ($typeOrder as $type):
        if (empty($day['types'][$type])) continue;
        $group = $day['types'][$type];
        // kcal subtotal for the group heading (meals only).
        $sub = 0; $hasSub = false;
        foreach ($group as $it) {
            if ($it['kind'] !== 'meal') continue;
            $k = $mealMacros[$it['id']]['kcal'] ?? null;
            if ($k !== null) { $sub += $k; $hasSub = true; }
        }
    ?>
    <div class="type-head">
      <span class="type-name"><?= e($type) ?></span>
      <?php if ($hasSub): ?><span class="type-kcal">~<?= number_format($sub) ?> kcal</span><?php endif; ?>
    </div>
    <div class="day-group">
      <?php foreach ($group as $item): ?>
        <?php if ($item['kind'] === 'meal'):
            $m = $item['row'];
            $ings = $ingredientsByMeal[$m['id']] ?? [];
            $isRestaurant = strcasecmp($m['location'], 'Restaurant') === 0;
            $mm = $mealMacros[$m['id']] ?? ['kcal'=>null,'protein'=>null,'fiber'=>null];
        ?>
        <article class="meal card">
          <div class="meal-top">
            <h2 class="dish"><?= e($m['dish_name']) ?></h2>
            <div class="meal-actions">
              <a class="icon-link" href="meal.php?id=<?= (int)$m['id'] ?>" aria-label="Edit">Edit</a>
              <form method="post" action="meal.php" class="inline" onsubmit="return confirm('Delete this meal?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button type="submit" class="icon-link danger">Delete</button>
              </form>
            </div>
          </div>

          <div class="meta">
            <span class="time"><?= e(date('H:i', strtotime($m['eaten_at']))) ?></span>
            <span class="chip <?= $isRestaurant ? 'chip-out' : 'chip-home' ?>"><?= e($m['location']) ?></span>
            <?php if (!empty($m['place'])): ?><span class="place"><?= e($m['place']) ?></span><?php endif; ?>
            <?php if ($mm['kcal'] !== null): ?><span class="kcal">~<?= number_format($mm['kcal']) ?> kcal</span><?php endif; ?>
            <?php if ($mm['protein'] !== null): ?><span class="macro"><?= round($mm['protein']) ?> g P</span><?php endif; ?>
            <?php if ($mm['fiber'] !== null): ?><span class="macro"><?= round($mm['fiber']) ?> g fiber</span><?php endif; ?>
          </div>

          <?php if ($ings): ?>
            <ul class="ings">
              <?php foreach ($ings as $ing): ?>
                <li><?= ingredient_line($ing) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if (!empty($m['notes'])): ?>
            <p class="notes"><?= nl2br(e($m['notes'])) ?></p>
          <?php endif; ?>
        </article>
        <?php else:
            $a = $item['row'];
            $mins = (int)$a['minutes'];
        ?>
        <article class="meal card">
          <div class="meal-top">
            <h2 class="dish"><?= e($a['activity']) ?></h2>
            <div class="meal-actions">
              <a class="icon-link" href="activity.php?id=<?= (int)$a['id'] ?>" aria-label="Edit">Edit</a>
              <form method="post" action="activity.php" class="inline" onsubmit="return confirm('Delete this activity?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="icon-link danger">Delete</button>
              </form>
            </div>
          </div>

          <div class="meta">
            <span class="time"><?= e(date('H:i', $item['ts'])) ?></span>
            <span class="chip chip-activity">Activity</span>
            <span class="mins"><?= $mins ?> min<?= $mins === 1 ? '' : 's' ?></span>
          </div>

          <?php if (!empty($a['notes'])): ?>
            <p class="notes"><?= nl2br(e($a['notes'])) ?></p>
          <?php endif; ?>
        </article>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>

<a class="fab" href="add.php" aria-label="Add entry">+</a>

<?php require __DIR__ . '/footer.php'; ?>
