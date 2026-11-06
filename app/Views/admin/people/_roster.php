<?php
// PATH LOKASI FILE: app/Views/admin/people/_roster.php
// Isi satu struktur di Data Pengurus > Per Struktur. Potongan HTML yang dimuat saat baris struktur dibuka (PeopleController::struktur),
// bukan halaman utuh, jadi tanpa header dan footer.
//   - struktur tanpa Bidang dan unit ($flat): daftar nama dan jabatan pengurusnya langsung (Pengurus Inti, lalu Pimpinan Lainnya)
//   - struktur yang punya Bidang atau unit: pohon Susunan (Pengurus Inti, Bidang, unit) dengan kolom yang sama seperti barisnya
// Dari controller: $term, $tree (Susunan::arrange, sudah disaring bila ada penyaring), $flags (K/S/B terisi), $facet (isinya hasil
// penyaringan), $flat.

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
<?php elseif ($flat && !Susunan::rowCount($tree)): ?>
  <p class="mon-empty">Belum ada pengurus yang tercatat di struktur ini.</p>
<?php else: ?>
  <?php $this->view('admin/_susunan', ['tree' => $tree, 'treeId' => 'roster-' . (int) $term['id'], 'metrics' => true, 'intiFlags' => $flags, 'toolbar' => false, 'flat' => $flat]); ?>
<?php endif; ?>
