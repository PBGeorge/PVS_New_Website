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

// Per-meal kcal total (sum of ingredients that have an estimate), and a
// running per-day total. Meals with no estimated ingredients stay null so
// we don't show a misleading "0 kcal".
$mealKcal = [];
foreach ($ingredientsByMeal as $mid => $rows) {
    $sum = 0; $any = false;
    foreach ($rows as $r) {
        if ($r['calories'] !== null && $r['calories'] !== '') { $sum += (int)$r['calories']; $any = true; }
    }
    $mealKcal[$mid] = $any ? $sum : null;
}
$dayKcal = [];
foreach ($items as $item) {
    if ($item['kind'] !== 'meal') continue;
    $mk = $mealKcal[$item['id']] ?? null;
    if ($mk === null) continue;
    $dayKey = date('Y-m-d', $item['ts']);
    $dayKcal[$dayKey] = ($dayKcal[$dayKey] ?? 0) + $mk;
}

function ingredient_line(array $ing): string {
    $parts = [];
    if ($ing['quantity'] !== null && $ing['quantity'] !== '') $parts[] = $ing['quantity'];
    $parts[] = $ing['name'];
    $line = e(implode(' ', $parts));
    if ($ing['preparation'] !== null && $ing['preparation'] !== '') {
        $line .= ' <span class="prep">— ' . e($ing['preparation']) . '</span>';
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
  <?php
  $currentDay = null;
  foreach ($items as $item):
      $day = date('l, j M Y', $item['ts']);
      if ($day !== $currentDay):
          if ($currentDay !== null) echo '</div>'; // close previous .day-group
          $currentDay = $day;
          $dayTotal = $dayKcal[date('Y-m-d', $item['ts'])] ?? null;
          $label = e($day);
          if ($dayTotal !== null) $label .= ' <span class="day-kcal">~' . number_format($dayTotal) . ' kcal</span>';
          echo '<div class="day-label">' . $label . '</div><div class="day-group">';
      endif;

      if ($item['kind'] === 'meal'):
          $m = $item['row'];
          $ings = $ingredientsByMeal[$m['id']] ?? [];
          $isRestaurant = strcasecmp($m['location'], 'Restaurant') === 0;
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
        <?php if (($mealKcal[$m['id']] ?? null) !== null): ?><span class="kcal">~<?= number_format($mealKcal[$m['id']]) ?> kcal</span><?php endif; ?>
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
  </div><!-- close last .day-group -->
<?php endif; ?>

<a class="fab" href="add.php" aria-label="Add entry">+</a>

<?php require __DIR__ . '/footer.php'; ?>
