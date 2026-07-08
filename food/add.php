<?php
require __DIR__ . '/bootstrap.php';
require_login();

$PAGE_TITLE = 'Add entry';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Add entry</h1>
  <a class="btn-ghost" href="index.php">Cancel</a>
</div>

<p class="section-note">What would you like to log?</p>

<div class="choice-grid">
  <a class="choice card" href="meal.php">
    <span class="choice-emoji" aria-hidden="true">🍽️</span>
    <span class="choice-title">Meal</span>
    <span class="choice-sub">Dish, ingredients and where you ate</span>
  </a>
  <a class="choice card" href="activity.php">
    <span class="choice-emoji" aria-hidden="true">🏃</span>
    <span class="choice-title">Activity</span>
    <span class="choice-sub">Type of activity, minutes and notes</span>
  </a>
</div>

<?php require __DIR__ . '/footer.php'; ?>
