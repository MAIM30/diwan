<?php
// PATH LOKASI FILE: app/Views/pengurus/koreksi.php
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'data']);
$statusLabels = ['submitted' => 'Menunggu admin', 'resolved' => 'Ditangani', 'rejected' => 'Tidak ditindaklanjuti'];
$statusClasses = ['submitted' => 'core', 'resolved' => 'ok', 'rejected' => 'danger'];
?>

<div class="page-head"><h1>Pengajuan Koreksi</h1></div>
<div class="note">
  Gunakan formulir ini jika jabatan atau penempatan yang tampil di Portal Pengurus tidak sesuai. Laporan akan masuk ke admin yang lingkupnya mencakup struktur tersebut. Data Anda tidak berubah otomatis.
</div>

<section class="card">
  <h2 style="margin-top:0">Laporkan kekeliruan</h2>
  <?php if (empty($current)): ?>
    <div class="alert info">Tidak ada penempatan yang sedang berjalan untuk dilaporkan. Hubungi admin struktur bila Anda merasa seharusnya memiliki jabatan aktif.</div>
  <?php else: ?>
    <form method="post" action="<?= BASEURL ?>/pengurus/koreksi_submit">
      <?= Csrf::field() ?>
      <div class="field">
        <label for="placement_id">Jabatan atau penempatan yang keliru</label>
        <select id="placement_id" name="placement_id" required>
          <option value="">Pilih penempatan saat ini</option>
          <?php foreach ($current as $row): ?>
            <?php
              $context = $row['position_name'] . ' · ' . $row['level'] . ' ' . $row['structure_name'];
              if (!empty($row['unit_name'])) $context .= ' · ' . (!empty($row['group_name']) ? $row['group_name'] . ' › ' : '') . $row['unit_name'];
              $context .= ' · ' . $row['term_name'];
            ?>
            <option value="<?= (int) $row['id'] ?>"><?= e($context) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="category">Bagian yang keliru</label>
        <select id="category" name="category" required>
          <option value="">Pilih bagian</option>
          <option value="placement">Penempatan (struktur atau unit organisasi)</option>
          <option value="position">Jabatan</option>
        </select>
      </div>
      <div class="field">
        <label for="details">Jelaskan kekeliruannya</label>
        <textarea id="details" name="details" rows="4" minlength="10" maxlength="2000" required
                  placeholder="Jelaskan data yang tampil dan koreksi yang seharusnya."></textarea>
        <div class="help">Tuliskan informasi yang membantu admin memeriksa laporan. Maksimal 2.000 karakter.</div>
      </div>
      <button class="btn" type="submit"><?= Ui::icon('message-circle', 17) ?> Kirim ke admin</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2 style="margin-top:0">Riwayat pengajuan</h2>
  <?php if (empty($requests)): ?>
    <p class="muted" style="margin-bottom:0">Anda belum pernah mengirim laporan koreksi.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Pengajuan</th><th>Rincian</th><th>Status</th><th>Catatan admin</th><th>Dikirim</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $request): ?>
          <tr>
            <td>
              <strong><?= e(PositionCorrectionModel::categoryLabel($request['category'])) ?></strong>
              <div class="small"><?= e($request['position_snapshot']) ?></div>
              <div class="small muted"><?= e($request['structure_snapshot']) ?> · <?= e($request['term_snapshot']) ?></div>
              <?php if (!empty($request['unit_snapshot'])): ?><div class="small muted"><?= e($request['unit_snapshot']) ?></div><?php endif; ?>
            </td>
            <td class="small" style="white-space:pre-wrap"><?= e($request['details']) ?></td>
            <td><span class="badge <?= $statusClasses[$request['status']] ?? '' ?>"><?= e($statusLabels[$request['status']] ?? $request['status']) ?></span></td>
            <td class="small"><?= $request['admin_note'] !== null && $request['admin_note'] !== '' ? nl2br(e($request['admin_note'])) : '<span class="muted">—</span>' ?></td>
            <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime($request['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php $this->view('layouts/member_footer'); ?>
