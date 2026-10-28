<?php
// PATH LOKASI FILE: app/Views/admin/structures/index.php
// Pohon struktur: tiap simpul adalah <details>; mengetuknya membuka keterangan dan anak-anaknya (tanpa JavaScript pun
// berfungsi). Variabel: $tops (puncak dalam lingkup), $kids (id induk => anak langsung), $admins (id struktur => akun admin).
$this->view('layouts/header', ['title' => $title]);

$canEdit = Auth::canEdit();

$node = function (array $r, $open) use (&$node, $kids, $admins, $canEdit) {
    $id = (int) $r['id'];
    $children = $kids[$id] ?? [];
    ?>
    <li class="sn">
      <details<?= $open ? ' open' : '' ?>>
        <summary>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main">
            <span class="sn-name"><?= e($r['name']) ?></span>
            <span class="badge"><?= e($r['level']) ?></span>
            <?php if ($r['wing'] === 'muslimah'): ?><span class="badge muslimah">Muslimah</span><?php endif; ?>
            <?php if (!$r['is_active']): ?><span class="badge">nonaktif</span><?php endif; ?>
          </span>
          <?php if ($children): ?><span class="sn-n"><?= count($children) ?> di bawahnya</span><?php endif; ?>
        </summary>

        <div class="sn-body">
          <dl class="sn-facts">
            <?php if ($r['region_code']): ?><div><dt>Kode wilayah</dt><dd class="mono"><?= e($r['region_code']) ?></dd></div><?php endif; ?>
            <div><dt>Periode</dt><dd><a href="<?= BASEURL ?>/terms?structure=<?= $id ?>">Lihat periode</a></dd></div>
            <?php if ($canEdit): ?>
              <div>
                <dt>Admin struktur ini</dt>
                <dd class="small">
                  <?php foreach (($admins[$id] ?? []) as $a): ?>
                    <div><a href="<?= BASEURL ?>/accounts/form/<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></a>
                      <?php if ($a['status'] !== 'active'): ?><span class="badge">ditangguhkan</span><?php endif; ?>
                      <?php if (AdminNaming::isPlaceholderPhone($a['phone'])): ?><span class="badge core" title="Nomor pengganti: ganti dengan nomor WA admin sebenarnya lewat Ubah akun">nomor pengganti</span><?php endif; ?></div>
                  <?php endforeach; ?>
                  <?php if (empty($admins[$id])): ?><span class="muted">belum ada</span><?php endif; ?>
                  <div><a href="<?= BASEURL ?>/accounts/form?scope=<?= $id ?>">+ Buat admin</a></div>
                </dd>
              </div>
            <?php endif; ?>
          </dl>

          <?php if ($canEdit): ?>
            <div class="sn-actions">
              <a class="btn small secondary" href="<?= BASEURL ?>/structures/form/<?= $id ?>">Ubah</a>
              <form class="inline" method="post" action="<?= BASEURL ?>/structures/delete"
                    data-confirm="Hapus struktur <?= e($r['name']) ?>?" onsubmit="return confirm(this.dataset.confirm)">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn small danger">Hapus</button>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($children): ?>
          <ul>
            <?php foreach ($children as $c) $node($c, false); ?>
          </ul>
        <?php endif; ?>
      </details>
    </li>
    <?php
};
?>

<div class="page-head">
  <h1>Struktur Organisasi</h1>
  <div class="actions">
    <?php if ($canEdit): ?>
      <a class="btn" href="<?= BASEURL ?>/structures/form">Tambah Struktur</a>
    <?php endif; ?>
  </div>
</div>

<div class="note">
  Sayap Muslimah (MWP/MWW/MWD/MWC/MWRa) adalah cabang paralel di bawah akar yang sama, bukan label lain
  dari struktur umum: &ldquo;DPW Sulawesi Tenggara&rdquo; dan &ldquo;MWW Sulawesi Tenggara&rdquo;
  adalah dua baris berbeda. Lingkup admin tidak pernah menyeberang antar cabang.
</div>

<div class="card">
  <?php if (empty($tops)): ?>
    <div class="empty"><span>Belum ada struktur dalam lingkup Anda.</span></div>
  <?php else: ?>
    <div class="btn-row" style="margin:0 0 8px">
      <button type="button" class="btn small secondary" data-tree="struktur" data-tree-all="open">Buka semua</button>
      <button type="button" class="btn small secondary" data-tree="struktur" data-tree-all="close">Tutup semua</button>
    </div>
    <ul class="stree" id="struktur">
      <?php foreach ($tops as $t) $node($t, count($tops) === 1); ?>
    </ul>
  <?php endif; ?>
</div>

<?php $this->view('layouts/footer'); ?>
