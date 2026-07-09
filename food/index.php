<?php
require __DIR__ . '/bootstrap.php';
require_login();
$me = current_user();
$userId = (int)$me['id'];

// Two trailing windows ending today, same convention as the Export page's
// range=7|30 (see export_bounds()) — no calendar-week/month logic to keep
// this simple and consistent with the rest of the app.
$today     = date('Y-m-d');
$weekFrom  = date('Y-m-d', strtotime('-6 days'));
$monthFrom = date('Y-m-d', strtotime('-29 days'));

$week  = daily_nutrition_series($userId, $weekFrom, $today);
$month = daily_nutrition_series($userId, $monthFrom, $today);

$kcalTarget    = daily_kcal_target($userId);
$proteinTarget = $me['target_protein_g'] !== null ? (float)$me['target_protein_g'] : null;
$fiberTarget   = $me['target_fiber_g']   !== null ? (float)$me['target_fiber_g']   : null;

$dashboardData = [
    'ranges' => [
        'week'  => $week,
        'month' => $month,
    ],
    'targets' => [
        'kcal'    => $kcalTarget,
        'protein' => $proteinTarget,
        'fiber'   => $fiberTarget,
    ],
];

$PAGE_TITLE = 'Dashboard';
$SHOW_NAV   = true;
$ACTIVE_NAV = 'dashboard';
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <div class="head-actions">
    <a class="btn-ghost" href="diary.php">View all meals</a>
    <a class="btn" href="add.php">Add entry</a>
  </div>
</div>

<div class="seg" role="tablist" aria-label="Chart period">
  <button type="button" class="seg-btn active" data-range="week" role="tab" aria-selected="true">Weekly</button>
  <button type="button" class="seg-btn" data-range="month" role="tab" aria-selected="false">Monthly</button>
</div>

<div class="card chart-card">
  <div class="chart-head">
    <h2>Calories</h2>
    <span class="chart-target" id="kcalTargetLabel"></span>
  </div>
  <div class="chart-wrap"><canvas id="kcalChart"></canvas></div>
</div>

<div class="card chart-card">
  <div class="chart-head">
    <h2>Protein</h2>
    <span class="chart-target" id="proteinTargetLabel"></span>
  </div>
  <div class="chart-wrap"><canvas id="proteinChart"></canvas></div>
  <?php if ($proteinTarget === null): ?>
    <p class="chart-note">Set a protein target on the <a href="password.php">Account</a> page to show a reference line.</p>
  <?php endif; ?>
</div>

<div class="card chart-card">
  <div class="chart-head">
    <h2>Fiber</h2>
    <span class="chart-target" id="fiberTargetLabel"></span>
  </div>
  <div class="chart-wrap"><canvas id="fiberChart"></canvas></div>
  <?php if ($fiberTarget === null): ?>
    <p class="chart-note">Set a fiber target on the <a href="password.php">Account</a> page to show a reference line.</p>
  <?php endif; ?>
</div>

<p class="chart-note">Charts show logged intake only — activity minutes aren't subtracted from calories (yet).</p>

<a class="fab" href="add.php" aria-label="Add entry">+</a>

<script id="dashboardData" type="application/json"><?= json_encode($dashboardData, JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  const dataEl = document.getElementById('dashboardData');
  if (!dataEl || typeof Chart === 'undefined') return;

  const raw     = JSON.parse(dataEl.textContent);
  const targets = raw.targets;
  const css     = getComputedStyle(document.documentElement);
  const cssVar  = (name, fallback) => (css.getPropertyValue(name).trim() || fallback);

  Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, -apple-system, sans-serif";
  Chart.defaults.color = cssVar('--muted', '#5C6270');

  function alpha(hex, a) {
    const h = hex.replace('#', '');
    const r = parseInt(h.substring(0, 2), 16);
    const g = parseInt(h.substring(2, 4), 16);
    const b = parseInt(h.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${a})`;
  }

  const primary = cssVar('--primary', '#1B8DD1');
  const teal    = cssVar('--teal',    '#22B39A');
  const gold    = cssVar('--gold',    '#F3C64B');
  const ink     = cssVar('--ink',     '#1A1D24');

  function fmt(n, unit) {
    if (n === null || n === undefined) return '';
    const r = Math.round(n * 10) / 10;
    return (unit === 'kcal' ? Math.round(r).toLocaleString() : r) + ' ' + unit;
  }

  function buildDatasets(range, key, label, barColor, targetValue, targetColor) {
    const series = raw.ranges[range];
    const datasets = [{
      type: 'bar',
      label: label,
      data: series[key],
      backgroundColor: barColor,
      borderRadius: 4,
      maxBarThickness: 26,
      order: 2,
    }];
    if (targetValue !== null && targetValue !== undefined) {
      datasets.push({
        type: 'line',
        label: 'Target',
        data: series.labels.map(() => targetValue),
        borderColor: targetColor,
        borderDash: [6, 4],
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 0,
        fill: false,
        order: 1,
      });
    }
    return datasets;
  }

  function makeChart(canvasId, key, label, barColor, targetValue, targetColor, unit) {
    const el = document.getElementById(canvasId);
    if (!el) return null;
    const chart = new Chart(el.getContext('2d'), {
      data: {
        labels: raw.ranges.week.labels,
        datasets: buildDatasets('week', key, label, barColor, targetValue, targetColor),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: targetValue !== null && targetValue !== undefined,
            labels: { boxWidth: 12, usePointStyle: true },
          },
          tooltip: {
            callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y, unit)}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
          y: { beginAtZero: true, grid: { color: 'rgba(26,29,36,.06)' } },
        },
      },
    });
    return {
      setRange(range) {
        const series = raw.ranges[range];
        chart.data.labels   = series.labels;
        chart.data.datasets = buildDatasets(range, key, label, barColor, targetValue, targetColor);
        chart.update();
      },
    };
  }

  const kcalChart    = makeChart('kcalChart',    'kcal',    'Calories', alpha(primary, .75), targets.kcal,    ink,  'kcal');
  const proteinChart = makeChart('proteinChart', 'protein', 'Protein',  alpha(teal, .75),    targets.protein, ink,  'g');
  const fiberChart   = makeChart('fiberChart',   'fiber',   'Fiber',    alpha(gold, .85),    targets.fiber,   ink,  'g');

  const labelEls = {
    kcalTargetLabel:    targets.kcal    !== null && targets.kcal    !== undefined ? 'Target ~' + fmt(targets.kcal, 'kcal') + '/day'    : '',
    proteinTargetLabel: targets.protein !== null && targets.protein !== undefined ? 'Target ' + fmt(targets.protein, 'g') + '/day' : '',
    fiberTargetLabel:   targets.fiber   !== null && targets.fiber   !== undefined ? 'Target ' + fmt(targets.fiber, 'g') + '/day'   : '',
  };
  Object.keys(labelEls).forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = labelEls[id];
  });

  document.querySelectorAll('.seg-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.seg-btn').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
      const range = btn.dataset.range;
      if (kcalChart)    kcalChart.setRange(range);
      if (proteinChart) proteinChart.setRange(range);
      if (fiberChart)   fiberChart.setRange(range);
    });
  });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
