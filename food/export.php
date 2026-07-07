<?php
require __DIR__ . '/bootstrap.php';
require_login();

// One row per ingredient (meal columns repeated); meals with no
// ingredients still produce a single row. This denormalized shape is
// the easiest to read, filter, and share for nutrition review.
$rows = [];

$meals = $pdo->query("
    SELECT m.*, u.display_name AS author
    FROM meals m
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.eaten_at ASC, m.id ASC
")->fetchAll();

$ingByMeal = [];
if ($meals) {
    $ids = array_column($meals, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT * FROM ingredients WHERE meal_id IN ($in) ORDER BY position, id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $ingByMeal[$r['meal_id']][] = $r;
}

foreach ($meals as $m) {
    $base = [
        'Date'        => date('Y-m-d', strtotime($m['eaten_at'])),
        'Time'        => date('H:i',   strtotime($m['eaten_at'])),
        'Dish'        => $m['dish_name'],
        'Location'    => $m['location'],
        'Place'       => $m['place'] ?? '',
        'Ingredient'  => '',
        'Quantity'    => '',
        'Preparation' => '',
        'Notes'       => $m['notes'] ?? '',
        'Added by'    => $m['author'] ?? '',
    ];
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
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
