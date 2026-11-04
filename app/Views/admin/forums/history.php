<?php
// PATH LOKASI FILE: app/Views/admin/forums/history.php
$this->view('layouts/header', ['title' => $title]);

$actionLabel = [
    'forum_dibuat'        => 'Forum dibuat',
    'forum_diubah'        => 'Forum diubah',
    'forum_dihapus'       => 'Forum dihapus',
    'status_terbuka'      => 'Forum dibuka',
    'status_dikunci'      => 'Forum dikunci',
    'status_selesai'      => 'Forum diselesaikan',
    'peserta_ditetapkan'  => 'Peserta ditetapkan',
    'peserta_diubah'      => 'Peserta diubah',
    'peserta_dicabut'     => 'Peserta dicabut',
    'peserta_diganti'     => 'Peserta diganti',
    'peserta_dihapus'     => 'Peserta dihapus',
];
?>

<div class="page-head">
  <a class="btn-back" href="<?= BASEURL ?>/forums/detail/<?= (int) $forum['id'] ?>"><?= Ui::icon('arrow-left', 17) ?> <?= e($forum['name']) ?></a>
  <h1>Riwayat Perubahan</h1>
</div>

<?php if (empty($history)): ?>
  <div class="empty">
    <?= Ui::icon('clock', 48) ?>
    <p>Belum ada riwayat.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="tbl">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Aksi</th>
          <th>Peserta</th>
          <th>Alasan</th>
          <th>Pelaku</th>
          <?php if ($forum['locked_at']): ?>
            <th class="center" title="Terjadi setelah forum dikunci">SAK</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td style="white-space:nowrap"><?= e($h['created_at']) ?></td>
            <td><?= e($actionLabel[$h['action']] ?? $h['action']) ?></td>
            <td><?= $h['participant_name'] ? e($h['participant_name']) : '<span class="muted">—</span>' ?></td>
            <td><?= $h['reason'] ? e($h['reason']) : '<span class="muted">—</span>' ?></td>
            <td><?= $h['actor_name'] ? e($h['actor_name']) : '<span class="muted">Sistem</span>' ?></td>
            <?php if ($forum['locked_at']): ?>
              <td class="center"><?= $h['after_lock'] ? '<span class="badge t-amber">Ya</span>' : '' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
