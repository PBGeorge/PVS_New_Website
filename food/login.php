<?php
require __DIR__ . '/bootstrap.php';

// If no accounts exist yet, send them to setup first.
if (!users_exist()) redirect('setup.php');

// Already signed in? Go home.
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();

    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        redirect('index.php');
    }
    $error = 'That username and password don\'t match.';
}

$PAGE_TITLE = 'Sign in';
require __DIR__ . '/header.php';
?>
<div class="auth">
  <h1 class="auth-title"><?= e(APP_NAME) ?></h1>
  <p class="auth-sub">Sign in to log and review meals.</p>

  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card form" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Username
      <input name="username" autocapitalize="none" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn" type="submit">Sign in</button>
  </form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
