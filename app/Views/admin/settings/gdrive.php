<?php
// PATH LOKASI FILE: app/Views/admin/settings/gdrive.php
$this->view('layouts/header', ['title' => $title]);

$s = $settings;
$configured = !empty($s['gdrive_service_account_json']) && !empty($s['gdrive_folder_id']);
$email = '';
if (!empty($s['gdrive_service_account_json'])) {
    $decoded = json_decode($s['gdrive_service_account_json'], true);
    $email = $decoded['client_email'] ?? '';
}
?>

<div class="page-head">
  <h1><?= Ui::icon('file-text', 22) ?> Pengaturan Google Drive</h1>
  <span class="badge <?= $configured ? 'ok' : '' ?>"><?= $configured ? 'Dikonfigurasi' : 'Belum dikonfigurasi' ?></span>
</div>

<div class="note">
  PDF Biodata Pengurus yang dikirim lewat Portal Pengurus otomatis diunggah ke folder Google Drive ini,
  lewat akun layanan (service account) -- bukan akun Google pribadi siapa pun.
</div>

<div class="card">
  <h2>Kredensial</h2>
  <form method="post" action="<?= BASEURL ?>/settings/gdrive_save">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="gdrive_service_account_json">Kunci JSON Service Account</label>
      <textarea id="gdrive_service_account_json" name="gdrive_service_account_json" rows="6"
                placeholder='{"type": "service_account", "client_email": "...", "private_key": "...", ...}'
                autocomplete="off"><?= e($s['gdrive_service_account_json'] ?? '') ?></textarea>
      <div class="help">Isi JSON lengkap yang diunduh saat membuat kunci service account di Google Cloud Console.</div>
      <?php if ($email): ?>
        <div class="help"><span class="badge ok">terbaca</span> Email: <code><?= e($email) ?></code> -- folder Drive tujuan HARUS di-share (Editor) ke email ini.</div>
      <?php endif; ?>
    </div>

    <div class="field">
      <label for="gdrive_folder_id">ID Folder Drive Tujuan</label>
      <input type="text" id="gdrive_folder_id" name="gdrive_folder_id"
             value="<?= e($s['gdrive_folder_id'] ?? '') ?>"
             placeholder="mis. 125kbwkNUclni2P511Dx9EpBcaD5AdLKM" autocomplete="off">
      <div class="help">Bagian akhir URL folder Drive-nya: <code>drive.google.com/drive/folders/<b>ID_INI</b></code></div>
    </div>

    <div style="display:flex; gap:8px; margin-top:12px">
      <button type="submit" class="btn">Simpan</button>
    </div>
  </form>
</div>

<?php if ($configured): ?>
<div class="card">
  <h2>Tes Kredensial</h2>
  <p class="small muted">Memastikan kunci service account sah dan bisa dipakai meminta akses token ke Google (belum mengunggah apa pun).</p>
  <form method="post" action="<?= BASEURL ?>/settings/gdrive_test" style="margin-top:8px">
    <?= Csrf::field() ?>
    <button type="submit" class="btn secondary"><?= Ui::icon('activity', 16) ?> Tes Kredensial</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Panduan Singkat</h2>
  <ol style="margin:0; padding-left:20px; line-height:1.8">
    <li>Buka <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>, buat project baru (atau pakai yang sudah ada)</li>
    <li>Menu <strong>APIs &amp; Services &rarr; Library</strong>, cari dan aktifkan <strong>Google Drive API</strong></li>
    <li>Menu <strong>IAM &amp; Admin &rarr; Service Accounts &rarr; Create Service Account</strong>, beri nama bebas (mis. "diwan-biodata")</li>
    <li>Buka service account yang baru dibuat &rarr; tab <strong>Keys</strong> &rarr; <strong>Add Key &rarr; Create new key</strong> &rarr; pilih <strong>JSON</strong> &rarr; unduh berkasnya</li>
    <li>Buka isi berkas JSON itu dengan teks editor, salin-tempel SELURUH isinya ke kolom "Kunci JSON Service Account" di atas</li>
    <li>Di Google Drive, buka folder tujuan &rarr; <strong>Share</strong> &rarr; tempel email service account (kolom <code>client_email</code> di JSON tadi) &rarr; beri akses <strong>Editor</strong></li>
    <li>Salin ID folder itu dari URL-nya, isi di kolom "ID Folder Drive Tujuan" di atas, lalu Simpan</li>
    <li>Klik <strong>Tes Kredensial</strong> untuk memastikan semuanya terhubung</li>
  </ol>
</div>

<?php $this->view('layouts/footer'); ?>
