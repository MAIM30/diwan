<?php
// PATH LOKASI FILE: app/Views/admin/placements/form.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb">
  <a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo;
  <a href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>"><?= e($term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name']) ?></a>
  &rsaquo; <?= e($title) ?>
</div>

<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="note">
  Penempatan ini akan tercatat di periode <strong><?= e($term['name']) ?></strong>.
  Menambah penempatan di sini tidak mengubah apa pun di periode lain.
</div>

<div class="card" style="max-width:720px">
  <form method="post" action="<?= BASEURL ?>/placements/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
    <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">

    <div class="field">
      <label for="person_id">Orang</label>
      <select id="person_id" name="person_id" required <?= !empty($row['id']) ? 'disabled' : '' ?>>
        <option value="">— pilih —</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) ($row['person_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
            <?= e(Ui::fullName($p)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($row['id'])): ?>
        <input type="hidden" name="person_id" value="<?= (int) $row['person_id'] ?>">
        <div class="help">Orang tidak bisa ditukar pada penempatan yang sudah ada. Hapus penempatan
          ini lalu buat yang baru bila memang salah orang.</div>
      <?php else: ?>
        <div class="help">Belum ada di daftar?
          <a href="<?= BASEURL ?>/people/form" target="_blank">Tambahkan orang</a> dulu.</div>
      <?php endif; ?>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="structure_unit_id">Unit</label>
        <select id="structure_unit_id" name="structure_unit_id">
          <option value="">— pucuk pimpinan struktur (tanpa unit) —</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($row['structure_unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
              <?= e(($u['group_name'] ? $u['group_name'] . ' — ' : '') . ($u['display_name'] ?: $u['catalog_name'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help">Dibiarkan kosong berarti jabatan di tingkat struktur (Ketua Umum, Ketua
          DPW, Sekretaris Jenderal, dst.). Hanya penempatan tanpa unit yang bisa dihitung sebagai
          Pengurus Inti.</div>
      </div>

      <div class="field">
        <label for="position_id">Jabatan</label>
        <select id="position_id" name="position_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) ($row['position_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e($p['name']) ?><?= PositionModel::isCoreAt($p, $term['tier']) ? ' (inti)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label class="check">
        <input type="checkbox" name="is_primary" value="1" <?= !empty($row['is_primary']) ? 'checked' : '' ?>>
        <span>Penempatan utama orang ini di periode ini</span>
      </label>
      <div class="help">Satu orang boleh punya beberapa penempatan di periode yang sama (rangkap
        jabatan). Tandai yang utama supaya laporan tahu mana yang dipakai sebagai jabatan pokok.</div>
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="start_date">Mulai Menjabat</label>
        <input type="date" id="start_date" name="start_date" value="<?= e($row['start_date'] ?? '') ?>">
        <div class="help">Kosongkan bila mengikuti tanggal periode.</div>
      </div>
      <div class="field">
        <label for="end_date">Berakhir</label>
        <input type="date" id="end_date" name="end_date" value="<?= e($row['end_date'] ?? '') ?>">
        <div class="help">Isi hanya untuk pergantian di tengah periode.</div>
      </div>
      <div class="field">
        <label for="end_reason">Alasan Berakhir</label>
        <select id="end_reason" name="end_reason">
          <option value="">—</option>
          <?php foreach (['selesai_periode'=>'Selesai periode','mengundurkan_diri'=>'Mengundurkan diri','mutasi'=>'Mutasi','wafat'=>'Wafat','diberhentikan'=>'Diberhentikan','lainnya'=>'Lainnya'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($row['end_reason'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="sk_reference">Rujukan SK</label>
      <input type="text" id="sk_reference" name="sk_reference" value="<?= e($row['sk_reference'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="notes">Catatan</label>
      <textarea id="notes" name="notes"><?= e($row['notes'] ?? '') ?></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
