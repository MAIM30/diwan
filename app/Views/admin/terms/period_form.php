<?php
// PATH LOKASI FILE: app/Views/admin/terms/period_form.php
// Buat acuan periode baru, atau (bila $row ada) betulkan nama / tanggal acuan yang keliru. $row['terms'] = struktur yang memakai acuan itu.
$this->view('layouts/header', ['title' => $title]);
$edit = !empty($row['id']);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; <a href="<?= BASEURL ?>/terms/period_catalog">Acuan Periode</a> &rsaquo; <?= $edit ? e($row['name']) . ' &rsaquo; Ubah' : 'Buat' ?></div>
<div class="page-head"><h1><?= e($title) ?></h1></div>

<?php if ($edit): ?>
<div class="note">
  Acuan ini <strong>dipakai bersama</strong>: setiap periode struktur yang memakainya menyimpan salinan nama dan tanggalnya, dan semuanya ikut
  berubah begitu Anda menyimpan (<?= count($row['terms']) ?> periode struktur sekarang). Penempatan pengurus tidak berubah.
  Bila yang keliru hanya pilihan acuan pada <em>satu</em> struktur, jangan ubah acuan ini &mdash; buka periode struktur itu dan pakai
  <strong>Ubah Periode</strong> untuk menggantinya.
</div>
<?php endif; ?>

<div class="card" style="max-width:680px">
  <form method="post" action="<?= BASEURL ?>/terms/period_save">
    <?= Csrf::field() ?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>
    <div class="field">
      <label for="name">Nama periode</label>
      <input type="text" id="name" name="name" required maxlength="50" placeholder="mis. 2026-2031" value="<?= e($row['name'] ?? '') ?>">
    </div>
    <div class="grid-2">
      <div class="field"><label for="start_date">Tanggal mulai</label><input type="date" id="start_date" name="start_date" required value="<?= e($row['start_date'] ?? '') ?>"></div>
      <div class="field"><label for="end_date_planned">Tanggal akhir rencana</label><input type="date" id="end_date_planned" name="end_date_planned" value="<?= e($row['end_date_planned'] ?? '') ?>"><div class="help">Tanggal yang tercantum pada SK; boleh dikosongkan bila belum ditentukan.</div></div>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $edit ? 'Simpan Perubahan' : 'Simpan Acuan' ?></button>
      <a class="btn secondary" href="<?= BASEURL ?>/terms/<?= $edit ? 'period_detail/' . (int) $row['id'] : 'period_catalog' ?>">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
