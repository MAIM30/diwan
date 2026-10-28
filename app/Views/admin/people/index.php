<?php
// PATH LOKASI FILE: app/Views/admin/people/index.php
$this->view('layouts/header', ['title' => $title]);

$pages = max(1, (int) ceil($total / $perPage));
$backQuery = http_build_query(array_filter([
    'q' => $keyword, 'gender' => $gender, 'filter' => $filter,
    'menjabat' => $serving ? 1 : '', 'susun' => $perBidang ? 'bidang' : '',
    'geo' => $geoKey, 'page' => $page > 1 ? $page : '',
], function ($v) { return $v !== '' && $v !== null; }));

$qs = function (array $over = []) use ($keyword, $gender, $filter, $serving, $perBidang, $geoKey) {
    $p = array_merge(['q' => $keyword, 'gender' => $gender, 'filter' => $filter, 'menjabat' => $serving ? 1 : '', 'susun' => $perBidang ? 'bidang' : '', 'geo' => $geoKey], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/people' . ($p ? '?' . http_build_query($p) : '');
};
$chip = function ($label, $on, $href, $icon = null) {
    return '<a class="chip' . ($on ? ' on' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="true"' : '') . '>'
        . ($icon ? Ui::icon($icon, 14) : '') . e($label) . '</a>';
};
$rc = function ($label, $count, $icon, $active, $href, $tone = '') {
    $cls = 'stat' . ($tone ? ' ' . $tone : '') . ($active ? ' active' : '');
    return '<a class="' . $cls . '" href="' . e($href) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . '<div class="stat-top"><span class="chip-ic">' . Ui::icon($icon, 18) . '</span>'
        . '<span class="lbl">' . e($label) . '</span></div>'
        . '<div class="num">' . Ui::n($count) . '</div></a>';
};
?>

<div class="page-head">
  <h1>Data Pengurus</h1>
  <span class="badge"><?= Ui::n($total) ?> orang</span>
  <div class="actions">
    <?php if (Auth::canEdit()): ?>
      <a class="btn secondary" href="<?= BASEURL ?>/people/deleted">Data Terhapus</a>
      <a class="btn" href="<?= BASEURL ?>/people/form"><?= Ui::icon('user-plus', 17) ?> Tambah Orang</a>
    <?php endif; ?>
  </div>
</div>

<div class="stats recap">
  <?php if ($showL): ?>
    <?= $rc('Laki-laki', $recap['L'], 'users', $gender === 'L', $qs(['gender' => $gender === 'L' ? '' : 'L', 'page' => ''])) ?>
  <?php endif; ?>
  <?php if ($showP): ?>
    <?= $rc('Perempuan', $recap['P'], 'users', $gender === 'P', $qs(['gender' => $gender === 'P' ? '' : 'P', 'page' => '']), 't-rose') ?>
  <?php endif; ?>
  <?= $rc('Data Lengkap', $recap['lengkap'], 'clipboard-check', $filter === 'lengkap', $qs(['filter' => $filter === 'lengkap' ? '' : 'lengkap', 'page' => '']), 't-green') ?>
  <?= $rc('Belum Lengkap', $recap['belum_lengkap'], 'alert', $filter === 'belum_lengkap', $qs(['filter' => $filter === 'belum_lengkap' ? '' : 'belum_lengkap', 'page' => '']), 't-amber') ?>
  <?= $rc('Rangkap', $recap['rangkap'], 'grid', $filter === 'rangkap', $qs(['filter' => $filter === 'rangkap' ? '' : 'rangkap', 'page' => '']), 't-violet') ?>
  <?= $rc('Tidak Rangkap', $recap['tidak_rangkap'], 'check', $filter === 'tidak_rangkap', $qs(['filter' => $filter === 'tidak_rangkap' ? '' : 'tidak_rangkap', 'page' => '']), 't-green') ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/people" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari pengurus"
           placeholder="Nama, nomor WA, atau NIK lengkap 16 digit">
    <?php if ($gender): ?><input type="hidden" name="gender" value="<?= e($gender) ?>"><?php endif; ?>
    <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <?php if ($serving): ?><input type="hidden" name="menjabat" value="1"><?php endif; ?>
    <?php if ($perBidang): ?><input type="hidden" name="susun" value="bidang"><?php endif; ?>
    <?php if ($geoKey): ?><input type="hidden" name="geo" value="<?= e($geoKey) ?>"><?php endif; ?>
  </form>
  <div class="chips">
    <?= $chip('Per Bidang', $perBidang, $qs(['susun' => $perBidang ? '' : 'bidang', 'geo' => '', 'page' => '']), 'layers') ?>
    <?= $chip('Tanpa Unit Organisasi (SKUO)', $filter === 'tanpa_unit', $qs(['filter' => $filter === 'tanpa_unit' ? '' : 'tanpa_unit', 'susun' => '', 'geo' => '', 'page' => '']), 'users') ?>
  </div>
</div>

<?php if ($filter === 'tanpa_unit'): ?>
  <div class="note">Daftar ini mencakup pengurus tanpa penempatan aktif di SKUO pada periode berjalan, termasuk yang hanya menjabat sebagai Pengurus Inti atau belum memiliki penempatan.</div>
<?php endif; ?>

<?php if ($perBidang && !empty($geoTabs)): ?>
<div class="chips" style="margin-bottom:12px">
  <?php foreach ($geoTabs as $tab): ?>
    <?= $chip($tab['label'], $geoKey === $tab['key'], $qs(['geo' => $geoKey === $tab['key'] ? '' : $tab['key'], 'page' => '']), 'map-pin') ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($perBidang): ?>
<div class="card">
  <?php if (empty($groups['tree']) && empty($groups['unplaced'])): ?>
    <div class="empty">
      <?= Ui::icon('users', 28) ?>
      <span><?= ($keyword !== '' || $gender || $filter || $serving)
        ? 'Tidak ada pengurus yang cocok dengan penyaringan ini.' : 'Belum ada data pengurus di lingkup Anda.' ?></span>
      <?php if ($keyword !== '' || $gender || $filter || $serving): ?>
        <a class="btn small secondary" href="<?= e($qs(['q' => '', 'gender' => '', 'filter' => '', 'menjabat' => ''])) ?>">Hapus penyaringan</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php $this->view('admin/_susunan', ['tree' => $groups['tree'], 'treeId' => 'per-bidang']); ?>

    <?php if (!empty($groups['unplaced'])): ?>
      <ul class="stree" style="margin-top:6px">
        <li class="sn">
          <details>
            <summary>
              <?= Ui::icon('chevron-right', 16, 'caret') ?>
              <span class="sn-main"><span class="sn-name">Belum menjabat di periode berjalan</span></span>
              <span class="sn-n"><?= count($groups['unplaced']) ?> orang</span>
            </summary>
            <div class="sn-body sn-roster">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Nama</th><th>Nomor WA</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($groups['unplaced'] as $r): ?>
                    <tr>
                      <td><a href="<?= BASEURL ?>/people/detail/<?= (int) $r['id'] ?>"><?= Ui::nameHtml($r) ?></a></td>
                      <td class="mono"><?= e($r['phone'] ?: '—') ?></td>
                      <td><span class="badge ended"><?= e($r['status'] === 'deceased' ? 'Wafat' : ($r['status'] === 'inactive' ? 'Nonaktif' : 'Tidak menjabat')) ?></span></td>
                      <td class="nowrap">
                        <?php if (Auth::canEdit()): ?>
                          <a class="btn small secondary" href="<?= BASEURL ?>/people/form/<?= (int) $r['id'] ?>"><?= Ui::icon('edit', 14) ?> Ubah</a>
                          <form class="inline" method="post" action="<?= BASEURL ?>/people/delete"
                                onsubmit="return confirm(this.dataset.confirm)"
                                data-confirm="Hapus <?= e($r['full_name']) ?> beserta seluruh riwayat penempatannya? Data dapat dipulihkan dari Data Terhapus. Penghapusan ditolak bila ada penempatan di luar lingkup Anda.">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="with_placements" value="1">
                            <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                            <button type="submit" class="btn small danger" title="Hapus pengurus"><?= Ui::icon('trash', 14) ?> Hapus</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </details>
        </li>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <?= Ui::icon('users', 28) ?>
      <span><?= ($keyword !== '' || $gender || $filter || $serving)
        ? 'Tidak ada pengurus yang cocok dengan penyaringan ini.' : 'Belum ada data pengurus di lingkup Anda.' ?></span>
      <?php if ($keyword !== '' || $gender || $filter || $serving): ?>
        <a class="btn small secondary" href="<?= BASEURL ?>/people">Hapus penyaringan</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Nama</th><th>Jabatan Terkini</th><th>Nomor WA</th><th>NIK</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= BASEURL ?>/people/detail/<?= (int) $r['id'] ?>"><?= Ui::nameHtml($r) ?></a>
              <?php $tl = Ui::titlesLine($r); if ($tl !== ''): ?>
                <div class="small muted"><?= e($tl) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($r['position_name']): ?>
                <strong><?= e($r['position_name']) ?></strong>
                <div class="muted"><?= e($r['structure_label']) ?><?= (int) $r['placement_count'] > 1 ? ' · +' . ((int) $r['placement_count'] - 1) . ' lainnya' : '' ?></div>
              <?php else: ?>
                <span class="muted">Belum ditempatkan</span>
              <?php endif; ?>
            </td>
            <td class="mono"><?= e($r['phone'] ?: '—') ?></td>
            <td class="mono"><?= e($r['nik'] ?: '—') ?></td>
            <td>
              <?php if ($r['status'] === 'deceased'): ?><span class="badge ended">Wafat</span>
              <?php elseif ($r['status'] === 'inactive'): ?><span class="badge ended">Nonaktif</span>
              <?php elseif ($r['is_serving']): ?><span class="badge ok">Menjabat</span>
              <?php else: ?><span class="badge ended">Tidak menjabat</span><?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if (Auth::canEdit()): ?>
                <a class="btn small secondary" href="<?= BASEURL ?>/people/form/<?= (int) $r['id'] ?>"><?= Ui::icon('edit', 14) ?> Ubah</a>
                <form class="inline" method="post" action="<?= BASEURL ?>/people/delete"
                      onsubmit="return confirm(this.dataset.confirm)"
                      data-confirm="Hapus <?= e($r['full_name']) ?> beserta <?= (int) $r['placement_count'] ?> penempatan dalam lingkup Anda? Semua riwayat akan disembunyikan dan dapat dipulihkan dari Data Terhapus. Penghapusan ditolak bila orang ini juga menjabat di luar lingkup Anda.">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="with_placements" value="1">
                  <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                  <button type="submit" class="btn small danger" title="Hapus pengurus"><?= Ui::icon('trash', 14) ?> Hapus</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

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

<?php $this->view('layouts/footer'); ?>
