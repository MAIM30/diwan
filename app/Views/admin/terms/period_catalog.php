<?php
// PATH LOKASI FILE: app/Views/admin/terms/period_catalog.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; Acuan Periode</div>
<div class="page-head">
  <h1>Acuan Periode Kepengurusan</h1>
  <div class="actions">
    <?php if (Auth::canEdit()): ?><a class="btn" href="<?= BASEURL ?>/terms/period_form">Buat Periode Kepengurusan</a><?php endif; ?>
  </div>
</div>

<div class="note">Buat satu acuan untuk nama dan tanggal periode. Acuan yang sama dapat dipakai oleh banyak struktur agar rekap susunan lintas struktur tersaji bersama.</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Periode</th><th>Mulai</th><th>Akhir rencana</th><th class="num">Struktur dalam lingkup</th><th class="num">Penempatan</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="muted">Belum ada acuan periode.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td class="small"><?= e($r['start_date']) ?></td>
          <td class="small"><?= e($r['end_date_planned'] ?: '—') ?></td>
          <td class="num"><?= (int) $r['structure_count'] ?></td>
          <td class="num"><?= (int) $r['placement_count'] ?></td>
          <td><a class="btn small secondary" href="<?= BASEURL ?>/terms/period_detail/<?= (int) $r['id'] ?>">Rekap struktur</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
