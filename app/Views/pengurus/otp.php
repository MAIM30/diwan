<?php // PATH LOKASI FILE: app/Views/pengurus/otp.php -- halaman verifikasi OTP ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<meta name="robots" content="noindex">
<title>Verifikasi OTP &middot; <?= e(APP_NAME) ?></title>
<?= Ui::favicons() ?>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="auth">

  <?php $this->view('auth/_brand', ['variant' => 'member']); ?>

  <main class="auth-main">
    <div class="auth-card">
      <span class="role-pill is-member"><?= Ui::icon('shield', 14) ?> Verifikasi</span>
      <h1>Masukkan Kode OTP</h1>
<?php if ($viaAdmin): ?>
      <p class="lead">Pengiriman kode lewat WhatsApp sedang tidak aktif. Minta kode OTP kepada admin, lalu ketik di bawah. Berlaku 15 menit.</p>

      <?php $this->view('layouts/flash'); ?>

      <?php // Admin mengenali peminta dari nomor pengirim chat; isi pesan tak memuat nama atau nomor (lihat Otp::adminChatUrl). ?>
      <a class="btn secondary block" href="<?= e($adminChatUrl) ?>" target="_blank" rel="noopener" style="margin-bottom:16px">
        <?= Ui::icon('phone', 18) ?> Minta kode ke admin lewat WhatsApp
      </a>
<?php else: ?>
      <p class="lead">Kode 4 digit telah dikirim ke WhatsApp <strong><?= e($maskedPhone) ?></strong>. Berlaku 5 menit.</p>

      <?php $this->view('layouts/flash'); ?>
<?php endif; ?>

      <form method="post" action="<?= BASEURL ?>/pengurus/otp_verify">
        <?= Csrf::field() ?>

        <div class="field">
          <label for="code">Kode OTP</label>
          <div class="input-icon">
            <?= Ui::icon('lock', 18) ?>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{4}"
                   maxlength="4" autocomplete="one-time-code" required
                   placeholder="0000" autofocus
                   style="letter-spacing:.3em; font-size:1.3rem; font-weight:700; text-align:center">
          </div>
        </div>

        <button type="submit" class="btn block">Verifikasi</button>
      </form>

      <div style="text-align:center; margin-top:16px">
        <a href="<?= BASEURL ?>/pengurus/login" class="small">Ganti nomor</a>
      </div>
    </div>
  </main>

</div>
<?php $this->view('layouts/scripts'); ?>
</body>
</html>
