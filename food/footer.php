</main>

<?php if (!empty($SHOW_NAV)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
document.getElementById('exportBtn')?.addEventListener('click', async function () {
  this.disabled = true;
  const original = this.textContent;
  this.textContent = 'Exporting…';
  try {
    const res = await fetch('export.php', { headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error('export failed');
    const rows = await res.json();
    if (!rows.length) { alert('Nothing to export yet.'); return; }
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
</script>
<?php endif; ?>
</body>
</html>
