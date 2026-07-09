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
    <h2>Daily nutrition</h2>
  </div>
  <div class="chart-meta">
    <span class="chart-meta-item"><span class="chart-dot kcal"></span>Calories target ~<?= number_format($kcalTarget) ?> kcal/day</span>
    <?php if ($proteinTarget !== null): ?>
      <span class="chart-meta-item"><span class="chart-dot protein"></span>Protein target <?= e((string)$proteinTarget) ?> g/day</span>
    <?php endif; ?>
    <?php if ($fiberTarget !== null): ?>
      <span class="chart-meta-item"><span class="chart-dot fiber"></span>Fiber target <?= e((string)$fiberTarget) ?> g/day</span>
    <?php endif; ?>
  </div>
  <div class="chart-wrap"><canvas id="nutritionChart"></canvas></div>
  <?php if ($proteinTarget === null || $fiberTarget === null): ?>
    <p class="chart-note">
      Set a <?= $proteinTarget === null && $fiberTarget === null ? 'protein and fiber target' : ($proteinTarget === null ? 'protein target' : 'fiber target') ?>
      on the <a href="password.php">Account</a> page to show <?= ($proteinTarget === null && $fiberTarget === null) ? 'their reference lines' : 'its reference line' ?>.
    </p>
  <?php endif; ?>
  <p class="chart-note">Shows logged intake only — activity minutes aren't subtracted from calories (yet).</p>
</div>

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

  function fmt(n, unit) {
    if (n === null || n === undefined) return '';
    const r = Math.round(n * 10) / 10;
    return (unit === 'kcal' ? Math.round(r).toLocaleString() : r) + ' ' + unit;
  }

  // One chart, two axes: calories as bars on the left (kcal) axis, protein
  // and fiber as lines sharing the right (grams) axis — the three metrics
  // live on very different scales, so a single shared axis would flatten
  // protein/fiber to near-invisible slivers next to calorie bars.
  function buildDatasets(range) {
    const series = raw.ranges[range];
    const datasets = [
      {
        type: 'bar', label: 'Calories', data: series.kcal,
        backgroundColor: alpha(primary, .75), borderRadius: 4, maxBarThickness: 22,
        yAxisID: 'y', order: 3,
      },
      {
        type: 'line', label: 'Protein', data: series.protein,
        borderColor: teal, backgroundColor: teal, borderWidth: 2,
        pointRadius: 3, pointHoverRadius: 4, tension: .3, fill: false,
        yAxisID: 'y1', order: 1,
      },
      {
        type: 'line', label: 'Fiber', data: series.fiber,
        borderColor: gold, backgroundColor: gold, borderWidth: 2,
        pointRadius: 3, pointHoverRadius: 4, tension: .3, fill: false,
        yAxisID: 'y1', order: 2,
      },
    ];
    if (targets.kcal !== null && targets.kcal !== undefined) {
      datasets.push({
        type: 'line', label: 'Calories target', data: series.labels.map(() => targets.kcal),
        borderColor: alpha(primary, .9), borderDash: [6, 4], borderWidth: 1.5,
        pointRadius: 0, pointHoverRadius: 0, fill: false, yAxisID: 'y', order: 0,
      });
    }
    if (targets.protein !== null && targets.protein !== undefined) {
      datasets.push({
        type: 'line', label: 'Protein target', data: series.labels.map(() => targets.protein),
        borderColor: teal, borderDash: [6, 4], borderWidth: 1.5,
        pointRadius: 0, pointHoverRadius: 0, fill: false, yAxisID: 'y1', order: 0,
      });
    }
    if (targets.fiber !== null && targets.fiber !== undefined) {
      datasets.push({
        type: 'line', label: 'Fiber target', data: series.labels.map(() => targets.fiber),
        borderColor: gold, borderDash: [6, 4], borderWidth: 1.5,
        pointRadius: 0, pointHoverRadius: 0, fill: false, yAxisID: 'y1', order: 0,
      });
    }
    return datasets;
  }

  const canvas = document.getElementById('nutritionChart');
  if (canvas) {
    const chart = new Chart(canvas.getContext('2d'), {
      data: {
        labels: raw.ranges.week.labels,
        datasets: buildDatasets('week'),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            labels: {
              boxWidth: 12,
              usePointStyle: true,
              // Target lines still show in the tooltip; keeping them out of
              // the legend stops it doubling in size for little benefit.
              filter: (item) => !item.text.includes('target'),
            },
          },
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y, ctx.dataset.yAxisID === 'y' ? 'kcal' : 'g')}`,
            },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
          y: {
            beginAtZero: true,
            position: 'left',
            title: { display: true, text: 'kcal' },
            grid: { color: 'rgba(26,29,36,.06)' },
          },
          y1: {
            beginAtZero: true,
            position: 'right',
            title: { display: true, text: 'g' },
            grid: { display: false },
          },
        },
      },
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
        chart.data.labels   = raw.ranges[range].labels;
        chart.data.datasets = buildDatasets(range);
        chart.update();
      });
    });
  }
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
