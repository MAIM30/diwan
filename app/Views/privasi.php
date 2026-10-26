<?php // PATH LOKASI FILE: app/Views/privasi.php -- kebijakan privasi, publik ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title><?= e($title) ?> &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<main style="max-width:760px; margin:0 auto; padding:24px 16px">
  <div class="card">
    <h1><?= e($title) ?></h1>
    <p class="small muted">DIWAN &middot; Data Induk Pengurus Wahdah Islamiyah</p>

    <h2>Tentang aplikasi</h2>
    <p>
      DIWAN adalah sistem internal Wahdah Islamiyah untuk mengelola data induk kepengurusan: siapa menjabat
      apa, di struktur mana, pada periode berapa. Aplikasi ini bukan layanan umum. Akunnya hanya untuk
      pengurus dan pengelola data organisasi.
    </p>

    <h2>Data yang kami simpan</h2>
    <p>
      Data diri pengurus (nama, kontak, tempat dan tanggal lahir, domisili, dan sejenisnya), riwayat jabatan,
      serta Biodata Pengurus yang diisi sendiri oleh pengurus. Data ini hanya dapat dilihat oleh pengurus
      yang bersangkutan dan pengelola data sesuai lingkup kewenangannya. Kami tidak menjual atau membagikan
      data ini kepada pihak lain untuk kepentingan di luar organisasi.
    </p>

    <h2>Penggunaan Google Drive</h2>
    <p>
      DIWAN meminta izin Google Drive <code>drive.file</code> dari akun Google milik pengelola DIWAN. Izin
      ini dipakai hanya untuk satu tujuan: menyimpan berkas PDF Biodata Pengurus ke sebuah folder yang dibuat
      oleh DIWAN sendiri. Dengan izin ini DIWAN tidak dapat membaca, mengubah, atau menghapus berkas lain di
      Drive tersebut. Token akses disimpan di server DIWAN dan tidak dibagikan kepada siapa pun.
    </p>
    <p>
      Penggunaan dan pemindahan informasi yang diterima dari Google API oleh DIWAN mematuhi
      <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Kebijakan Data Pengguna Layanan Google API</a>,
      termasuk persyaratan Penggunaan Terbatas (<em>Limited Use</em>).
    </p>

    <h2>Layanan pihak ketiga</h2>
    <p>
      Untuk berfungsi, DIWAN memakai Google Drive (penyimpanan PDF), WhatsApp (pengiriman kode masuk dan
      pemberitahuan), dan Cloudflare (jaringan dan keamanan akses). Hanya data yang diperlukan untuk
      fungsi masing-masing yang diteruskan.
    </p>

    <h2>Mencabut izin dan meminta penghapusan</h2>
    <p>
      Pemilik akun Google dapat mencabut izin DIWAN kapan saja di
      <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>.
      Pengurus yang ingin data dirinya diperbaiki atau dihapus dapat menghubungi pengelola data di
      strukturnya, atau menghubungi kami di
      <a href="mailto:diwanwahdahislamiyah@gmail.com">diwanwahdahislamiyah@gmail.com</a>.
    </p>

    <p class="small muted">Terakhir diperbarui: <?= e(Ui::date('2026-10-26')) ?></p>
  </div>
</main>
</body>
</html>
