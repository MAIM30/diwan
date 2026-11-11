<?php
// PATH LOKASI FILE: app/Views/admin/positions/members.php
// Master Data > Jabatan > Lihat: daftar pengurus yang memegang satu jabatan.
//
// Dari controller: $position, $rows (satu baris per penempatan, sudah dipaging), $total (cocok dengan pencarian dan ada dalam
// lingkup admin), $allCount (semua penempatan jabatan ini), $inScope (yang ada dalam lingkup admin), $keyword, $page, $pages,
// $perPage, $perPageOptions.

$this->view('layouts/header', ['title' => $title]);

$atLabels = ['structure' => 'Struktur', 'unit' => 'Unit', 'both' => 'Keduanya'];

// Saringan struktur dari halaman Jabatan (?st=DPP&lv=DPW): daftar ini dibatasi sama dengan angka "Dipakai" yang diklik.
$stAbbr  = $structure['abbr'] ?? '';
$stLevel = $structure['level'] ?? '';
$stQuery = $stAbbr !== '' ? '?' . http_build_query(array_filter(['st' => $stAbbr, 'lv' => $stLevel], function ($v) { return $v !== ''; })) : '';
$stLabel = $stAbbr === '' ? '' : ($stLevel === '' ? $stAbbr : ($stLevel === $stAbbr ? $stAbbr . ' saja' : $stAbbr . ' › ' . $stLevel));

$qs = function (array $over = []) use ($position, $keyword, $perPage, $stAbbr, $stLevel) {
    $p = array_merge(['q' => $keyword, 'per_page' => $perPage === 25 ? '' : $perPage, 'page' => '', 'st' => $stAbbr, 'lv' => $stLevel], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/positions/members/' . (int) $position['id'] . ($p ? '?' . http_build_query($p) : '');
};

$hidden = max(0, $allCount - $inScope);
$offset = ($page - 1) * $perPage;
$today  = date('Y-m-d');
?>

<div class="page-head">
  <h1><?= e($position['name']) ?></h1>
  <span class="badge"><?= e($atLabels[$position['applies_to']] ?? $position['applies_to']) ?></span>
  <?php if ($position['applies_to'] !== 'structure' && ($kindsText = PositionModel::kindsLabel($position['unit_kinds'] ?? '')) !== ''): ?>
    <span class="badge" title="Jenis unit tempat jabatan ini berlaku"><?= e($kindsText) ?></span>
  <?php endif; ?>
  <?php if ($position['is_core']): ?><span class="badge ok">Inti</span><?php endif; ?>
  <?php if (!$position['is_active']): ?><span class="badge">nonaktif</span><?php endif; ?>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/positions<?= e($stQuery) ?>">&larr; Jabatan</a>
  </div>
</div>

<div class="note">
  <strong><?= Ui::n($inScope) ?></strong> penempatan memegang jabatan ini. Satu orang tampil sekali per penempatan,
  jadi yang rangkap di beberapa struktur atau unit muncul beberapa kali.
  <?php if ($stAbbr !== ''): ?>
    <br><span class="muted">Dibatasi pada struktur <strong><?= e($stLabel) ?></strong>.
      <a href="<?= e($qs(['st' => '', 'lv' => ''])) ?>">Tampilkan semua struktur</a></span>
  <?php endif; ?>
  <?php if ($hidden > 0): ?>
    <br><span class="muted"><?= Ui::n($hidden) ?> penempatan lain berada di luar lingkup akses Anda dan tidak ditampilkan.</span>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/positions/members/<?= (int) $position['id'] ?>" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari pengurus"
           placeholder="Cari nama, struktur, atau unit">
    <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int) $perPage ?>"><?php endif; ?>
    <?php if ($stAbbr !== ''): ?><input type="hidden" name="st" value="<?= e($stAbbr) ?>"><?php endif; ?>
    <?php if ($stLevel !== ''): ?><input type="hidden" name="lv" value="<?= e($stLevel) ?>"><?php endif; ?>
  </form>
  <?php if ($keyword !== ''): ?>
    <a class="btn small secondary" href="<?= e($qs(['q' => ''])) ?>">Tampilkan semua</a>
  <?php endif; ?>
  <label class="per-page">
    <select onchange="window.location.href=this.value" aria-label="Jumlah baris per halaman">
      <?php foreach ($perPageOptions as $opt): ?>
        <option value="<?= e($qs(['per_page' => $opt === 25 ? '' : $opt, 'page' => ''])) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= (int) $opt ?></option>
      <?php endforeach; ?>
    </select>
    <span>per halaman</span>
  </label>
</div>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted" style="margin:0">
    <?php if ($keyword !== ''): ?>
      Tidak ada pengurus yang cocok dengan pencarian itu.
    <?php elseif ($inScope === 0 && $allCount > 0): ?>
      Jabatan ini dipegang <?= Ui::n($allCount) ?> penempatan, semuanya di luar lingkup akses Anda.
    <?php else: ?>
      Belum ada pengurus yang memegang jabatan ini.
    <?php endif; ?>
  </p></div>
<?php else: ?>
  <div class="card">
    <h2>
      <?= Ui::n($total) ?> penempatan
      <?php if ($keyword !== ''): ?><span class="hint">&mdash; &ldquo;<?= e($keyword) ?>&rdquo;</span><?php endif; ?>
    </h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="num">#</th>
            <th>Nama</th>
            <th>Struktur</th>
            <th>Unit Organisasi</th>
            <th>Periode</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="num small muted"><?= $offset + $i + 1 ?></td>
            <td><?= Ui::personCell($r, BASEURL . '/people/detail/' . (int) $r['id']) ?></td>
            <td><?= e($r['level'] . ' ' . $r['structure_name']) ?></td>
            <td><?= $r['unit_name'] !== null && $r['unit_name'] !== '' ? e($r['unit_name']) : '<span class="muted">' . e(Ui::slotLabel(null, $position['is_core'])) . '</span>' ?></td>
            <td>
              <?= e($r['term_name']) ?>
              <?php if (!empty($r['end_date']) && $r['end_date'] < $today): ?>
                <span class="badge" title="Penempatan berakhir">berakhir <?= e(Ui::date($r['end_date'])) ?></span>
              <?php elseif ($r['is_current']): ?>
                <span class="badge current">berjalan</span>
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
        foreach ($win as $n):
          if ($prev && $n - $prev > 1) echo '<span class="gap">…</span>';
          echo $n === $page
            ? '<span class="cur" aria-current="page">' . $n . '</span>'
            : '<a href="' . e($qs(['page' => $n])) . '">' . $n . '</a>';
          $prev = $n;
        endforeach;
        ?>
      </nav>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
