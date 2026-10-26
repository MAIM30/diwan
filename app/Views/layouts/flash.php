<?php
// PATH LOKASI FILE: app/Views/layouts/flash.php
// Pesan sekali-tampil (Flash::take). Dicetak sebagai kotak `.alert` biasa -- itu yang terbaca tanpa JavaScript -- dan app.js (SweetAlert2)
// menggantinya dengan notifikasi: toast untuk sukses dan info, jendela untuk galat dan peringatan. Galat/peringatan memakai role="alert".
$messages = Flash::take();
if (!$messages) return;
?>
<div data-flash>
<?php foreach ($messages as $msg): ?>
  <div class="alert <?= e($msg['type']) ?>" role="<?= in_array($msg['type'], ['error', 'warning'], true) ? 'alert' : 'status' ?>"><?= e($msg['message']) ?></div>
<?php endforeach; ?>
</div>
