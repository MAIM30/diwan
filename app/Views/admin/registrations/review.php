<?php
// PATH LOKASI FILE: app/Views/admin/registrations/review.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/registrations">Pendaftaran</a> &rsaquo; <?= Ui::nameOnly($reg) ?></div>

<div class="page-head">
  <h1><?= Ui::nameHtml($reg) ?></h1>
  <?php
  $badge = ['submitted'=>'', 'verified'=>'core', 'activated'=>'ok', 'rejected'=>'danger'][$reg['status']] ?? '';
  $label = ['submitted'=>'Diajukan','verified'=>'Diverifikasi','activated'=>'Aktif','rejected'=>'Ditolak'][$reg['status']] ?? $reg['status'];
  ?>
  <span class="badge <?= $badge ?>"><?= e($label) ?></span>
</div>

<div class="card">
  <h2>Data yang Diisi Pendaftar <span class="hint">&mdash; apa adanya, belum masuk data master</span></h2>
  <table>
    <tr><th>Nama asli</th><td><strong><?= Ui::nameOnly($reg) ?></strong></td></tr>
    <tr><th>Gelar depan</th><td><?= e($reg['title_prefix'] ?: '—') ?></td></tr>
    <tr><th>Gelar belakang</th><td><?= e($reg['title_suffix'] ?: '—') ?></td></tr>
    <?php $extra = !empty($reg['extra_data']) ? (json_decode($reg['extra_data'], true) ?: []) : []; ?>
    <tr><th>Jenis Kelamin</th><td><?= $reg['gender'] === 'P' ? 'Perempuan' : 'Laki-laki' ?>
      <?php if (!empty($extra['gender_dugaan'])): ?><span class="badge core">diduga dari nama &mdash; konfirmasi saat mengaktifkan</span><?php endif; ?></td></tr>
    <tr><th>Nomor WA</th><td class="mono"><?= e($reg['phone']) ?></td></tr>
    <tr><th>Email</th><td><?= e($reg['email'] ?: '—') ?></td></tr>
    <tr><th>Tanggal Lahir</th><td><?= e($reg['birth_date'] ?: '—') ?></td></tr>
    <tr>
      <th>NIK</th>
      <td class="mono"><?= e($reg['nik'] ?: '—') ?></td>
    </tr>
    <tr><th>Struktur Tujuan</th><td><?= e($reg['level'] . ' ' . $reg['structure_name']) ?></td></tr>
    <?php if (!empty($extra['catatan'])): ?>
      <tr><th>Catatan Pendaftar</th><td><?= e($extra['catatan']) ?></td></tr>
    <?php endif; ?>
    <?php $profil = is_array($extra['profil'] ?? null) ? $extra['profil'] : []; $form = is_array($extra['formulir'] ?? null) ? $extra['formulir'] : []; ?>
    <?php if (!empty($profil['birth_place'])): ?><tr><th>Tempat Lahir</th><td><?= e($profil['birth_place']) ?></td></tr><?php endif; ?>
    <?php if (!empty($profil['city']) || !empty($profil['address_text'])): ?><tr><th>Domisili</th><td><?= e($profil['city'] ?? $profil['address_text']) ?></td></tr><?php endif; ?>
    <?php if (!empty($profil['occupation'])): ?><tr><th>Pekerjaan</th><td><?= e($profil['occupation']) ?></td></tr><?php endif; ?>
    <?php if (!empty($profil['family_phone'])): ?><tr><th>HP Keluarga</th><td class="mono"><?= e(Text::maskPhone($profil['family_phone'])) ?></td></tr><?php endif; ?>
    <?php if (!empty($form['jabatan']) || !empty($form['bidang'])): ?>
      <tr><th>Jabatan di Formulir</th><td><?= e(trim(($form['jabatan'] ?? '') . ' / ' . ($form['bidang'] ?? ''), ' /')) ?>
        <div class="small muted">Tanggal lahir di formulir: <?= e($form['ttl'] ?? '—') ?></div></td></tr>
    <?php endif; ?>
    <tr><th>Masuk</th><td class="small"><?= e($reg['created_at']) ?></td></tr>
    <?php if ($reg['reviewed_at']): ?>
      <tr><th>Ditinjau</th><td class="small"><?= e($reg['reviewed_at']) ?> oleh <?= e($reg['reviewer_name'] ?: '—') ?></td></tr>
    <?php endif; ?>
    <?php if ($reg['review_note']): ?>
      <tr><th>Catatan Peninjau</th><td><?= e($reg['review_note']) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<?php if ($match['confidence'] !== 'none'): ?>
  <div class="card">
    <h2>Kemungkinan Orang yang Sudah Ada
      <span class="hint">&mdash; cocok pada: <?= e(str_replace('_', ' ', (string) $match['matched_on'])) ?></span>
    </h2>
    <div class="alert <?= $match['confidence'] === 'ambiguous' ? 'error' : 'warn' ?>">
      <?php if ($match['confidence'] === 'ambiguous'): ?>
        Ada <strong>lebih dari satu</strong> kemungkinan. Sistem tidak memilih sendiri &mdash;
        periksa manual sebelum memutuskan.
      <?php else: ?>
        Tingkat kecocokan: <strong><?= e($match['confidence']) ?></strong>.
        Keputusan tetap pada Anda; sistem tidak pernah menggabungkan data otomatis.
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Nama</th><th>L/P</th><th>Nomor WA</th><th>NIK</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($match['candidates'] as $c): ?>
          <tr>
            <td><?= Ui::personCell($c) ?></td>
            <td><?= $c['gender'] === 'P' ? 'P' : 'L' ?></td>
            <?php if (!empty($c['out_of_scope'])): ?>
              <td class="small muted" colspan="3">Di luar lingkup akses Anda &mdash; biodata tidak ditampilkan.</td>
            <?php else: ?>
              <td class="small mono"><?= e($c['phone'] ?: '—') ?></td>
              <td class="small mono"><?= e($c['nik'] ?: '—') ?></td>
              <td><a class="btn small secondary" target="_blank"
                     href="<?= BASEURL ?>/people/detail/<?= (int) $c['id'] ?>">Buka</a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($reg['status'] === 'submitted' && Auth::canVerify()): ?>
  <div class="card">
    <h2>Langkah 1 &mdash; Verifikasi</h2>
    <form method="post" action="<?= BASEURL ?>/registrations/verify">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $reg['id'] ?>">
      <div class="field">
        <label for="review_note">Catatan (opsional)</label>
        <textarea id="review_note" name="review_note"></textarea>
      </div>
      <button type="submit" class="btn">Tandai Diverifikasi</button>
    </form>

    <hr style="margin:20px 0;border:none;border-top:1px solid var(--border)">

    <form method="post" action="<?= BASEURL ?>/registrations/reject">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $reg['id'] ?>">
      <div class="field">
        <label for="reject_note">Alasan Penolakan (wajib)</label>
        <textarea id="reject_note" name="review_note" required></textarea>
      </div>
      <button type="submit" class="btn danger">Tolak Pengajuan</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($reg['status'] === 'verified' && Auth::canVerify()): ?>
  <div class="card">
    <h2>Langkah 2 &mdash; Aktifkan dengan Penempatan</h2>

    <div class="alert info">
      Status <strong>Aktif</strong> di aplikasi ini adalah syarat, <strong>bukan</strong> jaminan
      otomatis mendapat akun di syuro atau aplikasi lain. Tidak ada akun yang dibuat oleh langkah ini.
    </div>

    <?php if (empty($terms)): ?>
      <div class="alert error">
        Struktur <?= e($reg['level'] . ' ' . $reg['structure_name']) ?> belum punya periode
        kepengurusan. <a href="<?= BASEURL ?>/terms/form/0/<?= (int) $reg['target_structure_id'] ?>">Buat
        periodenya</a> lebih dulu &mdash; setiap penempatan wajib terikat ke sebuah periode.
      </div>
    <?php else: ?>
      <form method="post" action="<?= BASEURL ?>/registrations/activate">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $reg['id'] ?>">

        <div class="field">
          <label>Identitas orang</label>
          <label class="check">
            <input type="radio" name="person_mode" value="new" checked>
            <span>Daftarkan sebagai <strong>orang baru</strong></span>
          </label>
          <?php foreach ($match['candidates'] as $c): ?>
            <label class="check">
              <input type="radio" name="person_mode" value="existing"
                     onclick="document.getElementById('existing_person_id').value='<?= (int) $c['id'] ?>'">
              <span>Ini orang yang <strong>sudah ada</strong>: <?= Ui::nameHtml($c) ?>
                <span class="muted">(biodata yang sudah tercatat tidak akan ditimpa)</span></span>
            </label>
          <?php endforeach; ?>
          <input type="hidden" id="existing_person_id" name="existing_person_id" value="">
        </div>

        <?php if (!empty($extra['gender_dugaan'])): ?>
          <div class="alert warn">Jenis kelamin pendaftar ini <strong>diduga dari namanya</strong> (formulir biodata tidak memuatnya). Pastikan sebelum mengaktifkan.</div>
        <?php endif; ?>
        <div class="field">
          <label for="gender">Jenis kelamin (untuk orang baru)</label>
          <select id="gender" name="gender">
            <option value="L" <?= $reg['gender'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
            <option value="P" <?= $reg['gender'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
          </select>
        </div>

        <div class="grid-2">
          <div class="field">
            <label for="term_id">Periode</label>
            <select id="term_id" name="term_id" required>
              <?php foreach ($terms as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) $defaultTermId === (int) $t['id'] ? 'selected' : '' ?>>
                  <?= e($t['name']) ?><?= $t['is_current'] ? ' (berjalan)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="structure_unit_id">Unit</label>
            <select id="structure_unit_id" name="structure_unit_id">
              <option value="">— pucuk pimpinan struktur (tanpa unit) —</option>
              <?php foreach ($units as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) ($reg['target_unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                  <?= e(($u['group_name'] ? $u['group_name'] . ' — ' : '') . ($u['display_name'] ?: $u['catalog_name'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="help">Daftar unit mengikuti periode yang dipilih di atas. Bila Anda mengubah
              periode, muat ulang halaman ini agar daftarnya ikut berubah.</div>
          </div>
        </div>

        <div class="grid-2">
          <div class="field">
            <label for="position_id">Jabatan</label>
            <select id="position_id" name="position_id" required>
              <option value="">— pilih —</option>
              <?php foreach ($positions as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $reg['target_position_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>&nbsp;</label>
            <label class="check">
              <input type="checkbox" name="is_primary" value="1" checked>
              <span>Penempatan utama</span>
            </label>
          </div>
        </div>

        <div class="btn-row">
          <button type="submit" class="btn">Aktifkan</button>
          <a class="btn secondary" href="<?= BASEURL ?>/registrations">Nanti</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($reg['status'] === 'activated'): ?>
  <div class="card">
    <div class="alert success">
      Pendaftar sudah aktif.
      <?php if ($reg['resulting_person_id']): ?>
        <a href="<?= BASEURL ?>/people/detail/<?= (int) $reg['resulting_person_id'] ?>">Buka data orangnya</a>.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
