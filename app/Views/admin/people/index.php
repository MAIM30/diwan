<?php
// PATH LOKASI FILE: app/Views/admin/people/index.php
// Data Pengurus: baris Jenjang, rekap tiga kelompok (Status jabatan, Kelengkapan data, Jenis kelamin), lalu satu dari dua tampilan:
// "Per Struktur" (jenjang -> struktur -> daftar pengurus, untuk memantau) atau "Daftar" (tabel A-Z, untuk mencari satu orang).
//
// Dari controller: $keyword, $gender, $filter, $serving, $level (jenjang terpilih atau ''), $levelCounts (jenjang => orang),
// $perStruktur, $totalAll (seluruh lingkup), $recap (PersonModel::recapCounts untuk pencarian + jenjang), $showL/$showP,
// Daftar: $rows, $total, $page, $perPage, $perPageOptions. Per Struktur: $blocks (PeopleOverview::build), $openLevels.
// Chip Jenjang: $levelCounts (level => jumlah orang, boleh nol: semua jenjang yang punya struktur dalam lingkup), $structCounts (level => jumlah struktur).
// $showEmpty (struktur tanpa pengurus ikut tampil; ?kosong=0 menyembunyikannya) dan $hasFacet (ada pencarian atau penyaring orang: struktur kosong
// tak disertakan, jadi tombolnya disembunyikan).
$this->view('layouts/header', ['title' => $title]);

$pages = max(1, (int) ceil($total / $perPage));
$perPageParam = $perPage !== 25 ? $perPage : '';

// Satu-satunya tempat parameter halaman ini disusun: tiap tautan di halaman membawa semua pilihan yang sedang aktif, termasuk tampilannya.
$base = ['q' => $keyword, 'gender' => $gender, 'filter' => $filter, 'menjabat' => $serving ? 1 : '', 'lv' => $level,
         'susun' => $perStruktur ? 'struktur' : 'daftar', 'per_page' => $perPageParam, 'kosong' => $showEmpty ? '' : '0'];
$keepEmpty = function ($v) { return $v !== '' && $v !== null; };
$backQuery = http_build_query(array_filter($base + ['page' => $page > 1 ? $page : ''], $keepEmpty));
$qs = function (array $over = []) use ($base, $keepEmpty) {
    $p = array_filter(array_merge($base, $over), $keepEmpty);
    return BASEURL . '/people' . ($p ? '?' . http_build_query($p) : '');
};
// Pencarian dan penyaring daftar yang ikut ke isi tiap struktur (PeopleController::struktur), supaya isinya cocok dengan angka barisnya.
$facetQuery = http_build_query(array_filter(['q' => $keyword, 'gender' => $gender, 'filter' => $filter, 'menjabat' => $serving ? 1 : ''], $keepEmpty));

$chip = function ($label, $on, $href, $icon = null, $title = '') {
    return '<a class="chip' . ($on ? ' on' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="true"' : '')
        . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . ($icon ? Ui::icon($icon, 14) : '') . e($label) . '</a>';
};
// Kartu rekap. $sub dan $extra sudah berupa HTML yang aman (dibangun di bawah dari angka dan teks tetap).
$rk = function ($label, $count, $icon, $tone, $active, $href, $sub = '', $extra = '') {
    return '<a class="rk-card ' . $tone . ($active ? ' active' : '') . '" href="' . e($href) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . '<span class="rk-top"><span class="rk-ic">' . Ui::icon($icon, 15) . '</span><span class="rk-lbl">' . e($label) . '</span></span>'
        . '<span class="rk-num">' . Ui::n($count) . '</span>'
        . ($sub !== '' ? '<span class="rk-sub">' . $sub . '</span>' : '') . $extra . '</a>';
};

$chipGroups = PeopleOverview::chipGroups($levelCounts);
?>

<div class="page-head">
  <h1>Data Pengurus</h1>
  <span class="badge"><?= Ui::n($totalAll) ?> orang</span>
  <div class="actions">
    <?php if (Auth::canEdit()): ?>
      <a class="btn secondary" href="<?= BASEURL ?>/people/deleted">Data Terhapus</a>
      <a class="btn" href="<?= BASEURL ?>/people/form"><?= Ui::icon('user-plus', 17) ?> Tambah Orang</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($chipGroups): ?>
<div class="mon-strip" role="group" aria-label="Saring menurut jenjang">
  <span class="mon-strip-l">Jenjang</span>
  <?= $chip('Semua', $level === '', $qs(['lv' => '', 'page' => '']), null) ?>
  <?php foreach ($chipGroups as $wing => $list): ?>
    <span class="mon-sep" aria-hidden="true"></span>
    <?php foreach ($list as $lv): $on = $level === $lv; ?>
      <a class="chip<?= $on ? ' on' : ($levelCounts[$lv] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['lv' => $on ? '' : $lv, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>
         title="<?= e((PeopleOverview::LEVEL_NAMES[$lv] ?? $lv) . ' · ' . Ui::n($structCounts[$lv] ?? 0) . ' struktur') ?>"><?= e($lv) ?> <span class="chip-n"><?= Ui::n($levelCounts[$lv]) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<p class="mon-note">Satu orang bisa menjabat di beberapa jenjang, jadi jumlah di chip lebih besar dari total orang.</p>
<?php endif; ?>

<p class="mon-ctx">
  <span>Menampilkan: <b><?= $level !== '' ? e($level) : 'semua pengurus di lingkup Anda' ?></b><?php if ($keyword !== ''): ?> &middot; pencarian &ldquo;<?= e($keyword) ?>&rdquo;<?php endif; ?> &middot; <?= Ui::n($recap['total']) ?> orang</span>
  <?php if ($level !== '' || $keyword !== ''): ?>
    <a href="<?= e($qs(['lv' => '', 'q' => '', 'page' => ''])) ?>">Hapus penyaringan</a>
  <?php else: ?>
    <span class="muted">Pilih jenjang di atas, kartu di bawah akan mengikuti.</span>
  <?php endif; ?>
</p>

<div class="rk-clusters">
  <section class="rk-cl" aria-label="Status jabatan">
    <h2>Status jabatan</h2>
    <div class="rk-cards">
      <?= $rk('Menjabat', $recap['menjabat'], 'users', '', $serving, $qs(['menjabat' => $serving ? '' : 1, 'page' => '']),
              Ui::pct($recap['menjabat'], $recap['total']) . ' dari ' . Ui::n($recap['total']) . ' orang') ?>
      <?= $rk('Rangkap', $recap['rangkap'], 'grid', 't-violet', $filter === 'rangkap', $qs(['filter' => $filter === 'rangkap' ? '' : 'rangkap', 'page' => '']),
              Ui::pct($recap['rangkap'], $recap['menjabat']) . ' dari yang menjabat &middot; tunggal ' . Ui::n($recap['tunggal'])) ?>
      <?= $rk('Tidak menjabat', $recap['tidak_menjabat'], 'circle', 't-slate', $filter === 'tidak_menjabat', $qs(['filter' => $filter === 'tidak_menjabat' ? '' : 'tidak_menjabat', 'susun' => 'daftar', 'page' => '']),
              $recap['tidak_menjabat'] > 0 ? 'belum ada penempatan berjalan' : 'tidak ada pada pilihan ini') ?>
    </div>
  </section>

  <section class="rk-cl" aria-label="Kelengkapan data">
    <h2>Kelengkapan data</h2>
    <div class="rk-cards">
      <?= $rk('Data lengkap', $recap['lengkap'], 'clipboard-check', 't-green', $filter === 'lengkap', $qs(['filter' => $filter === 'lengkap' ? '' : 'lengkap', 'page' => '']),
              Ui::pct($recap['lengkap'], $recap['total']) . ' &middot; ' . e(PersonModel::COMPLETE_SHORT),
              '<span class="rk-meter"><i style="width:' . ($recap['total'] > 0 ? min(100, round($recap['lengkap'] / $recap['total'] * 100, 1)) : 0) . '%"></i></span>') ?>
      <?= $rk('Belum lengkap', $recap['belum_lengkap'], 'alert', 't-amber', $filter === 'belum_lengkap', $qs(['filter' => $filter === 'belum_lengkap' ? '' : 'belum_lengkap', 'page' => '']),
              Ui::pct($recap['belum_lengkap'], $recap['total']) . ' perlu dilengkapi') ?>
    </div>
  </section>

  <?php if ($showL || $showP): $gTotal = ($showL ? $recap['L'] : 0) + ($showP ? $recap['P'] : 0); ?>
  <section class="rk-cl" aria-label="Jenis kelamin">
    <h2>Jenis kelamin</h2>
    <div class="rk-card rk-gender">
      <div class="rk-gcols">
        <?php if ($showL): ?>
          <a class="rk-gcol<?= $gender === 'L' ? ' active' : '' ?>" href="<?= e($qs(['gender' => $gender === 'L' ? '' : 'L', 'page' => ''])) ?>"<?= $gender === 'L' ? ' aria-current="true"' : '' ?>>
            <small>Laki-laki</small><b><?= Ui::n($recap['L']) ?></b></a>
        <?php endif; ?>
        <?php if ($showP): ?>
          <a class="rk-gcol p<?= $gender === 'P' ? ' active' : '' ?>" href="<?= e($qs(['gender' => $gender === 'P' ? '' : 'P', 'page' => ''])) ?>"<?= $gender === 'P' ? ' aria-current="true"' : '' ?>>
            <small>Perempuan</small><b><?= Ui::n($recap['P']) ?></b></a>
        <?php endif; ?>
      </div>
      <?php if ($showL && $showP && $gTotal > 0): $lPct = round($recap['L'] / $gTotal * 100, 1); ?>
        <div class="rk-split" role="img" aria-label="Laki-laki <?= e(Ui::pct($recap['L'], $gTotal)) ?>, perempuan <?= e(Ui::pct($recap['P'], $gTotal)) ?>">
          <i class="l" style="width:<?= $lPct ?>%"></i><i class="p" style="width:<?= 100 - $lPct ?>%"></i>
        </div>
        <span class="rk-sub"><?= e(Ui::pct($recap['L'], $gTotal)) ?> &middot; <?= e(Ui::pct($recap['P'], $gTotal)) ?></span>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/people" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari pengurus"
           placeholder="Nama, nomor WA, atau NIK lengkap 16 digit">
    <?php if ($gender): ?><input type="hidden" name="gender" value="<?= e($gender) ?>"><?php endif; ?>
    <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <?php if ($serving): ?><input type="hidden" name="menjabat" value="1"><?php endif; ?>
    <?php if ($level): ?><input type="hidden" name="lv" value="<?= e($level) ?>"><?php endif; ?>
  </form>
  <div class="view-seg" role="group" aria-label="Tampilan">
    <a href="<?= e($qs(['susun' => 'struktur', 'page' => ''])) ?>" class="<?= $perStruktur ? 'on' : '' ?>"<?= $perStruktur ? ' aria-current="true"' : '' ?>>Per Struktur</a>
    <a href="<?= e($qs(['susun' => 'daftar', 'page' => ''])) ?>" class="<?= !$perStruktur ? 'on' : '' ?>"<?= !$perStruktur ? ' aria-current="true"' : '' ?>>Daftar</a>
  </div>
  <div class="chips">
    <?= $chip('Tanpa Unit Organisasi (SKUO)', $filter === 'tanpa_unit', $qs(['filter' => $filter === 'tanpa_unit' ? '' : 'tanpa_unit', 'susun' => 'daftar', 'page' => '']), 'users') ?>
    <?php if ($perStruktur && !$hasFacet): ?>
      <?= $chip('Sembunyikan struktur tanpa pengurus', !$showEmpty, $qs(['kosong' => $showEmpty ? '0' : '']), null, 'Struktur yang belum punya pengurus atau periode (angkanya nol) ikut tampil supaya bisa dipantau; ketuk untuk menyembunyikannya') ?>
    <?php endif; ?>
  </div>
  <?php if ($perStruktur): ?>
    <span class="mon-bulk">
      <button type="button" class="btn small secondary" data-mon-tree="mon-tree" data-mon-all="open">Buka semua jenjang</button>
      <button type="button" class="btn small secondary" data-mon-tree="mon-tree" data-mon-all="close">Tutup semua</button>
    </span>
  <?php else: ?>
    <label class="per-page">
      Tampilkan
      <select onchange="window.location.href=this.value" aria-label="Jumlah baris per halaman">
        <?php foreach ($perPageOptions as $opt): ?>
          <option value="<?= e($qs(['per_page' => $opt === 25 ? '' : $opt, 'page' => ''])) ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
            <?= (int) $opt ?> baris
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
</div>

<?php if ($filter === 'tanpa_unit'): ?>
  <div class="note">Daftar ini mencakup pengurus tanpa penempatan aktif di SKUO pada periode berjalan, termasuk yang hanya menjabat sebagai Pengurus Inti atau belum memiliki penempatan.</div>
<?php endif; ?>

<?php if ($perStruktur): ?>
<?php
// ---------- Tampilan Per Struktur ----------
$caret = Ui::icon('chevron-right', 16, 'caret');
$cols = function (array $n, $inti) {
    return '<span class="mon-n">' . Ui::n($n['orang']) . '</span><span class="mon-i">' . $inti . '</span>'
         . '<span class="mon-b">' . Ui::meter($n['lengkap'], $n['orang']) . '</span>'
         . '<span class="mon-lp">' . Ui::n($n['l']) . ' &middot; ' . Ui::n($n['p']) . '</span>';
};
$intiSum = function ($lengkap, $strukt) {
    return '<span class="mon-intisum ' . ($lengkap === $strukt ? 'ok' : 'warn') . '"><b>' . Ui::n($lengkap) . '</b> dari ' . Ui::n($strukt) . ' lengkap</span>';
};
$rosterUrl = function ($termId) use ($facetQuery) {
    return BASEURL . '/people/struktur/' . (int) $termId . ($facetQuery !== '' ? '?' . $facetQuery : '');
};
// Baris satu struktur; daftar pengurusnya dimuat saat dibuka (struktur tanpa Bidang dan unit: nama dan jabatan langsung; selain itu
// Pengurus Inti, Bidang, unit). Di bawah nama: induknya ("Sulawesi Selatan" untuk sebuah kabupaten) dan jumlah struktur anaknya ("5 MWD").
// $d = kedalaman untuk indentasi. Struktur yang belum punya periode berjalan (term_id null) tak punya isi untuk dimuat: barisnya tidak bisa dibuka
// dan hanya menunjukkan bahwa strukturnya ada (dengan tautan Buat periode bagi yang boleh mengubah).
// Periode yang sudah dibuat tetapi belum ditandai berjalan ($pending) ditulis apa adanya dengan tombol Tandai berjalan; Buat periode hanya bila belum ada (Ui::termGap).
$backPath = substr($qs(), strlen(BASEURL) + 1);
$noTermNote = function ($structureId, $pending) use ($backPath) {
    return Ui::termGap($structureId, $pending, $backPath);
};
$structureRow = function (array $r, $d) use ($caret, $cols, $rosterUrl, $noTermNote) {
    $small = implode(' · ', array_filter([$r['context'], $r['sub']], function ($x) { return $x !== ''; }));
    if ($r['term_id'] === null) {
        return '<div class="mon-row mon-noterm" style="--d:' . (int) $d . '"><span class="mon-t"><span class="mon-caret-gap"></span>'
            . '<span class="mon-ttl"><b>' . e($r['name']) . '</b><small>' . ($small !== '' ? e($small) . ' &middot; ' : '') . $noTermNote($r['id'], $r['pending']) . '</small></span></span>'
            . $cols($r['num'], Ui::ksb($r['flags'])) . '</div>';
    }
    ob_start(); ?>
    <details class="mon-s" data-roster-url="<?= e($rosterUrl($r['term_id'])) ?>">
      <summary class="mon-row" style="--d:<?= (int) $d ?>">
        <span class="mon-t"><?= $caret ?><span class="mon-ttl"><b><?= e($r['name']) ?></b><?= $small !== '' ? '<small>' . e($small) . '</small>' : '' ?></span></span>
        <?= $cols($r['num'], Ui::ksb($r['flags'])) ?>
      </summary>
      <div class="mon-roster" style="--base:<?= (int) $d + 1 ?>"></div>
    </details>
    <?php return ob_get_clean();
};
?>
<?php if ($filter === 'tidak_menjabat'): ?>
  <div class="card"><div class="empty">
    <?= Ui::icon('users', 28) ?>
    <span>Orang yang tidak menjabat belum punya struktur, jadi tidak tampil di Per Struktur.</span>
    <a class="btn small secondary" href="<?= e($qs(['susun' => 'daftar', 'page' => ''])) ?>">Lihat di Daftar</a>
  </div></div>
<?php elseif (empty($blocks)): ?>
  <div class="card"><div class="empty">
    <?= Ui::icon('users', 28) ?>
    <span><?= ($keyword !== '' || $gender || $filter || $serving || $level)
      ? 'Tidak ada pengurus yang cocok dengan penyaringan ini.' : 'Belum ada data pengurus di lingkup Anda.' ?></span>
    <?php if ($keyword !== '' || $gender || $filter || $serving || $level): ?>
      <a class="btn small secondary" href="<?= e($qs(['q' => '', 'gender' => '', 'filter' => '', 'menjabat' => '', 'lv' => '', 'page' => ''])) ?>">Hapus penyaringan</a>
    <?php endif; ?>
  </div></div>
<?php else: ?>
<div class="card mon-card">
  <div class="mon-scroll">
    <div class="mon-tree" id="mon-tree">
      <div class="mon-head"><span>Jenjang &middot; struktur</span><span>Orang</span><span>Inti (K &middot; S &middot; B)</span><span>Data lengkap</span><span>L &middot; P</span></div>

      <?php foreach ($blocks as $b):
        $open = in_array($b['level'], $openLevels, true);
        $inti = $b['single'] ? Ui::ksb($b['flags']) : $intiSum($b['inti_lengkap'], $b['strukt']);
        // Jenjang dengan satu struktur yang belum punya periode berjalan: tak ada isi untuk dimuat, jadi barisnya statis.
        if ($b['single'] && $b['term_id'] === null):
      ?>
        <div class="mon-g mon-lvl <?= e($b['wing']) ?>">
          <div class="mon-row mon-noterm" style="--d:0">
            <span class="mon-t"><span class="mon-caret-gap"></span><span class="mon-ttl"><b><?= e($b['level']) ?></b>
              <small><?= e($b['name']) ?> &middot; <?= $noTermNote($b['rows'][0]['id'], $b['pending']) ?></small></span></span>
            <?= $cols($b['num'], $inti) ?>
          </div>
        </div>
      <?php continue; endif; ?>
        <details class="mon-g mon-lvl <?= e($b['wing']) ?>"<?= $open ? ' open' : '' ?><?= $b['single'] ? ' data-roster-url="' . e($rosterUrl($b['term_id'])) . '"' : '' ?>>
          <summary class="mon-row" style="--d:0">
            <span class="mon-t"><?= $caret ?><span class="mon-ttl"><b><?= e($b['level']) ?></b>
              <small><?= e($b['name']) ?><?= $b['single'] ? '' : ' &middot; ' . Ui::n($b['strukt']) . ' struktur' ?></small></span></span>
            <?= $cols($b['num'], $inti) ?>
          </summary>

          <?php if ($b['single']): ?>
            <div class="mon-roster" style="--base:1"></div>
          <?php else: ?>
            <div class="mon-body">
              <?php foreach ($b['rows'] as $r) echo $structureRow($r, 1); ?>
            </div>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="mon-legend">
    <?= Ui::ksb(['k' => true, 's' => true, 'b' => false]) ?>
    <span>K Ketua &middot; S Sekretaris &middot; B Bendahara. Merah putus-putus: belum ada pengurusnya di Pengurus Inti.</span>
    <span>Data lengkap: <?= e(PersonModel::COMPLETE_SHORT) ?> terisi. Hijau mulai 90%, kuning mulai 75%, merah di bawahnya.</span>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<?php
// ---------- Tampilan Daftar ----------
?>
<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <?= Ui::icon('users', 28) ?>
      <span><?= ($keyword !== '' || $gender || $filter || $serving || $level)
        ? 'Tidak ada pengurus yang cocok dengan penyaringan ini.' : 'Belum ada data pengurus di lingkup Anda.' ?></span>
      <?php if ($keyword !== '' || $gender || $filter || $serving || $level): ?>
        <a class="btn small secondary" href="<?= e($qs(['q' => '', 'gender' => '', 'filter' => '', 'menjabat' => '', 'lv' => '', 'page' => ''])) ?>">Hapus penyaringan</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Nama</th><th>Jabatan / Penempatan Utama</th><th>Kontak</th><th title="<?= e('Lengkap = ' . PersonModel::COMPLETE_RULE) ?>">Kelengkapan</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          // Struktur tersimpan sebagai "DPD Kab. Pinrang"; jenjangnya (kata pertama) dijadikan label tersendiri.
          [$rowLevel, $rowStructure] = array_pad(explode(' ', (string) $r['structure_label'], 2), 2, '');
          $missing = PersonModel::missingFields($r);
        ?>
          <tr>
            <td>
              <a href="<?= BASEURL ?>/people/detail/<?= (int) $r['id'] ?>"><?= Ui::nameHtml($r) ?></a>
            </td>
            <td class="small">
              <?php if ($r['position_name']): ?>
                <strong><?= e($r['position_name']) ?></strong>
                <div class="muted"><?php if (!empty($r['unit_name'])): ?><?= e($r['unit_name']) ?> &middot; <?php endif; ?><span class="badge lv"><?= e($rowLevel) ?></span> <?= e(PeopleOverview::shortName($rowStructure)) ?><?php $otherPlacementCount = max(0, (int) ($r['current_placement_count'] ?? 0) - 1); ?><?php if ($otherPlacementCount > 0): ?> · <button type="button" class="placement-more" data-placement-recap-url="<?= BASEURL ?>/people/other_placements/<?= (int) $r['id'] ?>?featured=<?= (int) ($r['current_placement_id'] ?? 0) ?>" data-person-name="<?= e(Ui::fullName($r)) ?>">+<?= $otherPlacementCount ?> lainnya</button><?php endif; ?></div>
              <?php else: ?>
                <span class="muted">Belum ditempatkan</span>
              <?php endif; ?>
            </td>
            <td class="small nowrap">
              <div class="mono"><?= e($r['phone'] ?: '—') ?></div>
              <div class="mono muted">NIK <?= e($r['nik'] ?: '—') ?></div>
            </td>
            <td>
              <?php if ($missing): ?><span class="badge warn" title="<?= e('Belum lengkap. Syaratnya: ' . PersonModel::COMPLETE_RULE) ?>">Kurang: <?= e(implode(', ', $missing)) ?></span>
              <?php else: ?><span class="badge ok" title="<?= e('Lengkap: ' . PersonModel::COMPLETE_RULE) ?>">Lengkap</span><?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'deceased'): ?><span class="badge ended">Wafat</span>
              <?php elseif ($r['status'] === 'inactive'): ?><span class="badge ended">Nonaktif</span>
              <?php elseif ($r['is_serving']): ?><span class="badge ok">Menjabat</span>
              <?php else: ?><span class="badge ended">Tidak menjabat</span><?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if (Auth::canEdit()): ?>
                <span class="row-actions">
                  <a class="row-btn" href="<?= BASEURL ?>/people/form/<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah <?= e(Ui::fullName($r)) ?>"><?= Ui::icon('edit', 15) ?></a>
                  <form class="inline" method="post" action="<?= BASEURL ?>/people/delete"
                        onsubmit="return confirm(this.dataset.confirm)"
                        data-confirm="Hapus <?= e($r['full_name']) ?> beserta <?= (int) $r['placement_count'] ?> penempatan dalam lingkup Anda? Semua riwayat akan disembunyikan dan dapat dipulihkan dari Data Terhapus. Penghapusan ditolak bila orang ini juga menjabat di luar lingkup Anda.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="with_placements" value="1">
                    <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                    <button type="submit" class="row-btn danger" title="Hapus pengurus" aria-label="Hapus <?= e(Ui::fullName($r)) ?>"><?= Ui::icon('trash', 15) ?></button>
                  </form>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="mon-count">Menampilkan <?= Ui::n(($page - 1) * $perPage + 1) ?>&ndash;<?= Ui::n(min($total, $page * $perPage)) ?> dari <b><?= Ui::n($total) ?></b> orang</p>
    <?php // Badge "Lengkap" tidak menjelaskan dirinya, dan baris yang NIK-nya "—" pun Lengkap: aturannya dinyatakan terang di sini dan di tooltip. ?>
    <p class="mon-note">Lengkap = <?= e(PersonModel::COMPLETE_RULE) ?> Kolom Kontak tetap menampilkan NIK bila ada.</p>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Halaman">
        <?php
        $win = array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2)))));
        sort($win);
        $prev = 0;
        foreach ($win as $i):
          if ($prev && $i - $prev > 1) echo '<span class="gap">…</span>';
          echo $i === $page
            ? '<span class="cur" aria-current="page">' . $i . '</span>'
            : '<a href="' . e($qs(['page' => $i])) . '">' . $i . '</a>';
          $prev = $i;
        endforeach;
        ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<dialog class="biodata-roster-modal" id="placement-recap-modal" aria-labelledby="placement-recap-title">
  <div class="page-head">
    <h2 id="placement-recap-title">Jabatan rangkap</h2>
    <button type="button" class="btn small secondary" data-placement-recap-close>Tutup</button>
  </div>
  <div class="table-wrap">
    <table><thead><tr><th>Jabatan</th><th>Unit Organisasi</th><th>Struktur</th><th>Periode</th></tr></thead>
      <tbody id="placement-recap-body"><tr><td colspan="4" class="muted">Pilih daftar untuk memuat jabatan.</td></tr></tbody>
    </table>
  </div>
</dialog>

<?php $this->view('layouts/footer'); ?>
