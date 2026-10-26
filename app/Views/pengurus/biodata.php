<?php
// PATH LOKASI FILE: app/Views/pengurus/biodata.php
// Formulir "Biodata / Profil Singkat Pengurus". Variabel: $person, $placement (penempatan aktif
// pertama, atau null), $existing (baris biodata_submissions, atau $_POST+commitments saat galat
// validasi, atau null kalau belum pernah diisi), $commitmentTexts (BiodataPdf::COMMITMENTS),
// $errors (opsional, kolom => pesan).
//
// Section A (Identitas Diri) SENGAJA read-only di sini -- datanya sama dengan /pengurus/ubah,
// jangan diduplikasi supaya tidak ada dua sumber kebenaran yang bisa berbeda.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'beranda']);

$errors = $errors ?? [];
$e2 = function ($col) use ($existing) {
    if (is_array($existing) && array_key_exists($col, $existing)) return (string) $existing[$col];
    return '';
};
$err = function ($col) use ($errors) { return isset($errors[$col]) ? '<div class="help err" role="alert">' . e($errors[$col]) . '</div>' : ''; };

$jabatanDefault = $existing['jabatan_text'] ?? ($placement['position_name'] ?? '');
$bidangDefault = $existing['bidang_text'] ?? ($placement ? ($placement['unit_name'] ?? trim(($placement['level'] ?? '') . ' ' . ($placement['structure_name'] ?? ''))) : '');
$checkedList = is_array($existing['commitments'] ?? null) ? array_map('intval', $existing['commitments']) : array_keys($commitmentTexts);
$nama = trim(($person['title_prefix'] ?? '') . ' ' . $person['name'] . ' ' . ($person['title_suffix'] ?? ''));
?>

<div class="page-head">
  <h1><?= Ui::icon('file-text', 22) ?> Biodata Pengurus</h1>
  <a class="btn secondary" href="<?= BASEURL ?>/pengurus"><?= Ui::icon('home', 15) ?> Beranda</a>
</div>

<?php if ($errors): ?>
  <div class="alert error" role="alert">Ada isian yang perlu diperbaiki. Belum ada yang disimpan.</div>
<?php endif; ?>

<?php if (!$errors && is_array($existing) && !empty($existing['gdrive_uploaded_at'])): ?>
  <div class="alert info" role="status">
    Biodata Anda sudah terkirim ke Google Drive pada <?= Ui::date($existing['gdrive_uploaded_at']) ?>.
    Mengirim ulang akan <strong>menggantikan</strong> dokumen sebelumnya.
  </div>
<?php endif; ?>

<form method="post" action="<?= BASEURL ?>/pengurus/biodata_process" enctype="multipart/form-data" autocomplete="off">
  <?= Csrf::field() ?>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('id-card', 19) ?> A. Identitas Diri</h2></div>

    <div class="photo-field">
      <div data-photo-preview><?= Ui::avatar(array_merge($person, ['photo_path' => null]), 'xl') ?></div>
      <div class="field">
        <label for="photo">Foto untuk biodata</label>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" data-shrink-image>
        <div class="help">JPG, PNG, atau WebP, dipotong persegi dari tengah. Tanpa unggahan, PDF memakai foto profil Anda bila ada.</div>
        <?= $err('photo') ?>
      </div>
    </div>

    <p class="small muted" style="margin:0 0 12px">
      Diambil dari data Anda. Keliru? Ubah dulu di <a href="<?= BASEURL ?>/pengurus/ubah">Ubah Data Saya</a>, lalu kembali ke sini.
    </p>
    <div class="grid-2">
      <div class="field"><label>Nama Lengkap dan Gelar</label><div class="static"><?= e($nama) ?></div></div>
      <div class="field"><label>Tempat, Tanggal Lahir</label><div class="static"><?= e(trim(($person['birth_place'] ?? '-') . ($person['birth_date'] ? ', ' . Ui::date($person['birth_date']) : ''))) ?></div></div>
      <div class="field"><label>Domisili</label><div class="static"><?= e($person['city'] ?? '-') ?></div></div>
      <div class="field"><label>Nomor HP/WhatsApp</label><div class="static"><?= e($person['phone'] ?? '-') ?></div></div>
      <div class="field"><label>Pekerjaan/Profesi</label><div class="static"><?= e($person['occupation'] ?? '-') ?></div></div>
      <div class="field"><label>No. HP Keluarga</label><div class="static"><?= e($person['family_phone'] ?? '-') ?></div></div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('briefcase', 19) ?> B. Profil Keorganisasian</h2></div>

    <div class="grid-2">
      <div class="field">
        <label for="jabatan_text">Jabatan/Amanah</label>
        <input type="text" id="jabatan_text" name="jabatan_text" maxlength="150" value="<?= e($existing ? $e2('jabatan_text') : $jabatanDefault) ?>">
        <div class="help">Otomatis dari penempatan aktif Anda. Ubah di sini kalau sebutannya beda.</div>
        <?= $err('jabatan_text') ?>
      </div>
      <div class="field">
        <label for="bidang_text">Bidang/Departemen/Lembaga</label>
        <input type="text" id="bidang_text" name="bidang_text" maxlength="150" value="<?= e($existing ? $e2('bidang_text') : $bidangDefault) ?>">
        <?= $err('bidang_text') ?>
      </div>
    </div>

    <div class="field">
      <label for="pengalaman_organisasi">Pengalaman Organisasi</label>
      <textarea id="pengalaman_organisasi" name="pengalaman_organisasi" rows="3" maxlength="2000"><?= e($e2('pengalaman_organisasi')) ?></textarea>
      <?= $err('pengalaman_organisasi') ?>
    </div>
    <div class="field">
      <label for="kompetensi">Kompetensi/Keahlian</label>
      <textarea id="kompetensi" name="kompetensi" rows="3" maxlength="1000" placeholder="Satu baris, satu keahlian"><?= e($e2('kompetensi')) ?></textarea>
      <?= $err('kompetensi') ?>
    </div>
    <div class="field">
      <label for="karya_kontribusi">Karya/Kontribusi</label>
      <textarea id="karya_kontribusi" name="karya_kontribusi" rows="3" maxlength="2000"><?= e($e2('karya_kontribusi')) ?></textarea>
      <?= $err('karya_kontribusi') ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('shield', 19) ?> Komitmen Kesiapan Menjadi Pengurus</h2></div>
    <p class="small muted" style="margin:0 0 12px">Tandai pernyataan yang Anda pilih:</p>
    <div class="check-list">
      <?php foreach ($commitmentTexts as $n => $text): ?>
        <label class="check-row">
          <input type="checkbox" name="commitments[]" value="<?= (int) $n ?>" <?= in_array((int) $n, $checkedList, true) ? 'checked' : '' ?>>
          <span class="check-no"><?= (int) $n ?></span>
          <span class="check-text"><?= e($text) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('calendar', 19) ?> Pernyataan Akhir</h2></div>
    <p class="small muted" style="margin:0 0 14px">
      Dengan memilih pernyataan di atas, saya menyatakan bahwa saya memahami kepengurusan sebagai amanah
      yang membutuhkan kesiapan diri untuk berkontribusi secara utuh. Saya bersedia memberikan kontribusi
      terbaik sesuai kemampuan dan amanah yang diberikan.
    </p>
    <div class="grid-2">
      <div class="field">
        <label for="signed_place">Tempat</label>
        <input type="text" id="signed_place" name="signed_place" maxlength="100" value="<?= e($e2('signed_place')) ?>" placeholder="mis. Makassar">
        <?= $err('signed_place') ?>
      </div>
      <div class="field">
        <label for="signed_date">Tanggal</label>
        <input type="date" id="signed_date" name="signed_date" value="<?= e($e2('signed_date') ?: date('Y-m-d')) ?>">
        <?= $err('signed_date') ?>
      </div>
    </div>
  </section>

  <div class="btn-row">
    <button type="submit" class="btn">Simpan &amp; Kirim</button>
    <a class="btn secondary" href="<?= BASEURL ?>/pengurus">Batal</a>
  </div>
</form>

<?php $this->view('layouts/member_footer'); ?>
