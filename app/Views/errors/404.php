<?php // PATH LOKASI FILE: app/Views/errors/404.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Tidak Ditemukan</title>
<link rel="stylesheet" href="<?= BASEURL ?>/assets/css/app.css">
</head>
<body>
<div class="centered">
  <div class="card">
    <div class="hero">
    <h1>Tidak Ditemukan</h1>
    
    </div>

    <div class="alert info"><?= e($message ?? 'Data tidak ditemukan.') ?></div>
    <div class="btn-row"><a class="btn secondary" href="<?= BASEURL ?>/dashboard">Kembali ke Dasbor</a></div>
  </div>
</div>
</body>
</html>
