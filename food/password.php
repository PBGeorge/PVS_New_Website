<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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

$PAGE_TITLE = 'Change password';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Change password</h1>
  <a class="btn-ghost" href="index.php">Back</a>
</div>

<?php if (!empty($_GET['changed'])): ?>
  <div class="ok">Your password has been changed.</div>
  <div class="card empty">
    <p>You're all set.</p>
    <a class="btn" href="index.php">Back to diary</a>
  </div>
<?php else: ?>
  <?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>

  <form method="post" class="card form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Current password
      <input type="password" name="current" autocomplete="current-password" required autofocus>
    </label>
    <label>New password <span class="hint">(8+ characters)</span>
      <input type="password" name="new" autocomplete="new-password" required>
    </label>
    <label>Confirm new password
      <input type="password" name="confirm" autocomplete="new-password" required>
    </label>
    <button class="btn" type="submit">Change password</button>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
