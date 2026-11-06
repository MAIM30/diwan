<?php
// PATH LOKASI FILE: app/Views/admin/_susunan.php
// Daftar bertingkat yang membuka isinya saat diketuk, dipakai halaman detail periode dan isi tiap struktur di Data Pengurus > Per Struktur.
// Variabel: $tree (simpul dari Susunan::arrange), $treeId (id elemen, sasaran tombol Buka semua / Tutup semua).
// Opsional: $metrics (true = tiap simpul menampilkan kolom Orang / Inti / Data lengkap / L·P yang sejajar dengan baris Per Struktur,
// bukan keterangan teks), $intiFlags (K/S/B terisi, ditampilkan pada simpul Pengurus Inti saat $metrics), $toolbar (false = tanpa tombol
// Buka semua / Tutup semua), $flat (true = tanpa simpul untuk dibuka: daftar nama dan jabatan langsung, Pengurus Inti dulu lalu Pimpinan
// Lainnya; untuk struktur yang tak punya Bidang dan unit, mis. MWW dan MWD).
// Tautan Ubah memakai term_id milik barisnya sendiri, jadi satu daftar boleh memuat penempatan dari beberapa periode.
// Lencana "rangkap" (penempatan tak bertanda "utama") tidak ditampilkan saat $metrics: bendera itu kosong di hampir semua penempatan
// (seluruh DPD, MWD, DSO, DSY, DPK), jadi hampir setiap baris akan salah disebut rangkap; rangkap yang sebenarnya (lebih dari satu
// penempatan berjalan) dihitung kartu "Rangkap" di halaman Data Pengurus.
$canEdit = Auth::canEdit();
$metrics = !empty($metrics);
$flat = !empty($flat);
$toolbar = !isset($toolbar) || $toolbar;
$intiFlags = $intiFlags ?? null;

// $compact (daftar rata): kolom Kontak selalu ada, kolom Masa hanya bila ada penempatan yang bertanggal (selebihnya "penuh periode"
// berulang di setiap baris).
$roster = function (array $n, $compact = false) use ($canEdit, $metrics) {
    $showPhone = $compact || $n['phone'];
    $showMasa = true;
    if ($compact) {
        $showMasa = false;
        foreach ($n['rows'] as $r) if ($r['start_date'] || $r['end_date']) { $showMasa = true; break; }
    }
    ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Jabatan</th>
            <th>Nama</th>
            <?php if ($showPhone): ?><th>Kontak</th><?php endif; ?>
            <?php if ($showMasa): ?><th>Masa</th><?php endif; ?>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($n['rows'] as $r): ?>
          <tr>
            <td class="nowrap"><?= e($r['position_name']) ?></td>
            <td>
              <?= Ui::personCell($r, BASEURL . '/people/detail/' . (int) $r['person_id'], null, 'sm') ?>
              <?php if (!$metrics && !$r['is_primary']): ?><span class="badge" style="margin-left:46px">rangkap</span><?php endif; ?>
              <?php if (!empty($r['memimpin'])): ?>
                <div class="small muted" style="margin-left:46px">Memimpin <?= e(implode(', ', $r['memimpin'])) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($showPhone): ?><td class="small mono"><?= e($r['phone'] ?: '—') ?></td><?php endif; ?>
            <?php if ($showMasa): ?>
            <td class="small">
              <?php if ($r['end_date']): ?>
                <span class="badge ended">berakhir <?= e($r['end_date']) ?></span>
                <div class="muted"><?= e(str_replace('_', ' ', (string) $r['end_reason'])) ?></div>
              <?php elseif ($r['start_date']): ?>
                sejak <?= e($r['start_date']) ?>
              <?php else: ?>
                <span class="muted">penuh periode</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php if ($canEdit): ?>
              <td class="nowrap">
                <a class="btn small secondary" href="<?= BASEURL ?>/placements/form/<?= (int) $r['term_id'] ?>/<?= (int) $r['id'] ?>">Ubah</a>
                <?php if (!$r['end_date']): ?>
                  <form class="inline" method="post" action="<?= BASEURL ?>/placements/end"
                        data-confirm="Akhiri penempatan ini? Riwayatnya tetap tersimpan." onsubmit="return confirm(this.dataset.confirm)">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="end_reason" value="mengundurkan_diri">
                    <button type="submit" class="btn small secondary">Akhiri</button>
                  </form>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
};

// Daftar rata: tanpa simpul <details>. Tiap bagian yang berisi (Pengurus Inti, Pimpinan Lainnya) diberi judul kecil dan tabelnya langsung.
if ($flat) {
    echo '<div class="mon-flat">';
    foreach ($tree as $n) {
        if (empty($n['rows'])) continue;
        echo '<div class="mon-flat-h">' . e($n['title']) . ' <span>' . Ui::n($n['m']['orang']) . ' orang</span></div>';
        $roster($n, true);
    }
    echo '</div>';
    return;
}

$node = function (array $n, $depth = 0) use (&$node, $roster, $metrics, $intiFlags) {
    $meta = [];
    if ($n['children'] && $n['noun']) $meta[] = count($n['children']) . ' ' . $n['noun'];
    $meta[] = $n['count'] > 0 ? $n['count'] . ' orang' : ($n['rows'] !== null && !$n['rows'] ? 'belum ada pengurus' : '0 orang');
    ?>
    <li class="sn">
      <details<?= $n['open'] ? ' open' : '' ?>>
        <summary<?= $metrics ? ' class="mon-row" style="--d:' . (int) $depth . '"' : '' ?>>
          <?php if ($metrics): ?><span class="mon-t"><?php endif; ?>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main">
            <span class="sn-name"><?= e($n['title']) ?></span>
            <?php if ($n['badge']): ?><span class="badge"><?= e($n['badge']) ?></span><?php endif; ?>
            <?php if ($n['lead']): ?><span class="small muted"> &middot; <?= e($n['lead']) ?></span><?php endif; ?>
            <?php if ($metrics && $n['children'] && $n['noun']): ?><span class="small muted"><?= count($n['children']) . ' ' . e($n['noun']) ?></span><?php endif; ?>
          </span>
          <?php if ($metrics): ?></span><?php endif; ?>
          <?php if ($metrics): ?>
            <span class="mon-n"><?= Ui::n($n['m']['orang']) ?></span>
            <span class="mon-i"><?= ($n['kind'] ?? null) === 'inti' && $intiFlags ? Ui::ksb($intiFlags) : '<span class="muted">&mdash;</span>' ?></span>
            <span class="mon-b"><?= Ui::meter($n['m']['lengkap'], $n['m']['orang']) ?></span>
            <span class="mon-lp"><?= Ui::n($n['m']['l']) ?> &middot; <?= Ui::n($n['m']['p']) ?></span>
          <?php else: ?>
            <span class="sn-n"><?= e(implode(' · ', $meta)) ?></span>
          <?php endif; ?>
        </summary>

        <?php if ($n['rows'] !== null): ?>
          <div class="sn-body sn-roster">
            <?php if ($n['rows']): $roster($n); else: ?>
              <p class="muted small" style="margin:6px 10px"><?= e($n['empty']) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($n['children']): ?>
          <ul>
            <?php foreach ($n['children'] as $c) $node($c, $depth + 1); ?>
          </ul>
        <?php elseif ($n['rows'] === null): ?>
          <div class="sn-body"><p class="muted small" style="margin:0"><?= e($n['empty']) ?></p></div>
        <?php endif; ?>
      </details>
    </li>
    <?php
};
?>
<?php if ($toolbar): ?>
<div class="btn-row" style="margin:0 0 8px">
  <button type="button" class="btn small secondary" data-tree="<?= e($treeId) ?>" data-tree-all="open">Buka semua</button>
  <button type="button" class="btn small secondary" data-tree="<?= e($treeId) ?>" data-tree-all="close">Tutup semua</button>
</div>
<?php endif; ?>
<ul class="stree<?= $metrics ? ' mon' : '' ?>" id="<?= e($treeId) ?>">
  <?php foreach ($tree as $n) $node($n); ?>
</ul>
