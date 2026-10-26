<?php
// PATH LOKASI FILE: app/Views/pengurus/ubah.php
// Pengurus memperbarui data pribadinya sendiri. Variabel: $person, $errors (kolom => pesan), $old ($_POST saat galat).
//
// PENTING (lihat MemberModel::SELF_FIELDS): sesi portal hanya bermodal nomor WA yang bukan rahasia, jadi
//   * bidang KASAR (provinsi, kota, kecamatan, kelurahan, tempat lahir, pekerjaan) tampil terisi dan bisa dikosongkan; empat yang pertama
//     dipilih bertingkat dari data wilayah resmi (app.js, data-lokasi), dan tetap bisa diketik bila daftarnya tak bisa dimuat;
//   * bidang SENSITIF (jalan, tanggal lahir, email, HP keluarga) TIDAK ditampilkan -- hanya diberi lencana "sudah terisi";
//     kolom kosong berarti "jangan ubah", dan isinya hanya terganti bila mengetik yang baru;
//   * nomor WA dan NIK tidak bisa diubah dari sini (kredensial masuk).
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'data']);

$val = function ($col) use ($old, $person) { return array_key_exists($col, $old) && is_string($old[$col]) ? $old[$col] : (string) ($person[$col] ?? ''); };
$err = function ($col) use ($errors) { return isset($errors[$col]) ? '<div class="help err" role="alert">' . e($errors[$col]) . '</div>' : ''; };
$mark = function ($col) use ($person) { return empty($person[$col]) ? '' : '<div class="help"><span class="badge ok">sudah terisi</span></div>'; };
?>

<div class="page-head"><h1>Ubah Data Saya</h1></div>

<?php if ($errors): ?>
  <div class="alert error" role="alert">Ada isian yang perlu diperbaiki. Belum ada yang disimpan.</div>
<?php endif; ?>

<form method="post" action="<?= BASEURL ?>/pengurus/ubah_process" autocomplete="off" data-lokasi="<?= BASEURL ?>/lokasi">
  <?= Csrf::field() ?>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('map-pin', 19) ?> Domisili</h2></div>

    <div class="field">
      <label for="address_text">Jalan / BTN / Lorong (dan nomor rumah)</label>
      <input type="text" id="address_text" name="address_text" maxlength="255" value="<?= e($val('address_text') === (string) ($person['address_text'] ?? '') && !array_key_exists('address_text', $old) ? '' : $val('address_text')) ?>"
             placeholder="mis. BTN Mawar Blok C No. 5, Lorong 3">
      <?= $mark('address_text') ?>
      <?= $err('address_text') ?>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="province">Provinsi</label>
        <input type="text" id="province" name="province" maxlength="100" data-lokasi-field="province" value="<?= e($val('province')) ?>">
        <?= $err('province') ?>
      </div>
      <div class="field">
        <label for="city">Kota / Kabupaten</label>
        <input type="text" id="city" name="city" maxlength="100" data-lokasi-field="city" value="<?= e($val('city')) ?>" placeholder="mis. Kota Kendari / Kabupaten Bone">
        <?= $err('city') ?>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="district">Kecamatan</label>
        <input type="text" id="district" name="district" maxlength="100" data-lokasi-field="district" value="<?= e($val('district')) ?>">
        <?= $err('district') ?>
      </div>
      <div class="field">
        <label for="village">Kelurahan / Desa</label>
        <input type="text" id="village" name="village" maxlength="100" data-lokasi-field="village" value="<?= e($val('village')) ?>">
        <?= $err('village') ?>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('user', 19) ?> Data diri lain</h2></div>

    <div class="grid-2">
      <div class="field">
        <label for="birth_place">Tempat lahir</label>
        <input type="text" id="birth_place" name="birth_place" maxlength="100" value="<?= e($val('birth_place')) ?>">
        <?= $err('birth_place') ?>
      </div>
      <div class="field">
        <label for="birth_date">Tanggal lahir</label>
        <input type="date" id="birth_date" name="birth_date" value="<?= e(array_key_exists('birth_date', $old) && is_string($old['birth_date']) ? $old['birth_date'] : '') ?>"
               min="1930-01-01" max="<?= (int) date('Y') - 10 ?>-12-31">
        <?= $mark('birth_date') ?>
        <?= $err('birth_date') ?>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" maxlength="150" autocapitalize="none" value="<?= e(array_key_exists('email', $old) && is_string($old['email']) ? $old['email'] : '') ?>">
        <?= $mark('email') ?>
        <?= $err('email') ?>
      </div>
      <div class="field">
        <label for="occupation">Pekerjaan / profesi</label>
        <input type="text" id="occupation" name="occupation" maxlength="150" value="<?= e($val('occupation')) ?>">
        <?= $err('occupation') ?>
      </div>
    </div>

    <div class="field">
      <label for="family_phone">Nomor HP keluarga (kontak darurat)</label>
      <input type="tel" id="family_phone" name="family_phone" inputmode="tel" maxlength="20" value="<?= e(array_key_exists('family_phone', $old) && is_string($old['family_phone']) ? $old['family_phone'] : '') ?>" placeholder="08xxxxxxxxxx">
      <?= $mark('family_phone') ?>
      <?= $err('family_phone') ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('lock', 19) ?> Yang tidak bisa diubah di sini</h2></div>
    <p class="small muted" style="margin:0">
      Nama &amp; gelar, jenis kelamin, <strong>nomor WhatsApp</strong>, dan <strong>NIK</strong> diubah lewat admin struktur Anda.
    </p>
  </section>

  <div class="btn-row">
    <button type="submit" class="btn">Simpan perubahan</button>
    <a class="btn secondary" href="<?= BASEURL ?>/pengurus/data">Batal</a>
  </div>
</form>

<?php $this->view('layouts/member_footer'); ?>
