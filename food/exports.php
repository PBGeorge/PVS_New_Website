<?php
require __DIR__ . '/bootstrap.php';
require_login();

$PAGE_TITLE = 'Export';
$SHOW_NAV   = true;
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <h1>Export</h1>
  <a class="btn-ghost" href="index.php">Back</a>
</div>

<form id="exportForm" class="card form" onsubmit="return false;">
  <div class="range-opts">
    <label class="opt"><input type="radio" name="range" value="7">  <span>Last 7 days</span></label>
    <label class="opt"><input type="radio" name="range" value="30" checked> <span>Last 30 days</span></label>
    <label class="opt"><input type="radio" name="range" value="90"> <span>Last 90 days</span></label>
    <label class="opt"><input type="radio" name="range" value="all"> <span>All time</span></label>
    <label class="opt"><input type="radio" name="range" value="custom"> <span>Custom range</span></label>
  </div>

  <div class="row2 custom-dates">
    <label>From
      <input type="date" name="from">
    </label>
    <label>To
      <input type="date" name="to">
    </label>
  </div>

  <div class="export-actions">
    <button type="button" class="btn" id="xlsxBtn">Download Excel</button>
    <button type="button" class="btn btn-word" id="wordBtn">Download Word</button>
  </div>
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
(function () {
  const form = document.getElementById('exportForm');

  // Build the query string for the current selection, or null if invalid.
  function query() {
    const range  = form.querySelector('input[name=range]:checked').value;
    const params = new URLSearchParams({ range });
    if (range === 'custom') {
      const from = form.querySelector('[name=from]').value;
      const to   = form.querySelector('[name=to]').value;
      if (!from || !to) { alert('Pick both a start and an end date.'); return null; }
      params.set('from', from);
      params.set('to', to);
    }
    return params.toString();
  }

  document.getElementById('wordBtn').addEventListener('click', function () {
    const q = query();
    if (q === null) return;
    window.location = 'word.php?' + q;
  });

  document.getElementById('xlsxBtn').addEventListener('click', async function () {
    const q = query();
    if (q === null) return;
    this.disabled = true;
    const original = this.textContent;
    this.textContent = 'Preparing…';
    try {
      const res = await fetch('export.php?' + q, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error('export failed');
      const rows = await res.json();
      if (!rows.length) { alert('Nothing logged in that range.'); return; }
      const ws = XLSX.utils.json_to_sheet(rows);
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Food Log');
      const stamp = new Date().toISOString().slice(0, 10);
      XLSX.writeFile(wb, 'food-log-' + stamp + '.xlsx');
    } catch (err) {
      alert('Could not export. Please try again.');
    } finally {
      this.disabled = false;
      this.textContent = original;
    }
  });

  // Enable the custom date fields only when "Custom range" is selected.
  function syncCustom() {
    const custom = form.querySelector('input[name=range]:checked').value === 'custom';
    form.querySelectorAll('.custom-dates input').forEach(i => i.disabled = !custom);
    form.querySelector('.custom-dates').style.opacity = custom ? '1' : '.5';
  }
  form.querySelectorAll('input[name=range]').forEach(r => r.addEventListener('change', syncCustom));
  syncCustom();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
