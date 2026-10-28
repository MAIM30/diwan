<?php
// PATH LOKASI FILE: app/Views/admin/terms/index.php
$this->view('layouts/header', ['title' => $title]);
$filterStructure = isset($_GET['structure']) ? (int) $_GET['structure'] : 0;
?>

<div class="page-head">
  <h1>Periode Kepengurusan</h1>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/terms/period_catalog">Acuan Periode &amp; Rekap</a>
    <?php if (Auth::canEdit()): ?>
      <a class="btn" href="<?= BASEURL ?>/terms/form">Tambah Periode</a>
    <?php endif; ?>
  </div>
</div>

<div class="note">
  Acuan periode dapat digunakan oleh banyak struktur. Setiap struktur memiliki susunan, status, dan SK sendiri;
  penempatan lama tetap menjadi arsip.
</div>

<div class="card">
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
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="muted">Belum ada periode di lingkup Anda.</td></tr>
      <?php endif; ?>
      <?php
      $lastStructure = null;
      foreach ($rows as $r):
        if ($filterStructure && (int) $r['structure_id'] !== $filterStructure) continue;
        $structureLabel = $r['level'] . ' ' . $r['structure_name'];
        if ($structureLabel !== $lastStructure):
          $lastStructure = $structureLabel;
      ?>
        <tr class="row-group"><td colspan="8"><?= e($structureLabel) ?></td></tr>
      <?php endif; ?>
        <tr>
          <td></td>
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
</div>

<?php $this->view('layouts/footer'); ?>
