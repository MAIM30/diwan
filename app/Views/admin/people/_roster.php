<?php
// PATH LOKASI FILE: app/Views/admin/people/_roster.php
// Isi satu struktur di Data Pengurus > Per Struktur: Pengurus Inti, lalu Bidang dan unit-unitnya. Potongan HTML yang dimuat saat baris
// struktur dibuka (PeopleController::struktur), bukan halaman utuh, jadi tanpa header dan footer.
// Dari controller: $term, $tree (Susunan::arrange, sudah disaring bila ada penyaring), $flags (K/S/B terisi), $facet (isinya hasil penyaringan).

$missing = [];
foreach (['k' => 'Ketua', 's' => 'Sekretaris', 'b' => 'Bendahara'] as $key => $label) {
    if (empty($flags[$key])) $missing[] = $label;
}
?>
<?php if ($missing): ?>
  <div class="mon-gap" role="note">
    <?= Ui::icon('alert', 16) ?>
    <span><strong><?= e(implode(', ', $missing)) ?></strong> belum ada pengurusnya di Pengurus Inti.</span>
    <?php if (Auth::canEdit()): ?>
      <a href="<?= BASEURL ?>/placements/form/<?= (int) $term['id'] ?>">Tambah penempatan</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$tree): ?>
  <p class="mon-empty">Tidak ada pengurus di struktur ini yang cocok dengan penyaringan.</p>
<?php else: ?>
  <?php $this->view('admin/_susunan', ['tree' => $tree, 'treeId' => 'roster-' . (int) $term['id'], 'metrics' => true, 'intiFlags' => $flags, 'toolbar' => false]); ?>
<?php endif; ?>
