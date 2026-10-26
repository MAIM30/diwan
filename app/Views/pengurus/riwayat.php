<?php
// PATH LOKASI FILE: app/Views/pengurus/riwayat.php
// Riwayat jabatan pengurus itu sendiri: linimasa (Gantt) + daftar, seluruh periode dan struktur.
// Variabel: $history (sudah tersaring), $counts, $filter, $gantt (HTML grafik).
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'riwayat']);
$today = date('Y-m-d');
$pill = function ($key, $label) use ($filter, $counts) {
    $on = $filter === $key;
    return '<a class="chip' . ($on ? ' on' : '') . '" href="' . BASEURL . '/pengurus/riwayat' . ($key === 'semua' ? '' : '?f=' . $key) . '"'
        . ($on ? ' aria-current="true"' : '') . '>' . e($label) . ' <span class="chip-n">' . (int) $counts[$key] . '</span></a>';
};
?>

<div class="page-head">
  <h1>Riwayat Jabatan</h1>
</div>

<div class="chips" style="margin-bottom:16px" role="group" aria-label="Penyaring riwayat">
  <?= $pill('semua', 'Semua') ?>
  <?= $pill('berjalan', 'Berjalan') ?>
  <?= $pill('arsip', 'Arsip') ?>
</div>

<section class="card">
  <div class="card-head"><h2><?= Ui::icon('calendar', 19) ?> Linimasa</h2></div>
  <?= $gantt ?>
</section>

<section class="card">
  <div class="card-head"><h2><?= Ui::icon('layers', 19) ?> Daftar</h2>
    <span class="badge"><?= count($history) ?> penempatan</span></div>
  <?php if (empty($history)): ?>
    <div class="empty"><?= Ui::icon('briefcase', 26) ?><span>Tidak ada penempatan pada penyaringan ini.</span></div>
  <?php else: ?>
    <ol class="tl">
      <?php foreach ($history as $h):
        $now = !empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= $today); ?>
        <li class="<?= $now ? 'now' : '' ?>">
          <div class="l-head">
            <strong><?= e($h['position_name']) ?></strong>
            <?php if ($h['is_core'] && !$h['unit_name']): ?><span class="badge core">inti</span><?php endif; ?>
            <?php if ($now): ?><span class="badge current">berjalan</span>
            <?php elseif ($h['term_status'] === 'ended'): ?><span class="badge ended">arsip</span><?php endif; ?>
            <?php if ($h['wing'] === 'muslimah'): ?><span class="badge muslimah">Muslimah</span><?php endif; ?>
          </div>
          <div class="l-sub">
            <?= e($h['level'] . ' ' . $h['structure_name']) ?>
            <?= $h['unit_name'] ? ' · ' . e($h['unit_name']) : ' · <span class="muted">pucuk pimpinan</span>' ?>
          </div>
          <div class="l-meta">
            Periode <?= e($h['term_name']) ?> ·
            <?php if ($h['end_date']): ?>
              berakhir <?= e(Ui::date($h['end_date'])) ?> (<?= e(str_replace('_', ' ', (string) $h['end_reason'])) ?>)
            <?php else: ?>
              <?= e(Ui::date($h['start_date'] ?: $h['term_start'])) ?> &rarr;
              <?= e($h['end_date_actual'] ? Ui::date($h['end_date_actual']) : ($h['end_date_planned'] ? Ui::date($h['end_date_planned']) : 'sekarang')) ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<?php $this->view('layouts/member_footer'); ?>
