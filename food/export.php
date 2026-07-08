<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

// One row per ingredient (meal columns repeated); meals with no
// ingredients still produce a single row. Activities add one row each.
// A leading Type column distinguishes them. This denormalized shape is
// the easiest to read, filter, and share for review.
$rows = [];

[$from, $to] = export_bounds();

// --- Meals ---
$where  = 'm.created_by = ?';
$params = [$me['id']];
if ($from !== null && $to !== null) {
    $where   .= ' AND m.eaten_at BETWEEN ? AND ?';
    $params[] = $from;
    $params[] = $to;
}

$meals = $pdo->prepare("
    SELECT m.*
    FROM meals m
    WHERE $where
    ORDER BY m.eaten_at DESC, m.id DESC
");
$meals->execute($params);
$meals = $meals->fetchAll();

$ingByMeal = [];
if ($meals) {
    $ids = array_column($meals, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT * FROM ingredients WHERE meal_id IN ($in) ORDER BY position, id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $ingByMeal[$r['meal_id']][] = $r;
}

// --- Activities ---
$awhere  = 'a.created_by = ?';
$aparams = [$me['id']];
if ($from !== null && $to !== null) {
    $awhere   .= ' AND a.done_at BETWEEN ? AND ?';
    $aparams[] = $from;
    $aparams[] = $to;
}
$activities = $pdo->prepare("SELECT a.* FROM activities a WHERE $awhere ORDER BY a.done_at DESC, a.id DESC");
$activities->execute($aparams);
$activities = $activities->fetchAll();

// Every column that either record type can fill, in a fixed order so the
// sheet stays consistent whether or not a given kind appears.
$blank = [
    'Type'        => '',
    'Date'        => '',
    'Time'        => '',
    'Item'        => '',
    'Location'    => '',
    'Place'       => '',
    'Minutes'     => '',
    'Ingredient'  => '',
    'Quantity'    => '',
    'Preparation' => '',
    'Notes'       => '',
];

// Merge into one timeline, newest first, so meals and activities interleave.
$items = [];
foreach ($meals as $m) {
    $items[] = ['ts' => strtotime($m['eaten_at']), 'id' => (int)$m['id'], 'kind' => 'meal', 'row' => $m];
}
foreach ($activities as $a) {
    $items[] = ['ts' => strtotime($a['done_at']), 'id' => (int)$a['id'], 'kind' => 'activity', 'row' => $a];
}
usort($items, fn($x, $y) => $y['ts'] <=> $x['ts'] ?: $y['id'] <=> $x['id']);

foreach ($items as $item) {
    if ($item['kind'] === 'meal') {
        $m = $item['row'];
        $base = array_merge($blank, [
            'Type'     => 'Meal',
            'Date'     => date('Y-m-d', $item['ts']),
            'Time'     => date('H:i',   $item['ts']),
            'Item'     => $m['dish_name'],
            'Location' => $m['location'],
            'Place'    => $m['place'] ?? '',
            'Notes'    => $m['notes'] ?? '',
        ]);
        $ings = $ingByMeal[$m['id']] ?? [];
        if (!$ings) {
            $rows[] = $base;
        } else {
            foreach ($ings as $ing) {
                $row = $base;
                $row['Ingredient']  = $ing['name'];
                $row['Quantity']    = $ing['quantity'] ?? '';
                $row['Preparation'] = $ing['preparation'] ?? '';
                $rows[] = $row;
            }
        }
    } else {
        $a = $item['row'];
        $rows[] = array_merge($blank, [
            'Type'    => 'Activity',
            'Date'    => date('Y-m-d', $item['ts']),
            'Time'    => date('H:i',   $item['ts']),
            'Item'    => $a['activity'],
            'Minutes' => (int)$a['minutes'],
            'Notes'   => $a['notes'] ?? '',
        ]);
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
