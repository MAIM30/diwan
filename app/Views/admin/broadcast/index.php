<?php
// PATH LOKASI FILE: app/Views/admin/broadcast/index.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head">
  <h1><?= Ui::icon('radio', 22) ?> Broadcast WhatsApp</h1>
</div>

<div class="note">
  Kirim pesan WhatsApp ke pengurus. Cari penerima berdasarkan struktur, unit, atau nama orang.
  Gunakan <code>{nama}</code> dalam pesan untuk menyisipkan nama penerima.
</div>

<div class="card">
  <h2>Tulis Pesan</h2>
  <form method="post" action="<?= BASEURL ?>/broadcast/preview" id="bc-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="targets" id="bc-targets" value="">

    <div class="field">
      <label>Penerima</label>
      <div class="bc-mode-tabs">
        <button type="button" class="bc-tab active" data-mode="structure"><?= Ui::icon('network', 14) ?> Struktur</button>
        <button type="button" class="bc-tab" data-mode="unit"><?= Ui::icon('grid', 14) ?> Unit</button>
        <button type="button" class="bc-tab" data-mode="person"><?= Ui::icon('user', 14) ?> Orang</button>
      </div>
      <div class="bc-search-wrap">
        <?= Ui::icon('search', 16) ?>
        <input type="text" id="bc-search" autocomplete="off" placeholder="Ketik nama untuk mencari...">
        <div class="bc-dropdown" id="bc-dropdown"></div>
      </div>
      <div class="bc-chips" id="bc-chips"></div>
      <div class="help">Kosongkan untuk mengirim ke <strong>semua pengurus aktif</strong>. Bisa memilih campuran struktur, unit, dan orang.</div>
    </div>

    <div class="field" style="max-width:260px">
      <label for="scope">Lingkup</label>
      <select id="scope" name="scope">
        <option value="all">Semua pengurus</option>
        <option value="inti">Hanya Pengurus Inti</option>
      </select>
    </div>

    <div class="field">
      <label for="message">Pesan</label>
      <textarea id="message" name="message" rows="6" required
                placeholder="Assalamu'alaikum {nama}, ..."
                style="width:100%; font-size:14px; line-height:1.6"></textarea>
      <div class="help">Maks. 4096 karakter. <code>{nama}</code> akan diganti dengan nama penerima.</div>
    </div>

    <button type="submit" class="btn"><?= Ui::icon('send', 16) ?> Pratinjau Penerima</button>
  </form>
</div>

<style>
.bc-mode-tabs { display:flex; gap:4px; margin-bottom:8px; }
.bc-tab { padding:6px 14px; border:1px solid var(--border); border-radius:var(--radius-sm); background:#fff; cursor:pointer; font-size:13px; display:inline-flex; align-items:center; gap:5px; color:var(--ink-2); }
.bc-tab.active { background:var(--primer-pucat); border-color:var(--primer-tua); color:var(--primer-gelap); font-weight:600; }
.bc-search-wrap { position:relative; }
.bc-search-wrap .ic { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; }
.bc-search-wrap input { width:100%; padding:9px 12px 9px 34px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:14px; }
.bc-search-wrap input:focus { outline:none; border-color:var(--primer-tua); box-shadow:0 0 0 3px rgba(15,150,176,.12); }
.bc-dropdown { position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid var(--border); border-radius:var(--radius-sm); box-shadow:var(--shadow-md,0 4px 12px rgba(0,0,0,.1)); z-index:50; max-height:260px; overflow-y:auto; display:none; }
.bc-dropdown.open { display:block; }
.bc-dd-item { padding:9px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-size:14px; }
.bc-dd-item:hover, .bc-dd-item.focus { background:var(--primer-pucat); }
.bc-dd-item .hint { font-size:12px; color:var(--muted); }
.bc-dd-item .ic-type { font-size:11px; padding:2px 6px; border-radius:3px; background:var(--bg-2); color:var(--ink-2); margin-right:8px; }
.bc-dd-empty { padding:12px 14px; color:var(--muted); font-size:13px; }
.bc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.bc-chip { display:inline-flex; align-items:center; gap:4px; padding:4px 8px 4px 10px; background:var(--primer-pucat); border:1px solid var(--primer-tua); border-radius:16px; font-size:13px; color:var(--primer-gelap); }
.bc-chip button { background:none; border:none; cursor:pointer; padding:0 2px; color:var(--primer-tua); font-size:16px; line-height:1; }
.bc-chip button:hover { color:#c00; }
</style>

<script>
(function () {
  var searchEl = document.getElementById('bc-search');
  var dropdown = document.getElementById('bc-dropdown');
  var chipsEl  = document.getElementById('bc-chips');
  var targetsEl = document.getElementById('bc-targets');
  var tabs     = document.querySelectorAll('.bc-tab');
  var mode     = 'structure';
  var selected = []; // [{id, label, type}]
  var debounce = null;
  var focusIdx = -1;

  var placeholders = {structure: 'Ketik nama struktur...', unit: 'Ketik nama unit...', person: 'Ketik nama atau nomor WA...'};

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      mode = tab.getAttribute('data-mode');
      searchEl.placeholder = placeholders[mode] || '';
      searchEl.value = '';
      closeDropdown();
      searchEl.focus();
    });
  });

  searchEl.addEventListener('input', function () {
    clearTimeout(debounce);
    var q = searchEl.value.trim();
    if (q.length < 2) { closeDropdown(); return; }
    debounce = setTimeout(function () { doSearch(q); }, 250);
  });

  searchEl.addEventListener('keydown', function (e) {
    var items = dropdown.querySelectorAll('.bc-dd-item');
    if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(items, 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(items, -1); }
    else if (e.key === 'Enter' && focusIdx >= 0 && items[focusIdx]) { e.preventDefault(); items[focusIdx].click(); }
    else if (e.key === 'Escape') { closeDropdown(); }
  });

  function moveFocus(items, dir) {
    if (!items.length) return;
    focusIdx = Math.max(0, Math.min(items.length - 1, focusIdx + dir));
    items.forEach(function (el, i) { el.classList.toggle('focus', i === focusIdx); });
    items[focusIdx].scrollIntoView({block:'nearest'});
  }

  function doSearch(q) {
    fetch('<?= BASEURL ?>/broadcast/search?mode=' + encodeURIComponent(mode) + '&q=' + encodeURIComponent(q), {
      credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { renderDropdown(data); })
    .catch(function () { closeDropdown(); });
  }

  function renderDropdown(data) {
    dropdown.innerHTML = '';
    focusIdx = -1;
    if (!data.length) {
      dropdown.innerHTML = '<div class="bc-dd-empty">Tidak ditemukan</div>';
      dropdown.classList.add('open');
      return;
    }
    var selectedIds = selected.map(function (s) { return s.id; });
    data.forEach(function (item) {
      if (selectedIds.indexOf(item.id) >= 0) return;
      var div = document.createElement('div');
      div.className = 'bc-dd-item';
      div.innerHTML = '<span><span class="ic-type">' + ({structure:'Struktur',unit:'Unit',person:'Orang'}[item.type]||'') + '</span>' + esc(item.label) + '</span><span class="hint">' + esc(item.hint || '') + '</span>';
      div.addEventListener('click', function () { addChip(item); closeDropdown(); searchEl.value = ''; searchEl.focus(); });
      dropdown.appendChild(div);
    });
    if (!dropdown.children.length) {
      dropdown.innerHTML = '<div class="bc-dd-empty">Semua sudah dipilih</div>';
    }
    dropdown.classList.add('open');
  }

  function addChip(item) {
    if (selected.some(function (s) { return s.id === item.id; })) return;
    selected.push(item);
    syncChips();
  }

  function removeChip(id) {
    selected = selected.filter(function (s) { return s.id !== id; });
    syncChips();
  }

  function syncChips() {
    chipsEl.innerHTML = '';
    selected.forEach(function (s) {
      var span = document.createElement('span');
      span.className = 'bc-chip';
      span.innerHTML = esc(s.label) + ' <button type="button" title="Hapus">&times;</button>';
      span.querySelector('button').addEventListener('click', function () { removeChip(s.id); });
      chipsEl.appendChild(span);
    });
    targetsEl.value = selected.map(function (s) { return s.id; }).join(',');
  }

  function closeDropdown() { dropdown.classList.remove('open'); dropdown.innerHTML = ''; focusIdx = -1; }

  document.addEventListener('click', function (e) {
    if (!searchEl.contains(e.target) && !dropdown.contains(e.target)) closeDropdown();
  });

  function esc(s) { var d = document.createElement('span'); d.textContent = s; return d.innerHTML; }
})();
</script>

<?php $this->view('layouts/footer'); ?>
