<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();

$errors    = [];   // password-form errors
$mailError = '';   // email-form error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'email') {
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

$PAGE_TITLE = 'Account';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Account</h1>
  <a class="btn-ghost" href="index.php">Back</a>
</div>

<?php if (!empty($_GET['saved']) && $_GET['saved'] === 'email'): ?>
  <div class="ok">Recovery email saved.</div>
<?php endif; ?>
<?php if (!empty($_GET['changed'])): ?>
  <div class="ok">Your password has been changed.</div>
<?php endif; ?>

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
