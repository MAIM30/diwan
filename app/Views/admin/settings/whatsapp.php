<?php
// PATH LOKASI FILE: app/Views/admin/settings/whatsapp.php
$this->view('layouts/header', ['title' => $title]);

$s = $settings;
$configured = !empty($s['wa_gateway_url']) && !empty($s['wa_gateway_key']);
$qrMinutes = min(10, max(1, (int) ($s['wa_qr_display_minutes'] ?? 5)));
$qrUrl = rtrim((string) ($s['wa_gateway_url'] ?? 'http://localhost:3001'), '/') . '/qr?minutes=' . $qrMinutes;
?>

<div class="page-head">
  <h1><?= Ui::icon('message-circle', 22) ?> Pengaturan WhatsApp</h1>
  <span class="badge <?= $configured ? 'ok' : '' ?>"><?= $configured ? 'Dikonfigurasi' : 'Belum dikonfigurasi' ?></span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/settings/whatsapp_log"><?= Ui::icon('file-text', 16) ?> Log Pesan</a>
  </div>
</div>

<div class="note">
  WA Gateway mengirim OTP login pengurus, notifikasi penempatan, dan broadcast lewat WhatsApp.
  Gateway berjalan di server lokal menggunakan Baileys (Node.js).
</div>

<div class="card">
  <h2>Koneksi Gateway</h2>
  <form method="post" action="<?= BASEURL ?>/settings/whatsapp_save">
    <?= Csrf::field() ?>

    <div class="grid-2">
      <div class="field">
        <label for="wa_gateway_url">URL Gateway</label>
        <input type="url" id="wa_gateway_url" name="wa_gateway_url"
               value="<?= e($s['wa_gateway_url'] ?? '') ?>"
               placeholder="http://localhost:3001" autocomplete="off">
        <div class="help">Alamat server WA Gateway (Node.js). Bawaan: <code>http://localhost:3001</code></div>
      </div>
      <div class="field">
        <label for="wa_gateway_key">API Key</label>
        <input type="password" id="wa_gateway_key" name="wa_gateway_key"
               value="<?= e($s['wa_gateway_key'] ?? '') ?>"
               placeholder="diwan-wa-secret" autocomplete="off">
        <div class="help">Samakan dengan variabel <code>API_KEY</code> di server.js gateway.</div>
      </div>
    </div>

    <div class="field" style="max-width:360px;margin-top:14px">
      <label for="wa_qr_display_minutes">Lama sesi tampilan QR (menit)</label>
      <input type="number" id="wa_qr_display_minutes" name="wa_qr_display_minutes"
             value="<?= $qrMinutes ?>" min="1" max="10" step="1">
      <div class="help">Halaman scan tetap memperbarui QR kira-kira setiap 20 detik karena QR individual punya masa berlaku sendiri. Pengaturan ini menentukan berapa lama halaman menunggu sebelum meminta sesi tampilan baru.</div>
    </div>

    <h3 style="margin-top:20px">Jeda Broadcast (Anti-Spam)</h3>
    <p class="small muted" style="margin-bottom:8px">
      Jeda acak antar pesan saat broadcast. Semakin besar jeda, semakin aman dari deteksi spam WhatsApp.
      Minimum 3 detik.
    </p>
    <div class="grid-2">
      <div class="field">
        <label for="wa_broadcast_delay_min">Jeda Minimum (detik)</label>
        <input type="number" id="wa_broadcast_delay_min" name="wa_broadcast_delay_min"
               value="<?= e($s['wa_broadcast_delay_min'] ?? '8') ?>"
               min="3" max="60" step="1">
      </div>
      <div class="field">
        <label for="wa_broadcast_delay_max">Jeda Maksimum (detik)</label>
        <input type="number" id="wa_broadcast_delay_max" name="wa_broadcast_delay_max"
               value="<?= e($s['wa_broadcast_delay_max'] ?? '15') ?>"
               min="4" max="120" step="1">
      </div>
    </div>

    <div style="display:flex; gap:8px; margin-top:12px">
      <button type="submit" class="btn">Simpan</button>
    </div>
  </form>
</div>

<?php if ($configured): ?>
<div class="card">
  <h2>Koneksi dengan kode pasangan</h2>
  <p class="small muted">Alternatif scan QR. Masukkan nomor WhatsApp perangkat yang akan ditautkan, lalu masukkan kode yang tampil di aplikasi WhatsApp.</p>
  <form method="post" action="<?= BASEURL ?>/settings/whatsapp_pair" class="grid-2" style="align-items:end;margin-top:10px">
    <?= Csrf::field() ?>
    <div class="field">
      <label for="pair_phone">Nomor WhatsApp perangkat</label>
      <input type="tel" id="pair_phone" name="pair_phone" inputmode="tel" required placeholder="62812xxxxxxxx">
      <div class="help">Gunakan kode negara 62 tanpa tanda +, spasi, atau tanda baca.</div>
    </div>
    <div class="field">
      <button type="submit" class="btn">Buat kode pasangan</button>
    </div>
  </form>
  <?php if (!empty($pairingCode)): ?>
    <div class="alert info" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:14px">
      <div>Kode untuk <span class="mono"><?= e($pairingPhone) ?></span><br><strong id="wa-pair-code" class="mono" style="font-size:24px;letter-spacing:3px"><?= e($pairingCode) ?></strong>
        <div class="small">Di WhatsApp: Perangkat tertaut → Tautkan perangkat → Tautkan dengan nomor telepon.</div>
      </div>
      <button type="button" class="btn secondary" id="copy-wa-pair-code">Salin kode</button>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Tes Koneksi</h2>
  <p class="small muted">Menguji apakah Gateway aktif dan WhatsApp tersambung.</p>
  <form method="post" action="<?= BASEURL ?>/settings/whatsapp_test" style="margin-top:8px">
    <?= Csrf::field() ?>
    <button type="submit" class="btn secondary"><?= Ui::icon('activity', 16) ?> Tes Koneksi</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Panduan Singkat</h2>
  <ol style="margin:0; padding-left:20px; line-height:1.8">
    <li>Buka terminal, masuk ke folder <code>wa-gateway/</code></li>
    <li>Jalankan <code>npm start</code> (pertama kali) atau <code>node server.js</code></li>
    <li>Buka <a href="<?= e($qrUrl) ?>" target="_blank" rel="noopener">halaman koneksi WA</a></li>
    <li>Pilih scan QR, atau masukkan nomor di bagian <strong>Koneksi dengan kode pasangan</strong> di atas</li>
    <li>Isi URL Gateway (<code>http://localhost:3001</code>) dan API Key di atas, lalu Simpan</li>
    <li>Klik <strong>Tes Koneksi</strong> untuk memastikan semuanya terhubung</li>
  </ol>

  <div class="note" style="margin-top:14px">
    <strong>Catatan:</strong> Server gateway harus tetap berjalan selama aplikasi dipakai.
    Setelah pertama kali scan QR, sesi tersimpan di <code>wa-gateway/auth_state/</code> &mdash;
    tidak perlu scan ulang selama sesi masih aktif.
  </div>
</div>

<script>
document.getElementById('copy-wa-pair-code')?.addEventListener('click', function () {
  var code = document.getElementById('wa-pair-code').textContent.trim();
  var button = this;
  function done() { button.textContent = 'Tersalin'; setTimeout(function () { button.textContent = 'Salin kode'; }, 1800); }
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(code).then(done).catch(function () { window.prompt('Salin kode pasangan ini:', code); });
  else window.prompt('Salin kode pasangan ini:', code);
});
</script>

<?php $this->view('layouts/footer'); ?>
