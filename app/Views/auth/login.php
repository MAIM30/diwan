<?php // PATH LOKASI FILE: app/Views/auth/login.php -- halaman masuk ADMIN (pengelola data) ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Masuk Admin &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="auth">

  <?php $this->view('auth/_brand', ['variant' => 'admin']); ?>

  <main class="auth-main">
    <div class="auth-card">
      <span class="role-pill is-admin"><?= Ui::icon('key', 14) ?> Admin</span>
      <h1>Masuk</h1>
      <p class="lead">Khusus pengelola data kepengurusan.</p>

      <?php $this->view('layouts/flash'); ?>

      <form method="post" action="<?= BASEURL ?>/auth/login_process">
        <?= Csrf::field() ?>

        <div class="field">
          <label for="username">Email atau nomor WhatsApp</label>
          <div class="input-icon">
            <?= Ui::icon('user', 18) ?>
            <input type="text" id="username" name="username" autocomplete="username" required
                   placeholder="nama@pengurus.wahdah.or.id atau 08xxxxxxxxxx" autofocus
                   autocapitalize="none" spellcheck="false">
          </div>
        </div>

        <div class="field">
          <label for="password">Kata sandi</label>
          <div class="input-icon">
            <?= Ui::icon('lock', 18) ?>
            <input type="password" id="password" name="password" autocomplete="current-password" required
                   placeholder="Masukkan kata sandi">
            <button type="button" class="pw-toggle" data-toggle-pw="password" aria-label="Tampilkan kata sandi" aria-pressed="false">
              <span class="pw-on"><?= Ui::icon('eye', 18) ?></span>
              <span class="pw-off" hidden><?= Ui::icon('eye-off', 18) ?></span>
            </button>
          </div>
        </div>

        <button type="submit" class="btn block">Masuk</button>
      </form>

      <p class="auth-alt">
        Anda pengurus? <a href="<?= BASEURL ?>/pengurus/login">Masuk di sini</a>
      </p>
      <p class="auth-hint">Lupa kata sandi? Hubungi Super Admin.</p>
    </div>
  </main>

</div>
<?php $this->view('layouts/scripts'); ?>
</body>
</html>
