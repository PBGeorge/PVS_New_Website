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

$view       = build_diary_view($meals, $activities, $ingredientsByMeal);
$items      = $view['items'];
$byDay      = $view['byDay'];
$mealMacros = $view['mealMacros'];
$dayMacros  = $view['dayMacros'];
$typeOrder  = $view['typeOrder'];

$PAGE_TITLE = 'Diary';
$SHOW_NAV   = true;
$ACTIVE_NAV = 'diary';
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
        // Macro subtotal for the group heading (meals only).
        $sub = ['kcal' => null, 'protein' => null, 'fiber' => null];
        foreach ($group as $it) {
            if ($it['kind'] !== 'meal') continue;
            $gm = $mealMacros[$it['id']] ?? null;
            if (!$gm) continue;
            foreach (['kcal', 'protein', 'fiber'] as $k) {
                if ($gm[$k] !== null) $sub[$k] = ($sub[$k] ?? 0) + $gm[$k];
            }
        }
        $subLabel = macro_summary($sub);
    ?>
    <div class="type-head">
      <span class="type-name"><?= e($type) ?></span>
      <?php if ($subLabel !== ''): ?><span class="type-kcal"><?= e($subLabel) ?></span><?php endif; ?>
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
