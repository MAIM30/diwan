<?php
// PATH LOKASI FILE: app/Views/admin/forums/index.php
$this->view('layouts/header', ['title' => $title]);

$statusBadge = function ($s) {
    $map = ['draft' => 'muted', 'terbuka' => 't-blue', 'dikunci' => 't-amber', 'selesai' => 't-green'];
    $label = ['draft' => 'Draft', 'terbuka' => 'Terbuka', 'dikunci' => 'Dikunci', 'selesai' => 'Selesai'];
    return '<span class="badge ' . ($map[$s] ?? '') . '">' . ($label[$s] ?? e($s)) . '</span>';
};
?>

<div class="page-head">
  <h1><?= e($title) ?></h1>
  <span class="badge"><?= count($forums) ?> forum</span>
  <div class="actions">
    <?php if (Auth::canEdit()): ?>
      <a class="btn" href="<?= BASEURL ?>/forums/form"><?= Ui::icon('plus', 17) ?> Buat Forum</a>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($forums)): ?>
  <div class="empty">
    <?= Ui::icon('calendar-check', 48) ?>
    <p>Belum ada forum permusyawaratan.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="tbl">
      <thead>
        <tr>
          <th>Nama Forum</th>
          <th>Jenis</th>
          <th>Penyelenggara</th>
          <th>Tanggal</th>
          <th class="center">Peserta</th>
          <th class="center">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($forums as $f): ?>
          <tr>
            <td><a href="<?= BASEURL ?>/forums/detail/<?= (int) $f['id'] ?>"><?= e($f['name']) ?></a></td>
            <td><?= e(ForumModel::TYPES[$f['type']] ?? $f['type']) ?></td>
            <td><?= e(trim($f['level'] . ' ' . $f['structure_name'])) ?></td>
            <td>
              <?php if ($f['start_date']): ?>
                <?= e($f['start_date']) ?><?= $f['end_date'] ? ' — ' . e($f['end_date']) : '' ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="center"><?= (int) $f['active_count'] ?></td>
            <td class="center"><?= $statusBadge($f['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
