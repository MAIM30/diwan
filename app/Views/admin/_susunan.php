<?php
// PATH LOKASI FILE: app/Views/admin/_susunan.php
// Daftar bertingkat yang membuka isinya saat diketuk, dipakai halaman detail periode dan daftar pengurus "per Bidang".
// Variabel: $tree (simpul dari Susunan::arrange / Susunan::wrap), $treeId (id elemen, sasaran tombol Buka semua / Tutup semua).
// Tautan Ubah memakai term_id milik barisnya sendiri, jadi satu daftar boleh memuat penempatan dari beberapa periode.
$canEdit = Auth::canEdit();

$roster = function (array $n) use ($canEdit) {
    ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Jabatan</th>
            <th>Nama</th>
            <?php if ($n['phone']): ?><th>Kontak</th><?php endif; ?>
            <th>Masa</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($n['rows'] as $r): ?>
          <tr>
            <td class="nowrap"><?= e($r['position_name']) ?></td>
            <td>
              <?= Ui::personCell($r, BASEURL . '/people/detail/' . (int) $r['person_id'], null, 'sm') ?>
              <?php if (!$r['is_primary']): ?><span class="badge" style="margin-left:46px">rangkap</span><?php endif; ?>
              <?php if (!empty($r['memimpin'])): ?>
                <div class="small muted" style="margin-left:46px">Memimpin <?= e(implode(', ', $r['memimpin'])) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($n['phone']): ?><td class="small mono"><?= e($r['phone'] ?: '—') ?></td><?php endif; ?>
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

$node = function (array $n) use (&$node, $roster) {
    $meta = [];
    if ($n['children'] && $n['noun']) $meta[] = count($n['children']) . ' ' . $n['noun'];
    $meta[] = $n['count'] > 0 ? $n['count'] . ' orang' : ($n['rows'] !== null && !$n['rows'] ? 'belum ada pengurus' : '0 orang');
    ?>
    <li class="sn">
      <details<?= $n['open'] ? ' open' : '' ?>>
        <summary>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main">
            <span class="sn-name"><?= e($n['title']) ?></span>
            <?php if ($n['badge']): ?><span class="badge"><?= e($n['badge']) ?></span><?php endif; ?>
            <?php if ($n['lead']): ?><span class="small muted"> &middot; <?= e($n['lead']) ?></span><?php endif; ?>
          </span>
          <span class="sn-n"><?= e(implode(' · ', $meta)) ?></span>
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
            <?php foreach ($n['children'] as $c) $node($c); ?>
          </ul>
        <?php elseif ($n['rows'] === null): ?>
          <div class="sn-body"><p class="muted small" style="margin:0"><?= e($n['empty']) ?></p></div>
        <?php endif; ?>
      </details>
    </li>
    <?php
};
?>
<div class="btn-row" style="margin:0 0 8px">
  <button type="button" class="btn small secondary" data-tree="<?= e($treeId) ?>" data-tree-all="open">Buka semua</button>
  <button type="button" class="btn small secondary" data-tree="<?= e($treeId) ?>" data-tree-all="close">Tutup semua</button>
</div>
<ul class="stree" id="<?= e($treeId) ?>">
  <?php foreach ($tree as $n) $node($n); ?>
</ul>
