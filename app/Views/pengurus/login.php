<?php // PATH LOKASI FILE: app/Views/pengurus/login.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<meta name="robots" content="noindex">
<title>Masuk Pengurus &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="auth">

  <?php $this->view('auth/_brand', ['variant' => 'member']); ?>

  <main class="auth-main">
    <div class="auth-card">
      <span class="role-pill is-member"><?= Ui::icon('id-card', 14) ?> Pengurus</span>
      <h1>Masuk</h1>
      <p class="lead"><?= !empty($otpEnabled)
          ? 'Masukkan nomor WhatsApp Anda. Kode OTP akan dikirim untuk verifikasi.'
          : 'Masukkan nomor WhatsApp Anda untuk melanjutkan.' ?></p>

      <?php $this->view('layouts/flash'); ?>

      <form method="post" action="<?= BASEURL ?>/pengurus/login_process">
        <?= Csrf::field() ?>

        <div class="field">
          <label for="wa">Nomor WhatsApp</label>
          <div class="input-icon">
            <?= Ui::icon('phone', 18) ?>
            <?php // type=tel: nomor WA bukan rahasia seperti kata sandi, dan papan angka ponsel muncul. ?>
            <input type="tel" id="wa" name="wa" inputmode="tel" autocomplete="tel" required
                   placeholder="08xxxxxxxxxx" autofocus>
          </div>
          <div class="help">Nomor yang tercatat di data pengurus.<?= !empty($otpEnabled)
              ? '' : ' NIK 16 digit juga bisa dipakai bila sudah tercatat.' ?></div>
        </div>

        <button type="submit" class="btn block"><?= !empty($otpEnabled) ? 'Kirim Kode OTP' : 'Masuk' ?></button>
      </form>

      <p class="auth-alt">
        Belum terdaftar sebagai pengurus? <a href="<?= BASEURL ?>/daftar">Daftar di sini</a>
      </p>
      <p class="auth-hint">Pengelola data? <a href="<?= BASEURL ?>/auth/login">Masuk sebagai admin</a></p>
    </div>
  </main>

</div>
<?php $this->view('layouts/scripts'); ?>
</body>
</html>
