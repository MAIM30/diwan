<?php
// PATH LOKASI FILE: app/Views/admin/position_corrections/index.php
$this->view('layouts/header', ['title' => $title]);
$tabs = ['submitted' => 'Menunggu', 'resolved' => 'Ditangani', 'rejected' => 'Tidak ditindaklanjuti', 'all' => 'Semua'];
?>

<div class="page-head"><h1>Laporan Koreksi</h1></div>
<div class="note">
  Pengurus mengirim laporan bila jabatan atau penempatan yang tampil di portal perlu diperiksa.
  Menandai laporan sebagai ditangani hanya memperbarui status laporan; perubahan data kepengurusan tetap dilakukan melalui menu Periode/Penempatan.
</div>

<div class="card">
  <div class="btn-row" style="margin:0">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="btn small <?= $status === $key ? '' : 'secondary' ?>"
         href="<?= BASEURL ?>/positionCorrections?status=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="card muted">Tidak ada laporan pada pilihan ini.</div>
<?php endif; ?>

<?php foreach ($rows as $row): ?>
  <?php
    $statusLabel = ['submitted' => 'Menunggu', 'resolved' => 'Ditangani', 'rejected' => 'Tidak ditindaklanjuti'][$row['status']] ?? $row['status'];
    $statusClass = ['submitted' => 'core', 'resolved' => 'ok', 'rejected' => 'danger'][$row['status']] ?? '';
  ?>
  <section class="card correction-card">
    <div class="card-head">
      <h2><?= e($row['full_name']) ?></h2>
      <span class="badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
    </div>
    <div class="small muted">
      <?= e($row['phone']) ?> · <?= e($row['structure_snapshot']) ?> · <?= e($row['term_snapshot']) ?>
      · Dikirim <?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?>
    </div>
    <p style="margin:12px 0 4px">
      <strong><?= e(PositionCorrectionModel::categoryLabel($row['category'])) ?> yang dilaporkan:</strong>
      <?= e($row['position_snapshot']) ?><?= !empty($row['unit_snapshot']) ? ' · ' . e($row['unit_snapshot']) : '' ?>
    </p>
    <div class="note" style="margin:10px 0; white-space:pre-wrap"><?= e($row['details']) ?></div>
    <?php if (!empty($row['admin_note'])): ?>
      <p class="small"><strong>Catatan admin:</strong> <?= nl2br(e($row['admin_note'])) ?></p>
    <?php endif; ?>
    <?php if ($row['status'] === 'submitted'): ?>
      <form method="post" action="<?= BASEURL ?>/positionCorrections/decide/<?= (int) $row['id'] ?>" class="correction-decision">
        <?= Csrf::field() ?>
        <label for="note-<?= (int) $row['id'] ?>">Catatan untuk pengurus (opsional)</label>
        <textarea id="note-<?= (int) $row['id'] ?>" name="admin_note" maxlength="2000" rows="2"
                  placeholder="Contoh: Data sudah dikoreksi pada menu Penempatan."></textarea>
        <div class="btn-row">
          <button class="btn" type="submit" name="decision" value="resolved">Tandai ditangani</button>
          <button class="btn danger" type="submit" name="decision" value="rejected">Tidak ditindaklanjuti</button>
          <?php if (!empty($row['term_id']) && empty($row['placement_deleted_at']) && Auth::canEdit()): ?>
            <a class="btn secondary" href="<?= BASEURL ?>/placements/form/<?= (int) $row['term_id'] ?>/<?= (int) $row['placement_id'] ?>">Buka penempatan</a>
          <?php endif; ?>
        </div>
      </form>
    <?php elseif (!empty($row['reviewer_name'])): ?>
      <div class="small muted">Diproses oleh <?= e($row['reviewer_name']) ?><?= $row['reviewed_at'] ? ' · ' . e(date('d/m/Y H:i', strtotime($row['reviewed_at']))) : '' ?></div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php $this->view('layouts/footer'); ?>
