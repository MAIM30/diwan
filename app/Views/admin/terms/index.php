<?php
// PATH LOKASI FILE: app/Views/admin/terms/index.php
// Periode Kepengurusan: baris Jenjang (chip), baris Acuan periode (chip, hanya bila ada lebih dari satu), kotak cari, chip status, lalu tabel
// berhalaman terurut jenjang -> induk -> nama -> periode terbaru. Pola yang sama dengan halaman Struktur Organisasi. Dulu seluruh periode
// (ratusan baris) tampil sekaligus tanpa satu pun saringan; ?structure=ID dari halaman Struktur dan dasbor tetap berlaku.
//
// Dari controller: $allCount (periode dalam lingkup), $total (yang cocok), $rows (satu halaman), $filtered, $levelCounts (jenjang => jumlah),
// $periods (id => nama acuan, kosong bila hanya satu) + $periodCounts, $filters (kunci => label) + $filterCounts, $lv / $pr / $f / $q,
// $structureId + $structureLabel, $page / $perPage / $perPageOptions.
$this->view('layouts/header', ['title' => $title]);

$pages = max(1, (int) ceil($total / $perPage));
$base = ['lv' => $lv, 'pr' => $pr ?: '', 'q' => $q, 'f' => $f, 'structure' => $structureId ?: '', 'per_page' => $perPage !== 50 ? $perPage : ''];
$keep = function ($v) { return $v !== '' && $v !== null; };
// Satu-satunya tempat parameter halaman ini disusun: tiap tautan membawa semua pilihan yang sedang aktif.
$qs = function (array $over = []) use ($base, $keep) {
    $p = array_filter(array_merge($base, $over), $keep);
    return BASEURL . '/terms' . ($p ? '?' . http_build_query($p) : '');
};
$chipGroups = PeopleOverview::chipGroups($levelCounts);
$clear = ['lv' => '', 'pr' => '', 'q' => '', 'f' => '', 'structure' => '', 'page' => ''];
?>

<div class="page-head">
  <h1>Periode Kepengurusan</h1>
  <span class="badge"><?= Ui::n($allCount) ?> periode</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/terms/period_catalog">Acuan Periode &amp; Rekap</a>
    <?php if (Auth::canEdit()): ?>
      <a class="btn" href="<?= BASEURL ?>/terms/form">Tambah Periode</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($allCount > 0): ?>
<div class="mon-strip" role="group" aria-label="Saring menurut jenjang">
  <span class="mon-strip-l">Jenjang</span>
  <a class="chip<?= $lv === '' ? ' on' : '' ?>" href="<?= e($qs(['lv' => '', 'page' => ''])) ?>"<?= $lv === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n(array_sum($levelCounts)) ?></span></a>
  <?php foreach ($chipGroups as $wing => $list): ?>
    <span class="mon-sep" aria-hidden="true"></span>
    <?php foreach ($list as $level): $on = $lv === $level; ?>
      <a class="chip<?= $on ? ' on' : ($levelCounts[$level] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['lv' => $on ? '' : $level, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>
         title="<?= e(PeopleOverview::LEVEL_NAMES[$level] ?? $level) ?>"><?= e($level) ?> <span class="chip-n"><?= Ui::n($levelCounts[$level]) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<?php if ($periods): ?>
<div class="mon-strip" role="group" aria-label="Saring menurut acuan periode" style="margin-top:8px">
  <span class="mon-strip-l">Acuan</span>
  <a class="chip<?= $pr === 0 ? ' on' : '' ?>" href="<?= e($qs(['pr' => '', 'page' => ''])) ?>"<?= $pr === 0 ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n(array_sum($periodCounts)) ?></span></a>
  <?php foreach ($periods as $id => $name): $on = $pr === (int) $id; ?>
    <a class="chip<?= $on ? ' on' : ($periodCounts[$id] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['pr' => $on ? '' : $id, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($name) ?> <span class="chip-n"><?= Ui::n($periodCounts[$id]) ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="toolbar" style="margin-top:10px">
  <form class="q" method="get" action="<?= BASEURL ?>/terms" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($q) ?>" aria-label="Cari periode" placeholder="Nama struktur, induk, periode, atau nomor SK, mis. Barru, 2021-2026">
    <?php foreach (['lv' => $lv, 'pr' => $pr ?: '', 'f' => $f, 'structure' => $structureId ?: '', 'per_page' => $perPage !== 50 ? $perPage : ''] as $k => $v): if ($v === '') continue; ?>
      <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
  </form>
  <div class="chips" role="group" aria-label="Saring menurut status">
    <a class="chip<?= $f === '' ? ' on' : '' ?>" href="<?= e($qs(['f' => '', 'page' => ''])) ?>"<?= $f === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n($filterCounts['']) ?></span></a>
    <?php foreach ($filters as $key => $label): $on = $f === $key; ?>
      <a class="chip<?= $on ? ' on' : ($filterCounts[$key] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['f' => $on ? '' : $key, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span class="chip-n"><?= Ui::n($filterCounts[$key]) ?></span></a>
    <?php endforeach; ?>
  </div>
  <label class="per-page">
    Tampilkan
    <select onchange="window.location.href=this.value" aria-label="Jumlah baris per halaman">
      <?php foreach ($perPageOptions as $opt): ?>
        <option value="<?= e($qs(['per_page' => $opt === 50 ? '' : $opt, 'page' => ''])) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= (int) $opt ?> baris</option>
      <?php endforeach; ?>
    </select>
  </label>
</div>

<p class="mon-ctx">
  <span>Menampilkan <b><?= Ui::n($total) ?></b> dari <?= Ui::n($allCount) ?> periode<?= $structureId ? ' &middot; struktur <b>' . e($structureLabel !== '' ? $structureLabel : '#' . $structureId) . '</b>' : '' ?><?= $lv !== '' ? ' &middot; jenjang <b>' . e($lv) . '</b>' : '' ?><?= $pr ? ' &middot; acuan <b>' . e($periods[$pr] ?? '') . '</b>' : '' ?><?= $f !== '' ? ' &middot; ' . e(mb_strtolower($filters[$f])) : '' ?><?= $q !== '' ? ' &middot; pencarian &ldquo;' . e($q) . '&rdquo;' : '' ?></span>
  <?php if ($filtered): ?><a href="<?= e($qs($clear)) ?>">Hapus penyaringan</a><?php endif; ?>
</p>
<?php endif; ?>

<div class="card">
  <?php if ($allCount === 0): ?>
    <div class="empty"><span>Belum ada periode di lingkup Anda.</span></div>
  <?php elseif ($total === 0): ?>
    <div class="empty">
      <?= Ui::icon('calendar', 28) ?>
      <span>Tidak ada periode yang cocok dengan penyaringan ini.</span>
      <a class="btn small secondary" href="<?= e($qs($clear)) ?>">Hapus penyaringan</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Struktur</th>
            <th>Periode</th>
            <th>Mulai</th>
            <th>Akhir</th>
            <th>Status</th>
            <th>No. SK</th>
            <th class="num">Pengurus</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php
        $prevStructure = null;
        foreach ($rows as $r):
          $firstOfStructure = $prevStructure !== (int) $r['structure_id'];
          $prevStructure = (int) $r['structure_id'];
        ?>
          <tr>
            <td>
              <?php if ($firstOfStructure): ?>
                <div class="st-name"<?= $r['short'] !== $r['structure_name'] ? ' title="' . e($r['structure_name']) . '"' : '' ?>><?= e($r['level'] . ' ' . $r['short']) ?></div>
                <?php if ($r['parent_label'] !== ''): ?><div class="small muted">di bawah <?= e($r['parent_label']) ?></div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= BASEURL ?>/terms/detail/<?= (int) $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a>
              <a class="small muted" href="<?= BASEURL ?>/terms/period_detail/<?= (int) $r['governance_period_id'] ?>">rekap periode</a>
              <?php if ($r['is_current']): ?><span class="badge current">berjalan</span><?php endif; ?>
            </td>
            <td class="nowrap small"><?= e($r['start_date']) ?></td>
            <td class="nowrap small">
              <?php if (!empty($r['end_date_actual'])): ?>
                <?= e($r['end_date_actual']) ?>
                <div class="muted" style="font-size:11px">aktual</div>
              <?php elseif (!empty($r['end_date_planned'])): ?>
                <?= e($r['end_date_planned']) ?>
                <div class="muted" style="font-size:11px">rencana</div>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $r['status'] === 'ended' ? 'ended' : ($r['status'] === 'active' ? 'ok' : '') ?>">
                <?= e($r['status']) ?>
              </span>
            </td>
            <td class="small mono"><?= e($r['sk_number'] ?: '—') ?>
              <?php if (!empty($r['sk_file_path'])): ?>
                <a href="<?= BASEURL ?>/terms/sk/<?= (int) $r['id'] ?>" target="_blank" rel="noopener" class="badge ok" title="Buka berkas SK">PDF</a>
              <?php else: ?>
                <span class="badge" title="Berkas SK belum dilampirkan">tanpa berkas</span>
              <?php endif; ?></td>
            <td class="num">
              <?php if ((int) $r['placement_count'] === 0): ?>
                <span class="badge danger">kosong</span>
              <?php else: ?>
                <?= (int) $r['placement_count'] ?>
              <?php endif; ?>
            </td>
            <td class="nowrap">
              <a class="btn small secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $r['id'] ?>">Susunan</a>
              <?php if (Auth::canEdit()): ?>
                <a class="btn small secondary" href="<?= BASEURL ?>/terms/form/<?= (int) $r['id'] ?>">Ubah</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="mon-count">Menampilkan <?= Ui::n(($page - 1) * $perPage + 1) ?>&ndash;<?= Ui::n(min($total, $page * $perPage)) ?> dari <b><?= Ui::n($total) ?></b> periode</p>

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

<div class="note">
  Acuan periode dapat digunakan oleh banyak struktur. Setiap struktur memiliki susunan, status, dan SK sendiri;
  penempatan lama tetap menjadi arsip.
</div>

<?php $this->view('layouts/footer'); ?>
