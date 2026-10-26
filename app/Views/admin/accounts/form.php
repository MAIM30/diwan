<?php
// PATH LOKASI FILE: app/Views/admin/accounts/form.php
$this->view('layouts/header', ['title' => $title]);

$roleLabels = [
  'admin_struktur' => 'Admin Struktur — kelola data di lingkupnya',
  'verifikator'    => 'Verifikator — hanya memverifikasi pendaftar',
  'viewer'         => 'Peninjau — hanya melihat',
];
if ($canGrantSuper) {
  $roleLabels = ['super_admin' => 'Super Admin — seluruh sistem, tanpa batas lingkup'] + $roleLabels;
}
// Minimal tiga baris peran, atau sebanyak yang sudah dimiliki akun ini.
$slots = max(3, count($roles));
?>

<div class="crumb"><a href="<?= BASEURL ?>/accounts">Akun</a> &rsaquo; <?= e($title) ?></div>
<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="card" style="max-width:760px">
  <form method="post" action="<?= BASEURL ?>/accounts/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">

    <div class="grid-2">
      <div class="field">
        <label for="display_name">Nama Tampilan</label>
        <input type="text" id="display_name" name="display_name" required value="<?= e($row['display_name'] ?? ($suggestName ?? '')) ?>">
      </div>
      <div class="field">
        <label for="phone">Nomor WhatsApp (dipakai untuk masuk)</label>
        <input type="text" id="phone" name="phone" required value="<?= e($row['phone'] ?? '') ?>"
               placeholder="08xxxxxxxxxx">
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="email">Email (nama pengguna untuk masuk)</label>
        <input type="email" id="email" name="email" value="<?= e($row['email'] ?? '') ?>"
               placeholder="admin.dpd.kendari@pengurus.wahdah.or.id" autocapitalize="none">
        <div class="help">Opsional. Bila diisi, akun bisa masuk dengan email ini <em>atau</em> nomor WhatsApp.</div>
      </div>
      <div class="field">
        <label for="password">Kata Sandi</label>
        <input type="password" id="password" name="password" autocomplete="new-password"
               <?= empty($row['id']) ? 'required' : '' ?>>
        <div class="help">
          Minimal 10 karakter.
          <?= !empty($row['id']) ? 'Biarkan kosong bila tidak ingin mengubah kata sandi.' : '' ?>
        </div>
        <label class="check" style="margin-top:6px">
          <input type="checkbox" name="must_change_password" value="1"
                 <?= empty($row['id']) || !empty($row['must_change_password']) ? 'checked' : '' ?>>
          <span>Kata sandi sementara: wajib diganti saat masuk pertama</span>
        </label>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="person_id">Terkait data pengurus (opsional)</label>
        <select id="person_id" name="person_id">
          <option value="">— bukan pengurus —</option>
          <?php foreach ($people as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) ($row['person_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e(Ui::fullName($p)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help">Kosongkan untuk akun yang bukan pengurus (mis. pengelola teknis).</div>
      </div>
      <div class="field">
        <label for="status">Status Akun</label>
        <select id="status" name="status">
          <option value="active" <?= ($row['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
          <option value="suspended" <?= ($row['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Ditangguhkan</option>
        </select>
      </div>
    </div>

    <h2 style="font-size:15px;margin:22px 0 10px">Peran &amp; Lingkup</h2>
    <p class="small muted">
      Satu akun boleh punya beberapa baris peran, masing-masing dengan lingkupnya sendiri &mdash;
      misalnya mengelola DPD Kota Kendari sekaligus menjadi verifikator di DPW.
      Lingkup sebuah peran otomatis mencakup seluruh struktur di bawahnya.
    </p>

    <?php for ($i = 0; $i < $slots; $i++): $existing = $roles[$i] ?? null; ?>
      <div class="grid-2" style="border-bottom:1px solid var(--border);padding-bottom:6px;margin-bottom:10px">
        <div class="field">
          <label for="role<?= $i ?>">Peran <?= $i + 1 ?></label>
          <select id="role<?= $i ?>" name="role[<?= $i ?>]">
            <option value="">— kosong —</option>
            <?php foreach ($roleLabels as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($existing['role'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="scope<?= $i ?>">Lingkup</label>
          <select id="scope<?= $i ?>" name="scope_structure_id[<?= $i ?>]">
            <?php if ($canGrantSuper): ?>
              <option value="">— seluruh sistem (hanya untuk Super Admin) —</option>
            <?php endif; ?>
            <?php foreach ($structures as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) ($existing['scope_structure_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
                <?= empty($s['parent_id']) ? ' (= ' . SCOPE_LABEL_ROOT . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    <?php endfor; ?>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/accounts">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
