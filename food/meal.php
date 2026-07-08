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
        // Only the meal's owner can delete it. ingredients cascade.
        $st = $pdo->prepare('DELETE FROM meals WHERE id = ? AND created_by = ?');
        $st->execute([$id, $me['id']]);
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
    $kcals = $_POST['ing_kcal'] ?? [];

    // For edits, confirm the meal belongs to this user before touching
    // anything (guards the meal row and its ingredients from a crafted id).
    if ($id > 0) {
        $own = $pdo->prepare('SELECT id FROM meals WHERE id = ? AND created_by = ?');
        $own->execute([$id, $me['id']]);
        if (!$own->fetch()) redirect('index.php');
    }

    $errors = [];
    if ($dish === '') $errors[] = 'Give the dish a name.';

    if (!$errors) {
        // Build the ingredient rows (skipping blanks), keeping any kcal the
        // user typed by hand. Estimate the rest in one batched call *before*
        // opening the transaction, so a slow API never holds a DB lock.
        $rows = [];
        foreach ($names as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $manualRaw = trim((string)($kcals[$i] ?? ''));
            $rows[] = [
                'name'        => $n,
                'quantity'    => (trim((string)($qtys[$i]  ?? '')) ?: null),
                'preparation' => (trim((string)($preps[$i] ?? '')) ?: null),
                'manual_kcal' => ($manualRaw !== '' && is_numeric($manualRaw)) ? max(0, (int)$manualRaw) : null,
            ];
        }

        $toEstimate = [];
        foreach ($rows as $idx => $r) {
            if ($r['manual_kcal'] === null) {
                $toEstimate[$idx] = ['name' => $r['name'], 'quantity' => $r['quantity'], 'preparation' => $r['preparation']];
            }
        }
        $estByIdx = [];
        if ($toEstimate) {
            $estimates = estimate_calories_batch(array_values($toEstimate));
            foreach (array_keys($toEstimate) as $pos => $idx) {
                $estByIdx[$idx] = $estimates[$pos] ?? null;
            }
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                // Only the owner can update; a non-owning id changes nothing.
                $st = $pdo->prepare('UPDATE meals SET dish_name=?, location=?, place=?, eaten_at=?, notes=? WHERE id=? AND created_by=?');
                $st->execute([$dish, $location, ($place ?: null), $eatenAt, ($notes ?: null), $id, $me['id']]);
                $pdo->prepare('DELETE FROM ingredients WHERE meal_id = ?')->execute([$id]);
            } else {
                $st = $pdo->prepare('INSERT INTO meals (dish_name, location, place, eaten_at, notes, created_by) VALUES (?,?,?,?,?,?)');
                $st->execute([$dish, $location, ($place ?: null), $eatenAt, ($notes ?: null), $me['id']]);
                $id = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare('INSERT INTO ingredients (meal_id, name, quantity, preparation, position, calories, calories_manual) VALUES (?,?,?,?,?,?,?)');
            $pos = 0;
            foreach ($rows as $idx => $r) {
                if ($r['manual_kcal'] !== null) { $cal = $r['manual_kcal'];      $manual = 1; }
                else                            { $cal = $estByIdx[$idx] ?? null; $manual = 0; }
                $ins->execute([$id, $r['name'], $r['quantity'], $r['preparation'], $pos++, $cal, $manual]);
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
        $k = trim((string)($kcals[$i] ?? ''));
        $existingIngredients[] = [
            'name' => $n, 'quantity' => $qtys[$i] ?? '', 'preparation' => $preps[$i] ?? '',
            'calories' => ($k !== '' && is_numeric($k)) ? (int)$k : null,
            'calories_manual' => ($k !== '' && is_numeric($k)) ? 1 : 0,
        ];
    }
}

// ---------- Load for edit (GET ?id=) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $errors = [];
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // Only load the meal if it belongs to the current user.
        $st = $pdo->prepare('SELECT * FROM meals WHERE id = ? AND created_by = ?');
        $st->execute([$id, $me['id']]);
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
  <a class="btn-ghost" href="<?= $isEdit ? 'index.php' : 'add.php' ?>">Cancel</a>
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
  <p class="hint ing-hint">Leave <strong>kcal</strong> blank to have it estimated for you. Type a value to override.</p>
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
    <input name="ing_kcal[]"  placeholder="kcal" class="i-kcal" inputmode="numeric">
    <button type="button" class="ing-remove" aria-label="Remove">×</button>
  </div>
</template>

<script>
const list = document.getElementById('ingList');
const tpl  = document.getElementById('ingRowTpl');

function addRow(name = '', qty = '', prep = '', kcal = '') {
  const node = tpl.content.firstElementChild.cloneNode(true);
  node.querySelector('.i-name').value = name;
  node.querySelector('.i-qty').value  = qty;
  node.querySelector('.i-prep').value = prep;
  node.querySelector('.i-kcal').value = kcal;
  node.querySelector('.ing-remove').addEventListener('click', () => node.remove());
  list.appendChild(node);
}

// Only manual kcal values are pre-filled; AI estimates stay blank so they
// re-resolve (for free, from cache) on the next save.
const existing = <?= json_encode(array_map(fn($i) => [
    'name' => $i['name'], 'qty' => $i['quantity'] ?? '', 'prep' => $i['preparation'] ?? '',
    'kcal' => ((int)($i['calories_manual'] ?? 0) === 1 && $i['calories'] !== null) ? (string)$i['calories'] : ''
], $existingIngredients), JSON_UNESCAPED_UNICODE) ?>;

if (existing.length) existing.forEach(i => addRow(i.name, i.qty, i.prep, i.kcal));
else addRow();

document.getElementById('addIng').addEventListener('click', () => addRow());
</script>
<?php require __DIR__ . '/footer.php'; ?>
