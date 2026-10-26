<?php
// PATH LOKASI FILE: app/Views/layouts/member_header.php
// Kerangka Portal Pengurus. Dirancang ponsel-dulu: di layar lebar ada navigasi di bilah atas; di layar
// ponsel navigasinya berpindah ke BILAH BAWAH (mudah dijangkau ibu jari), tab aktif membesar menjadi
// pil putih berlabel. Sengaja TANPA sidebar admin -- portal ini hanya punya empat halaman.
// Variabel: $title, $person, $active ('beranda' | 'susunan' | 'riwayat' | 'data'), $hero (opsional: true
// hanya di Beranda -- bilah atas menjadi transparan di atas latar melengkung, lihat .m-home di app.css).
[$pre, $mName, $suf] = Ui::nameParts($person);
$mFirst = preg_split('/\s+/u', trim($mName), -1, PREG_SPLIT_NO_EMPTY)[0] ?? $mName;
$mItems = [
    'beranda' => ['pengurus',           'home',     'Beranda'],
    'susunan' => ['pengurus/susunan',   'users',    'Susunan'],
    'riwayat' => ['pengurus/riwayat',   'calendar', 'Riwayat'],
    'data'    => ['pengurus/data',      'id-card',  'Data Saya'],
];
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f96b0">
<meta name="robots" content="noindex">
<title><?= isset($title) ? e($title) . ' &middot; ' : '' ?>Portal Pengurus &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body class="m-body<?= !empty($hero) ? ' m-home' : '' ?>">

<header class="m-top">
  <div class="m-top-in">
    <a class="m-logo" href="<?= BASEURL ?>/pengurus" title="<?= e(APP_ACRONYM) ?>">
      <span class="logo-mark"><img src="<?= BASEURL ?>/assets/img/diwan-mark.png" alt="" width="40" height="40"></span>
      <span class="logo-text"><strong><?= e(APP_SHORT) ?></strong><small>Portal Pengurus</small></span>
    </a>

    <nav class="m-nav" aria-label="Navigasi portal">
      <?php foreach ($mItems as $key => [$href, $icon, $label]): ?>
        <a href="<?= BASEURL ?>/<?= $href ?>" class="<?= ($active ?? '') === $key ? 'active' : '' ?>"
           <?= ($active ?? '') === $key ? 'aria-current="page"' : '' ?>><?= Ui::icon($icon, 17) ?> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <details class="user-menu">
      <summary>
        <?= Ui::avatar($person, 'sm') ?>
        <span class="who"><strong><?= e($mFirst) ?></strong><small>Pengurus</small></span>
        <?= Ui::icon('chevron-down', 16) ?>
      </summary>
      <div class="menu">
        <div class="menu-head">
          <strong><?= e(Ui::fullName($person)) ?></strong>
          <small>Portal Pengurus</small>
        </div>
        <form method="post" action="<?= BASEURL ?>/pengurus/logout">
          <?= Csrf::field() ?>
          <button type="submit"><?= Ui::icon('log-out', 17) ?> Keluar</button>
        </form>
      </div>
    </details>
  </div>
</header>

<?php // Bilah bawah (hanya tampil di layar ponsel, lihat app.css). Tab tak aktif hanya berikon, jadi
      // namanya dibawa aria-label; tab aktif memunculkan labelnya sebagai teks. ?>
<nav class="m-tabbar" aria-label="Navigasi bawah">
  <?php foreach ($mItems as $key => [$href, $icon, $label]): $on = ($active ?? '') === $key; ?>
    <a href="<?= BASEURL ?>/<?= $href ?>" class="<?= $on ? 'active' : '' ?>" aria-label="<?= e($label) ?>"
       <?= $on ? 'aria-current="page"' : '' ?>><?= Ui::icon($icon, 21) ?><span class="lbl"><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>

<main class="m-main">
<?php $this->view('layouts/flash'); ?>
