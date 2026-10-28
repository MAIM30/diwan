<?php
// PATH LOKASI FILE: app/Views/admin/placements/form.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb">
  <a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo;
  <a href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>"><?= e($term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name']) ?></a>
  &rsaquo; <?= e($title) ?>
</div>

<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="note">
  Struktur asal: <strong><?= e($term['level'] . ' · ' . $term['structure_name']) ?></strong>
  &nbsp;|&nbsp; Periode: <strong><?= e($term['name']) ?></strong>.
  Periode ini memiliki acuan bersama yang dapat dipakai oleh beberapa struktur.
  Pilih struktur tujuan yang sudah memakai acuan periode yang sama.
</div>

<div class="card" style="max-width:720px">
  <form method="post" action="<?= BASEURL ?>/placements/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
    <input type="hidden" name="base_term_id" value="<?= (int) $term['id'] ?>">

    <?php $selectedTermId = (int) ($row['term_id'] ?? $term['id']); ?>
    <div class="grid-2">
      <div class="field">
        <label for="gender">Jenis Kelamin</label>
        <?php if (!empty($row['id'])): ?>
          <input type="hidden" id="gender" name="gender" value="<?= e($row['gender']) ?>">
          <input type="text" value="<?= $row['gender'] === 'P' ? 'Perempuan' : 'Laki-laki' ?>" disabled>
        <?php else: ?>
          <select id="gender" name="gender" required>
            <option value="">— pilih —</option>
            <option value="L">Laki-laki</option>
            <option value="P">Perempuan</option>
          </select>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="structure_level">Tingkat Struktur</label>
        <select id="structure_level" required>
          <option value="">— pilih tingkat —</option>
          <?php foreach ($targetTerms as $target): ?>
            <option value="<?= e($target['level']) ?>" data-wing="<?= e($target['wing']) ?>" <?= ($row && $row['level'] === $target['level']) ? 'selected' : '' ?>><?= e($target['level']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="structure_search">Cari Struktur</label>
      <input type="search" id="structure_search" placeholder="Ketik nama atau tingkat struktur..." autocomplete="off">
      <select id="target_term_id" name="target_term_id" required>
        <option value="">— pilih struktur —</option>
        <?php foreach ($targetTerms as $target): ?>
          <option value="<?= (int) $target['id'] ?>" data-level="<?= e($target['level']) ?>" data-wing="<?= e($target['wing']) ?>" data-search="<?= e(strtolower($target['level'] . ' ' . $target['structure_name'])) ?>" <?= $selectedTermId === (int) $target['id'] ? 'selected' : '' ?>>
            <?= e($target['level'] . ' · ' . $target['structure_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="help" id="structure-help">Hanya struktur dalam lingkup Anda yang sudah memakai acuan periode ini yang ditampilkan.</div>
    </div>

    <div class="field">
      <label for="person_id">Orang</label>
      <select id="person_id" name="person_id" required <?= !empty($row['id']) ? 'disabled' : '' ?>>
        <option value="">— pilih —</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int) $p['id'] ?>" data-gender="<?= e($p['gender']) ?>" <?= (int) ($row['person_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
            <?= e(Ui::fullName($p)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($row['id'])): ?>
        <input type="hidden" name="person_id" value="<?= (int) $row['person_id'] ?>">
        <div class="help">Orang tidak bisa ditukar pada penempatan yang sudah ada. Anulir penempatan
          ini lalu buat yang baru bila memang salah orang.</div>
      <?php else: ?>
        <div class="help">Pilih orang dengan jenis kelamin yang sesuai struktur. Belum ada di daftar?
          <a href="<?= BASEURL ?>/people/form" target="_blank">Tambahkan orang</a> dulu.</div>
      <?php endif; ?>
    </div>

    <?php if (!empty($row['id'])): ?>
      <div class="field">
        <label>Penempatan lain orang ini dalam periode yang sama</label>
        <?php if (empty($otherPlacements)): ?>
          <div class="help">Tidak ada penempatan lain untuk orang ini pada periode <?= e($term['name']) ?>.</div>
        <?php else: ?>
          <div class="help">Periksa apakah rangkap jabatan ini memang benar. Entri dengan masa yang bertumpang tindih ditandai sebagai rangkap.</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Jabatan / SKUO</th><th>Masa</th><th>Status masa</th><th></th></tr>
              </thead>
              <tbody>
              <?php
              $targetStart = $row['start_date'] ?: $term['start_date'];
              $targetEnd = $row['end_date'] ?: ($term['end_date_actual'] ?: ($term['end_date_planned'] ?: '9999-12-31'));
              foreach ($otherPlacements as $other):
                  $otherStart = $other['start_date'] ?: $other['term_start'];
                  $otherEnd = $other['end_date'] ?: ($other['end_date_actual'] ?: ($other['end_date_planned'] ?: '9999-12-31'));
                  $overlaps = $targetStart <= $otherEnd && $otherStart <= $targetEnd;
              ?>
                <tr>
                  <td>
                    <strong><?= e($other['position_name']) ?></strong>
                    <div class="small muted"><?= e($other['level'] . ' ' . $other['structure_name']) ?></div>
                    <div class="small muted"><?= e($other['group_name'] ? $other['group_name'] . ' · ' : '') ?><?= e($other['unit_name'] ?: 'Pengurus Inti / tingkat struktur') ?></div>
                  </td>
                  <td class="small">
                    <?= e($other['start_date'] ?: 'awal periode') ?> – <?= e($other['end_date'] ?: 'akhir periode') ?>
                  </td>
                  <td>
                    <?php if ($overlaps): ?><span class="badge ended">Masa bertumpang tindih</span>
                    <?php else: ?><span class="badge">Tidak bertumpang tindih</span><?php endif; ?>
                  </td>
                  <td class="nowrap">
                    <a class="btn small secondary" href="<?= BASEURL ?>/placements/form/<?= (int) $other['term_id'] ?>/<?= (int) $other['id'] ?>">Ubah</a>
                    <form class="inline" method="post" action="<?= BASEURL ?>/placements/delete"
                          onsubmit="return confirm(this.dataset.confirm)"
                          data-confirm="Anulir penempatan <?= e($other['position_name']) ?> untuk orang ini? Entri akan disembunyikan dari susunan, sedangkan data orang dan penempatan lainnya tetap ada.">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="id" value="<?= (int) $other['id'] ?>">
                      <button type="submit" class="btn small danger">Anulir</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="grid-2">
      <div class="field">
        <label for="structure_unit_id">Unit Organisasi (SKUO)</label>
        <select id="structure_unit_id" name="structure_unit_id">
          <option value="">PENGURUS INTI (tingkat struktur, tanpa unit)</option>
          <optgroup label="SKUO periode <?= e($term['level'] . ' · ' . $term['structure_name']) ?>">
          <?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($row['structure_unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
              <?= e(($u['group_name'] ? $u['group_name'] . ' — ' : '') . ($u['display_name'] ?: $u['catalog_name'])) ?>
            </option>
          <?php endforeach; ?>
          </optgroup>
        </select>
        <div class="help">Daftar SKUO diambil dari periode dan struktur di atas. Pilih “Pengurus Inti” untuk jabatan tingkat struktur tanpa SKUO.</div>
      </div>

      <div class="field">
        <label for="position_id">Jabatan</label>
        <select id="position_id" name="position_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-applies-to="<?= e($p['applies_to']) ?>" <?= (int) ($row['position_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e($p['name']) ?><?= PositionModel::isCoreAt($p, $term['tier']) ? ' (inti)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help" id="position-context-help">Pilihan jabatan mengikuti apakah penempatan berada di tingkat struktur atau di dalam SKUO.</div>
      </div>
    </div>

    <div class="field">
      <label class="check">
        <input type="checkbox" name="is_primary" value="1" <?= !empty($row['is_primary']) ? 'checked' : '' ?>>
        <span>Penempatan utama orang ini di periode ini</span>
      </label>
      <div class="help">Satu orang boleh punya beberapa penempatan di periode yang sama (rangkap
        jabatan). Tandai yang utama supaya laporan tahu mana yang dipakai sebagai jabatan pokok.</div>
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="start_date">Mulai Menjabat</label>
        <input type="date" id="start_date" name="start_date" value="<?= e($row['start_date'] ?? '') ?>">
        <div class="help">Kosongkan bila mengikuti tanggal periode.</div>
      </div>
      <div class="field">
        <label for="end_date">Berakhir</label>
        <input type="date" id="end_date" name="end_date" value="<?= e($row['end_date'] ?? '') ?>">
        <div class="help">Isi hanya untuk pergantian di tengah periode.</div>
      </div>
      <div class="field">
        <label for="end_reason">Alasan Berakhir</label>
        <select id="end_reason" name="end_reason">
          <option value="">—</option>
          <?php foreach (['selesai_periode'=>'Selesai periode','mengundurkan_diri'=>'Mengundurkan diri','mutasi'=>'Mutasi','wafat'=>'Wafat','diberhentikan'=>'Diberhentikan','lainnya'=>'Lainnya'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($row['end_reason'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="sk_reference">Rujukan SK</label>
      <input type="text" id="sk_reference" name="sk_reference" value="<?= e($row['sk_reference'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="notes">Catatan</label>
      <textarea id="notes" name="notes"><?= e($row['notes'] ?? '') ?></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>">Batal</a>
    </div>
  </form>
</div>

<script>
(function () {
  var gender = document.getElementById('gender');
  var level = document.getElementById('structure_level');
  var search = document.getElementById('structure_search');
  var target = document.getElementById('target_term_id');
  var person = document.getElementById('person_id');
  var unit = document.getElementById('structure_unit_id');
  var position = document.getElementById('position_id');
  var hint = document.getElementById('position-context-help');
  var baseTermId = <?= (int) $term['id'] ?>;
  if (!gender || !level || !target || !person || !unit || !position) return;

  function allowedWing() { return gender.value === 'P' ? 'muslimah' : (gender.value === 'L' ? 'umum' : ''); }

  function filterOptions(select, predicate, keepBlank) {
    Array.prototype.forEach.call(select.options, function (option) {
      if (!option.value) return;
      var allowed = predicate(option);
      option.hidden = !allowed;
      option.disabled = !allowed;
    });
    if (select.selectedOptions[0] && select.selectedOptions[0].disabled) select.value = '';
  }

  function syncStructureChoices() {
    var wing = allowedWing();
    filterOptions(level, function (option) { return option.getAttribute('data-wing') === wing; });
    filterOptions(target, function (option) {
      var text = option.getAttribute('data-search') || '';
      return option.getAttribute('data-wing') === wing
        && (!level.value || option.getAttribute('data-level') === level.value)
        && (!search.value || text.indexOf(search.value.toLowerCase()) >= 0);
    });
    filterOptions(person, function (option) { return option.getAttribute('data-gender') === gender.value; });
  }

  function syncPositionOptions() {
    var context = unit.value ? 'unit' : 'structure';
    Array.prototype.forEach.call(position.options, function (option) {
      if (!option.value) return;
      var appliesTo = option.getAttribute('data-applies-to');
      var allowed = appliesTo === 'both' || appliesTo === context;
      option.hidden = !allowed;
      option.disabled = !allowed;
    });
    if (position.selectedOptions[0] && position.selectedOptions[0].disabled) position.value = '';
    hint.textContent = context === 'unit'
      ? 'Menampilkan jabatan yang berlaku di dalam SKUO struktur tujuan.'
      : 'Menampilkan jabatan yang berlaku di tingkat struktur tujuan, tanpa SKUO.';
  }

  function loadTargetOptions() {
    if (!target.value) return;
    var previousUnit = unit.value;
    var previousPosition = position.value;
    fetch(<?= json_encode(BASEURL . '/placements/options/' . (int) $term['id'] . '/') ?> + encodeURIComponent(target.value), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Pilihan struktur tidak dapat dimuat.');
      return response.json();
    }).then(function (data) {
      var unitHtml = '<option value="">PENGURUS INTI (tingkat struktur, tanpa unit)</option>';
      if (data.units.length) {
        unitHtml += '<optgroup label="SKUO periode ' + data.term.level + ' · ' + escapeHtml(data.term.structure_name) + '">';
        data.units.forEach(function (item) {
          var name = item.group_name ? item.group_name + ' — ' : '';
          name += item.display_name || item.catalog_name;
          unitHtml += '<option value="' + item.id + '">' + escapeHtml(name) + '</option>';
        });
        unitHtml += '</optgroup>';
      }
      unit.innerHTML = unitHtml;
      var stillSame = <?= json_encode((int) ($row['term_id'] ?? $term['id'])) ?> === parseInt(target.value, 10);
      if (stillSame) unit.value = previousUnit;

      var positionHtml = '<option value="">— pilih —</option>';
      data.positions.forEach(function (item) {
        var core = item.is_core == 1 || (item.core_from_tier !== null && parseInt(data.term.tier, 10) >= parseInt(item.core_from_tier, 10));
        positionHtml += '<option value="' + item.id + '" data-applies-to="' + item.applies_to + '">' + escapeHtml(item.name) + (core ? ' (inti)' : '') + '</option>';
      });
      position.innerHTML = positionHtml;
      if (stillSame) position.value = previousPosition;
      syncPositionOptions();
    }).catch(function (error) {
      document.getElementById('structure-help').textContent = error.message;
    });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char];
    });
  }

  gender.addEventListener('change', syncStructureChoices);
  level.addEventListener('change', function () { syncStructureChoices(); loadTargetOptions(); });
  search.addEventListener('input', syncStructureChoices);
  target.addEventListener('change', loadTargetOptions);
  unit.addEventListener('change', syncPositionOptions);
  syncStructureChoices();
  syncPositionOptions();
})();
</script>

<?php $this->view('layouts/footer'); ?>
