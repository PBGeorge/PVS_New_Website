<?php
require __DIR__ . '/bootstrap.php';
require_login();

// Load all meals with their ingredients, newest first.
$meals = $pdo->query("
    SELECT m.*, u.display_name AS author
    FROM meals m
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.eaten_at DESC, m.id DESC
")->fetchAll();

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
  <a class="btn" href="meal.php">Add meal</a>
</div>

<?php if (!$meals): ?>
  <div class="empty card">
    <p>No meals logged yet.</p>
    <a class="btn" href="meal.php">Log your first meal</a>
  </div>
<?php else: ?>
  <?php
  $currentDay = null;
  foreach ($meals as $m):
      $day = date('l, j M Y', strtotime($m['eaten_at']));
      if ($day !== $currentDay):
          if ($currentDay !== null) echo '</div>'; // close previous .day-group
          $currentDay = $day;
          echo '<div class="day-label">' . e($day) . '</div><div class="day-group">';
      endif;
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

      <?php if (!empty($m['author'])): ?>
        <div class="byline">Added by <?= e($m['author']) ?></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div><!-- close last .day-group -->
<?php endif; ?>

<a class="fab" href="meal.php" aria-label="Add meal">+</a>

<?php require __DIR__ . '/footer.php'; ?>
