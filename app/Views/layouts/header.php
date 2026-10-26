<?php
// PATH LOKASI FILE: app/Views/layouts/header.php
// Kerangka halaman admin: sidebar navigasi + topbar (pencarian, notifikasi, menu pengguna).
// Dipakai semua halaman admin lewat $this->view('layouts/header') ... $this->view('layouts/footer').

$current = explode('/', trim($_GET['url'] ?? '', '/'))[0] ?? '';
if ($current === '') $current = 'dashboard';

// "placements" dan "units/term" adalah bagian dari alur Periode; menyorot menu Periode di sana.
$navKey = ['placements' => 'terms'][$current] ?? $current;
if ($current === 'units' && strpos($_GET['url'] ?? '', 'units/term') === 0) $navKey = 'terms';
// "units/grouping" punya menu sendiri; sisa "units" (katalog) menyorot Unit Organisasi di Master Data.
if ($current === 'units' && strpos($_GET['url'] ?? '', 'units/grouping') === 0) $navKey = 'units-grouping';
// "settings/gdrive" punya menu sendiri, terpisah dari "settings/whatsapp".
if ($current === 'settings' && strpos($_GET['url'] ?? '', 'settings/gdrive') === 0) $navKey = 'settings-gdrive';

$pendingCount = Auth::canVerify() ? $this->model('RegistrationModel')->countPending() : 0;

$dn = Text::splitName(Auth::name());
$meAvatar = Ui::avatar(['name' => $dn['name'], 'uuid' => $_SESSION['auth']['uuid'] ?? ''], 'sm');

$nav = function ($key, $href, $icon, $label, $badge = 0) use ($navKey) {
    $active = $navKey === $key;
    return '<a class="nav-item' . ($active ? ' active' : '') . '" href="' . BASEURL . '/' . $href . '"'
        . ($active ? ' aria-current="page"' : '') . '>' . Ui::icon($icon, 19) . '<span>' . e($label) . '</span>'
        . ($badge > 0 ? '<span class="nav-badge" aria-label="' . (int) $badge . ' menunggu">' . (int) $badge . '</span>' : '')
        . '</a>';
};
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title><?= isset($title) ? e($title) . ' &middot; ' : '' ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASEURL ?>/assets/css/app.css">
</head>
<body>
<div class="app">

  <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
    <a class="logo" href="<?= BASEURL ?>/dashboard" title="<?= e(APP_ACRONYM) ?>">
      <span class="logo-mark"><img src="<?= BASEURL ?>/assets/img/diwan-mark.png" alt="" width="40" height="40"></span>
      <span class="logo-text"><strong><?= e(APP_SHORT) ?></strong><small>Wahdah Islamiyah</small></span>
    </a>

    <nav>
      <div class="nav-label">Utama</div>
      <?= $nav('dashboard', 'dashboard', 'home', 'Dasbor') ?>

      <div class="nav-label">Data Kepengurusan</div>
      <?= $nav('people', 'people', 'users', 'Pengurus') ?>
      <?= $nav('structures', 'structures', 'network', 'Struktur') ?>
      <?= $nav('terms', 'terms', 'calendar-check', 'Periode') ?>
      <?= $nav('units-grouping', 'units/grouping', 'layers', 'Pengelompokan Unit') ?>

      <!-- Master Data dipisah dari Data Kepengurusan karena isinya berbeda sifat: kosakata yang
           dipakai bersama lintas struktur dan jarang berubah, bukan data yang disusun ulang tiap
           periode. Pengelompokan Unit SENGAJA tetap di atas -- ia terikat periode, seperti
           Periode dan Penempatan, jadi memindahkannya ke sini hanya mengulang kebingungannya. -->
      <div class="nav-label">Master Data</div>
      <?= $nav('units', 'units/catalog', 'grid', 'Unit Organisasi') ?>
      <?= $nav('positions', 'positions', 'award', 'Jabatan') ?>

      <div class="nav-label">Verifikasi</div>
      <?= $nav('registrations', 'registrations', 'inbox', 'Pendaftaran', $pendingCount) ?>
      <?= $nav('biodata', 'biodata', 'file-text', 'Biodata Pengurus') ?>

      <?php if (Auth::hasAnyRole(['super_admin', 'admin_struktur'])): ?>
        <div class="nav-label">Pengaturan</div>
        <?= $nav('accounts', 'accounts', 'key', 'Akun Pengelola') ?>
      <?php endif; ?>
      <?php if (Auth::isSuperAdmin()): ?>
        <?= $nav('tokens', 'tokens', 'plug', 'Token API') ?>
        <?= $nav('settings', 'settings/whatsapp', 'message-circle', 'WhatsApp') ?>
        <?= $nav('broadcast', 'broadcast', 'radio', 'Broadcast WA') ?>
        <?= $nav('settings-gdrive', 'settings/gdrive', 'file-text', 'Google Drive') ?>
      <?php endif; ?>
    </nav>

    <div class="side-card">
      <div class="lbl">Lingkup akses</div>
      <div class="val"><?= e(AdminScope::label() ?: 'Tanpa lingkup') ?></div>
      <p>Data yang tampil dibatasi pada struktur ini beserta seluruh struktur di bawahnya.</p>
    </div>
  </aside>
  <div class="scrim" data-nav-close></div>

  <div class="main-col">
    <header class="topbar">
      <button type="button" class="icon-btn menu-btn" data-nav-toggle aria-label="Buka menu" aria-controls="sidebar">
        <?= Ui::icon('menu', 20) ?>
      </button>

      <form class="search" action="<?= BASEURL ?>/people" method="get" role="search">
        <?= Ui::icon('search', 18) ?>
        <input type="search" id="global-search" name="q" placeholder="Cari pengurus: nama, nomor WA, atau NIK…"
               autocomplete="off" aria-label="Cari pengurus">
        <kbd aria-hidden="true">Ctrl K</kbd>
      </form>

      <div class="top-actions">
        <?php if (Auth::canVerify()): ?>
          <a class="icon-btn" href="<?= BASEURL ?>/registrations" title="Pendaftaran menunggu verifikasi"
             aria-label="Pendaftaran menunggu: <?= (int) $pendingCount ?>">
            <?= Ui::icon('bell', 20) ?>
            <?php if ($pendingCount > 0): ?><span class="dot"><?= (int) $pendingCount ?></span><?php endif; ?>
          </a>
        <?php endif; ?>

        <details class="user-menu">
          <summary>
            <?= $meAvatar ?>
            <span class="who">
              <strong><?= e(Auth::name()) ?></strong>
              <small><?= e(Ui::primaryRoleLabel()) ?></small>
            </span>
            <?= Ui::icon('chevron-down', 16) ?>
          </summary>
          <div class="menu">
            <div class="menu-head">
              <strong><?= e(Auth::name()) ?></strong>
              <small><?= e(Ui::primaryRoleLabel()) ?> &middot; <?= e(AdminScope::label() ?: 'Tanpa lingkup') ?></small>
            </div>
            <a href="<?= BASEURL ?>/daftar" target="_blank" rel="noopener"><?= Ui::icon('user-plus', 17) ?> Formulir pendaftaran publik</a>
            <a href="<?= BASEURL ?>/sandi"><?= Ui::icon('lock', 17) ?> Ganti kata sandi</a>
            <form method="post" action="<?= BASEURL ?>/auth/logout">
              <?= Csrf::field() ?>
              <button type="submit"><?= Ui::icon('log-out', 17) ?> Keluar</button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <main class="content">
<?php $this->view('layouts/flash'); ?>
