<?php
require __DIR__ . '/bootstrap.php';

// No accounts yet? Set up first. Already signed in? Nothing to reset.
if (!users_exist()) redirect('setup.php');
if (current_user())  redirect('index.php');

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');

    // Only act on a valid, known email — but the response is always the same.
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        if ($u) {
            // Drop any earlier unused links for this user, then issue a fresh one.
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
                ->execute([$u['id']]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)'
            )->execute([$u['id'], hash('sha256', $token), $expires]);

            $scheme = 'https://';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
            $link   = $scheme . $host . $dir . '/reset.php?token=' . $token;

            require __DIR__ . '/mailer.php';
            $body = "Someone asked to reset the password for your " . APP_NAME . " account.\r\n\r\n"
                  . "To set a new password, open this link (valid for 1 hour):\r\n"
                  . $link . "\r\n\r\n"
                  . "If this wasn't you, you can ignore this email — your password stays the same.\r\n";
            smtp_send($email, APP_NAME . ' — password reset', $body);
        }
    }
    $sent = true; // neutral: always report "sent", regardless of match
}

$PAGE_TITLE = 'Forgot password';
require __DIR__ . '/header.php';
?>
<div class="auth">
  <h1 class="auth-title">Reset password</h1>

  <?php if ($sent): ?>
    <div class="ok">If that email is on file, we've sent a reset link. Check your inbox.</div>
    <p class="auth-alt"><a href="login.php">Back to sign in</a></p>
  <?php else: ?>
    <p class="auth-sub">Enter your recovery email and we'll send a link to set a new password.</p>
    <form method="post" class="card form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Recovery email
        <input type="email" name="email" autocomplete="email" required autofocus>
      </label>
      <button class="btn" type="submit">Send reset link</button>
    </form>
    <p class="auth-alt"><a href="login.php">Back to sign in</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
