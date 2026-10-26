<?php // PATH LOKASI FILE: app/Views/auth/sandi.php -- ganti kata sandi sendiri (admin) ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<meta name="robots" content="noindex">
<title>Ganti Kata Sandi &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASEURL ?>/assets/css/app.css">
</head>
<body>
<div class="auth">

  <?php $this->view('auth/_brand', [
      'variant'  => 'admin',
      'headline' => 'Amankan akun Anda',
      'tagline'  => 'Pilih kata sandi yang hanya Anda yang tahu.',
  ]); ?>

  <main class="auth-main">
    <div class="auth-card">
      <span class="role-pill is-admin"><?= Ui::icon('lock', 14) ?> <?= e(Auth::name()) ?></span>
      <h1>Ganti kata sandi</h1>
      <p class="lead">
        <?= !empty($forced)
            ? 'Kata sandi Anda masih sementara. Gantilah sebelum melanjutkan.'
            : 'Masukkan kata sandi sekarang, lalu yang baru.' ?>
      </p>

      <?php $this->view('layouts/flash'); ?>

      <form method="post" action="<?= BASEURL ?>/sandi/simpan">
        <?= Csrf::field() ?>

        <div class="field">
          <label for="current">Kata sandi sekarang</label>
          <div class="input-icon">
            <?= Ui::icon('lock', 18) ?>
            <input type="password" id="current" name="current" autocomplete="current-password" required autofocus>
          </div>
        </div>
        <div class="field">
          <label for="new">Kata sandi baru</label>
          <div class="input-icon">
            <?= Ui::icon('lock', 18) ?>
            <input type="password" id="new" name="new" autocomplete="new-password" required minlength="10">
          </div>
          <div class="help">Minimal 10 karakter. Tidak boleh memuat nama pengguna atau nomor WhatsApp Anda.</div>
        </div>
        <div class="field">
          <label for="confirm">Ulangi kata sandi baru</label>
          <div class="input-icon">
            <?= Ui::icon('lock', 18) ?>
            <input type="password" id="confirm" name="confirm" autocomplete="new-password" required minlength="10">
          </div>
        </div>

        <button type="submit" class="btn block">Simpan kata sandi</button>
      </form>

      <?php if (empty($forced)): ?>
        <p class="auth-alt"><a href="<?= BASEURL ?>/dashboard">Kembali ke dasbor</a></p>
      <?php else: ?>
        <form method="post" action="<?= BASEURL ?>/auth/logout" style="margin-top:14px">
          <?= Csrf::field() ?>
          <button type="submit" class="btn secondary block">Keluar</button>
        </form>
      <?php endif; ?>
    </div>
  </main>

</div>
<?php $this->view('layouts/scripts'); ?>
</body>
</html>
