<?php
// PATH LOKASI FILE: app/Views/admin/people/deleted.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head">
  <h1>Data Pengurus Terhapus</h1>
  <a class="btn secondary" href="<?= BASEURL ?>/people">Kembali ke Pengurus</a>
</div>

<div class="note">
  Pemulihan mengaktifkan kembali data orang dan penempatan yang dihapus dalam aksi yang sama.
  Penempatan yang sebelumnya dihapus satu per satu tidak ikut dipulihkan.
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>Nomor WA</th><th>Penempatan ikut terhapus</th><th>Dihapus</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="muted">Tidak ada data terhapus dalam lingkup Anda.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= Ui::nameHtml($r) ?></strong></td>
          <td class="mono"><?= e($r['phone'] ?: '—') ?></td>
          <td><?= Ui::n($r['deleted_placements']) ?></td>
          <td class="small nowrap"><?= e(Ui::date($r['deleted_at'])) ?></td>
          <td class="nowrap">
            <form class="inline" method="post" action="<?= BASEURL ?>/people/restore"
                  onsubmit="return confirm(this.dataset.confirm)"
                  data-confirm="Pulihkan <?= e($r['full_name']) ?> beserta <?= (int) $r['deleted_placements'] ?> penempatan yang ikut terhapus?">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn small">Pulihkan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($rows) >= 100): ?>
    <p class="help">Daftar menampilkan paling banyak 100 data terbaru.</p>
  <?php endif; ?>
</div>

<?php $this->view('layouts/footer'); ?>
