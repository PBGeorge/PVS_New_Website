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
// Export order: day descending (newest first), then time ascending within
// the day; per-type grouping and re-sorting happens below.
usort($items, function ($x, $y) {
    $dx = date('Y-m-d', $x['ts']);
    $dy = date('Y-m-d', $y['ts']);
    if ($dx !== $dy) return $dy <=> $dx;
    return $x['ts'] <=> $y['ts'] ?: $x['id'] <=> $y['id'];
});

function ing_line_doc(array $ing): string {
    $parts = [];
    if (($ing['quantity'] ?? '') !== '') $parts[] = $ing['quantity'];
    $parts[] = $ing['name'];
    $line = e(implode(' ', $parts));
    if (($ing['preparation'] ?? '') !== '') $line .= ' — ' . e($ing['preparation']);
    return $line;
}

function meal_macros_doc(array $ings): array {
    $t   = ['kcal' => 0, 'protein' => 0, 'fiber' => 0];
    $has = ['kcal' => false, 'protein' => false, 'fiber' => false];
    foreach ($ings as $r) {
        foreach (['kcal' => 'calories', 'protein' => 'protein_g', 'fiber' => 'fiber_g'] as $k => $c) {
            if (isset($r[$c]) && $r[$c] !== null && $r[$c] !== '') { $t[$k] += (float)$r[$c]; $has[$k] = true; }
        }
    }
    return [
        'kcal'    => $has['kcal']    ? (int)round($t['kcal']) : null,
        'protein' => $has['protein'] ? $t['protein']         : null,
        'fiber'   => $has['fiber']   ? $t['fiber']           : null,
    ];
}

function macro_bits_doc(array $mm): string {
    $b = [];
    if ($mm['kcal']    !== null) $b[] = number_format($mm['kcal']) . ' kcal';
    if ($mm['protein'] !== null) $b[] = round($mm['protein']) . ' g protein';
    if ($mm['fiber']   !== null) $b[] = round($mm['fiber']) . ' g fiber';
    return implode(' · ', $b);
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
  body    { font-family: Calibri, Arial, sans-serif; color: #1f2421; font-size: 11pt; }
  h1      { font-size: 18pt; margin: 0 0 2pt; }
  .sub    { color: #6c726b; margin: 0 0 14pt; font-size: 10pt; }
  table   { width: 100%; border-collapse: collapse; }
  .day-t  { margin: 16pt 0 4pt; border-bottom: 1px solid #cccccc; }
  .day-l  { font-size: 13pt; font-weight: bold; padding-bottom: 3pt; vertical-align: bottom; }
  .day-r  { text-align: right; color: #6c726b; font-size: 10pt; vertical-align: bottom; padding-bottom: 3pt; }
  .type   { font-size: 9.5pt; font-weight: bold; color: #1b8dd1; text-transform: uppercase; letter-spacing: .4pt; margin: 9pt 0 1pt; }
  .dish-t { margin: 0 0 7pt; }
  .dish-l { vertical-align: top; }
  .dish-r { vertical-align: top; text-align: right; width: 30%; color: #444444; font-size: 10pt; white-space: nowrap; padding-left: 12pt; }
  .dish-r .kcal { font-weight: bold; color: #1f2421; }
  .dish   { font-size: 12pt; font-weight: bold; margin: 0; }
  .meta   { color: #6c726b; font-size: 9.5pt; margin: 1pt 0 2pt; }
  ul      { margin: 2pt 0 2pt 16pt; padding: 0; }
  li      { font-size: 10.5pt; }
  .notes  { font-size: 9.5pt; color: #444444; margin: 2pt 0 0; }
</style>
</head>
<body>
<?php
$mealCount = count($meals);
$actCount  = count($activities);

// Group by day → meal type → dish. Days stay newest-first (as encountered);
// within a day, types follow the natural meal order and items run earliest
// to latest. Day macro totals accumulate as we go.
$typeOrder = array_merge(meal_type_order(), ['Other', 'Activity']);
$byDay = [];
foreach ($items as $item) {
    $dayKey = date('Y-m-d', $item['ts']);
    if (!isset($byDay[$dayKey])) {
        $byDay[$dayKey] = ['label' => date('l, j F Y', $item['ts']), 'types' => [], 'macros' => ['kcal'=>null,'protein'=>null,'fiber'=>null]];
    }
    if ($item['kind'] === 'meal') {
        $type = $item['row']['meal_type'] ?: 'Other';
        if (!in_array($type, $typeOrder, true)) $type = 'Other';
        $mm = meal_macros_doc($ingredientsByMeal[$item['row']['id']] ?? []);
        foreach (['kcal', 'protein', 'fiber'] as $k) {
            if ($mm[$k] !== null) $byDay[$dayKey]['macros'][$k] = ($byDay[$dayKey]['macros'][$k] ?? 0) + $mm[$k];
        }
    } else {
        $type = 'Activity';
    }
    $byDay[$dayKey]['types'][$type][] = $item;
}
foreach ($byDay as &$d) {
    foreach ($d['types'] as &$list) { usort($list, fn($x, $y) => $x['ts'] <=> $y['ts']); }
    unset($list);
}
unset($d);
?>
<h1>Food Log — <?= e($me['display_name']) ?></h1>
<p class="sub">Exported <?= e(date('j M Y')) ?> · <?= $mealCount ?> meal<?= $mealCount === 1 ? '' : 's' ?> · <?= $actCount ?> activit<?= $actCount === 1 ? 'y' : 'ies' ?></p>

<?php if (!$items): ?>
  <p>Nothing logged yet.</p>
<?php else: foreach ($byDay as $day): ?>
  <table class="day-t"><tr>
    <td class="day-l"><?= e($day['label']) ?></td>
    <td class="day-r"><?= e(macro_bits_doc($day['macros'])) ?></td>
  </tr></table>
  <?php foreach ($typeOrder as $type):
      if (empty($day['types'][$type])) continue; ?>
    <p class="type"><?= e($type) ?></p>
    <?php foreach ($day['types'][$type] as $item):
        if ($item['kind'] === 'meal'):
            $m    = $item['row'];
            $ings = $ingredientsByMeal[$m['id']] ?? [];
            $mm   = meal_macros_doc($ings); ?>
    <table class="dish-t"><tr>
      <td class="dish-l">
        <p class="dish"><?= e($m['dish_name']) ?></p>
        <p class="meta"><?= e(date('H:i', $item['ts'])) ?> · <?= e($m['location']) ?><?php if (!empty($m['place'])): ?> · <?= e($m['place']) ?><?php endif; ?></p>
        <?php if ($ings): ?><ul><?php foreach ($ings as $ing): ?><li><?= ing_line_doc($ing) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($m['notes'])): ?><p class="notes"><?= nl2br(e($m['notes'])) ?></p><?php endif; ?>
      </td>
      <td class="dish-r">
        <?php if ($mm['kcal'] !== null): ?><span class="kcal">≈ <?= number_format($mm['kcal']) ?> kcal</span><br><?php endif; ?>
        <?php if ($mm['protein'] !== null): ?><?= round($mm['protein']) ?> g protein<br><?php endif; ?>
        <?php if ($mm['fiber'] !== null): ?><?= round($mm['fiber']) ?> g fiber<?php endif; ?>
      </td>
    </tr></table>
    <?php else:
            $a = $item['row'];
            $mins = (int)$a['minutes']; ?>
    <table class="dish-t"><tr>
      <td class="dish-l">
        <p class="dish"><?= e($a['activity']) ?></p>
        <p class="meta"><?= e(date('H:i', $item['ts'])) ?></p>
        <?php if (!empty($a['notes'])): ?><p class="notes"><?= nl2br(e($a['notes'])) ?></p><?php endif; ?>
      </td>
      <td class="dish-r"><?= $mins ?> min<?= $mins === 1 ? '' : 's' ?></td>
    </tr></table>
    <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endforeach; endif; ?>
</body>
</html>
