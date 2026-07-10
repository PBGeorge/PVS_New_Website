<?php
// Printable diary export — a "Save as PDF" page. Uses the same view-building
// (build_diary_view) and CSS classes as diary.php, so it renders identically
// to the real diary instead of a separately maintained template that can
// drift out of sync. The only differences are: date-range filtering, no
// edit/delete controls, and a print stylesheet.
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

[$from, $to] = export_bounds();

$where  = 'm.created_by = ?';
$params = [$me['id']];
if ($from !== null && $to !== null) {
    $where   .= ' AND m.eaten_at BETWEEN ? AND ?';
    $params[] = $from;
    $params[] = $to;
}
$st = $pdo->prepare("
    SELECT m.*
    FROM meals m
    WHERE $where
    ORDER BY m.eaten_at DESC, m.id DESC
");
$st->execute($params);
$meals = $st->fetchAll();

$ingredientsByMeal = [];
if ($meals) {
    $ids = array_column($meals, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ist = $pdo->prepare("SELECT * FROM ingredients WHERE meal_id IN ($in) ORDER BY position, id");
    $ist->execute($ids);
    foreach ($ist->fetchAll() as $row) {
        $ingredientsByMeal[$row['meal_id']][] = $row;
    }
}

$awhere  = 'a.created_by = ?';
$aparams = [$me['id']];
if ($from !== null && $to !== null) {
    $awhere   .= ' AND a.done_at BETWEEN ? AND ?';
    $aparams[] = $from;
    $aparams[] = $to;
}
$ast = $pdo->prepare("SELECT a.* FROM activities a WHERE $awhere ORDER BY a.done_at DESC, a.id DESC");
$ast->execute($aparams);
$activities = $ast->fetchAll();

$view       = build_diary_view($meals, $activities, $ingredientsByMeal);
$items      = $view['items'];
$byDay      = $view['byDay'];
$mealMacros = $view['mealMacros'];
$dayMacros  = $view['dayMacros'];
$typeOrder  = $view['typeOrder'];

$rangeLabel = ($from !== null && $to !== null)
    ? date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to))
    : 'All time';
$mealCount = count($meals);
$actCount  = count($activities);

$PAGE_TITLE = 'Print diary';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="print-export">
  <div class="print-toolbar">
    <a class="btn-ghost" href="exports.php">← Back</a>
    <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
  </div>

  <div class="page-head">
    <h1>Food Log</h1>
  </div>
  <p class="export-meta">
    <?= e($me['display_name']) ?> · <?= e($rangeLabel) ?> ·
    <?= $mealCount ?> meal<?= $mealCount === 1 ? '' : 's' ?> ·
    <?= $actCount ?> activit<?= $actCount === 1 ? 'y' : 'ies' ?>
  </p>

  <?php if (!$items): ?>
    <div class="empty card">
      <p>Nothing logged in this range.</p>
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
</div>

<script>
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 200);
  });
</script>
<?php require __DIR__ . '/footer.php'; ?>
