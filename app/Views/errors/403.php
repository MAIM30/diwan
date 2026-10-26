<?php // PATH LOKASI FILE: app/Views/errors/403.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Akses Ditolak</title>
<link rel="stylesheet" href="<?= BASEURL ?>/assets/css/app.css">
</head>
<body>
<div class="centered">
  <div class="card">
    <div class="hero">
    <h1>Akses Ditolak</h1>
    
    </div>

    <div class="alert error"><?= e($message ?? 'Anda tidak punya hak akses untuk tindakan ini.') ?></div>
    <?php if (class_exists('AdminScope') && AdminScope::label()): ?>
      <p class="small muted">Lingkup akses akun Anda: <strong><?= e(AdminScope::label()) ?></strong>.</p>
    <?php endif; ?>
    <div class="btn-row"><a class="btn secondary" href="<?= BASEURL ?>/dashboard">Kembali ke Dasbor</a></div>
  </div>
</div>
</body>
</html>
