<?php
// PATH LOKASI FILE: app/Views/admin/placements/form.php
// Form penempatan, disusun menurut urutan orang berpikir: Siapa / Di mana dan sebagai apa / Kapan / Dokumen.
//
//  - Orangnya SELALU tampil (kartu bernama): yang punya penempatan ini saat mengubah, ?person_id= dari profil, atau hasil pencarian
//    (placements/orang) saat menambah. Dulu orang dicari di daftar 2.000 nama pertama sehingga untuk sebagian besar pengurus kolomnya kosong.
//  - "Akan tampil di" memperlihatkan di simpul mana susunan penempatan ini akan muncul (Pengurus Inti, Pimpinan Lainnya, atau
//    Bidang/kelompok › unit) menurut Unit dan Jabatan yang dipilih, sebab nama pilihan di formulir tidak sama dengan nama simpul di susunan.
//    Aturannya cermin PlacementModel::landingLabel / Susunan::arrange.
//  - "Penempatan lain" ada di panel samping DI LUAR form simpan: form di dalam form tidak sah di HTML, dan browser membuang yang di
//    dalamnya, sehingga tombol Anulir dulu menyimpan form ini alih-alih menganulir.
//
// Struktur dipilih lewat pemilih (_struktur_picker, mencari di placements/structures dalam acuan periode yang sama dan sayap orangnya), bukan
// <select> yang memuat ratusan struktur.
//
// Dari controller: $term, $row (penempatan yang diubah atau null), $person (orang atau null), $otherPlacements,
// $landingNow (tempat tampil sekarang, hanya saat mengubah), $units, $positions.
$this->view('layouts/header', ['title' => $title]);

$isEdit = !empty($row['id']);
$gender = (string) ($person['gender'] ?? '');
$termLabel = $term['level'] . ' · ' . $term['structure_name'];
$submitLabel = $isEdit ? 'Simpan perubahan' : 'Tambahkan penempatan';
$cancelUrl = $person && !$isEdit ? BASEURL . '/people/detail/' . (int) $person['id'] : BASEURL . '/terms/detail/' . (int) $term['id'];
?>

<div class="crumb">
  <a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo;
  <a href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>"><?= e($term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name']) ?></a>
  &rsaquo; <?= e($title) ?>
</div>

<div class="page-head">
  <h1><?= e($title) ?></h1>
  <span class="badge"><?= e($termLabel) ?> &middot; <?= e($term['name']) ?></span>
</div>

<div class="pf">
<form method="post" action="<?= BASEURL ?>/placements/save" class="pf-main" id="pf-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
  <input type="hidden" name="base_term_id" value="<?= (int) $term['id'] ?>">
  <input type="hidden" name="person_id" id="pf-person-id" value="<?= (int) ($person['id'] ?? 0) ?>">
  <input type="hidden" name="gender" id="pf-gender" value="<?= e($gender) ?>">

  <section class="card pf-card" aria-labelledby="pf-h-who">
    <h2 class="pf-step" id="pf-h-who"><i>1</i> Siapa</h2>

    <div class="pf-person" id="pf-person-card"<?= $person ? '' : ' hidden' ?>>
      <span id="pf-person-av"><?= $person ? Ui::avatar($person, 'lg') : '' ?></span>
      <div class="pf-person-text">
        <a class="pf-person-name" id="pf-person-name" href="<?= $person ? BASEURL . '/people/detail/' . (int) $person['id'] : '#' ?>"><?= $person ? e(Ui::fullName($person)) : '' ?></a>
        <div class="small muted" id="pf-person-meta"><?= $person ? ($gender === 'P' ? 'Perempuan &middot; sayap Muslimah' : 'Laki-laki &middot; sayap Umum') : '' ?></div>
      </div>
      <?php if (!$isEdit): ?><button type="button" class="btn small secondary" id="pf-person-change">Ganti orang</button><?php endif; ?>
    </div>
    <?php if ($isEdit): ?>
      <p class="help pf-help">Orang pada penempatan yang sudah ada tidak bisa ditukar. Bila salah orang, anulir penempatan ini lalu buat yang baru.</p>
    <?php else: ?>
      <div id="pf-search"<?= $person ? ' hidden' : '' ?>>
        <label for="pf-q" class="pf-label">Cari orang</label>
        <input type="search" id="pf-q" autocomplete="off" placeholder="Ketik nama, nomor WA, atau NIK lengkap (minimal 2 huruf)"
               aria-controls="pf-results" aria-expanded="false">
        <ul class="pf-results" id="pf-results" role="listbox" hidden></ul>
        <div class="help" id="pf-q-help">Belum terdaftar? <a href="<?= BASEURL ?>/people/form" target="_blank" rel="noopener">Tambahkan orang</a> dulu, lalu cari di sini.</div>
      </div>
    <?php endif; ?>
  </section>

  <section class="card pf-card" aria-labelledby="pf-h-where">
    <h2 class="pf-step" id="pf-h-where"><i>2</i> Di mana dan sebagai apa</h2>

    <div class="field">
      <label for="target_term_id">Struktur</label>
      <?php $this->view('admin/_struktur_picker', [
          'id' => 'target_term_id', 'name' => 'target_term_id', 'gp' => (int) $term['governance_period_id'],
          'wing' => $gender === 'P' ? 'muslimah' : ($gender === 'L' ? 'umum' : ''),
          'selected' => ['id' => (int) $term['id'], 'level' => $term['level'], 'name' => PeopleOverview::shortName($term['structure_name']),
                         'period' => $term['shared_period_name'] ?? $term['name'], 'current' => !empty($term['is_current']), 'wing' => $term['wing'] ?? ''],
      ]); ?>
      <div class="help" id="structure-help"><?= $isEdit
        ? 'Ganti hanya untuk memindahkan penempatan ke struktur lain yang memakai acuan periode yang sama.'
        : 'Hanya struktur dalam lingkup Anda yang memakai acuan periode ini, dan sesuai sayap orangnya (laki-laki: Umum, perempuan: Muslimah).' ?></div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="structure_unit_id">Unit Organisasi (SKUO)</label>
        <select id="structure_unit_id" name="structure_unit_id">
          <option value="">Pengurus Inti</option>
          <optgroup label="SKUO periode <?= e($termLabel) ?>">
          <?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['id'] ?>" data-kind="<?= e($u['kind']) ?>"<?= (int) ($row['structure_unit_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>>
              <?= e(($u['group_name'] ? $u['group_name'] . ' — ' : '') . ($u['display_name'] ?: $u['catalog_name'])) ?>
            </option>
          <?php endforeach; ?>
          </optgroup>
        </select>
        <div class="help">Pilih unit untuk menempatkan di dalam Bidang, Badan, Biro, dan sebagainya.</div>
      </div>

      <div class="field">
        <label for="position_id">Jabatan</label>
        <select id="position_id" name="position_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-core="<?= !empty($p['is_core']) ? 1 : 0 ?>"
                    data-applies-to="<?= e($p['applies_to']) ?>" data-unit-kinds="<?= e($p['unit_kinds'] ?? '') ?>"<?= (int) ($row['position_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['name']) ?><?= !empty($p['is_core']) ? ' (inti)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help" id="position-context-help">Pilihan jabatan mengikuti apakah penempatan di tingkat struktur atau di dalam unit.</div>
      </div>
    </div>

    <div class="pf-preview" id="pf-preview" role="status" aria-live="polite" data-now="<?= e((string) ($landingNow ?? '')) ?>">
      <div class="pf-pv-row"><span class="pf-pv-l">Akan tampil di</span> <strong id="pf-pv-where">&mdash;</strong></div>
      <div class="small" id="pf-pv-note"></div>
      <?php if ($isEdit): ?><div class="small muted">Sekarang tampil di: <b><?= e($landingNow) ?></b></div><?php endif; ?>
    </div>

    <label class="check pf-gap-top">
      <input type="checkbox" name="is_primary" value="1"<?= !empty($row['is_primary']) ? ' checked' : '' ?>>
      <span>Penempatan utama orang ini di periode ini</span>
    </label>
    <div class="help pf-help">Satu orang boleh punya beberapa penempatan di periode yang sama (rangkap jabatan). Tandai yang utama supaya laporan tahu jabatan pokoknya.</div>
  </section>

  <section class="card pf-card" aria-labelledby="pf-h-when">
    <h2 class="pf-step" id="pf-h-when"><i>3</i> Kapan</h2>
    <div class="grid-3">
      <div class="field">
        <label for="start_date">Mulai menjabat</label>
        <input type="date" id="start_date" name="start_date" value="<?= e($row['start_date'] ?? '') ?>">
        <div class="help">Kosongkan bila mengikuti tanggal periode.</div>
      </div>
      <div class="field">
        <label for="end_date">Berakhir</label>
        <input type="date" id="end_date" name="end_date" value="<?= e($row['end_date'] ?? '') ?>">
        <div class="help">Isi hanya untuk pergantian di tengah periode.</div>
      </div>
      <div class="field">
        <label for="end_reason">Alasan berakhir</label>
        <select id="end_reason" name="end_reason">
          <option value="">—</option>
          <?php foreach (['selesai_periode' => 'Selesai periode', 'mengundurkan_diri' => 'Mengundurkan diri', 'mutasi' => 'Mutasi', 'wafat' => 'Wafat', 'diberhentikan' => 'Diberhentikan', 'lainnya' => 'Lainnya'] as $k => $v): ?>
            <option value="<?= $k ?>"<?= ($row['end_reason'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </section>

  <section class="card pf-card" aria-labelledby="pf-h-doc">
    <h2 class="pf-step" id="pf-h-doc"><i>4</i> Dokumen dan catatan <span class="hint">&mdash; boleh dikosongkan</span></h2>
    <div class="field">
      <label for="sk_reference">Rujukan SK</label>
      <input type="text" id="sk_reference" name="sk_reference" value="<?= e($row['sk_reference'] ?? '') ?>">
    </div>
    <div class="field" style="margin-bottom:0">
      <label for="notes">Catatan</label>
      <textarea id="notes" name="notes" rows="3"><?= e($row['notes'] ?? '') ?></textarea>
    </div>
  </section>

  <div class="pf-bar">
    <button type="submit" class="btn"><?= e($submitLabel) ?></button>
    <a class="btn secondary" href="<?= e($cancelUrl) ?>">Batal</a>
    <span class="small muted" id="pf-bar-note"></span>
  </div>
</form>

<?php if ($isEdit): ?>
<aside class="pf-side" aria-label="Penempatan lain orang ini">
  <div class="card">
    <h2>Penempatan lain orang ini <span class="hint">&mdash; periode yang sama</span></h2>
    <?php if (empty($otherPlacements)): ?>
      <p class="muted small" style="margin:0">Tidak ada penempatan lain untuk orang ini pada periode <?= e($term['name']) ?>.</p>
    <?php else: ?>
      <p class="muted small" style="margin:0 0 10px">Periksa apakah rangkap jabatannya memang benar.</p>
      <?php
      $targetStart = $row['start_date'] ?: $term['start_date'];
      $targetEnd = $row['end_date'] ?: ($term['end_date_actual'] ?: ($term['end_date_planned'] ?: '9999-12-31'));
      foreach ($otherPlacements as $other):
          $otherStart = $other['start_date'] ?: $other['term_start'];
          $otherEnd = $other['end_date'] ?: ($other['end_date_actual'] ?: ($other['end_date_planned'] ?: '9999-12-31'));
          $overlaps = $targetStart <= $otherEnd && $otherStart <= $targetEnd;
          // Tempat tampilnya di susunan (cermin PlacementModel::landingLabel).
          $where = !empty($other['structure_unit_id'])
              ? ($other['group_name'] ?: 'Unit tanpa Bidang') . ' › ' . $other['unit_name']
              : (!empty($other['is_core']) ? 'Pengurus Inti' : 'Pimpinan Lainnya');
      ?>
        <div class="pf-other">
          <div class="pf-other-main">
            <strong><?= e($other['position_name']) ?></strong>
            <?= $other['is_primary'] ? '<span class="badge ok">utama</span>' : '<span class="badge">rangkap</span>' ?>
            <?= $other['end_date'] ? '<span class="badge ended">berakhir</span>' : '' ?>
            <div class="small"><?= e($where) ?></div>
            <div class="small muted"><?= e($other['level'] . ' ' . $other['structure_name']) ?> &middot;
              <?= e($other['start_date'] ?: 'awal periode') ?> &ndash; <?= e($other['end_date'] ?: 'akhir periode') ?><?= $overlaps ? '' : ' &middot; tidak bertumpang tindih' ?></div>
          </div>
          <div class="pf-other-act">
            <a class="btn small secondary" href="<?= BASEURL ?>/placements/form/<?= (int) $other['term_id'] ?>/<?= (int) $other['id'] ?>">Ubah</a>
            <form class="inline" method="post" action="<?= BASEURL ?>/placements/delete"
                  data-confirm="Anulir penempatan <?= e($other['position_name']) ?> untuk orang ini? Entri disembunyikan dari susunan, sedangkan data orang dan penempatan lainnya tetap ada."
                  onsubmit="return confirm(this.dataset.confirm)">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int) $other['id'] ?>">
              <button type="submit" class="btn small danger">Anulir</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</aside>
<?php endif; ?>
</div>

<script>
(function () {
  var form = document.getElementById('pf-form');
  var target = document.getElementById('target_term_id');
  var unit = document.getElementById('structure_unit_id');
  var position = document.getElementById('position_id');
  var hint = document.getElementById('position-context-help');
  var personId = document.getElementById('pf-person-id');
  var genderInput = document.getElementById('pf-gender');
  var preview = document.getElementById('pf-preview');
  var pvWhere = document.getElementById('pf-pv-where');
  var pvNote = document.getElementById('pf-pv-note');
  var barNote = document.getElementById('pf-bar-note');
  var baseTermId = <?= (int) $term['id'] ?>;
  var currentTermId = <?= json_encode((int) ($row['term_id'] ?? $term['id'])) ?>;
  var landingNow = preview.getAttribute('data-now') || '';
  if (!form || !target || !unit || !position) return;

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
  }

  /* ---------- Sayap menurut orangnya: laki-laki = Umum, perempuan = Muslimah (pemilih struktur mencarinya di server) ---------- */
  var picker = target.closest('[data-struct-picker]');
  function allowedWing() { return genderInput.value === 'P' ? 'muslimah' : (genderInput.value === 'L' ? 'umum' : ''); }
  function syncStructureChoices() { if (picker && picker.structPicker) picker.structPicker.setWing(allowedWing()); }

  /* ---------- Jabatan menurut tempatnya (tingkat struktur / di dalam unit / jenis unit) ---------- */
  function syncPositionOptions() {
    var context = unit.value ? 'unit' : 'structure';
    // Jenis unit yang dipilih (mis. "biro"): jabatan khusus jenis lain ("Ketua Departemen") ikut disembunyikan. Aturan yang sama dijaga
    // ulang di server (PositionModel::mismatch), jadi ini hanya merapikan pilihan.
    var kind = unit.selectedOptions[0] ? (unit.selectedOptions[0].getAttribute('data-kind') || '') : '';
    Array.prototype.forEach.call(position.options, function (option) {
      if (!option.value) return;
      var appliesTo = option.getAttribute('data-applies-to');
      var kinds = (option.getAttribute('data-unit-kinds') || '').split(',').filter(Boolean);
      var ok = (appliesTo === 'both' || appliesTo === context) && (context !== 'unit' || !kinds.length || kinds.indexOf(kind) !== -1);
      option.hidden = !ok; option.disabled = !ok;
    });
    if (position.selectedOptions[0] && position.selectedOptions[0].disabled) position.value = '';
    hint.textContent = context === 'unit'
      ? 'Menampilkan jabatan yang berlaku di unit ini (menurut jenis unitnya).'
      : 'Menampilkan jabatan yang berlaku di tingkat struktur, tanpa unit.';
    updatePreview();
  }

  /* ---------- Pratinjau: di simpul mana susunan penempatan ini akan tampil ---------- */
  function updatePreview() {
    var p = position.selectedOptions[0], name = p && p.value ? p.getAttribute('data-name') : '';
    var where, note;
    if (unit.value && unit.selectedOptions[0]) {
      where = unit.selectedOptions[0].textContent.trim().replace(/\s+—\s+/, ' › ');
      if (where.indexOf(' › ') < 0) where = 'Unit tanpa Bidang › ' + where;
      note = 'Di dalam unit, tempatnya mengikuti unit itu.';
    } else if (!name) {
      where = '—'; note = 'Pilih jabatan.';
    } else if (p.getAttribute('data-core') === '1') {
      where = 'Pengurus Inti'; note = 'Jabatan ' + name + ' adalah jabatan inti.';
    } else {
      where = 'Pimpinan Lainnya';
      note = 'Jabatan ' + name + ' bukan jabatan inti, jadi tanpa unit ia tampil di Pimpinan Lainnya. Ingin masuk Pengurus Inti? Pilih jabatan inti. Ingin masuk Bidang, Badan, atau Biro? Pilih unitnya.';
    }
    pvWhere.textContent = where;
    var changed = landingNow !== '' && where !== '—' && where !== landingNow && parseInt(target.value, 10) === currentTermId;
    preview.classList.toggle('changed', changed);
    pvNote.textContent = changed ? 'Berpindah dari ' + landingNow + '. ' + note : note;
    barNote.textContent = landingNow !== '' && !changed && parseInt(target.value, 10) === currentTermId ? 'Tempat tampilnya belum berubah.' : '';
  }

  /* ---------- Struktur lain: unit dan jabatannya dimuat ulang ---------- */
  function loadTargetOptions() {
    if (!target.value) { updatePreview(); return; }
    var previousUnit = unit.value, previousPosition = position.value;
    fetch(<?= json_encode(BASEURL . '/placements/options/' . (int) $term['id'] . '/') ?> + encodeURIComponent(target.value), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Pilihan struktur tidak dapat dimuat.');
      return response.json();
    }).then(function (data) {
      var unitHtml = '<option value="">Pengurus Inti</option>';
      if (data.units.length) {
        unitHtml += '<optgroup label="SKUO periode ' + escapeHtml(data.term.level) + ' · ' + escapeHtml(data.term.structure_name) + '">';
        data.units.forEach(function (item) {
          var name = (item.group_name ? item.group_name + ' — ' : '') + (item.display_name || item.catalog_name);
          unitHtml += '<option value="' + item.id + '" data-kind="' + escapeHtml(item.kind || '') + '">' + escapeHtml(name) + '</option>';
        });
        unitHtml += '</optgroup>';
      }
      unit.innerHTML = unitHtml;
      var stillSame = currentTermId === parseInt(target.value, 10);
      if (stillSame) unit.value = previousUnit;

      var positionHtml = '<option value="">— pilih —</option>';
      data.positions.forEach(function (item) {
        var core = item.is_core == 1;
        positionHtml += '<option value="' + item.id + '" data-name="' + escapeHtml(item.name) + '" data-core="' + (core ? 1 : 0) + '" data-applies-to="' + escapeHtml(item.applies_to)
          + '" data-unit-kinds="' + escapeHtml(item.unit_kinds || '') + '">' + escapeHtml(item.name) + (core ? ' (inti)' : '') + '</option>';
      });
      position.innerHTML = positionHtml;
      if (stillSame) position.value = previousPosition;
      syncPositionOptions();
    }).catch(function (error) {
      document.getElementById('structure-help').textContent = error.message;
    });
  }

  /* ---------- Orang (hanya saat menambah): cari lewat placements/orang ---------- */
  var q = document.getElementById('pf-q');
  var results = document.getElementById('pf-results');
  var card = document.getElementById('pf-person-card');
  var searchBox = document.getElementById('pf-search');
  var change = document.getElementById('pf-person-change');

  function setPerson(p) {
    personId.value = p.id; genderInput.value = p.gender;
    document.getElementById('pf-person-name').textContent = p.name;
    document.getElementById('pf-person-name').href = <?= json_encode(BASEURL . '/people/detail/') ?> + p.id;
    document.getElementById('pf-person-meta').textContent = (p.gender === 'P' ? 'Perempuan · sayap Muslimah' : 'Laki-laki · sayap Umum') + (p.sub ? ' · ' + p.sub : '');
    var av = document.createElement('span');
    av.className = 'av av-lg av-c' + (p.hue || 0); av.textContent = p.initial || '?';
    var holder = document.getElementById('pf-person-av'); holder.textContent = ''; holder.appendChild(av);
    card.hidden = false; searchBox.hidden = true; results.hidden = true;
    syncStructureChoices(); updatePreview();
  }

  if (q && results && card && searchBox) {
    var timer = null, seq = 0;
    var showMessage = function (text) {
      results.textContent = '';
      var li = document.createElement('li'); li.className = 'pf-res-empty'; li.textContent = text; results.appendChild(li);
      results.hidden = false; q.setAttribute('aria-expanded', 'true');
    };
    q.addEventListener('input', function () {
      clearTimeout(timer);
      var v = q.value.trim();
      if (v.length < 2) { results.hidden = true; q.setAttribute('aria-expanded', 'false'); return; }
      timer = setTimeout(function () {
        var mine = ++seq;
        fetch(<?= json_encode(BASEURL . '/placements/orang?q=') ?> + encodeURIComponent(v), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { if (!r.ok) throw new Error('gagal'); return r.json(); })
          .then(function (list) {
            if (mine !== seq) return;
            if (!list.length) { showMessage('Tidak ditemukan. Cek ejaan, atau tambahkan orangnya dulu.'); return; }
            results.textContent = '';
            list.forEach(function (p) {
              var li = document.createElement('li'), b = document.createElement('button');
              b.type = 'button'; b.setAttribute('role', 'option');
              var n = document.createElement('span'); n.className = 'pf-res-name'; n.textContent = p.name;
              var s = document.createElement('small'); s.className = 'muted'; s.textContent = (p.gender === 'P' ? 'P' : 'L') + ' · ' + p.sub;
              b.appendChild(n); b.appendChild(s);
              b.addEventListener('click', function () { setPerson(p); });
              li.appendChild(b); results.appendChild(li);
            });
            results.hidden = false; q.setAttribute('aria-expanded', 'true');
          })
          .catch(function () { if (mine === seq) showMessage('Pencarian gagal. Coba lagi.'); });
      }, 250);
    });
    if (change) change.addEventListener('click', function () {
      card.hidden = true; searchBox.hidden = false; personId.value = ''; genderInput.value = '';
      q.value = ''; q.focus(); syncStructureChoices();
    });
    form.addEventListener('submit', function (e) {
      if (personId.value) return;
      e.preventDefault();
      var help = document.getElementById('pf-q-help');
      help.textContent = 'Pilih orangnya dulu dari hasil pencarian.'; help.classList.add('err');
      q.focus();
    });
  }

  form.addEventListener('submit', function (e) {
    if (target.value) return;
    e.preventDefault();
    var help = document.getElementById('structure-help');
    help.textContent = 'Pilih strukturnya dulu.'; help.classList.add('err');
    var box = picker ? picker.querySelector('.sp-q') : null;
    if (box) box.focus();
  });
  target.addEventListener('change', loadTargetOptions);
  unit.addEventListener('change', syncPositionOptions);
  position.addEventListener('change', updatePreview);
  syncStructureChoices();
  syncPositionOptions();
})();
</script>

<?php $this->view('layouts/footer'); ?>
