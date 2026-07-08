<?php
// Streams the signed-in user's diary as a Word-compatible .doc
// (HTML-based; opens in Word and Google Docs). Grouped by day,
// one block per meal. Per-person, like the rest of the app.
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

// Activities over the same range.
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

// Merge meals and activities into one timeline, newest first, so the
// document reads chronologically within each day.
$items = [];
foreach ($meals as $m) {
    $items[] = ['ts' => strtotime($m['eaten_at']), 'id' => (int)$m['id'], 'kind' => 'meal', 'row' => $m];
}
foreach ($activities as $a) {
    $items[] = ['ts' => strtotime($a['done_at']), 'id' => (int)$a['id'], 'kind' => 'activity', 'row' => $a];
}
usort($items, fn($x, $y) => $y['ts'] <=> $x['ts'] ?: $y['id'] <=> $x['id']);

function ing_line_doc(array $ing): string {
    $parts = [];
    if (($ing['quantity'] ?? '') !== '') $parts[] = $ing['quantity'];
    $parts[] = $ing['name'];
    $line = e(implode(' ', $parts));
    if (($ing['preparation'] ?? '') !== '') $line .= ' — ' . e($ing['preparation']);
    return $line;
}

$filename = 'food-log-' . date('Y-m-d') . '.doc';
header('Content-Type: application/msword; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<title>Food Log — <?= e($me['display_name']) ?></title>
<style>
  body   { font-family: Calibri, Arial, sans-serif; color: #1f2421; }
  h1     { font-size: 20pt; margin: 0 0 4pt; }
  .sub   { color: #6c726b; margin: 0 0 18pt; }
  h2.day { font-size: 13pt; border-bottom: 1px solid #cccccc; padding-bottom: 3pt; margin: 18pt 0 8pt; }
  .meal  { margin: 0 0 12pt; }
  .dish  { font-size: 12pt; font-weight: bold; margin: 0; }
  .meta  { color: #6c726b; font-size: 10pt; margin: 1pt 0 3pt; }
  ul     { margin: 3pt 0 3pt 18pt; padding: 0; }
  li     { font-size: 11pt; }
  .notes { font-size: 10pt; color: #444444; margin: 3pt 0 0; }
</style>
</head>
<body>
<?php
$mealCount = count($meals);
$actCount  = count($activities);
?>
<h1>Food Log — <?= e($me['display_name']) ?></h1>
<p class="sub">Exported <?= e(date('j M Y')) ?> · <?= $mealCount ?> meal<?= $mealCount === 1 ? '' : 's' ?> · <?= $actCount ?> activit<?= $actCount === 1 ? 'y' : 'ies' ?></p>

<?php if (!$items): ?>
  <p>Nothing logged yet.</p>
<?php else:
    $currentDay = null;
    foreach ($items as $item):
        $day = date('l, j F Y', $item['ts']);
        if ($day !== $currentDay):
            $currentDay = $day; ?>
    <h2 class="day"><?= e($day) ?></h2>
        <?php endif;
        if ($item['kind'] === 'meal'):
            $m = $item['row'];
            $ings = $ingredientsByMeal[$m['id']] ?? [];
    ?>
  <div class="meal">
    <p class="dish"><?= e($m['dish_name']) ?></p>
    <p class="meta"><?= e(date('H:i', $item['ts'])) ?> · <?= e($m['location']) ?><?php if (!empty($m['place'])): ?> · <?= e($m['place']) ?><?php endif; ?></p>
    <?php if ($ings): ?>
    <ul>
      <?php foreach ($ings as $ing): ?><li><?= ing_line_doc($ing) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($m['notes'])): ?>
    <p class="notes"><?= nl2br(e($m['notes'])) ?></p>
    <?php endif; ?>
  </div>
  <?php else:
            $a = $item['row'];
            $mins = (int)$a['minutes'];
  ?>
  <div class="meal">
    <p class="dish"><?= e($a['activity']) ?></p>
    <p class="meta"><?= e(date('H:i', $item['ts'])) ?> · Activity · <?= $mins ?> min<?= $mins === 1 ? '' : 's' ?></p>
    <?php if (!empty($a['notes'])): ?>
    <p class="notes"><?= nl2br(e($a['notes'])) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endforeach; endif; ?>
</body>
</html>
