<?php
// PATH LOKASI FILE: app/Views/auth/_brand.php
// Panel merek halaman masuk (admin, pengurus, ganti kata sandi). Latarnya TERANG: logo transparan dan tulisan "DIWAN"-nya teal gelap,
// jadi hanya terbaca di atas latar terang. Layar lebar = panel kiri; ponsel = kepala atas bertepi bergelombang (lihat app.css).
// Logo di tengah, dua baris tulisan resminya di bawahnya sebagai TEKS (bukan bagian gambar).
// Variabel: $variant ('admin' | 'member', hanya memilih hiasan), $headline dan $tagline (opsional: kalimat konteks halaman,
// mis. "Amankan akun Anda"; bila kosong tidak ditampilkan).
$isMember = ($variant ?? 'admin') === 'member';
?>
<aside class="auth-brand">
  <div class="brand-center">
    <a class="lockup" href="<?= BASEURL ?>/">
      <img src="<?= BASEURL ?>/assets/img/diwan-logo.png" alt="<?= e(APP_NAME) ?>" width="560" height="449">
    </a>

    <div class="brand-id">
      <strong><?= e(APP_ACRONYM) ?></strong>
      <span><?= e(APP_TAGLINE) ?></span>
    </div>

    <?php if (!empty($headline)): ?>
      <div class="auth-copy">
        <h2><?= e($headline) ?></h2>
        <?php if (!empty($tagline)): ?><p><?= e($tagline) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="auth-foot">&copy; <?= date('Y') ?> Wahdah Islamiyah</div>

  <?php if ($isMember): ?>
    <!-- Hiasan pengurus: kartu identitas bertumpuk. Murni dekoratif. -->
    <svg class="auth-art" viewBox="0 0 520 360" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
      <rect x="150" y="60" width="300" height="190" rx="20"/>
      <circle cx="225" cy="130" r="32"/>
      <path d="M190 220c4-22 19-34 35-34s31 12 35 34"/>
      <path d="M292 112h120M292 140h96M292 168h120M292 196h70"/>
      <rect x="86" y="118" width="300" height="190" rx="20" opacity=".6"/>
      <rect x="30" y="176" width="300" height="170" rx="20" opacity=".35"/>
    </svg>
  <?php else: ?>
    <!-- Hiasan admin: bagan struktur organisasi. Murni dekoratif. -->
    <svg class="auth-art" viewBox="0 0 520 360" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
      <path d="M260 52V88M100 88H420M100 88V126M260 88V126M420 88V126"/>
      <path d="M100 154V186M50 186H150M50 186V225M150 186V225"/>
      <path d="M260 154V186M224 186H296M224 186V225M296 186V225"/>
      <path d="M420 154V186M384 186H456M384 186V225M456 186V225"/>
      <circle cx="260" cy="36" r="16"/>
      <circle cx="100" cy="140" r="14"/><circle cx="260" cy="140" r="14"/><circle cx="420" cy="140" r="14"/>
      <circle cx="50" cy="236" r="11"/><circle cx="150" cy="236" r="11"/>
      <circle cx="224" cy="236" r="11"/><circle cx="296" cy="236" r="11"/>
      <circle cx="384" cy="236" r="11"/><circle cx="456" cy="236" r="11"/>
    </svg>
  <?php endif; ?>
</aside>
