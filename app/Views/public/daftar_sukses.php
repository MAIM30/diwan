<?php // PATH LOKASI FILE: app/Views/public/daftar_sukses.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Pendaftaran Terkirim &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="centered">
  <div class="card">
    <div class="hero">
    <h1>Pengajuan Terkirim</h1>
    <p class="sub"><?= e(APP_NAME) ?></p>
    </div>

    <div class="alert success">
      Alhamdulillah! Pengajuan Anda sudah masuk dan menunggu verifikasi pengurus struktur terkait.
    </div>

    <p class="small">Yang terjadi selanjutnya:</p>
    <ol class="small">
      <li>Pengurus struktur yang Anda pilih memeriksa data Anda.</li>
      <li>Bila sudah sesuai, data Anda dicatat beserta jabatan dan periodenya.</li>
      <li>Bila ada yang perlu diperbaiki, Anda akan dihubungi lewat nomor WhatsApp yang Anda isi.</li>
    </ol>

    <div class="note">
      Status aktif pada data pengurus <strong>bukan</strong> pemberian akun otomatis di aplikasi lain
      seperti syuro. Bila Anda membutuhkan akses ke aplikasi tersebut, ajukan terpisah ke
      pengurus struktur Anda.
    </div>

    <div class="btn-row">
      <a class="btn" href="<?= BASEURL ?>/pengurus/login">Masuk ke Portal Pengurus</a>
      <a class="btn secondary" href="<?= BASEURL ?>/daftar">Kirim pengajuan lain</a>
    </div>
  </div>
</div>
</body>
</html>
