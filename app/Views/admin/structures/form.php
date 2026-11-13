<?php
// PATH LOKASI FILE: app/Views/admin/structures/form.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/structures">Struktur</a> &rsaquo; <?= e($title) ?></div>
<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="card" style="max-width:680px">
  <form method="post" action="<?= BASEURL ?>/structures/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">

    <div class="field">
      <label for="name">Nama Struktur</label>
      <input type="text" id="name" name="name" required value="<?= e($row['name'] ?? '') ?>"
             placeholder="mis. Sulawesi Tenggara">
      <div class="help">Tulis tanpa awalan tingkat. Awalan (DPW, MWW, dst.) diambil dari pilihan Tingkat.</div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="level">Tingkat</label>
        <?php
        // Keterangan tiap tingkat dibaca dari daftar tingkat (StructureLevels) dan ditampilkan di bawah pilihan begitu dipilih; induk wajib
        // dipakai skrip di bawah untuk menyaring pilihan Induk.
        $levelInfo = []; $levelParent = [];
        foreach ($levels as $lv) {
            $d = StructureLevels::find($lv);
            if (!$d) continue;
            $info = $d['name'] . ' · ' . (StructureLevels::GROUPS[$d['grp']] ?? $d['grp']) . ' · setingkat ' . Ui::tierName($d['tier']);
            $req = StructureLevels::requiredParent($lv);
            if ($req !== null) $info .= ' · wajib di bawah ' . $req;
            elseif (StructureLevels::parentOf($lv) !== null) $info .= ' · lazimnya di bawah ' . StructureLevels::parentOf($lv);
            $levelInfo[$lv] = $info;
            if ($req !== null) $levelParent[$lv] = $req;
        }
        ?>
        <select id="level" name="level" required>
          <option value="">— pilih —</option>
          <?php foreach ($levels as $lv): ?>
            <option value="<?= e($lv) ?>" data-info="<?= e($levelInfo[$lv] ?? '') ?>" <?= ($row['level'] ?? '') === $lv ? 'selected' : '' ?>><?= e($lv) ?><?= StructureLevels::find($lv) && empty(StructureLevels::find($lv)['is_active']) ? ' (nonaktif)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help" id="level-help">Pilih tingkat; keterangannya tampil di sini.<?php if (Auth::isSuperAdmin()): ?> Tingkat lain bisa ditambahkan di <a href="<?= BASEURL ?>/levels">Tingkat Struktur</a>.<?php endif; ?></div>
      </div>

      <div class="field">
        <label for="parent_id">Induk</label>
        <select id="parent_id" name="parent_id">
          <option value="">— tanpa induk (akar) —</option>
          <?php foreach ($parents as $p): ?>
            <?php if (!empty($row['id']) && (int) $p['id'] === (int) $row['id']) continue; ?>
              <option value="<?= (int) $p['id'] ?>" data-parent-level="<?= e($p['level']) ?>" <?= (int) ($row['parent_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e(str_repeat('— ', (int) $p['depth']) . $p['level'] . ' ' . $p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help">Struktur tidak boleh dijadikan induk bagi dirinya atau keturunannya.</div>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="region_code">Kode Wilayah (opsional)</label>
        <input type="text" id="region_code" name="region_code" value="<?= e($row['region_code'] ?? '') ?>"
               placeholder="mis. SULTRA">
        <div class="help">
          Pengait antar sayap: beri kode yang SAMA pada DPW dan MWW yang mewakili wilayah sama.
          Ini hanya untuk laporan &mdash; keduanya tetap dua node terpisah dan lingkup aksesnya tidak bercampur.
        </div>
      </div>

      <div class="field">
        <label>Status</label>
        <label class="check">
          <input type="checkbox" name="is_active" value="1" <?= !isset($row['is_active']) || $row['is_active'] ? 'checked' : '' ?>>
          <span>Aktif</span>
        </label>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/structures">Batal</a>
    </div>
  </form>
</div>

<script>
(function () {
  var level = document.getElementById('level');
  var parent = document.getElementById('parent_id');
  if (!level || !parent) return;
  var requiredParent = <?= json_encode($levelParent ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
  var help = document.getElementById('level-help');
  var helpDefault = help ? help.innerHTML : '';
  function syncParent() {
    var required = requiredParent[level.value] || '';
    var info = level.selectedOptions[0] ? level.selectedOptions[0].getAttribute('data-info') : '';
    if (help && info) help.textContent = info; else if (help) help.innerHTML = helpDefault;
    Array.prototype.forEach.call(parent.options, function (option) {
      if (!option.value) return;
      option.hidden = !!required && option.getAttribute('data-parent-level') !== required;
      option.disabled = option.hidden;
    });
    if (parent.selectedOptions[0] && parent.selectedOptions[0].disabled) parent.value = '';
  }
  level.addEventListener('change', syncParent);
  syncParent();
})();
</script>

<?php $this->view('layouts/footer'); ?>
