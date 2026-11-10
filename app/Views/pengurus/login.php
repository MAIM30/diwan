<?php // PATH LOKASI FILE: app/Views/pengurus/login.php ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<meta name="robots" content="noindex">
<title>Masuk Pengurus &middot; <?= e(APP_NAME) ?></title>
<?= Ui::favicons() ?>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="auth">

  <?php $this->view('auth/_brand', ['variant' => 'member']); ?>

  <main class="auth-main">
    <div class="auth-card">
      <span class="role-pill is-member"><?= Ui::icon('id-card', 14) ?> Pengurus</span>
      <h1>Masuk</h1>
      <p class="lead">Masukkan nomor WhatsApp Anda untuk melanjutkan.</p>

      <?php $this->view('layouts/flash'); ?>

      <form method="post" action="<?= BASEURL ?>/pengurus/login_process">
        <?= Csrf::field() ?>

        <div class="field">
          <label for="wa">Nomor WhatsApp</label>
          <?php // Angka dibuat besar agar mudah diperiksa; inputmode numeric tetap memakai papan angka bawaan HP. ?>
          <input class="phone-entry-input" type="tel" id="wa" name="wa" inputmode="numeric" autocomplete="tel"
                 maxlength="16" pattern="[0-9]*" required placeholder="08xxxxxxxxxx" autofocus>
          <div class="help">Masukkan angka saja, tanpa spasi atau tanda baca. Gunakan nomor yang tercatat di data pengurus.</div>
        </div>

        <button type="submit" class="btn block">Lanjutkan</button>
      </form>

      <p class="auth-alt">
        Belum terdaftar sebagai pengurus? <a href="<?= BASEURL ?>/daftar">Daftar di sini</a>
      </p>
      <p class="auth-hint">Pengelola data? <a href="<?= BASEURL ?>/auth/login">Masuk sebagai admin</a></p>
    </div>
  </main>

</div>
<?php $this->view('layouts/scripts'); ?>
<script>
(function () {
  var input = document.getElementById('wa');
  if (!input) return;
  input.addEventListener('input', function () {
    var caret = input.selectionStart || 0;
    var digitsBeforeCaret = input.value.slice(0, caret).replace(/\D/g, '').length;
    var digits = input.value.replace(/\D/g, '');
    if (input.value === digits) return;
    input.value = digits;
    input.setSelectionRange(digitsBeforeCaret, digitsBeforeCaret);
  });
})();
</script>
</body>
</html>
