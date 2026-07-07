<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

// ---------- Handle POST (save or delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('DELETE FROM meals WHERE id = ?'); // ingredients cascade
        $st->execute([$id]);
        redirect('index.php');
    }

    // --- Save (insert or update) ---
    $id        = (int)($_POST['id'] ?? 0);
    $dish      = trim($_POST['dish_name'] ?? '');
    $location  = ($_POST['location'] ?? 'Home') === 'Restaurant' ? 'Restaurant' : 'Home';
    $place     = trim($_POST['place'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $rawWhen   = $_POST['eaten_at'] ?? '';
    $ts        = $rawWhen ? strtotime($rawWhen) : time();
    $eatenAt   = date('Y-m-d H:i:s', $ts ?: time());

    $names = $_POST['ing_name'] ?? [];
    $qtys  = $_POST['ing_qty']  ?? [];
    $preps = $_POST['ing_prep'] ?? [];

    $errors = [];
    if ($dish === '') $errors[] = 'Give the dish a name.';

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE meals SET dish_name=?, location=?, place=?, eaten_at=?, notes=? WHERE id=?');
                $st->execute([$dish, $location, ($place ?: null), $eatenAt, ($notes ?: null), $id]);
                $pdo->prepare('DELETE FROM ingredients WHERE meal_id = ?')->execute([$id]);
            } else {
                $st = $pdo->prepare('INSERT INTO meals (dish_name, location, place, eaten_at, notes, created_by) VALUES (?,?,?,?,?,?)');
                $st->execute([$dish, $location, ($place ?: null), $eatenAt, ($notes ?: null), $me['id']]);
                $id = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare('INSERT INTO ingredients (meal_id, name, quantity, preparation, position) VALUES (?,?,?,?,?)');
            $pos = 0;
            foreach ($names as $i => $n) {
                $n = trim((string)$n);
                if ($n === '') continue; // skip blank rows
                $ins->execute([
                    $id,
                    $n,
                    (trim((string)($qtys[$i]  ?? '')) ?: null),
                    (trim((string)($preps[$i] ?? '')) ?: null),
                    $pos++,
                ]);
            }
            $pdo->commit();
            redirect('index.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not save. Please try again.';
        }
    }
    // On error, fall through and re-render the form with submitted values.
    $meal = ['id' => $id, 'dish_name' => $dish, 'location' => $location, 'place' => $place, 'eaten_at' => $eatenAt, 'notes' => $notes];
    $existingIngredients = [];
    foreach ($names as $i => $n) {
        if (trim((string)$n) === '') continue;
        $existingIngredients[] = ['name' => $n, 'quantity' => $qtys[$i] ?? '', 'preparation' => $preps[$i] ?? ''];
    }
}

// ---------- Load for edit (GET ?id=) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $errors = [];
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM meals WHERE id = ?');
        $st->execute([$id]);
        $meal = $st->fetch();
        if (!$meal) redirect('index.php');
        $st = $pdo->prepare('SELECT * FROM ingredients WHERE meal_id = ? ORDER BY position, id');
        $st->execute([$id]);
        $existingIngredients = $st->fetchAll();
    } else {
        $meal = ['id' => 0, 'dish_name' => '', 'location' => 'Home', 'place' => '', 'eaten_at' => date('Y-m-d H:i:s'), 'notes' => ''];
        $existingIngredients = [];
    }
}

$isEdit       = (int)($meal['id'] ?? 0) > 0;
$eatenInput   = date('Y-m-d\TH:i', strtotime($meal['eaten_at']));
$PAGE_TITLE   = $isEdit ? 'Edit meal' : 'Add meal';
$SHOW_NAV     = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1><?= $isEdit ? 'Edit meal' : 'Add meal' ?></h1>
  <a class="btn-ghost" href="index.php">Cancel</a>
</div>

<?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>

<form method="post" class="card form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)$meal['id'] ?>">

  <label>Dish
    <input name="dish_name" value="<?= e($meal['dish_name']) ?>" required autofocus>
  </label>

  <div class="row2">
    <label>When
      <input type="datetime-local" name="eaten_at" value="<?= e($eatenInput) ?>" required>
    </label>
    <label>Location
      <select name="location">
        <option value="Home"       <?= strcasecmp($meal['location'],'Home')===0?'selected':'' ?>>Home</option>
        <option value="Restaurant" <?= strcasecmp($meal['location'],'Restaurant')===0?'selected':'' ?>>Restaurant</option>
      </select>
    </label>
  </div>

  <label>Place <span class="hint">(optional — e.g. restaurant name)</span>
    <input name="place" value="<?= e($meal['place'] ?? '') ?>">
  </label>

  <div class="ing-head">
    <span>Ingredients</span>
    <button type="button" class="btn-ghost small" id="addIng">+ Add ingredient</button>
  </div>
  <div id="ingList" class="ing-list"></div>

  <label>Notes <span class="hint">(optional)</span>
    <textarea name="notes" rows="2"><?= e($meal['notes'] ?? '') ?></textarea>
  </label>

  <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Save meal' ?></button>
</form>

<template id="ingRowTpl">
  <div class="ing-row">
    <input name="ing_name[]"  placeholder="Ingredient" class="i-name">
    <input name="ing_qty[]"   placeholder="Qty (e.g. 200 g)" class="i-qty">
    <input name="ing_prep[]"  placeholder="Prepared (e.g. grilled)" class="i-prep">
    <button type="button" class="ing-remove" aria-label="Remove">×</button>
  </div>
</template>

<script>
const list = document.getElementById('ingList');
const tpl  = document.getElementById('ingRowTpl');

function addRow(name = '', qty = '', prep = '') {
  const node = tpl.content.firstElementChild.cloneNode(true);
  node.querySelector('.i-name').value = name;
  node.querySelector('.i-qty').value  = qty;
  node.querySelector('.i-prep').value = prep;
  node.querySelector('.ing-remove').addEventListener('click', () => node.remove());
  list.appendChild(node);
}

const existing = <?= json_encode(array_map(fn($i) => [
    'name' => $i['name'], 'qty' => $i['quantity'] ?? '', 'prep' => $i['preparation'] ?? ''
], $existingIngredients), JSON_UNESCAPED_UNICODE) ?>;

if (existing.length) existing.forEach(i => addRow(i.name, i.qty, i.prep));
else addRow();

document.getElementById('addIng').addEventListener('click', () => addRow());
</script>
<?php require __DIR__ . '/footer.php'; ?>
