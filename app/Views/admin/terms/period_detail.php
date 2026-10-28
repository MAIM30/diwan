<?php
// PATH LOKASI FILE: app/Views/admin/terms/period_detail.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb"><a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo; <a href="<?= BASEURL ?>/terms/period_catalog">Acuan Periode</a> &rsaquo; <?= e($period['name']) ?></div>
<div class="page-head">
  <h1>Rekap <?= e($period['name']) ?></h1>
  <?php if (Auth::canEdit()): ?><div class="actions"><a class="btn" href="<?= BASEURL ?>/terms/form?governance_period_id=<?= (int) $period['id'] ?>">Gunakan periode ini untuk struktur</a></div><?php endif; ?>
</div>

<div class="card">
  <div class="grid-3">
    <div><strong>Mulai</strong><div><?= e(Ui::date($period['start_date'])) ?></div></div>
    <div><strong>Akhir rencana</strong><div><?= e($period['end_date_planned'] ? Ui::date($period['end_date_planned']) : 'Belum ditentukan') ?></div></div>
    <div><strong>Total penempatan</strong><div><?= (int) $placementCount ?></div></div>
  </div>
</div>

<?php if (Auth::canEdit() && !empty($availableStructures)): ?>
  <div class="card">
    <h2>Gunakan acuan ini pada struktur</h2>
    <p class="muted">Pilih struktur yang memakai periode ini. Setiap struktur mendapat baris periode sendiri; SKUO dan SK tetap dilengkapi terpisah.</p>
    <form method="post" action="<?= BASEURL ?>/terms/attach_structures">
      <?= Csrf::field() ?>
      <input type="hidden" name="governance_period_id" value="<?= (int) $period['id'] ?>">
      <div class="field">
        <label for="structure-filter">Cari struktur</label>
        <input type="search" id="structure-filter" placeholder="Ketik tingkat atau nama struktur..." autocomplete="off">
      </div>
      <div class="field" id="structure-check-list" style="max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:10px">
        <?php foreach ($availableStructures as $s): ?>
          <label class="check attach-structure-option" data-search="<?= e(strtolower($s['level'] . ' ' . $s['name'])) ?>" style="display:flex;padding:6px 2px">
            <input type="checkbox" name="structure_ids[]" value="<?= (int) $s['id'] ?>">
            <span><?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="check" style="margin:12px 0">
        <input type="checkbox" name="mark_current" value="1">
        <span>Tandai periode ini berjalan pada semua struktur yang dipilih</span>
      </label>
      <div class="help">Jika dicentang, penanda periode berjalan sebelumnya pada tiap struktur yang dipilih akan dilepas. Jika tidak, periode baru berstatus draf.</div>
      <div class="btn-row"><button class="btn" type="submit">Tautkan Periode ke Struktur</button></div>
    </form>
  </div>
  <script>
  (function () {
    var input = document.getElementById('structure-filter');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      document.querySelectorAll('.attach-structure-option').forEach(function (item) {
        item.hidden = q !== '' && (item.getAttribute('data-search') || '').indexOf(q) < 0;
      });
    });
  })();
  </script>
<?php endif; ?>

<div class="card">
  <h2>Struktur yang menggunakan periode ini <span class="hint">&mdash; <?= count($rows) ?> struktur</span></h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Struktur</th><th>Status periode</th><th>Berjalan</th><th class="num">Penempatan</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Belum ada struktur dalam lingkup Anda yang memakai acuan ini.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['level'] . ' ' . $r['structure_name']) ?></strong></td>
        <td><?= e($r['status']) ?></td>
        <td><?= !empty($r['is_current']) ? '<span class="badge current">berjalan</span>' : '—' ?></td>
        <td class="num"><?= (int) $r['placement_count'] ?></td>
        <td class="nowrap">
          <a class="btn small secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $r['id'] ?>">Buka susunan</a>
          <?php if (Auth::canEdit()): ?><a class="btn small secondary" href="<?= BASEURL ?>/units/term/<?= (int) $r['id'] ?>">SKUO</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php $this->view('layouts/footer'); ?>
