<?php
// PATH LOKASI FILE: app/Views/admin/forums/detail.php
$this->view('layouts/header', ['title' => $title]);

$statusBadge = function ($s) {
    $map = ['draft' => 'muted', 'terbuka' => 't-blue', 'dikunci' => 't-amber', 'selesai' => 't-green'];
    $label = ['draft' => 'Draft', 'terbuka' => 'Terbuka', 'dikunci' => 'Dikunci', 'selesai' => 'Selesai'];
    return '<span class="badge ' . ($map[$s] ?? '') . '">' . ($label[$s] ?? e($s)) . '</span>';
};
$catLabel = ['peserta_penuh' => 'Peserta Penuh', 'undangan' => 'Undangan', 'peninjau' => 'Peninjau'];
$stLabel  = ['ditetapkan' => 'Ditetapkan', 'dicabut' => 'Dicabut', 'diganti' => 'Diganti'];
$isLocked = in_array($forum['status'], ['dikunci', 'selesai'], true);
$isFinished = $forum['status'] === 'selesai';
?>

<div class="page-head">
  <a class="btn-back" href="<?= BASEURL ?>/forums"><?= Ui::icon('arrow-left', 17) ?> Forum</a>
  <h1><?= e($forum['name']) ?></h1>
  <?= $statusBadge($forum['status']) ?>
</div>

<div class="stats recap" style="margin-bottom:1rem">
  <div class="stat t-blue"><div class="stat-top"><span class="lbl">Peserta Penuh</span></div><div class="num"><?= (int) $counts['peserta_penuh'] ?></div></div>
  <div class="stat t-violet"><div class="stat-top"><span class="lbl">Undangan</span></div><div class="num"><?= (int) $counts['undangan'] ?></div></div>
  <div class="stat"><div class="stat-top"><span class="lbl">Peninjau</span></div><div class="num"><?= (int) $counts['peninjau'] ?></div></div>
  <div class="stat t-green"><div class="stat-top"><span class="lbl">Total Aktif</span></div><div class="num"><?= (int) $activeCount ?></div></div>
</div>

<div class="card" style="margin-bottom:1rem;padding:1rem">
  <div style="display:flex;flex-wrap:wrap;gap:1rem">
    <div><strong>Jenis:</strong> <?= e(ForumModel::TYPES[$forum['type']] ?? $forum['type']) ?></div>
    <div><strong>Penyelenggara:</strong> <?= e(trim($forum['level'] . ' ' . $forum['structure_name'])) ?></div>
    <?php if ($forum['start_date']): ?>
      <div><strong>Tanggal:</strong> <?= e($forum['start_date']) ?><?= $forum['end_date'] ? ' — ' . e($forum['end_date']) : '' ?></div>
    <?php endif; ?>
    <?php if ($forum['decision_ref']): ?>
      <div><strong>SK:</strong> <?= e($forum['decision_ref']) ?></div>
    <?php endif; ?>
    <?php if ($forum['locked_at']): ?>
      <div><strong>Dikunci:</strong> <?= e($forum['locked_at']) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="toolbar" style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:.5rem">
  <?php if (!$isFinished): ?>
    <a class="btn" href="<?= BASEURL ?>/forums/assign/<?= (int) $forum['id'] ?>"><?= Ui::icon('user-plus', 17) ?> Tetapkan Peserta</a>
    <a class="btn secondary" href="<?= BASEURL ?>/forums/form/<?= (int) $forum['id'] ?>"><?= Ui::icon('edit', 17) ?> Ubah Forum</a>
  <?php endif; ?>

  <?php if ($forum['status'] === 'draft'): ?>
    <form method="post" action="<?= BASEURL ?>/forums/open/<?= (int) $forum['id'] ?>" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" class="btn secondary" onclick="return confirm('Buka forum untuk penetapan peserta?')">
        <?= Ui::icon('unlock', 17) ?> Buka Forum
      </button>
    </form>
    <form method="post" action="<?= BASEURL ?>/forums/delete/<?= (int) $forum['id'] ?>" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" class="btn danger" onclick="return confirm('Hapus forum ini? Forum hanya bisa dihapus selagi berstatus Draft.')">
        <?= Ui::icon('trash-2', 17) ?> Hapus
      </button>
    </form>
  <?php elseif ($forum['status'] === 'terbuka'): ?>
    <form method="post" action="<?= BASEURL ?>/forums/lock/<?= (int) $forum['id'] ?>" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" class="btn secondary" onclick="return confirm('Kunci forum? Perubahan peserta masih dimungkinkan dengan menyertakan alasan.')">
        <?= Ui::icon('lock', 17) ?> Kunci Forum
      </button>
    </form>
  <?php elseif ($forum['status'] === 'dikunci'): ?>
    <form method="post" action="<?= BASEURL ?>/forums/finish/<?= (int) $forum['id'] ?>" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" class="btn secondary" onclick="return confirm('Tandai forum selesai? Data menjadi baca-saja dan tidak bisa diubah lagi.')">
        <?= Ui::icon('check-circle', 17) ?> Selesaikan
      </button>
    </form>
  <?php endif; ?>

  <a class="btn secondary" href="<?= BASEURL ?>/forums/export/<?= (int) $forum['id'] ?>"><?= Ui::icon('download', 17) ?> Ekspor CSV</a>
  <a class="btn secondary" href="<?= BASEURL ?>/forums/history/<?= (int) $forum['id'] ?>"><?= Ui::icon('clock', 17) ?> Riwayat</a>
</div>
<?php else: ?>
<div class="toolbar" style="margin-bottom:1rem;display:flex;gap:.5rem">
  <a class="btn secondary" href="<?= BASEURL ?>/forums/export/<?= (int) $forum['id'] ?>"><?= Ui::icon('download', 17) ?> Ekspor CSV</a>
  <a class="btn secondary" href="<?= BASEURL ?>/forums/history/<?= (int) $forum['id'] ?>"><?= Ui::icon('clock', 17) ?> Riwayat</a>
</div>
<?php endif; ?>

<?php if (!empty($warnings)): ?>
<div class="card" style="margin-bottom:1rem;padding:1rem;border-left:4px solid var(--amber-500,#f59e0b)">
  <h3 style="margin:0 0 .5rem"><?= Ui::icon('alert-triangle', 18) ?> Peringatan sebelum mengunci</h3>
  <?php if (!empty($warnings['tanpa_hp'])): ?>
    <details>
      <summary><strong><?= count($warnings['tanpa_hp']) ?></strong> peserta tanpa nomor HP</summary>
      <ul><?php foreach ($warnings['tanpa_hp'] as $w): ?><li><?= e($w['full_name']) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php if (!empty($warnings['hp_rangkap'])): ?>
    <details>
      <summary><strong><?= count($warnings['hp_rangkap']) ?></strong> peserta dengan HP yang dipakai orang lain</summary>
      <ul><?php foreach ($warnings['hp_rangkap'] as $w): ?><li><?= e($w['full_name']) ?> (<?= e($w['phone']) ?>)</li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php if (!empty($warnings['tanpa_penempatan'])): ?>
    <details>
      <summary><strong><?= count($warnings['tanpa_penempatan']) ?></strong> peserta tanpa penempatan di periode berjalan</summary>
      <ul><?php foreach ($warnings['tanpa_penempatan'] as $w): ?><li><?= e($w['full_name']) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($participants)): ?>
  <div class="empty">
    <?= Ui::icon('users', 48) ?>
    <p>Belum ada peserta yang ditetapkan.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="tbl">
      <thead>
        <tr>
          <th>No</th>
          <th>Nama</th>
          <th>Kategori</th>
          <th>Utusan</th>
          <th>No HP</th>
          <th class="center">Status</th>
          <?php if ($forum['status'] === 'dikunci' || $forum['status'] === 'selesai'): ?>
            <th class="center" title="Diubah setelah forum dikunci">SAK</th>
          <?php endif; ?>
          <?php if ($canEdit && !$isFinished): ?>
            <th class="center">Aksi</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php $no = 0; foreach ($participants as $p): $no++; ?>
          <tr class="<?= $p['status'] !== 'ditetapkan' ? 'muted' : '' ?>">
            <td><?= $no ?></td>
            <td>
              <a href="<?= BASEURL ?>/people/detail/<?= (int) $p['person_id'] ?>"><?= e($p['full_name']) ?></a>
              <?php if ($p['status'] === 'diganti' && $p['replaced_by_name']): ?>
                <br><small class="muted">Diganti oleh <?= e($p['replaced_by_name']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= e($catLabel[$p['category']] ?? $p['category']) ?></td>
            <td><?= $p['repr_level'] ? e(trim($p['repr_level'] . ' ' . $p['repr_structure_name'])) : '<span class="muted">—</span>' ?></td>
            <td><?= $p['phone'] ? e($p['phone']) : '<span class="muted">—</span>' ?></td>
            <td class="center">
              <?php
              $stBadge = ['ditetapkan' => 't-green', 'dicabut' => 't-red', 'diganti' => 't-amber'];
              ?>
              <span class="badge <?= $stBadge[$p['status']] ?? '' ?>"><?= e($stLabel[$p['status']] ?? $p['status']) ?></span>
            </td>
            <?php if ($isLocked): ?>
              <td class="center"><?= $p['changed_after_lock'] ? '<span class="badge t-amber">Ya</span>' : '' ?></td>
            <?php endif; ?>
            <?php if ($canEdit && !$isFinished): ?>
              <td class="center" style="white-space:nowrap">
                <?php if ($p['status'] === 'ditetapkan'): ?>
                  <form method="post" action="<?= BASEURL ?>/forums/revoke/<?= (int) $p['id'] ?>" style="display:inline"
                        onsubmit="<?= $isLocked ? "var r=prompt('Alasan pencabutan (wajib karena forum sudah dikunci):');if(!r)return false;this.querySelector('[name=reason]').value=r" : "return confirm('Cabut peserta ini?')" ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn-sm danger" title="Cabut"><?= Ui::icon('x', 14) ?></button>
                  </form>
                  <?php if (!$isLocked): ?>
                    <form method="post" action="<?= BASEURL ?>/forums/remove/<?= (int) $p['id'] ?>" style="display:inline"
                          onsubmit="return confirm('Hapus peserta ini? (hanya untuk kekeliruan entri)')">
                      <?= Csrf::field() ?>
                      <button type="submit" class="btn-sm" title="Hapus (kekeliruan)"><?= Ui::icon('trash-2', 14) ?></button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
