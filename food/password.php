<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

$errors    = [];   // password-form errors
$mailError = '';   // email-form error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'targets') {
        save_meal_targets((int)$me['id'], $_POST);
        save_daily_macro_targets((int)$me['id'], $_POST);
        redirect('password.php?saved=targets');
    } elseif ($action === 'email') {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mailError = 'That doesn\'t look like a valid email address.';
        } else {
            $st = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
            $st->execute([($email !== '' ? $email : null), $me['id']]);
            redirect('password.php?saved=email');
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current'] ?? '');
        $new     = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = "The new passwords don't match.";
        } elseif ($new === $current) {
            $errors[] = 'Choose a password different from your current one.';
        }

        if (!$errors) {
            $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $st->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            redirect('password.php?changed=1');
        }
    }
}

$mealTargets   = meal_targets_for((int)$me['id']);
$dotClass      = ['gold' => 'breakfast', 'blue' => 'lunch', 'teal' => 'dinner'];
$proteinTarget = $me['target_protein_g'] !== null ? (float)$me['target_protein_g'] : null;
$fiberTarget   = $me['target_fiber_g']   !== null ? (float)$me['target_fiber_g']   : null;

$PAGE_TITLE = 'Account';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Account</h1>
  <a class="btn-ghost" href="index.php">Back</a>
</div>

<?php if (($_GET['saved'] ?? '') === 'targets'): ?>
  <div class="ok">Meal targets saved.</div>
<?php endif; ?>
<?php if (($_GET['saved'] ?? '') === 'email'): ?>
  <div class="ok">Recovery email saved.</div>
<?php endif; ?>
<?php if (!empty($_GET['changed'])): ?>
  <div class="ok">Your password has been changed.</div>
<?php endif; ?>

<h2 class="section-h">Daily meal targets</h2>
<p class="section-note">Set a calorie target per meal and two go-to variants — used as quick presets when you log a meal.</p>
<form method="post" class="card form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="targets">
  <?php foreach ($mealTargets as $type => $info): ?>
  <fieldset class="fs">
    <legend class="mt-legend"><span class="mt-dot <?= $dotClass[$info['dot']] ?>"></span><?= e($type) ?></legend>
    <label class="mt-field">Calorie target
      <span class="input-unit">
        <input type="text" inputmode="numeric" pattern="[0-9]*" name="target[<?= e($type) ?>]"
               value="<?= (int)$info['target'] ?>">
        <span class="unit">kcal</span>
      </span>
    </label>
    <div class="mt-variants">
      <div class="mt-variants-label">Meal variants</div>
      <?php foreach ($info['variants'] as $letter => $v): ?>
      <div class="mt-variant">
        <div class="mt-variant-top">
          <span class="mt-badge"><?= e($letter) ?></span>
          <span class="input-unit sm">
            <input type="text" inputmode="numeric" pattern="[0-9]*"
                   name="variant[<?= e($type) ?>][<?= e($letter) ?>][kcal]"
                   value="<?= $v['kcal'] !== null ? (int)$v['kcal'] : '' ?>">
            <span class="unit">kcal</span>
          </span>
        </div>
        <textarea name="variant[<?= e($type) ?>][<?= e($letter) ?>][name]" rows="3"
                  placeholder="e.g. Greek yogurt, granola &amp; berries"><?= e($v['name']) ?></textarea>
      </div>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php endforeach; ?>

  <fieldset class="fs">
    <legend class="mt-legend">Daily macros <span class="hint">(optional)</span></legend>
    <label class="mt-field">Protein target
      <span class="input-unit">
        <input type="text" inputmode="numeric" pattern="[0-9]*" name="protein_target"
               value="<?= $proteinTarget !== null ? e((string)$proteinTarget) : '' ?>" placeholder="e.g. 120">
        <span class="unit">g</span>
      </span>
    </label>
    <label class="mt-field">Fiber target
      <span class="input-unit">
        <input type="text" inputmode="numeric" pattern="[0-9]*" name="fiber_target"
               value="<?= $fiberTarget !== null ? e((string)$fiberTarget) : '' ?>" placeholder="e.g. 30">
        <span class="unit">g</span>
      </span>
    </label>
    <p class="hint">Leave blank to hide that chart's reference line on the Dashboard.</p>
  </fieldset>

  <button class="btn" type="submit">Save targets</button>
</form>

<h2 class="section-h">Recovery email</h2>
<p class="section-note">Used to reset your password if you ever get locked out.</p>
<?php if ($mailError): ?><div class="alert"><?= e($mailError) ?></div><?php endif; ?>
<form method="post" class="card form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="email">
  <label>Email address
    <input type="email" name="email" value="<?= e($me['email'] ?? '') ?>"
           autocomplete="email" placeholder="you@example.com">
  </label>
  <button class="btn" type="submit">Save email</button>
</form>

<h2 class="section-h">Change password</h2>
<?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>
<form method="post" class="card form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="password">
  <label>Current password
    <input type="password" name="current" autocomplete="current-password" required>
  </label>
  <label>New password <span class="hint">(8+ characters)</span>
    <input type="password" name="new" autocomplete="new-password" required>
  </label>
  <label>Confirm new password
    <input type="password" name="confirm" autocomplete="new-password" required>
  </label>
  <button class="btn" type="submit">Change password</button>
</form>
<?php require __DIR__ . '/footer.php'; ?>
