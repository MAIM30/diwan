<?php
// PATH LOKASI FILE: app/Views/admin/forums/form.php
$this->view('layouts/header', ['title' => $title]);
$v = function ($key, $default = '') use ($row) {
    return e($row[$key] ?? $default);
};
?>

<div class="page-head">
  <a class="btn-back" href="<?= BASEURL ?>/forums"><?= Ui::icon('arrow-left', 17) ?> Forum</a>
  <h1><?= e($title) ?></h1>
</div>

<form class="card form-card" method="post" action="<?= BASEURL ?>/forums/save">
  <?= Csrf::field() ?>
  <?php if ($row): ?>
    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
  <?php endif; ?>

  <div class="field">
    <label for="name">Nama Forum <span class="req">*</span></label>
    <input type="text" id="name" name="name" value="<?= $v('name') ?>" required maxlength="200"
           placeholder="mis. Mukernas XIX Wahdah Islamiyah">
  </div>

  <div class="row-2">
    <div class="field">
      <label for="type">Jenis Forum <span class="req">*</span></label>
      <select id="type" name="type" required>
        <?php foreach ($types as $code => $label): ?>
          <option value="<?= e($code) ?>" <?= ($row['type'] ?? 'mukernas') === $code ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="organizer_structure_id">Penyelenggara <span class="req">*</span></label>
      <select id="organizer_structure_id" name="organizer_structure_id" required <?= $row ? 'disabled' : '' ?>>
        <option value="">— Pilih struktur —</option>
        <?php foreach ($structures as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) ($row['organizer_structure_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
            <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($row): ?>
        <input type="hidden" name="organizer_structure_id" value="<?= (int) $row['organizer_structure_id'] ?>">
        <small class="help">Penyelenggara tidak bisa diubah setelah forum dibuat.</small>
      <?php endif; ?>
    </div>
  </div>

  <div class="row-2">
    <div class="field">
      <label for="start_date">Tanggal Mulai</label>
      <input type="date" id="start_date" name="start_date" value="<?= $v('start_date') ?>">
    </div>
    <div class="field">
      <label for="end_date">Tanggal Selesai</label>
      <input type="date" id="end_date" name="end_date" value="<?= $v('end_date') ?>">
    </div>
  </div>

  <div class="field">
    <label for="decision_ref">Referensi Keputusan</label>
    <input type="text" id="decision_ref" name="decision_ref" value="<?= $v('decision_ref') ?>"
           placeholder="Nomor SK / Surat Keputusan (opsional)" maxlength="200">
  </div>

  <div class="form-actions">
    <a class="btn secondary" href="<?= BASEURL ?>/forums">Batal</a>
    <button type="submit" class="btn"><?= $row ? 'Simpan Perubahan' : 'Buat Forum' ?></button>
  </div>
</form>

<?php $this->view('layouts/footer'); ?>
