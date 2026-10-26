<?php
// PATH LOKASI FILE: app/Views/admin/people/form.php
$this->view('layouts/header', ['title' => $title]);
[$pre, $nm, $suf] = $row ? Ui::nameParts($row) : ['', '', ''];
?>

<div class="crumb"><a href="<?= BASEURL ?>/people">Data Pengurus</a> &rsaquo; <?= e($title) ?></div>
<div class="page-head"><h1><?= e($title) ?></h1></div>

<div class="card" style="max-width:820px">
  <form method="post" action="<?= BASEURL ?>/people/save" data-name-form data-lokasi="<?= BASEURL ?>/lokasi">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">

    <h2 style="font-size:15px;margin:0 0 12px">Nama</h2>
    <div class="name-grid">
      <div class="field">
        <label for="title_prefix">Gelar depan</label>
        <input type="text" id="title_prefix" name="title_prefix" data-np="prefix" value="<?= e($pre) ?>" placeholder="Dr. H.">
        <div class="help">Opsional. Contoh: Dr., Prof., H., Hj., Ust.</div>
      </div>
      <div class="field">
        <label for="name">Nama asli <span style="color:var(--danger)">*</span></label>
        <input type="text" id="name" name="name" data-np="name" required value="<?= e($nm) ?>" placeholder="Muhammad Zaitun Rasmin">
        <div class="help">Tanpa gelar. Dipakai untuk pengurutan, pencarian, dan mendeteksi data ganda.</div>
      </div>
      <div class="field">
        <label for="title_suffix">Gelar belakang</label>
        <input type="text" id="title_suffix" name="title_suffix" data-np="suffix" value="<?= e($suf) ?>" placeholder="Lc., M.A.">
        <div class="help">Opsional. Pisahkan dengan koma.</div>
      </div>
    </div>
    <div class="name-preview">
      <span class="k">Nama lengkap</span><span class="v" data-name-preview>—</span>
    </div>
    <div class="note" style="margin-top:-6px">
      Bila gelar terlanjur tertulis di kolom nama (mis. &ldquo;Dr. Ahmad, Lc.&rdquo;), sistem memindahkannya
      ke kolom gelar saat disimpan.
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="gender">Jenis Kelamin</label>
        <select id="gender" name="gender">
          <option value="L" <?= ($row['gender'] ?? 'L') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
          <option value="P" <?= ($row['gender'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
        </select>
      </div>
      <div class="field">
        <label for="phone">Nomor WhatsApp</label>
        <input type="text" id="phone" name="phone" value="<?= e($row['phone'] ?? '') ?>" placeholder="08xxxxxxxxxx">
        <div class="help">Disimpan dalam bentuk 628xxx.</div>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($row['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="occupation">Pekerjaan / profesi</label>
        <input type="text" id="occupation" name="occupation" maxlength="150" value="<?= e($row['occupation'] ?? '') ?>" placeholder="mis. Guru, Dokter, Wiraswasta">
      </div>
      <div class="field">
        <label for="family_phone">Nomor HP keluarga</label>
        <input type="text" id="family_phone" name="family_phone" value="<?= e($row['family_phone'] ?? '') ?>" placeholder="08xxxxxxxxxx">
        <div class="help">Kontak darurat. Tidak ikut di API dan tersamar di Portal Pengurus.</div>
      </div>
    </div>

    <div class="field">
      <label for="nik">NIK (16 digit, opsional)</label>
      <input type="text" id="nik" name="nik" inputmode="numeric" maxlength="16"
             value="<?= e($row['nik'] ?? '') ?>" placeholder="Kosongkan bila belum ada">
      <div class="help">
        Data pribadi sensitif. Hanya diisi bila benar-benar diperlukan. Nomor ini
        <strong>tidak</strong> dipakai sebagai kunci oleh aplikasi lain &mdash; mereka memakai UUID,
        yang tidak akan pecah bila NIK dikoreksi nanti. Pembukaan NIK lewat API butuh izin
        tersendiri dan selalu tercatat.
      </div>
    </div>

    <div class="field">
      <label class="check">
        <input type="checkbox" name="portal_enabled" value="1"
               <?= !$row || !isset($row['portal_enabled']) || (int) $row['portal_enabled'] ? 'checked' : '' ?>>
        <span>Izinkan masuk ke Portal Pengurus dengan nomor WA</span>
      </label>
      <div class="help">
        Berlaku bila nomor WA terisi, tidak dipakai orang lain, dan orang ini punya penempatan. Nomor WA (atau NIK,
        bila terisi) adalah satu-satunya kredensial portal; <strong>cabut centang ini</strong> bila nomornya
        diketahui pihak lain atau akunnya disalahgunakan.
        Pencabutan berlaku seketika, termasuk untuk sesi yang sedang berjalan.
      </div>
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="birth_date">Tanggal Lahir</label>
        <input type="date" id="birth_date" name="birth_date" value="<?= e($row['birth_date'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="birth_place">Tempat Lahir</label>
        <input type="text" id="birth_place" name="birth_place" value="<?= e($row['birth_place'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="active" <?= ($row['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
          <option value="inactive" <?= ($row['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
          <option value="deceased" <?= ($row['status'] ?? '') === 'deceased' ? 'selected' : '' ?>>Wafat</option>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="address_text">Jalan / BTN / Lorong (dan nomor)</label>
      <input type="text" id="address_text" name="address_text" maxlength="255" value="<?= e($row['address_text'] ?? '') ?>">
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="province">Provinsi</label>
        <input type="text" id="province" name="province" maxlength="100" data-lokasi-field="province" value="<?= e($row['province'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="city">Kota / Kabupaten</label>
        <input type="text" id="city" name="city" maxlength="100" data-lokasi-field="city" value="<?= e($row['city'] ?? '') ?>">
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="district">Kecamatan</label>
        <input type="text" id="district" name="district" maxlength="100" data-lokasi-field="district" value="<?= e($row['district'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="village">Kelurahan / Desa</label>
        <input type="text" id="village" name="village" maxlength="100" data-lokasi-field="village" value="<?= e($row['village'] ?? '') ?>">
      </div>
    </div>
    <div class="help" style="margin:-4px 0 14px">Pilih berurutan dari provinsi; daftarnya mengikuti data wilayah resmi. Bila daftar tak bisa dimuat, kolom tetap bisa diisi manual.</div>

    <div class="field">
      <label for="notes">Catatan</label>
      <textarea id="notes" name="notes"><?= e($row['notes'] ?? '') ?></textarea>
    </div>

    <?php if (!$row): ?>
      <div class="note">Foto profil diunggah dari halaman profil setelah data ini disimpan.</div>
    <?php endif; ?>

    <div class="btn-row">
      <button type="submit" class="btn">Simpan</button>
      <a class="btn secondary" href="<?= BASEURL ?>/people<?= $row ? '/detail/' . (int) $row['id'] : '' ?>">Batal</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
