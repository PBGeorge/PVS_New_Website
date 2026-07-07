<?php
require __DIR__ . '/bootstrap.php';

// Find a live reset row for the given raw token, or null.
function find_reset(PDO $pdo, string $token): ?array {
    if ($token === '') return null;
    $st = $pdo->prepare(
        'SELECT id, user_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
    );
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token = (string)($_POST['token'] ?? '');
    $row   = find_reset($pdo, $token);

    if (!$row) {
        $errors[] = 'This reset link is invalid or has expired.';
    } else {
        $new     = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        if (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = "The new passwords don't match.";
        }

        if (!$errors) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([$row['id']]);
            redirect('login.php?reset=1');
        }
    }
} else {
    $token = (string)($_GET['token'] ?? '');
    $row   = find_reset($pdo, $token);
    if (!$row) $errors[] = 'This reset link is invalid or has expired.';
}

$PAGE_TITLE = 'Set a new password';
require __DIR__ . '/header.php';
?>
<div class="auth">
  <h1 class="auth-title">Set a new password</h1>

  <?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>

  <?php if ($row): ?>
    <form method="post" class="card form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New password <span class="hint">(8+ characters)</span>
        <input type="password" name="new" autocomplete="new-password" required autofocus>
      </label>
      <label>Confirm new password
        <input type="password" name="confirm" autocomplete="new-password" required>
      </label>
      <button class="btn" type="submit">Save new password</button>
    </form>
  <?php else: ?>
    <p class="auth-sub">Request a fresh link and try again.</p>
    <p class="auth-alt"><a href="forgot.php">Forgot password?</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
