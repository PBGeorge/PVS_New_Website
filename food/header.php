<?php
// Shared <head> + top bar. Set $PAGE_TITLE and optionally $SHOW_NAV before including.
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;
$SHOW_NAV   = $SHOW_NAV   ?? false;
$me         = $SHOW_NAV ? current_user() : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#3f7d5b">
<title><?= e($PAGE_TITLE) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php if ($SHOW_NAV): ?>
<header class="topbar">
  <a class="brand" href="index.php"><?= e(APP_NAME) ?></a>
  <nav class="topnav">
    <button type="button" class="btn-ghost" id="exportBtn">Export</button>
    <a class="btn-ghost" href="logout.php">Sign out</a>
  </nav>
</header>
<?php endif; ?>
<main class="wrap">
