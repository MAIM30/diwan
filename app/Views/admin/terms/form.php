<?php
// PATH LOKASI FILE: app/Views/admin/terms/form.php
$this->view('layouts/header', ['title' => $title]);
$selectedStructure = (int) ($row['structure_id'] ?? $structureId ?? 0);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; <?= e($title) ?></div>
<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="card" style="max-width:720px">
  <form method="post" action="<?= BASEURL ?>/terms/save" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">

    <div class="field">
      <label for="structure_id">Struktur</label>
      <select id="structure_id" name="structure_id" required <?= !empty($row['id']) ? 'disabled' : '' ?>>
        <option value="">— pilih —</option>
        <?php foreach ($structures as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $selectedStructure === (int) $s['id'] ? 'selected' : '' ?>>
            <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($row['id'])): ?>
        <input type="hidden" name="structure_id" value="<?= $selectedStructure ?>">
        <div class="help">Struktur periode tidak bisa dipindah setelah dibuat &mdash; penempatan di
          dalamnya sudah terikat ke struktur ini.</div>
      <?php endif; ?>
    </div>

    <div class="field">
      <label for="governance_period_id">Acuan Periode Kepengurusan</label>
      <?php if (!empty($row['id'])): ?>
        <input type="hidden" name="governance_period_id" value="<?= (int) $row['governance_period_id'] ?>">
        <input type="text" id="governance_period_id" value="<?= e($row['name']) ?> · <?= e($row['start_date']) ?> s.d. <?= e($row['end_date_planned'] ?: 'belum ditentukan') ?>" disabled>
        <div class="help">Acuan periode dikunci setelah susunan struktur dibuat. Nama dan tanggal bersama diatur dari daftar acuan periode.</div>
      <?php else: ?>
        <select id="governance_period_id" name="governance_period_id" required>
          <option value="">— pilih acuan periode —</option>
          <?php foreach ($governancePeriods as $period): ?>
            <option value="<?= (int) $period['id'] ?>" <?= (int) $governancePeriodId === (int) $period['id'] ? 'selected' : '' ?>><?= e($period['name']) ?> · <?= e($period['start_date']) ?> s.d. <?= e($period['end_date_planned'] ?: 'belum ditentukan') ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Belum ada acuan yang sesuai? <a href="<?= BASEURL ?>/terms/period_form" target="_blank">Buat acuan periode</a> terlebih dahulu.</div>
      <?php endif; ?>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="end_date_actual">Akhir &mdash; Aktual</label>
        <input type="date" id="end_date_actual" name="end_date_actual" value="<?= e($row['end_date_actual'] ?? '') ?>">
        <div class="help">Diisi saat serah terima benar-benar terjadi. Boleh lewat dari tanggal
          rencana &mdash; memang sering molor menunggu musyawarah berikutnya.</div>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="sk_number">Nomor SK</label>
        <input type="text" id="sk_number" name="sk_number" value="<?= e($row['sk_number'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="sk_date">Tanggal SK</label>
        <input type="date" id="sk_date" name="sk_date" value="<?= e($row['sk_date'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label for="sk_file">Berkas SK (PDF)</label>
      <input type="file" id="sk_file" name="sk_file" accept="application/pdf,.pdf">
      <?php if (!empty($row['sk_file_path'])): ?>
        <div class="help">Terlampir: <a href="<?= BASEURL ?>/terms/sk/<?= (int) $row['id'] ?>" target="_blank" rel="noopener">buka SK</a>.
          Memilih berkas baru akan <strong>menggantinya</strong>.</div>
        <label class="check" style="margin-top:6px"><input type="checkbox" name="remove_sk_file" value="1"><span>Cabut berkas SK terlampir</span></label>
      <?php else: ?>
        <div class="help">Maksimal 20 MB. Dasar hukum susunan ini: tanpa SK, data periode tak punya bukti sumber.</div>
      <?php endif; ?>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (['draft' => 'Draf (sedang disusun)', 'active' => 'Aktif', 'ended' => 'Selesai'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($row['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Periode Berjalan</label>
        <label class="check">
          <input type="checkbox" name="is_current" value="1" <?= !empty($row['is_current']) ? 'checked' : '' ?>>
          <span>Tandai sebagai periode yang sedang berjalan</span>
        </label>
        <div class="help">Hanya satu periode per struktur yang bisa ditandai berjalan. Menandai yang
          ini otomatis melepas tanda dari periode sebelumnya &mdash; datanya tetap utuh.</div>
      </div>
    </div>

    <div class="field">
      <label for="notes">Catatan</label>
      <textarea id="notes" name="notes"><?= e($row['notes'] ?? '') ?></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/terms">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
