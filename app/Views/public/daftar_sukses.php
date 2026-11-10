<?php // PATH LOKASI FILE: app/Views/public/daftar_sukses.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Pendaftaran Terkirim &middot; <?= e(APP_NAME) ?></title>
<?= Ui::favicons() ?>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="centered">
  <div class="card">
    <div class="hero">
    <h1>Pengajuan Terkirim</h1>
    <p class="sub"><?= e(APP_NAME) ?></p>
    </div>

    <?php $outcome = $outcome ?? 'review'; ?>
    <?php if ($outcome === 'accepted'): ?>
      <div class="alert success">
        Alhamdulillah! Data Anda sudah tercatat sebagai pengurus. Anda bisa langsung masuk ke Portal Pengurus dengan nomor WhatsApp Anda.
      </div>

      <p class="small">Yang terjadi selanjutnya:</p>
      <ol class="small">
        <li>Masuk ke Portal Pengurus dan lengkapi data diri Anda.</li>
        <li>Pengurus struktur yang Anda pilih menetapkan jabatan dan periode Anda.</li>
      </ol>
    <?php else: ?>
      <div class="alert success">
        <?php if ($outcome === 'review_auto'): ?>
          Alhamdulillah! Pengajuan Anda sudah masuk. Data Anda perlu diperiksa pengurus struktur terkait sebelum dicatat.
        <?php else: ?>
          Alhamdulillah! Pengajuan Anda sudah masuk dan menunggu verifikasi pengurus struktur terkait.
        <?php endif; ?>
      </div>

      <p class="small">Yang terjadi selanjutnya:</p>
      <ol class="small">
        <li>Pengurus struktur yang Anda pilih memeriksa data Anda.</li>
        <li>Bila sudah sesuai, data Anda dicatat beserta jabatan dan periodenya.</li>
        <li>Bila ada yang perlu diperbaiki, Anda akan dihubungi lewat nomor WhatsApp yang Anda isi.</li>
      </ol>
    <?php endif; ?>

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
