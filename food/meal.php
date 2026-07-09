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
        redirect('diary.php');
    }

    // --- Save (insert or update) ---
    $id        = (int)($_POST['id'] ?? 0);
    $dish      = trim($_POST['dish_name'] ?? '');
    $location  = ($_POST['location'] ?? 'Home') === 'Restaurant' ? 'Restaurant' : 'Home';
    $mealType  = in_array(($_POST['meal_type'] ?? ''), meal_type_order(), true) ? $_POST['meal_type'] : null;
    $place     = trim($_POST['place'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $rawWhen   = $_POST['eaten_at'] ?? '';
    $ts        = $rawWhen ? strtotime($rawWhen) : time();
    $eatenAt   = date('Y-m-d H:i:s', $ts ?: time());

    $names = $_POST['ing_name'] ?? [];
    $qtys  = $_POST['ing_qty']  ?? [];
    $preps = $_POST['ing_prep'] ?? [];
    $kcals = $_POST['ing_kcal'] ?? [];
    $kcalOrig      = $_POST['ing_kcal_orig']       ?? []; // value the field was loaded with
    $kcalWasManual = $_POST['ing_kcal_was_manual'] ?? []; // '1' if that value was a manual override

    // For edits, confirm the meal belongs to this user before touching
    // anything (guards the meal row and its ingredients from a crafted id).
    if ($id > 0) {
        $own = $pdo->prepare('SELECT id FROM meals WHERE id = ? AND created_by = ?');
        $own->execute([$id, $me['id']]);
        if (!$own->fetch()) redirect('diary.php');
    }

    $errors = [];
    if ($dish === '') $errors[] = 'Give the dish a name.';

    if (!$errors) {
        // Build the ingredient rows (skipping blanks). Decide, per row, whether
        // its kcal is a manual override or should be AI-estimated:
        //   - blank                                   → estimate
        //   - an AI value left unchanged from load     → estimate (may refresh)
        //   - a value that was already manual, or that the user edited/added
        //                                             → manual (kept as-is)
        $rows = [];
        foreach ($names as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $typed     = trim((string)($kcals[$i] ?? ''));
            $orig      = trim((string)($kcalOrig[$i] ?? ''));
            $wasManual = (string)($kcalWasManual[$i] ?? '0') === '1';
            $manualKcal = null;
            if ($typed !== '' && is_numeric($typed) && ($wasManual || $typed !== $orig)) {
                $manualKcal = max(0, (int)$typed);
            }
            $rows[] = [
                'name'        => $n,
                'quantity'    => (trim((string)($qtys[$i]  ?? '')) ?: null),
                'preparation' => (trim((string)($preps[$i] ?? '')) ?: null),
                'manual_kcal' => $manualKcal,
            ];
        }

        // Estimate nutrition for every row (protein + fiber are always
        // AI-estimated; kcal too unless the user set it manually). One
        // batched, cache-backed call, before the transaction so a slow API
        // never holds a DB lock.
        $nutri = $rows ? estimate_nutrition_batch(array_map(fn($r) => [
            'name' => $r['name'], 'quantity' => $r['quantity'], 'preparation' => $r['preparation'],
        ], $rows)) : [];

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                // Only the owner can update; a non-owning id changes nothing.
                $st = $pdo->prepare('UPDATE meals SET dish_name=?, location=?, meal_type=?, place=?, eaten_at=?, notes=? WHERE id=? AND created_by=?');
                $st->execute([$dish, $location, $mealType, ($place ?: null), $eatenAt, ($notes ?: null), $id, $me['id']]);
                $pdo->prepare('DELETE FROM ingredients WHERE meal_id = ?')->execute([$id]);
            } else {
                $st = $pdo->prepare('INSERT INTO meals (dish_name, location, meal_type, place, eaten_at, notes, created_by) VALUES (?,?,?,?,?,?,?)');
                $st->execute([$dish, $location, $mealType, ($place ?: null), $eatenAt, ($notes ?: null), $me['id']]);
                $id = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare('INSERT INTO ingredients (meal_id, name, quantity, preparation, position, calories, calories_manual, protein_g, fiber_g) VALUES (?,?,?,?,?,?,?,?,?)');
            $pos = 0;
            foreach ($rows as $idx => $r) {
                $est = $nutri[$idx] ?? ['kcal' => null, 'protein' => null, 'fiber' => null];
                if ($r['manual_kcal'] !== null) { $cal = $r['manual_kcal']; $manual = 1; }
                else                            { $cal = $est['kcal'];       $manual = 0; }
                $ins->execute([$id, $r['name'], $r['quantity'], $r['preparation'], $pos++, $cal, $manual, $est['protein'], $est['fiber']]);
            }
            $pdo->commit();
            redirect('diary.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not save. Please try again.';
        }
    }
    // On error, fall through and re-render the form with submitted values.
    $meal = ['id' => $id, 'dish_name' => $dish, 'location' => $location, 'meal_type' => $mealType, 'place' => $place, 'eaten_at' => $eatenAt, 'notes' => $notes];
    $existingIngredients = [];
    foreach ($names as $i => $n) {
        if (trim((string)$n) === '') continue;
        $k    = trim((string)($kcals[$i] ?? ''));
        $orig = trim((string)($kcalOrig[$i] ?? ''));
        $wm   = (string)($kcalWasManual[$i] ?? '0') === '1';
        $isManual = ($k !== '' && is_numeric($k) && ($wm || $k !== $orig));
        $existingIngredients[] = [
            'name' => $n, 'quantity' => $qtys[$i] ?? '', 'preparation' => $preps[$i] ?? '',
            'calories' => ($k !== '' && is_numeric($k)) ? (int)$k : null,
            'calories_manual' => $isManual ? 1 : 0,
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
        if (!$meal) redirect('diary.php');
        $st = $pdo->prepare('SELECT * FROM ingredients WHERE meal_id = ? ORDER BY position, id');
        $st->execute([$id]);
        $existingIngredients = $st->fetchAll();
    } else {
        $meal = ['id' => 0, 'dish_name' => '', 'location' => 'Home', 'meal_type' => '', 'place' => '', 'eaten_at' => date('Y-m-d H:i:s'), 'notes' => ''];
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
  <a class="btn-ghost" href="<?= $isEdit ? 'diary.php' : 'add.php' ?>">Cancel</a>
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
    <label>Meal type
      <select name="meal_type" id="mealType">
        <option value="">—</option>
        <?php foreach (meal_type_order() as $t): ?>
          <option value="<?= e($t) ?>" <?= ($meal['meal_type'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="row2">
    <label>Location
      <select name="location">
        <option value="Home"       <?= strcasecmp($meal['location'],'Home')===0?'selected':'' ?>>Home</option>
        <option value="Restaurant" <?= strcasecmp($meal['location'],'Restaurant')===0?'selected':'' ?>>Restaurant</option>
      </select>
    </label>
    <label>Place <span class="hint">(optional)</span>
      <input name="place" value="<?= e($meal['place'] ?? '') ?>">
    </label>
  </div>

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
    <input type="hidden" name="ing_kcal_orig[]"       class="i-kcal-orig">
    <input type="hidden" name="ing_kcal_was_manual[]" class="i-kcal-wasman">
    <button type="button" class="ing-remove" aria-label="Remove">×</button>
  </div>
</template>

<script>
const list = document.getElementById('ingList');
const tpl  = document.getElementById('ingRowTpl');

function addRow(name = '', qty = '', prep = '', kcal = '', wasManual = false) {
  const node = tpl.content.firstElementChild.cloneNode(true);
  node.querySelector('.i-name').value = name;
  node.querySelector('.i-qty').value  = qty;
  node.querySelector('.i-prep').value = prep;
  node.querySelector('.i-kcal').value = kcal;
  // Remember what we loaded so save can tell an unchanged AI value (re-estimate)
  // from one the user actually typed (manual override).
  node.querySelector('.i-kcal-orig').value   = kcal;
  node.querySelector('.i-kcal-wasman').value = wasManual ? '1' : '0';
  node.querySelector('.ing-remove').addEventListener('click', () => node.remove());
  list.appendChild(node);
}

// Pre-fill kcal for every ingredient (AI estimate or manual value), so edit
// mode shows the numbers. The hidden fields track whether each was manual.
const existing = <?= json_encode(array_map(fn($i) => [
    'name' => $i['name'], 'qty' => $i['quantity'] ?? '', 'prep' => $i['preparation'] ?? '',
    'kcal' => ($i['calories'] !== null && $i['calories'] !== '') ? (string)(int)$i['calories'] : '',
    'wasManual' => (int)($i['calories_manual'] ?? 0) === 1,
], $existingIngredients), JSON_UNESCAPED_UNICODE) ?>;

if (existing.length) existing.forEach(i => addRow(i.name, i.qty, i.prep, i.kcal, i.wasManual));
else addRow();

document.getElementById('addIng').addEventListener('click', () => addRow());

// Suggest a meal type from the time, until the user picks one themselves.
(function () {
  const whenInput = document.querySelector('input[name=eaten_at]');
  const mealType  = document.getElementById('mealType');
  if (!whenInput || !mealType) return;
  let touched = <?= $isEdit ? 'true' : 'false' ?> || mealType.value !== '';
  mealType.addEventListener('change', () => { touched = true; });
  function suggest() {
    if (touched || !whenInput.value) return;
    const h = new Date(whenInput.value).getHours();
    let t = '';
    if      (h >= 5  && h <= 9)  t = 'Breakfast';
    else if (h >= 10 && h <= 11) t = 'Morning Snack';
    else if (h >= 12 && h <= 14) t = 'Lunch';
    else if (h >= 15 && h <= 16) t = 'Midday Snack';
    else if (h >= 17 && h <= 21) t = 'Dinner';
    if (t) mealType.value = t;
  }
  whenInput.addEventListener('input', suggest);
  whenInput.addEventListener('change', suggest);
  suggest();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
