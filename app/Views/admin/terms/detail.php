<?php
// PATH LOKASI FILE: app/Views/admin/terms/detail.php
// Susunan pengurus satu periode, dikelompokkan per kategori (lihat Susunan::arrange).
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; <?= e($term['level'] . ' ' . $term['structure_name']) ?></div>

<div class="page-head">
  <h1><?= e($term['level'] . ' ' . $term['structure_name']) ?> &middot; <?= e($term['name']) ?></h1>
  <?php if ($term['is_current']): ?><span class="badge current">periode berjalan</span><?php endif; ?>
  <div class="actions">
    <?php if (Auth::canEdit()): ?>
      <a class="btn" href="<?= BASEURL ?>/placements/form/<?= (int) $term['id'] ?>">Tambah Penempatan</a>
      <a class="btn secondary" href="<?= BASEURL ?>/units/term/<?= (int) $term['id'] ?>">Bidang &amp; Unit</a>
      <a class="btn secondary" href="<?= BASEURL ?>/terms/form/<?= (int) $term['id'] ?>">Ubah Periode</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="grid-3">
    <div><strong>Mulai</strong><div class="small"><?= e(Ui::date($term['start_date'])) ?></div></div>
    <div><strong>Akhir</strong><div class="small">
      <?= e(($term['end_date_actual'] ?: $term['end_date_planned']) ? Ui::date($term['end_date_actual'] ?: $term['end_date_planned']) : '—') ?>
      <?= $term['end_date_actual'] ? '<span class="muted">(aktual)</span>' : ($term['end_date_planned'] ? '<span class="muted">(rencana)</span>' : '') ?>
    </div></div>
    <div><strong>SK</strong>
      <div class="small mono"><?= e($term['sk_number'] ?: '—') ?></div>
      <?php if (!empty($term['sk_date'])): ?><div class="small muted"><?= e(Ui::date($term['sk_date'])) ?></div><?php endif; ?>
      <div class="small">
        <?php if (!empty($term['sk_file_path'])): ?>
          <a href="<?= BASEURL ?>/terms/sk/<?= (int) $term['id'] ?>" target="_blank" rel="noopener"><?= Ui::icon('file-text', 14) ?> Buka berkas SK (PDF)</a>
        <?php else: ?>
          <span class="badge core">berkas SK belum dilampirkan</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (!empty($skCoverage['total'])): $pct = (int) round($skCoverage['linked'] * 100 / $skCoverage['total']); ?>
    <div class="small" style="margin-top:10px">
      Penempatan tertaut SK: <strong><?= (int) $skCoverage['linked'] ?></strong> dari <?= (int) $skCoverage['total'] ?>
      <span class="badge <?= $pct === 100 ? 'ok' : 'core' ?>"><?= $pct ?>%</span>
    </div>
  <?php endif; ?>
  <?php if (!empty($term['notes'])): ?>
    <div class="note"><?= e($term['notes']) ?></div>
  <?php endif; ?>
</div>

<?php if (count($otherTerms) > 1): ?>
  <div class="card">
    <h2>Periode lain di struktur ini <span class="hint">&mdash; arsip, tidak tertimpa</span></h2>
    <?php foreach ($otherTerms as $ot): ?>
      <?php if ((int) $ot['id'] === (int) $term['id']) continue; ?>
      <a class="btn small secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $ot['id'] ?>">
        <?= e($ot['name']) ?><?= $ot['is_current'] ? ' (berjalan)' : '' ?>
      </a>
    <?php endforeach; ?>

    <?php if (Auth::canEdit() && empty($groups) && empty($units)): ?>
      <form method="post" action="<?= BASEURL ?>/terms/clone_units" style="margin-top:14px">
        <?= Csrf::field() ?>
        <input type="hidden" name="target_term_id" value="<?= (int) $term['id'] ?>">
        <div class="field" style="max-width:420px">
          <label for="source_term_id">Salin susunan Bidang &amp; unit dari periode</label>
          <select id="source_term_id" name="source_term_id" required>
            <option value="">— pilih periode asal —</option>
            <?php foreach ($otherTerms as $ot): ?>
              <?php if ((int) $ot['id'] === (int) $term['id']) continue; ?>
              <option value="<?= (int) $ot['id'] ?>"><?= e($ot['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">Hanya Bidang dan unit yang disalin. Penempatan pengurus tidak ikut
            &mdash; orangnya memang berganti di periode baru.</div>
        </div>
        <button type="submit" class="btn secondary">Salin Susunan Unit</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php $canEdit = Auth::canEdit(); ?>

<div class="card">
  <h2>Susunan Lengkap <span class="hint">&mdash; <?= count($rows) ?> penempatan</span></h2>

  <?php if (empty($rows)): ?>
    <p class="muted">Periode ini belum berisi penempatan.
      <?php if ($canEdit): ?>
        Mulai dengan <a href="<?= BASEURL ?>/units/term/<?= (int) $term['id'] ?>">menyusun Bidang &amp; unit</a>,
        lalu <a href="<?= BASEURL ?>/placements/form/<?= (int) $term['id'] ?>">tambahkan penempatan</a>.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <?php $this->view('admin/_susunan', ['tree' => $tree, 'treeId' => 'susunan']); ?>
  <?php endif; ?>
</div>

<?php $this->view('layouts/footer'); ?>
