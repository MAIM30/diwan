<?php
// PATH LOKASI FILE: app/Views/admin/settings/gdrive.php
$this->view('layouts/header', ['title' => $title]);

$s = $settings;
$hasClient  = !empty($s['gdrive_client_id']) && !empty($s['gdrive_client_secret']);
$configured = $hasClient && !empty($s['gdrive_refresh_token']) && !empty($s['gdrive_folder_id']);
?>

<div class="page-head">
  <h1><?= Ui::icon('file-text', 22) ?> Pengaturan Google Drive</h1>
  <span class="badge <?= $configured ? 'ok' : '' ?>"><?= $configured ? 'Terhubung' : 'Belum terhubung' ?></span>
</div>

<div class="note">
  PDF Biodata Pengurus yang dikirim lewat Portal Pengurus otomatis diunggah ke satu folder di Google Drive
  akun yang Anda hubungkan di bawah. Berkas menjadi milik akun itu dan memakai kuota Drive-nya.
</div>

<?php if ($configured): ?>
<div class="card">
  <h2>Folder Tujuan</h2>
  <p>
    <a href="https://drive.google.com/drive/folders/<?= e($s['gdrive_folder_id']) ?>" target="_blank" rel="noopener">
      <?= Ui::icon('file-text', 16) ?> <?= e(GoogleDrive::FOLDER_NAME) ?>
    </a>
  </p>
  <p class="small muted">
    Folder ini dibuat otomatis oleh DIWAN. Aplikasi hanya bisa menyentuh folder dan berkas yang dibuatnya sendiri,
    bukan isi Drive Anda yang lain. PDF lama yang sudah ada boleh Anda pindahkan ke folder ini lewat Drive.
  </p>
  <form method="post" action="<?= BASEURL ?>/settings/gdrive_test" style="margin-top:8px">
    <?= Csrf::field() ?>
    <button type="submit" class="btn secondary"><?= Ui::icon('activity', 16) ?> Tes Koneksi</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>1. Client OAuth Google</h2>
  <form method="post" action="<?= BASEURL ?>/settings/gdrive_save">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="gdrive_client_id">Client ID</label>
      <input type="text" id="gdrive_client_id" name="gdrive_client_id"
             value="<?= e($s['gdrive_client_id'] ?? '') ?>"
             placeholder="123456789-xxxx.apps.googleusercontent.com" autocomplete="off">
    </div>

    <div class="field">
      <label for="gdrive_client_secret">Client Secret</label>
      <input type="password" id="gdrive_client_secret" name="gdrive_client_secret"
             placeholder="<?= !empty($s['gdrive_client_secret']) ? 'Tersimpan -- kosongkan bila tidak diganti' : 'GOCSPX-...' ?>"
             autocomplete="off">
    </div>

    <div style="display:flex; gap:8px; margin-top:12px">
      <button type="submit" class="btn">Simpan</button>
    </div>
  </form>
</div>

<?php if ($hasClient): ?>
<div class="card">
  <h2>2. Hubungkan Akun Google</h2>
  <p class="small muted">
    Anda akan diarahkan ke Google untuk memberi izin. Pakai akun yang ingin menyimpan PDF biodatanya.
    Folder "<?= e(GoogleDrive::FOLDER_NAME) ?>" dibuat otomatis di Drive akun itu.
  </p>
  <form method="post" action="<?= BASEURL ?>/settings/gdrive_connect" style="margin-top:8px">
    <?= Csrf::field() ?>
    <button type="submit" class="btn"><?= Ui::icon('file-text', 16) ?> <?= $configured ? 'Hubungkan Ulang' : 'Hubungkan Google Drive' ?></button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Cara Membuat Client OAuth (sekali saja)</h2>
  <ol style="margin:0; padding-left:20px; line-height:1.8">
    <li>Buka <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>, pilih project yang sudah ada (<strong>diwan-biodata</strong>) dan pastikan <strong>Google Drive API</strong> aktif</li>
    <li>Menu <strong>APIs &amp; Services &rarr; OAuth consent screen</strong> (atau <strong>Google Auth Platform</strong>): isi nama aplikasi "DIWAN", email dukungan Anda, pilih audiens <strong>External</strong></li>
    <li>Di bagian <strong>Audience</strong>, tekan <strong>Publish app</strong> (status harus "In production"). Bila dibiarkan "Testing", izin akan hangus tiap 7 hari dan upload berhenti. Scope yang dipakai (drive.file) tidak butuh verifikasi Google</li>
    <li>Menu <strong>Credentials &rarr; Create Credentials &rarr; OAuth client ID</strong>, jenis <strong>Web application</strong></li>
    <li>Di <strong>Authorized redirect URIs</strong> isi persis: <code><?= e(GoogleDrive::redirectUri()) ?></code></li>
    <li>Salin <strong>Client ID</strong> dan <strong>Client Secret</strong> ke form di atas, Simpan, lalu tekan <strong>Hubungkan Google Drive</strong></li>
    <li>Di layar Google, bila muncul peringatan "Google hasn't verified this app", pilih <strong>Advanced &rarr; Go to DIWAN (unsafe)</strong>, lalu beri izin</li>
  </ol>
</div>

<?php $this->view('layouts/footer'); ?>
