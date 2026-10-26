<?php
// PATH LOKASI FILE: app/Views/admin/settings/whatsapp.php
$this->view('layouts/header', ['title' => $title]);

$s = $settings;
$configured = !empty($s['wa_gateway_url']) && !empty($s['wa_gateway_key']);
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
    <li>Buka <a href="http://localhost:3001/qr" target="_blank" rel="noopener">http://localhost:3001/qr</a> di browser</li>
    <li>Scan QR code dengan WhatsApp di HP (Linked Devices &rarr; Link a Device)</li>
    <li>Isi URL Gateway (<code>http://localhost:3001</code>) dan API Key di atas, lalu Simpan</li>
    <li>Klik <strong>Tes Koneksi</strong> untuk memastikan semuanya terhubung</li>
  </ol>

  <div class="note" style="margin-top:14px">
    <strong>Catatan:</strong> Server gateway harus tetap berjalan selama aplikasi dipakai.
    Setelah pertama kali scan QR, sesi tersimpan di <code>wa-gateway/auth_state/</code> &mdash;
    tidak perlu scan ulang selama sesi masih aktif.
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
