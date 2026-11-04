<?php
// PATH LOKASI FILE: app/Views/admin/forums/assign.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head">
  <a class="btn-back" href="<?= BASEURL ?>/forums/detail/<?= (int) $forum['id'] ?>"><?= Ui::icon('arrow-left', 17) ?> <?= e($forum['name']) ?></a>
  <h1>Tetapkan Peserta</h1>
</div>

<form class="card" method="get" action="<?= BASEURL ?>/forums/assign/<?= (int) $forum['id'] ?>" style="padding:1rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:end">
  <div class="field" style="flex:1;min-width:180px">
    <label for="structure">Struktur</label>
    <select id="structure" name="structure">
      <option value="">Semua struktur</option>
      <?php foreach ($structures as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $filterStruct === (int) $s['id'] ? 'selected' : '' ?>>
          <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="min-width:100px">
    <label><input type="checkbox" name="core" value="1" <?= $filterCore ? 'checked' : '' ?>> Pengurus Inti saja</label>
  </div>
  <div class="field" style="flex:1;min-width:180px">
    <label for="q">Cari nama / WA</label>
    <input type="search" id="q" name="q" value="<?= e($filterSearch) ?>" placeholder="Nama atau nomor WA">
  </div>
  <button type="submit" class="btn secondary"><?= Ui::icon('search', 17) ?> Saring</button>
</form>

<?php if (empty($candidates)): ?>
  <div class="empty">
    <?= Ui::icon('users', 48) ?>
    <p>Tidak ada orang yang cocok dengan filter. Coba perluas pencarian.</p>
  </div>
<?php else: ?>
  <form method="post" action="<?= BASEURL ?>/forums/assign_save" id="assignForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="forum_id" value="<?= (int) $forum['id'] ?>">

    <div class="card" style="padding:1rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
      <div class="field" style="min-width:160px">
        <label for="category">Kategori</label>
        <select id="category" name="category">
          <option value="peserta_penuh">Peserta Penuh</option>
          <option value="undangan">Undangan</option>
          <option value="peninjau">Peninjau</option>
        </select>
      </div>
      <div class="field" style="min-width:200px">
        <label for="decision_ref">SK / Referensi</label>
        <input type="text" id="decision_ref" name="decision_ref" placeholder="Opsional" maxlength="200">
      </div>
      <?php if ($isLocked): ?>
        <div class="field" style="min-width:200px">
          <label for="reason">Alasan <span class="req">*</span></label>
          <input type="text" id="reason" name="reason" required placeholder="Wajib karena forum dikunci">
        </div>
      <?php else: ?>
        <input type="hidden" name="reason" value="">
      <?php endif; ?>
      <button type="submit" class="btn"><?= Ui::icon('check', 17) ?> Tetapkan yang Dipilih</button>
    </div>

    <div class="card">
      <table class="tbl">
        <thead>
          <tr>
            <th class="center" style="width:40px">
              <input type="checkbox" id="checkAll" title="Pilih semua di halaman ini">
            </th>
            <th>Nama</th>
            <th>Jabatan (Periode Berjalan)</th>
            <th>No HP</th>
            <th class="center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($candidates as $c):
              $alreadyIn = in_array((int) $c['id'], $existingPids, true);
          ?>
            <tr class="<?= $alreadyIn ? 'muted' : '' ?>">
              <td class="center">
                <?php if (!$alreadyIn): ?>
                  <input type="checkbox" name="person_ids[]" value="<?= (int) $c['id'] ?>" class="person-check">
                  <input type="hidden" name="primary_placement_id_<?= (int) $c['id'] ?>" value="<?= (int) ($c['primary_placement_id'] ?? 0) ?>">
                  <input type="hidden" name="primary_structure_id_<?= (int) $c['id'] ?>" value="<?= (int) ($c['primary_structure_id'] ?? 0) ?>">
                <?php else: ?>
                  <span title="Sudah terdaftar"><?= Ui::icon('check', 16) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($c['full_name']) ?></td>
              <td><small><?= e($c['jabatan_list'] ?? '—') ?></small></td>
              <td><?= $c['phone'] ? e($c['phone']) : '<span class="muted">—</span>' ?></td>
              <td class="center">
                <?php if ($alreadyIn): ?>
                  <span class="badge t-green">Sudah</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>

  <script>
  document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.person-check').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
  });
  </script>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
