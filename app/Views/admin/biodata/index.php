<?php
// PATH LOKASI FILE: app/Views/admin/biodata/index.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head"><h1><?= Ui::icon('file-text', 22) ?> Biodata Pengurus</h1></div>

<div class="note">
  Daftar biodata yang sudah diisi pengurus sendiri lewat Portal Pengurus. Isiannya tidak bisa diubah
  dari sini -- hanya diunduh ulang atau dikirim ulang ke Google Drive kalau sebelumnya gagal.
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>Struktur</th><th>Status Drive</th><th>Diperbarui</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="muted">Belum ada biodata masuk di lingkup Anda.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= Ui::personCell(['uuid' => $r['person_uuid'], 'full_name' => $r['full_name'], 'photo_path' => $r['photo_path']]) ?></td>
          <td class="small"><?= e($r['structure_label'] ?? '-') ?></td>
          <td>
            <?php if ($r['gdrive_file_id']): ?>
              <span class="badge ok"><?= Ui::icon('check', 13) ?> Terkirim</span>
            <?php else: ?>
              <span class="badge"><?= Ui::icon('clock', 13) ?> Belum</span>
            <?php endif; ?>
          </td>
          <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime($r['updated_at']))) ?></td>
          <td class="nowrap">
            <a class="btn small secondary" href="<?= BASEURL ?>/biodata/pdf/<?= e($r['uuid']) ?>" target="_blank" rel="noopener">Unduh</a>
            <?php if (!$r['gdrive_file_id']): ?>
              <form method="post" action="<?= BASEURL ?>/biodata/resend" style="display:inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="uuid" value="<?= e($r['uuid']) ?>">
                <button type="submit" class="btn small secondary">Kirim ke Drive</button>
              </form>
            <?php elseif ($r['gdrive_file_url']): ?>
              <a class="btn small secondary" href="<?= e($r['gdrive_file_url']) ?>" target="_blank" rel="noopener">Buka di Drive</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
