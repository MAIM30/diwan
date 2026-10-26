<?php
// PATH LOKASI FILE: app/Views/admin/settings/whatsapp_log.php
$this->view('layouts/header', ['title' => $title]);

$typeLabels = ['otp' => 'OTP', 'notification' => 'Notifikasi', 'broadcast' => 'Broadcast'];
$statusClass = ['sent' => 'ok', 'delivered' => 'ok', 'read' => 'ok', 'pending' => '', 'failed' => 'danger'];
$pages = max(1, (int) ceil($total / $perPage));

$qs = function ($over = []) use ($type, $page) {
    $p = array_merge(['type' => $type, 'page' => $page], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null && $v !== 1; });
    return BASEURL . '/settings/whatsapp_log' . ($p ? '?' . http_build_query($p) : '');
};
?>

<div class="page-head">
  <h1><?= Ui::icon('file-text', 22) ?> Log WhatsApp</h1>
  <span class="badge"><?= Ui::n($total) ?> pesan</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/settings/whatsapp">&larr; Pengaturan</a>
  </div>
</div>

<div class="toolbar">
  <?php foreach ($typeLabels as $k => $label): ?>
    <a class="btn small <?= $type === $k ? '' : 'secondary' ?>" href="<?= $qs(['type' => $type === $k ? '' : $k, 'page' => 1]) ?>">
      <?= e($label) ?>
    </a>
  <?php endforeach; ?>
  <?php if ($type !== ''): ?>
    <a class="btn small secondary" href="<?= BASEURL ?>/settings/whatsapp_log">Semua</a>
  <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted" style="margin:0">Belum ada pesan yang tercatat.</p></div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Tujuan</th>
            <th>Tipe</th>
            <th>Template</th>
            <th>Status</th>
            <th>Message ID</th>
            <th>Error</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small nowrap"><?= e($r['created_at']) ?></td>
            <td class="mono small"><?= e(Text::maskPhone(preg_replace('/\D/', '', $r['phone']))) ?></td>
            <td><span class="badge"><?= e($typeLabels[$r['type']] ?? $r['type']) ?></span></td>
            <td class="small"><?= e($r['template_name'] ?: '—') ?></td>
            <td><span class="badge <?= $statusClass[$r['status']] ?? '' ?>"><?= e($r['status']) ?></span></td>
            <td class="small mono"><?= e($r['message_id'] ? substr($r['message_id'], 0, 20) . '…' : '—') ?></td>
            <td class="small"><?= e($r['error_message'] ? mb_substr($r['error_message'], 0, 60) : '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager" style="margin-top:12px">
        <?php if ($page > 1): ?>
          <a class="page-btn" href="<?= $qs(['page' => $page - 1]) ?>">&lsaquo;</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="<?= $qs(['page' => $p]) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
          <a class="page-btn" href="<?= $qs(['page' => $page + 1]) ?>">&rsaquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
