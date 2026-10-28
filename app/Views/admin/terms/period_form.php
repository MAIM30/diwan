<?php
// PATH LOKASI FILE: app/Views/admin/terms/period_form.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; <a href="<?= BASEURL ?>/terms/period_catalog">Acuan Periode</a> &rsaquo; Buat</div>
<div class="page-head"><h1>Buat Acuan Periode Kepengurusan</h1></div>

<div class="card" style="max-width:680px">
  <form method="post" action="<?= BASEURL ?>/terms/period_save">
    <?= Csrf::field() ?>
    <div class="field">
      <label for="name">Nama periode</label>
      <input type="text" id="name" name="name" required maxlength="50" placeholder="mis. 2026-2031">
    </div>
    <div class="grid-2">
      <div class="field"><label for="start_date">Tanggal mulai</label><input type="date" id="start_date" name="start_date" required></div>
      <div class="field"><label for="end_date_planned">Tanggal akhir rencana</label><input type="date" id="end_date_planned" name="end_date_planned"><div class="help">Tanggal yang tercantum pada SK; boleh dikosongkan bila belum ditentukan.</div></div>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit">Simpan Acuan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/terms/period_catalog">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
