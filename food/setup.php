<?php
require __DIR__ . '/bootstrap.php';

// Once accounts exist, this page is dead. Delete the file from the server.
if (users_exist() && empty($_GET['done'])) {
    http_response_code(403);
    $PAGE_TITLE = 'Setup complete';
    require __DIR__ . '/header.php';
    echo '<div class="auth"><h1 class="auth-title">Setup is done</h1>'
       . '<p class="auth-sub">Accounts already exist. Delete <code>setup.php</code> from the server, then sign in.</p>'
       . '<p><a class="btn" href="login.php">Go to sign in</a></p></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !users_exist()) {
    csrf_check();
    $accounts = [];
    for ($i = 1; $i <= 2; $i++) {
        $dn = trim($_POST["display_$i"] ?? '');
        $un = trim($_POST["user_$i"] ?? '');
        $pw = (string)($_POST["pass_$i"] ?? '');
        if ($dn === '' || $un === '' || strlen($pw) < 8) {
            $errors[] = "Account $i needs a name, a username, and a password of at least 8 characters.";
        } else {
            $accounts[] = [$dn, $un, $pw];
        }
    }
    if (count($accounts) === 2 && strcasecmp($accounts[0][1], $accounts[1][1]) === 0) {
        $errors[] = 'The two usernames must be different.';
    }
    if (!$errors && count($accounts) === 2) {
        $st = $pdo->prepare('INSERT INTO users (display_name, username, password_hash) VALUES (?,?,?)');
        foreach ($accounts as $a) {
            $st->execute([$a[0], $a[1], password_hash($a[2], PASSWORD_DEFAULT)]);
        }
        redirect('setup.php?done=1');
    }
}

$PAGE_TITLE = 'Set up accounts';
require __DIR__ . '/header.php';

if (!empty($_GET['done'])):
?>
<div class="auth">
  <h1 class="auth-title">Accounts created</h1>
  <p class="auth-sub"><strong>Important:</strong> delete <code>setup.php</code> from the server now, so no one can reset your accounts. Then sign in.</p>
  <p><a class="btn" href="login.php">Go to sign in</a></p>
</div>
<?php else: ?>
<div class="auth">
  <h1 class="auth-title">Create the two accounts</h1>
  <p class="auth-sub">One-time setup. You can change names and passwords later in the database.</p>

  <?php foreach ($errors as $msg): ?><div class="alert"><?= e($msg) ?></div><?php endforeach; ?>

  <form method="post" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php for ($i = 1; $i <= 2; $i++): ?>
      <fieldset class="fs">
        <legend>Account <?= $i ?></legend>
        <label>Display name
          <input name="display_<?= $i ?>" value="<?= e($_POST["display_$i"] ?? '') ?>" required>
        </label>
        <label>Username
          <input name="user_<?= $i ?>" autocapitalize="none" value="<?= e($_POST["user_$i"] ?? '') ?>" required>
        </label>
        <label>Password <span class="hint">(8+ characters)</span>
          <input type="password" name="pass_<?= $i ?>" required>
        </label>
      </fieldset>
    <?php endfor; ?>
    <button class="btn" type="submit">Create accounts</button>
  </form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
