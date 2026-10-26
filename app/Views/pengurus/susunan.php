<?php
// PATH LOKASI FILE: app/Views/pengurus/susunan.php
// Susunan tempat saya menjabat: pimpinan (kluster avatar) + rekan lainnya. HANYA nama + jabatan --
// tanpa foto (avatar rekan selalu berinisial), telepon, NIK, atau biodata lain.
//
// Sengaja TIDAK ada peringkat/penilaian ("best employee"): aplikasi ini tidak punya data kinerja, dan
// meranking pengurus bukan fungsi sebuah registri kepengurusan.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'susunan']);
?>

<div class="page-head">
  <h1>Susunan</h1>
  <span class="badge"><?= count($current) ?> tempat menjabat</span>
</div>

<?php if (empty($current)): ?>
  <section class="card">
    <div class="empty"><?= Ui::icon('users', 26) ?><span>Anda tidak sedang menjabat di periode berjalan, jadi belum ada susunan untuk ditampilkan.</span></div>
  </section>
<?php endif; ?>

<?php foreach ($current as $i => $c):
  $roster = $rosters[$i] ?? [];
  // Pimpinan = sampai 3 orang pertama berjabatan inti (Ketua/Sekretaris/Bendahara), urut protokoler.
  $leaders = array_slice(array_values(array_filter($roster, function ($r) { return !empty($r['is_core']); })), 0, 3);
  $leaderIds = array_column($leaders, 'id');
  $others = array_values(array_filter($roster, function ($r) use ($leaderIds) { return !in_array($r['id'], $leaderIds, true); }));
?>
  <section class="card">
    <div class="card-head">
      <h2><?= Ui::icon('users', 19) ?> <?= e($c['unit_name'] ?: 'Pucuk Pimpinan') ?></h2>
    </div>
    <p class="card-sub"><?= e($c['level'] . ' ' . $c['structure_name']) ?> · Periode <?= e($c['term_name']) ?>
      · Jabatan Anda: <strong><?= e($c['position_name']) ?></strong></p>

    <?php if (empty($roster)): ?>
      <div class="empty" style="padding:12px"><span>Belum ada rekan lain yang tercatat di sini.</span></div>
    <?php else: ?>

      <?php if (!empty($leaders)): ?>
        <div class="leaders" aria-label="Pimpinan">
          <?php foreach ($leaders as $L): ?>
            <div class="leader">
              <?= Ui::avatar($L, 'lg') /* tanpa photo_path: rekan hanya berinisial */ ?>
              <strong><?= Ui::nameOnly($L) ?></strong>
              <small><?= e($L['position_name']) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($others)): ?>
        <div class="small muted" style="margin:16px 0 6px;font-weight:600"><?= !empty($leaders) ? 'Anggota lainnya' : 'Rekan' ?></div>
        <ul class="roster">
          <?php foreach ($others as $r): ?>
            <li>
              <?= Ui::avatar($r, 'sm') ?>
              <span class="r-name"><?= Ui::nameHtml($r) ?></span>
              <span class="badge"><?= e($r['position_name']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (count($roster) >= 14): ?><p class="small muted" style="margin:10px 0 0">Menampilkan 14 pertama.</p><?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php $this->view('layouts/member_footer'); ?>
