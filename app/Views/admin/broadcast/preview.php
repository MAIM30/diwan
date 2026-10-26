<?php
// PATH LOKASI FILE: app/Views/admin/broadcast/preview.php
$this->view('layouts/header', ['title' => $title]);
$count = count($recipients);
$ids = array_column($recipients, 'id');
?>

<div class="page-head">
  <h1><?= Ui::icon('radio', 22) ?> Pratinjau Broadcast</h1>
  <span class="badge"><?= Ui::n($count) ?> penerima</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/broadcast">&larr; Kembali</a>
  </div>
</div>

<div class="card">
  <h2>Pesan</h2>
  <div style="background:var(--bg-2); border-radius:var(--radius-sm); padding:16px; white-space:pre-wrap; line-height:1.6; font-size:14px"><?= e($message) ?></div>
</div>

<?php if ($count === 0): ?>
  <div class="card"><p class="muted" style="margin:0">Tidak ada pengurus yang cocok dengan filter ini.</p></div>
<?php else: ?>
  <div class="card">
    <h2>Daftar Penerima</h2>
    <div class="table-wrap">
      <table id="bc-table">
        <thead><tr><th>#</th><th>Nama</th><th>Nomor WA</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $i => $r): ?>
          <tr data-pid="<?= (int) $r['id'] ?>">
            <td class="small muted"><?= $i + 1 ?></td>
            <td><?= e(Ui::fullName($r)) ?></td>
            <td class="mono small"><?= e(Text::maskPhone($r['phone'])) ?></td>
            <td class="bc-status"><span class="badge">menunggu</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Progress bar (tersembunyi sampai pengiriman mulai) -->
    <div id="bc-progress" style="display:none; margin-top:16px">
      <div style="display:flex; justify-content:space-between; margin-bottom:6px">
        <span id="bc-progress-text" class="small" style="font-weight:600">Mengirim...</span>
        <span id="bc-progress-count" class="small muted">0 / <?= $count ?></span>
      </div>
      <div style="background:var(--bg-2); border-radius:8px; height:12px; overflow:hidden">
        <div id="bc-progress-bar" style="height:100%; width:0; background:var(--primer-tua); border-radius:8px; transition:width .3s ease"></div>
      </div>
      <div id="bc-progress-detail" class="small muted" style="margin-top:8px"></div>
    </div>

    <!-- Hasil akhir (tersembunyi sampai selesai) -->
    <div id="bc-result" style="display:none; margin-top:16px"></div>

    <?php if ($count > 1):
      $avgDelay = ($delayMin + $delayMax) / 2;
      $estMinutes = ceil($count * $avgDelay / 60);
    ?>
    <div class="note" style="margin-top:12px">
      <?= Ui::icon('shield', 14) ?> <strong>Anti-spam:</strong> Pesan dikirim satu per satu dengan jeda acak <?= $delayMin ?>–<?= $delayMax ?> detik.
      Estimasi waktu: <strong>~<?= $estMinutes ?> menit</strong> untuk <?= Ui::n($count) ?> penerima.
    </div>
    <?php endif; ?>

    <div id="bc-actions" style="margin-top:16px">
      <button type="button" class="btn" id="bc-send-btn"
              data-confirm="Kirim pesan ke <?= $count ?> penerima? Tindakan ini tidak bisa dibatalkan.">
        <?= Ui::icon('send', 16) ?> Kirim ke <?= Ui::n($count) ?> Penerima
      </button>
    </div>
  </div>
<?php endif; ?>

<?php if ($count > 0): ?>
<script>
(function () {
  var ids = <?= json_encode(array_map('intval', $ids)) ?>;
  var message = <?= json_encode($message, JSON_UNESCAPED_UNICODE) ?>;
  var csrf = <?= json_encode(Csrf::token()) ?>;
  var total = ids.length;
  var sendBtn = document.getElementById('bc-send-btn');
  var progressWrap = document.getElementById('bc-progress');
  var progressBar = document.getElementById('bc-progress-bar');
  var progressText = document.getElementById('bc-progress-text');
  var progressCount = document.getElementById('bc-progress-count');
  var progressDetail = document.getElementById('bc-progress-detail');
  var resultDiv = document.getElementById('bc-result');
  var actionsDiv = document.getElementById('bc-actions');
  var sending = false;

  sendBtn.addEventListener('click', function () {
    if (sending) return;

    // SweetAlert2 confirmation
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: 'Kirim broadcast?',
        html: 'Pesan akan dikirim ke <strong>' + total + '</strong> penerima.<br>Tindakan ini tidak bisa dibatalkan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, kirim',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#0f96b0'
      }).then(function (r) { if (r.isConfirmed) startSending(); });
    } else {
      if (confirm('Kirim pesan ke ' + total + ' penerima?')) startSending();
    }
  });

  function startSending() {
    sending = true;
    sendBtn.disabled = true;
    sendBtn.style.opacity = '.5';
    progressWrap.style.display = 'block';
    // Remove the data-confirm so the global handler doesn't also fire
    sendBtn.removeAttribute('data-confirm');

    var done = 0, success = 0, failed = 0;
    var failedNames = [];

    function next(i) {
      if (i >= total) {
        finish(success, failed, failedNames);
        return;
      }

      var row = document.querySelector('tr[data-pid="' + ids[i] + '"]');
      if (row) {
        row.querySelector('.bc-status').innerHTML = '<span class="badge" style="background:#fff3cd;color:#856404">mengirim…</span>';
        row.scrollIntoView({block: 'nearest', behavior: 'smooth'});
      }

      var body = new FormData();
      body.append('_csrf', csrf);
      body.append('person_id', ids[i]);
      body.append('message', message);

      fetch('<?= BASEURL ?>/broadcast/send_one', {method: 'POST', body: body, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (data) {
          done++;
          if (data.ok) {
            success++;
            if (row) row.querySelector('.bc-status').innerHTML = '<span class="badge ok">terkirim</span>';
          } else {
            failed++;
            failedNames.push(data.name || ('ID ' + ids[i]));
            if (row) row.querySelector('.bc-status').innerHTML = '<span class="badge danger">gagal</span>';
          }
          updateProgress(done, success, failed);
          next(i + 1);
        })
        .catch(function () {
          done++;
          failed++;
          failedNames.push('ID ' + ids[i]);
          if (row) row.querySelector('.bc-status').innerHTML = '<span class="badge danger">gagal</span>';
          updateProgress(done, success, failed);
          next(i + 1);
        });
    }

    next(0);
  }

  function updateProgress(done, success, failed) {
    var pct = Math.round((done / total) * 100);
    progressBar.style.width = pct + '%';
    progressCount.textContent = done + ' / ' + total;
    progressText.textContent = 'Mengirim... ' + pct + '%';
    progressDetail.textContent = success + ' berhasil' + (failed > 0 ? ', ' + failed + ' gagal' : '');
  }

  function finish(success, failed, failedNames) {
    progressText.textContent = 'Selesai';
    progressBar.style.width = '100%';
    progressBar.style.background = failed > 0 ? '#e9a820' : 'var(--primer-tua)';

    var html = '<div class="alert ' + (failed > 0 ? 'info' : 'success') + '" style="margin:0">';
    html += '<strong>Broadcast selesai:</strong> ' + success + ' berhasil';
    if (failed > 0) html += ', ' + failed + ' gagal';
    html += '.</div>';
    if (failedNames.length > 0) {
      html += '<div class="small muted" style="margin-top:8px">Gagal: ' + failedNames.join(', ') + '</div>';
    }
    resultDiv.innerHTML = html;
    resultDiv.style.display = 'block';
    actionsDiv.innerHTML = '<a class="btn secondary" href="<?= BASEURL ?>/broadcast">&larr; Broadcast Baru</a> '
      + '<a class="btn secondary" href="<?= BASEURL ?>/settings/whatsapp_log">Lihat Log</a>';
  }
})();
</script>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
