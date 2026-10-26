<?php
// PATH LOKASI FILE: app/Views/admin/accounts/index.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head">
  <h1>Akun Pengelola</h1>
  <div class="actions">
    <a class="btn" href="<?= BASEURL ?>/accounts/form">Tambah Akun</a>
  </div>
</div>

<div class="note">
  Akun di sini adalah <strong>hak akses ke aplikasi ini</strong> &mdash; sumbu yang terpisah dari
  jabatan struktural seseorang. Seorang Ketua Departemen belum tentu punya akun, dan pemegang akun
  belum tentu pengurus. Lingkup sebuah peran mencakup struktur itu <em>beserta seluruh keturunannya</em>,
  tapi tidak pernah menyeberang ke cabang lain.
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>Nomor WA</th><th>Peran &amp; Lingkup</th><th>Terkait Pengurus</th><th>Status</th><th>Login Terakhir</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">Belum ada akun yang bisa Anda kelola.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['display_name']) ?></strong></td>
          <td class="small mono"><?= e($r['phone']) ?></td>
          <td class="small"><?= e($r['role_summary'] ?: '—') ?></td>
          <td class="small">
            <?php if ($r['person_id']): ?>
              <a href="<?= BASEURL ?>/people/detail/<?= (int) $r['person_id'] ?>"><?= e($r['person_name']) ?></a>
            <?php else: ?>
              <span class="muted">bukan pengurus</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['status'] === 'active'): ?><span class="badge ok">aktif</span>
            <?php else: ?><span class="badge danger">ditangguhkan</span><?php endif; ?>
          </td>
          <td class="small nowrap"><?= e($r['last_login_at'] ? date('d/m/Y H:i', strtotime($r['last_login_at'])) : '—') ?></td>
          <td class="nowrap">
            <a class="btn small secondary" href="<?= BASEURL ?>/accounts/form/<?= (int) $r['id'] ?>">Ubah</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
