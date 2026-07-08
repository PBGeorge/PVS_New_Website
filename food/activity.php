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
        // Only the activity's owner can delete it.
        $st = $pdo->prepare('DELETE FROM activities WHERE id = ? AND created_by = ?');
        $st->execute([$id, $me['id']]);
        redirect('index.php');
    }

    // --- Save (insert or update) ---
    $id       = (int)($_POST['id'] ?? 0);
    $activity = trim($_POST['activity'] ?? '');
    $minutes  = (int)($_POST['minutes'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $rawWhen  = $_POST['done_at'] ?? '';
    $ts       = $rawWhen ? strtotime($rawWhen) : time();
    $doneAt   = date('Y-m-d H:i:s', $ts ?: time());

    // For edits, confirm the activity belongs to this user before touching it.
    if ($id > 0) {
        $own = $pdo->prepare('SELECT id FROM activities WHERE id = ? AND created_by = ?');
        $own->execute([$id, $me['id']]);
        if (!$own->fetch()) redirect('index.php');
    }

    $errors = [];
    if ($activity === '') $errors[] = 'Give the activity a name.';
    if ($minutes <= 0)    $errors[] = 'Enter how many minutes (a number greater than 0).';

    if (!$errors) {
        try {
            if ($id > 0) {
                // Only the owner can update; a non-owning id changes nothing.
                $st = $pdo->prepare('UPDATE activities SET activity=?, minutes=?, notes=?, done_at=? WHERE id=? AND created_by=?');
                $st->execute([$activity, $minutes, ($notes ?: null), $doneAt, $id, $me['id']]);
            } else {
                $st = $pdo->prepare('INSERT INTO activities (activity, minutes, notes, done_at, created_by) VALUES (?,?,?,?,?)');
                $st->execute([$activity, $minutes, ($notes ?: null), $doneAt, $me['id']]);
            }
            redirect('index.php');
        } catch (Throwable $ex) {
            $errors[] = 'Could not save. Please try again.';
        }
    }
    // On error, fall through and re-render the form with submitted values.
    $act = ['id' => $id, 'activity' => $activity, 'minutes' => ($minutes ?: ''), 'notes' => $notes, 'done_at' => $doneAt];
}

// ---------- Load for edit (GET ?id=) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $errors = [];
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // Only load the activity if it belongs to the current user.
        $st = $pdo->prepare('SELECT * FROM activities WHERE id = ? AND created_by = ?');
        $st->execute([$id, $me['id']]);
        $act = $st->fetch();
        if (!$act) redirect('index.php');
    } else {
        $act = ['id' => 0, 'activity' => '', 'minutes' => '', 'notes' => '', 'done_at' => date('Y-m-d H:i:s')];
    }
}

$isEdit     = (int)($act['id'] ?? 0) > 0;
$doneInput  = date('Y-m-d\TH:i', strtotime($act['done_at']));
$PAGE_TITLE = $isEdit ? 'Edit activity' : 'Add activity';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1><?= $isEdit ? 'Edit activity' : 'Add activity' ?></h1>
  <a class="btn-ghost" href="<?= $isEdit ? 'index.php' : 'add.php' ?>">Cancel</a>
</div>

<?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>

<form method="post" class="card form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)$act['id'] ?>">

  <label>Activity
    <input name="activity" value="<?= e($act['activity']) ?>" placeholder="e.g. Walking, Gym, Cycling" required autofocus>
  </label>

  <div class="row2">
    <label>When
      <input type="datetime-local" name="done_at" value="<?= e($doneInput) ?>" required>
    </label>
    <label>Minutes
      <input type="number" name="minutes" value="<?= e((string)$act['minutes']) ?>" min="1" step="1" inputmode="numeric" required>
    </label>
  </div>

  <label>Notes <span class="hint">(optional)</span>
    <textarea name="notes" rows="3"><?= e($act['notes'] ?? '') ?></textarea>
  </label>

  <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Save activity' ?></button>
</form>
<?php require __DIR__ . '/footer.php'; ?>
