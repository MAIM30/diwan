<?php
// PATH LOKASI FILE: app/Views/admin/units/_pasang.php
// "Pasang ke struktur": memasang SATU entri katalog ke periode berjalan sebuah struktur, lalu pulang ke Pengelompokan Unit pada struktur itu.
// Kelompoknya otomatis menurut jenis unit (Biro-Biro, Badan-Badan, Pusat-Pusat; dibuat bila belum ada). Dipakai kotak "baru ditambahkan"
// dan baris katalog yang belum terpasang. Yang menyimpan tetap units/unit_save (masuk=auto), jadi aturannya satu.
// Variabel: $u (id, name, kind, wing), $targets (UnitModel::installTargets), $idp (awalan id elemen, unik per pemakaian).

$mine = array_values(array_filter($targets, function ($t) use ($u) { return $t['wing'] === $u['wing']; }));
// Struktur sesayap sedikit (admin satu wilayah): tampilkan semuanya. Banyak (admin nasional): hanya yang sudah disusun, supaya pilihannya
// tidak ratusan; sisanya lewat Pengelompokan Unit.
$choices = count($mine) <= 15 ? $mine : array_values(array_filter($mine, function ($t) { return $t['disusun']; }));
$autoGroup = UnitModel::KIND_GROUP[$u['kind']][0] ?? null;
?>
<?php if ($choices): ?>
  <form method="post" action="<?= BASEURL ?>/units/unit_save" class="pasang-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="unit_catalog_id" value="<?= (int) $u['id'] ?>">
    <input type="hidden" name="masuk" value="auto">
    <input type="hidden" name="return" value="grouping">
    <div class="sn-form">
      <div class="field">
        <label for="<?= e($idp) ?>-term">Pasang ke struktur</label>
        <select id="<?= e($idp) ?>-term" name="term_id" required>
          <option value="">&mdash; pilih struktur &mdash;</option>
          <?php foreach ($choices as $t): ?>
            <option value="<?= (int) $t['term_id'] ?>"><?= e($t['label']) ?> &middot; <?= e($t['term_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn">Pasang</button>
    </div>
  </form>
<?php endif; ?>
<p class="help" style="margin:8px 0 0">
  <?php if ($autoGroup): ?>
    Masuk otomatis ke kelompok &ldquo;<?= e($autoGroup) ?>&rdquo; (dibuat bila struktur itu belum punya).
  <?php else: ?>
    Jenis ini hidup di dalam Bidang: unit dipasang tanpa Bidang, lalu pilih Bidangnya di Pengelompokan Unit.
  <?php endif; ?>
  Struktur yang tidak ada di daftar: buka
  <a href="<?= BASEURL ?>/units/grouping?st=semua">Pengelompokan Unit</a>, cari strukturnya, lalu <strong>Tambah unit</strong>.
</p>
