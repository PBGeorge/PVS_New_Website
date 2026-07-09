<?php
// Shared <head> + top bar. Set $PAGE_TITLE and optionally $SHOW_NAV before including.
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;
$SHOW_NAV   = $SHOW_NAV   ?? false;
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$me         = $SHOW_NAV ? current_user() : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1B8DD1">
<title><?= e($PAGE_TITLE) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: APP_NAME ?>">
</head>
<body>
<?php if ($SHOW_NAV): ?>
<header class="topbar">
  <a class="brand" href="index.php"><?= e(APP_NAME) ?></a>
  <button type="button" class="nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="topnav">
    <span></span><span></span><span></span>
  </button>
  <nav class="topnav" id="topnav">
    <a class="btn-ghost<?= $ACTIVE_NAV === 'dashboard' ? ' active' : '' ?>" href="index.php">Dashboard</a>
    <a class="btn-ghost<?= $ACTIVE_NAV === 'diary'     ? ' active' : '' ?>" href="diary.php">Diary</a>
    <a class="btn-ghost" href="exports.php">Export</a>
    <a class="btn-ghost" href="password.php">Account</a>
    <a class="btn-ghost" href="logout.php">Sign out</a>
  </nav>
</header>
<?php endif; ?>
<main class="wrap">
