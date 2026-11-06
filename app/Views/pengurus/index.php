<?php
// PATH LOKASI FILE: app/Views/pengurus/index.php
// Beranda Portal Pengurus. Dua kolom di layar lebar (kiri: jabatan, Biodata, jabatan lain, perjalanan; kanan: data saya, rekan,
// riwayat masuk), satu kolom di ponsel dengan urutan yang sama menurut kelas bn-t-* (lihat app.css). Satu-satunya kartu gelap
// adalah Jabatan saat ini. Yang tampil ke pemilik: NIK dan WA utuh, email tersamar, tanggal lahir dan alamat jalan tidak
// ditampilkan (cukup bertanda Terisi); rekan hanya nama + jabatan. Bahan perhitungannya ada di PortalHome.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'beranda']);

[, $nameOnly] = Ui::nameParts($person);
$hello = PortalHome::greeting($nameOnly);
$gelar = Ui::titlesLine($person);

$main = $current[0] ?? null;
$mainLife = $lives[0] ?? null;
$others = array_slice($current, 1);

// Kepala kartu: ikon, judul (+ keterangan), lalu tautan/angka di kanan (HTML tepercaya dari pemanggil).
$head = function ($icon, $title, $right = '', $sub = '') {
    return '<div class="bn-th"><span class="bn-ib">' . Ui::icon($icon, 18) . '</span><div><h2>' . e($title) . '</h2>'
        . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</div>' . $right . '</div>';
};
$go = function ($href, $text, $icon = 'arrow-right') {
    return '<a class="bn-go" href="' . BASEURL . '/' . $href . '">' . e($text) . ' ' . Ui::icon($icon, 14) . '</a>';
};
?>

<section class="bn-hello">
  <?= Ui::avatar($person, 'lg') ?>
  <div class="bn-hello-tx">
    <div class="bn-greet"><?= e(Ui::greeting()) ?></div>
    <h1 class="bn-name<?= $hello['size'] ?>"><?= e($hello['first']) ?><?= $hello['last'] !== '' ? ' <b>' . e($hello['last']) . '</b>' : '' ?></h1>
    <?php if ($gelar !== ''): ?><div class="bn-sub"><?= e($gelar) ?></div><?php endif; ?>
  </div>
  <div class="bn-side">
    <span class="bn-date"><?= Ui::icon('calendar', 15) ?> <?= e(Ui::dateLong()) ?></span>
    <a class="bn-edit" href="<?= BASEURL ?>/pengurus/ubah" aria-label="Ubah data"><?= Ui::icon('edit', 16) ?><span>Ubah data</span></a>
  </div>
</section>

<?php ob_start(); ?>
<?php if ($main): ?>
  <section class="bn-hero bn-t-hero" aria-labelledby="bn-hero-title">
    <div class="bn-lbl"><?= Ui::icon('briefcase', 15) ?> Jabatan saat ini</div>
    <h2 class="bn-role" id="bn-hero-title"><?= e($main['position_name']) ?></h2>
    <div class="bn-skuo"><?= e(PortalHome::placeLabel($main)) ?></div>
    <ol class="bn-crumbs" aria-label="Struktur">
      <?php $crumbs = $chain ?: [['level' => $main['level'], 'name' => $main['structure_name']]]; ?>
      <?php foreach ($crumbs as $k => $s): ?>
        <?= $k > 0 ? '<li class="sep" aria-hidden="true">›</li>' : '' ?>
        <li class="c<?= $k === count($crumbs) - 1 ? ' last' : '' ?>"><?= e(PortalHome::structureLabel($s['level'], $s['name'])) ?></li>
      <?php endforeach; ?>
    </ol>

    <hr>
    <?php if ($mainLife): $cd = PortalHome::countdown($mainLife); $pct = (int) $mainLife['pct']; ?>
      <div class="bn-count<?= $cd['late'] ? ' late' : '' ?>">
        <div>
          <div class="k"><?= e($cd['label']) ?></div>
          <div class="n"><?= $cd['late'] ? '+' : '' ?><?php foreach ($cd['parts'] as $p): ?><?= (int) $p[0] ?><span class="u"><?= e($p[1]) ?></span><?php endforeach; ?></div>
        </div>
        <?php if ($others): ?><a class="bn-pill" href="#bn-lain">+<?= count($others) ?> jabatan lain <?= Ui::icon('arrow-right', 13) ?></a><?php endif; ?>
      </div>
      <div class="bn-track<?= $cd['late'] ? ' late' : '' ?>" role="img" aria-label="<?= $pct ?> persen masa jabatan telah berjalan">
        <div class="fill" style="width:<?= $pct ?>%"></div>
        <?php foreach (PortalHome::yearTicks($mainLife) as $x): ?><i class="tk" style="left:<?= $x ?>%"></i><?php endforeach; ?>
        <span class="bub" style="<?= PortalHome::bubbleStyle($pct) ?>"><?= $pct ?>% berjalan</span>
        <span class="now" style="left:<?= $pct ?>%"></span>
      </div>
      <div class="bn-dates"><span><?= e(Ui::date($mainLife['start'])) ?></span><span><?= e(Ui::date($mainLife['end'])) ?></span></div>
    <?php else: ?>
      <div class="bn-count">
        <div class="k">Periode ini belum punya tanggal akhir.</div>
        <?php if ($others): ?><a class="bn-pill" href="#bn-lain">+<?= count($others) ?> jabatan lain <?= Ui::icon('arrow-right', 13) ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="bn-tile bn-idle bn-t-hero">
    <?= $head('briefcase', 'Jabatan saat ini') ?>
    <div class="bn-idle-role">Tidak sedang menjabat</div>
    <?php if (!empty($history)): $h = $history[0]; [, $endLast] = MemberModel::range($h); ?>
      <div class="bn-last">
        <b><?= e($h['position_name']) ?> · <?= e(PortalHome::placeLabel($h)) ?></b>
        <span><?= e(PortalHome::structureName($h)) ?><?= $endLast ? ' · berakhir ' . e(Ui::date($endLast)) : '' ?></span>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php $heroHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
  <section class="bn-tile bn-t-bio">
    <div class="bn-bio">
      <div class="tx">
        <b>Biodata Pengurus</b>
        <span><?= Ui::icon($biodata ? ($biodata['gdrive_file_id'] ? 'check' : 'clock') : 'clock', 13) ?>
          <?= $biodata ? 'Diperbarui ' . e(Ui::date($biodata['updated_at'])) . ($biodata['gdrive_file_id'] ? ' · terkirim ke Drive' : ' · tersimpan') : 'Belum diisi' ?></span>
      </div>
      <div class="btns">
        <a class="bn-btn" href="<?= BASEURL ?>/pengurus/biodata"><?= Ui::icon('file-text', 15) ?> <?= $biodata ? 'Ubah' : 'Isi Biodata' ?></a>
        <?php if ($biodata): ?><a class="bn-btn alt" href="<?= BASEURL ?>/pengurus/biodata_pdf" target="_blank" rel="noopener"><?= Ui::icon('file-text', 15) ?> Unduh PDF</a><?php endif; ?>
      </div>
    </div>
  </section>
<?php $bioHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if ($others): $shown = array_slice($others, 0, PortalHome::OTHERS_MAX, true); ?>
  <section class="bn-tile bn-t-lain" id="bn-lain">
    <?= $head('layers', 'Jabatan lain', '<span class="bn-cnt">' . count($others) . '</span>', 'Selain jabatan utama') ?>
    <div class="bn-oth">
      <?php foreach ($shown as $i => $c): $life = $lives[$i + 1] ?? null; ?>
        <div class="bn-oc">
          <div class="r"><?= e($c['position_name']) ?><?= PortalHome::isTopCore($c) ? ' <span class="bn-chip core">inti</span>' : '' ?></div>
          <div class="u"><?= e(PortalHome::placeLabel($c) . ' · ' . PortalHome::structureName($c)) ?></div>
          <?php if ($life): ?>
            <div class="b<?= $life['late'] ? ' late' : '' ?>"><span><?= $life['late'] ? 'lewat ' . e(Ui::span(abs($life['days_left']))) : 'sisa ' . e(Ui::span($life['days_left'])) ?></span></div>
            <div class="bar" role="img" aria-label="<?= (int) $life['pct'] ?> persen masa jabatan telah berjalan"><i style="width:<?= (int) $life['pct'] ?>%"></i></div>
          <?php else: ?>
            <div class="b"><span class="muted">Tanpa tanggal akhir</span></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($others) > count($shown)): ?>
      <a class="bn-moreline" href="<?= BASEURL ?>/pengurus/riwayat?f=berjalan">+ <?= count($others) - count($shown) ?> jabatan lainnya <?= Ui::icon('chevron-right', 14) ?></a>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php $othersHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php
$total = count($data);
$got = count(array_filter($data, function ($d) { return $d['filled']; }));
$missingLabels = array_map(function ($d) { return $d['label']; }, array_filter($data, function ($d) { return !$d['filled']; }));
$circ = 2 * M_PI * 30;
$row = function (array $d) {
    $val = $d['filled'] ? ($d['value'] ?? 'Terisi') : 'Belum diisi';
    $fragment = $d['key'] === 'photo_path' ? '' : '#' . $d['key'];
    if ($d['filled']) $act = '<span class="ok" title="Terisi">' . Ui::icon('check', 13) . '</span>';
    elseif ($d['self']) $act = '<a class="bn-fill" href="' . BASEURL . '/pengurus/ubah' . e($fragment) . '">Isi ' . Ui::icon('arrow-right', 12) . '</a>';
    else $act = '<span class="bn-adm">' . Ui::icon('lock', 12) . ' Admin</span>';
    return '<li><span class="ib">' . Ui::icon($d['icon'], 16) . '</span><div class="t"><small>' . e($d['label']) . '</small>'
        . '<span' . ($d['filled'] ? '' : ' class="none"') . '>' . e($val) . '</span></div>' . $act . '</li>';
};
?>
  <section class="bn-tile bn-t-data">
    <?= $head('user', 'Data saya', $go('pengurus/ubah', 'Ubah', 'edit')) ?>
    <div class="bn-comp<?= $got === $total ? ' ok' : '' ?>">
      <div class="bn-donut" role="img" aria-label="<?= $got ?> dari <?= $total ?> terisi">
        <svg viewBox="0 0 72 72" aria-hidden="true"><circle class="t" cx="36" cy="36" r="30"/><?php if ($got > 0): ?><circle class="v" cx="36" cy="36" r="30" stroke-dasharray="<?= round($got / $total * $circ, 1) ?> <?= round($circ, 1) ?>"/><?php endif; ?></svg>
        <b><?= $got ?>/<?= $total ?></b>
      </div>
      <div class="tx">
        <b><?= $got === $total ? 'Data Anda lengkap' : $got . ' dari ' . $total . ' terisi' ?></b>
        <span><?= $got === $total ? 'Tidak ada yang perlu dilengkapi' : 'Belum: ' . e(implode(', ', $missingLabels)) ?></span>
      </div>
    </div>
    <div class="bn-grp">Bisa Anda ubah</div>
    <ul class="bn-kv"><?php foreach ($data as $d) if ($d['self']) echo $row($d); ?></ul>
    <div class="bn-grp"><?= Ui::icon('lock', 12) ?> Dikelola admin</div>
    <ul class="bn-kv"><?php foreach ($data as $d) if (!$d['self']) echo $row($d); ?></ul>
  </section>
<?php $dataHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if ($main && $colleagues): ?>
  <section class="bn-tile bn-t-rekan">
    <?= $head('users', PortalHome::colleaguesTitle($main), $go('pengurus/susunan', 'Susunan')) ?>
    <ul class="bn-rk">
      <?php foreach ($colleagues as $c): ?>
        <li>
          <?= Ui::avatar($c, 'md') ?>
          <div><div class="n"><?= e(Ui::fullName($c)) ?></div><div class="r"><?= e($c['position_name']) ?></div></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($colleaguesTotal > count($colleagues)): ?>
      <a class="bn-moreline" href="<?= BASEURL ?>/pengurus/susunan">+ <?= $colleaguesTotal - count($colleagues) ?> rekan lainnya <?= Ui::icon('chevron-right', 14) ?></a>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php $rekanHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php $career = PortalHome::career($history, PortalHome::CAREER_MAX); if ($career['rows']): ?>
  <section class="bn-tile bn-t-riw">
    <?= $head('route', 'Perjalanan saya', $go('pengurus/riwayat', 'Riwayat')) ?>
    <ul class="bn-tl">
      <?php foreach ($career['rows'] as $r): ?>
        <li class="<?= $r['now'] ? 'now' : '' ?>">
          <span class="yr"><?= e($r['years']) ?></span><span class="dot"></span>
          <div>
            <div class="p"><?= e($r['role']) ?> <span class="bn-chip<?= $r['now'] ? '' : ' arsip' ?>"><?= $r['now'] ? 'berjalan' : 'arsip' ?></span></div>
            <div class="s"><?= e($r['sub']) ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($career['more'] > 0): ?>
      <a class="bn-moreline bn-moreline-tl" href="<?= BASEURL ?>/pengurus/riwayat">+ <?= $career['more'] ?> lainnya <?= Ui::icon('chevron-right', 14) ?></a>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php $careerHtml = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if (!empty($logins)): ?>
  <section class="bn-tile bn-t-aman">
    <details class="bn-lg">
      <summary>
        <span class="bn-sec">
          <span class="ib"><?= Ui::icon('lock', 17) ?></span>
          <span class="tx"><b>Terakhir masuk</b><span><?= e(PortalHome::when($logins[0]['created_at'])) ?> · <span class="mono"><?= e(Text::maskIp($logins[0]['ip'])) ?></span></span></span>
        </span>
        <span class="go">Riwayat <?= Ui::icon('chevron-down', 14) ?></span>
      </summary>
      <ul>
        <?php foreach ($logins as $l): ?>
          <li><span><?= e(PortalHome::when($l['created_at'])) ?></span><span class="mono"><?= e(Text::maskIp($l['ip'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="bn-note">Tidak mengenali salah satunya? Hubungi admin struktur Anda agar akses portal Anda dicabut.</div>
    </details>
  </section>
<?php endif; ?>
<?php $amanHtml = ob_get_clean(); ?>

<div class="bn-cols">
  <div class="bn-col"><?= $heroHtml ?><?= $bioHtml ?><?= $othersHtml ?><?= $careerHtml ?></div>
  <div class="bn-col"><?= $dataHtml ?><?= $rekanHtml ?><?= $amanHtml ?></div>
</div>

<?php $this->view('layouts/member_footer'); ?>
