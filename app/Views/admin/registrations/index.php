<?php
// PATH LOKASI FILE: app/Views/admin/registrations/index.php
$this->view('layouts/header', ['title' => $title]);
$tabs = ['submitted'=>'Diajukan','verified'=>'Diverifikasi','activated'=>'Aktif','rejected'=>'Ditolak','all'=>'Semua'];
?>

<div class="page-head"><h1>Pendaftaran Pengurus</h1></div>

<div class="note">
  Pendaftar tidak otomatis masuk ke data pengurus. Pengajuan tersimpan terpisah sampai ada
  verifikator yang memutuskan &mdash; termasuk memutuskan apakah pendaftar ini orang baru atau
  orang yang sudah ada di database.
</div>

<div class="card">
  <div class="btn-row" style="margin:0">
    <?php foreach ($tabs as $k => $v): ?>
      <a class="btn small <?= $status === $k ? '' : 'secondary' ?>"
         href="<?= BASEURL ?>/registrations?status=<?= $k ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>L/P</th><th>Struktur Tujuan</th><th>Kemungkinan Ganda</th><th>Status</th><th>Masuk</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">Tidak ada pengajuan pada filter ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= Ui::personCell($r, Auth::canVerify() ? BASEURL . '/registrations/review/' . (int) $r['id'] : null,
                '<span class="mono">' . e($r['phone']) . '</span>' . (Ui::titlesLine($r) !== '' ? ' · ' . e(Ui::titlesLine($r)) : '')) ?></td>
          <td><?= $r['gender'] === 'P' ? 'P' : 'L' ?></td>
          <td class="small"><?= e($r['level'] . ' ' . $r['structure_name']) ?></td>
          <td class="small">
            <?php if ($r['match_confidence'] === 'none'): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="badge <?= $r['match_confidence'] === 'ambiguous' ? 'danger' : 'core' ?>">
                <?= e($r['match_confidence']) ?>
              </span>
              <?php if ($r['matched_name']): ?>
                <div class="muted"><?= e($r['matched_name']) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php
            $badge = ['submitted'=>'', 'verified'=>'core', 'activated'=>'ok', 'rejected'=>'danger'][$r['status']] ?? '';
            $label = ['submitted'=>'Diajukan','verified'=>'Diverifikasi','activated'=>'Aktif','rejected'=>'Ditolak'][$r['status']] ?? $r['status'];
            ?>
            <span class="badge <?= $badge ?>"><?= e($label) ?></span>
          </td>
          <td class="small nowrap"><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td>
          <td class="nowrap">
            <?php if (Auth::canVerify()): ?>
              <a class="btn small secondary" href="<?= BASEURL ?>/registrations/review/<?= (int) $r['id'] ?>">Tinjau</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
