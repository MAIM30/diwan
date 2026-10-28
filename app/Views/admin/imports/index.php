<?php
// PATH LOKASI FILE: app/Views/admin/imports/index.php
$this->view('layouts/header', ['title' => $title]);
$tabs = ['pending'=>'Menunggu', 'confirmed'=>'Dikonfirmasi', 'split'=>'Dipisah', 'corrected'=>'Dikoreksi', 'ignored'=>'Diabaikan', 'all'=>'Semua'];
$labels = ['matched_existing'=>'Cocok dengan orang yang ada', 'matched_in_batch'=>'Cocok dalam batch', 'ambiguous'=>'Kemungkinan ganda', 'unmapped_position'=>'Jabatan belum dipetakan', 'unmapped_unit'=>'Unit belum dipetakan', 'lainnya'=>'Lainnya'];
?>

<div class="page-head"><h1>Tinjauan Impor</h1></div>
<div class="note">
  Daftar ini berisi nama atau keadaan yang perlu keputusan manusia dari migrasi dan impor. Menyimpan keputusan
  hanya memperbarui catatan tinjauan; perubahan pada data orang atau penempatan tetap dilakukan lewat fitur terkait.
</div>

<div class="card">
  <div class="btn-row" style="margin:0">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="btn small <?= $status === $key ? '' : 'secondary' ?>" href="<?= BASEURL ?>/imports?status=<?= e($key) ?>">
        <?= e($label) ?> <span class="badge"><?= $key === 'all' ? Ui::n(array_sum($counts)) : Ui::n($counts[$key] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty"><span>Tidak ada butir pada status ini.</span></div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <article class="card" style="margin:0 0 12px;padding:16px">
        <div class="split">
          <div>
            <h2 style="margin:0 0 6px"><?= e($r['raw_name']) ?></h2>
            <div class="small muted">
              <?= e($r['batch_label']) ?> · <?= e(strtoupper($r['batch_source'])) ?>
              · <?= e($labels[$r['reason']] ?? $r['reason']) ?>
            </div>
            <?php if (!empty($r['raw_context'])): ?><p><strong>Konteks sumber:</strong> <?= e($r['raw_context']) ?></p><?php endif; ?>
            <?php if (!empty($r['note'])): ?><div class="note"><strong>Catatan proses:</strong> <?= nl2br(e($r['note'])) ?></div><?php endif; ?>
            <?php if (!empty($r['candidate_name'])): ?><p><strong>Calon orang:</strong> <?= e($r['candidate_name']) ?></p><?php endif; ?>
          </div>
          <form method="post" action="<?= BASEURL ?>/imports/resolve" style="min-width:min(100%,360px)">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="back_status" value="<?= e($status) ?>">
            <div class="field">
              <label for="resolution-<?= (int) $r['id'] ?>">Keputusan</label>
              <select id="resolution-<?= (int) $r['id'] ?>" name="resolution">
                <?php foreach ($tabs as $key => $label): if ($key === 'all') continue; ?>
                  <option value="<?= e($key) ?>" <?= $r['resolution'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="resolution-note-<?= (int) $r['id'] ?>">Catatan keputusan</label>
              <textarea id="resolution-note-<?= (int) $r['id'] ?>" name="resolution_note" rows="2" maxlength="10000"><?= e($r['resolution_note'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn small">Simpan keputusan</button>
            <?php if (!empty($r['resolved_at'])): ?><span class="small muted">Diputuskan <?= e(Ui::date($r['resolved_at'])) ?></span><?php endif; ?>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (count($rows) >= 500): ?><p class="help">Daftar dibatasi 500 butir terbaru. Gunakan filter status untuk mempersempit daftar.</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php $this->view('layouts/footer'); ?>
