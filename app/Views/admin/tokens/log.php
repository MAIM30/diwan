<?php
// PATH LOKASI FILE: app/Views/admin/tokens/log.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/tokens">Token API</a> &rsaquo; <?= e($client['name']) ?></div>
<div class="page-head"><h1>Log Pemakaian: <?= e($client['name']) ?></h1></div>

<div class="note">
  NIK pada endpoint lookup sudah <strong>diredaksi</strong> di log ini: log API disimpan lama dan
  dibaca luas, jadi yang perlu terlacak di sini adalah siapa memanggil lookup dan kapan, bukan
  nomornya. Catatan utuh &ldquo;siapa membuka NIK siapa&rdquo; ada di jejak audit.
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Waktu</th><th>Endpoint</th><th>Query</th><th class="num">Status</th><th class="num">Baris</th><th class="num">Durasi</th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="muted">Belum ada permintaan tercatat.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small nowrap"><?= e(date('d/m H:i:s', strtotime($r['requested_at']))) ?></td>
          <td class="small mono"><?= e($r['endpoint']) ?></td>
          <td class="small mono muted"><?= e($r['query_string'] ?: '—') ?></td>
          <td class="num">
            <span class="badge <?= (int) $r['http_status'] < 300 ? 'ok' : 'danger' ?>"><?= (int) $r['http_status'] ?></span>
          </td>
          <td class="num small"><?= $r['row_count'] === null ? '—' : (int) $r['row_count'] ?></td>
          <td class="num small"><?= (int) $r['duration_ms'] ?> ms</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
