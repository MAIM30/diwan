<?php
// PATH LOKASI FILE: app/Views/admin/units/term.php
// Bidang & unit milik SATU periode. Keduanya terikat periode, bukan sekadar struktur.
$this->view('layouts/header', ['title' => $title]);
?>

<div class="crumb">
  <a href="<?= BASEURL ?>/terms">Periode</a> &rsaquo;
  <a href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>"><?= e($term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name']) ?></a>
  &rsaquo; Bidang &amp; Unit
</div>

<div class="page-head">
  <h1>Bidang &amp; Unit</h1>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/units/grouping?open=<?= (int) $term['id'] ?>#t<?= (int) $term['id'] ?>">Pengelompokan Unit</a>
    <a class="btn secondary" href="<?= BASEURL ?>/terms/detail/<?= (int) $term['id'] ?>">Lihat Susunan Pengurus</a>
  </div>
</div>

<div class="note">
  Susunan ini milik periode <strong><?= e($term['name']) ?></strong> saja. Periode berikutnya punya
  susunannya sendiri &mdash; jadi ketika SK baru menambah atau membubarkan Bidang, susunan periode
  lama tetap terbaca apa adanya.
</div>

<div class="card">
  <h2>Bidang / Subbidang / Grup <span class="hint">&mdash; pengelompokan di atas unit</span></h2>

  <?php if (Auth::canEdit()): ?>
    <form method="post" action="<?= BASEURL ?>/units/group_save" style="margin-bottom:16px">
      <?= Csrf::field() ?>
      <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
      <div class="grid-3">
        <div class="field">
          <label for="g_name">Nama</label>
          <input type="text" id="g_name" name="name" required
                 placeholder="mis. Bidang Dakwah, Kaderisasi dan PSDM">
        </div>
        <div class="field">
          <label for="g_kind">Jenis</label>
          <select id="g_kind" name="kind">
            <option value="bidang">Bidang (umumnya DPP)</option>
            <option value="subbidang">Subbidang (umumnya Muslimah)</option>
            <option value="grup">Grup (mis. Biro-Biro, Pusat-Pusat)</option>
          </select>
        </div>
        <div class="field">
          <label for="g_sort">Urutan</label>
          <input type="number" id="g_sort" name="sort_order" value="500">
        </div>
      </div>
      <button type="submit" class="btn">Tambah Bidang</button>
    </form>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead><tr><th class="num">Urut</th><th>Nama</th><th>Jenis</th><th class="num">Unit</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($groups)): ?>
        <tr><td colspan="5" class="muted">Belum ada Bidang di periode ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($groups as $g): ?>
        <tr>
          <td class="num small"><?= (int) $g['sort_order'] ?></td>
          <td><strong><?= e($g['name']) ?></strong></td>
          <td><span class="badge"><?= e($g['kind']) ?></span></td>
          <td class="num"><?= (int) $g['unit_count'] ?></td>
          <td class="nowrap">
            <?php if (Auth::canEdit()): ?>
              <form class="inline" method="post" action="<?= BASEURL ?>/units/group_delete"
                    data-confirm="Hapus Bidang ini?" onsubmit="return confirm(this.dataset.confirm)">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <button type="submit" class="btn small danger">Hapus</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Unit di periode ini</h2>

  <?php if (Auth::canEdit()): ?>
    <form method="post" action="<?= BASEURL ?>/units/unit_save" style="margin-bottom:16px">
      <?= Csrf::field() ?>
      <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
      <div class="grid-3">
        <div class="field">
          <label for="u_catalog">Unit (dari katalog)</label>
          <select id="u_catalog" name="unit_catalog_id" required>
            <option value="">— pilih —</option>
            <?php foreach ($catalog as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">Belum ada di daftar?
            <a href="<?= BASEURL ?>/units/catalog">Tambahkan ke katalog</a> dulu.</div>
        </div>
        <div class="field">
          <label for="u_group">Bidang penaung</label>
          <select id="u_group" name="unit_group_id">
            <option value="">— belum dipetakan —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="u_sort">Urutan</label>
          <input type="number" id="u_sort" name="sort_order" value="500">
        </div>
      </div>
      <input type="hidden" name="display_name" value="">
      <button type="submit" class="btn">Tambah Unit ke Periode</button>
    </form>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Unit</th><th>Bidang Penaung</th><th>Jenis</th><th class="num">Pengurus</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="muted">Belum ada unit di periode ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <strong><?= e($r['display_name'] ?: $r['catalog_name']) ?></strong>
            <?php if ($r['abbreviation']): ?>
              <span class="small muted mono">(<?= e($r['abbreviation']) ?>)</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($r['group_name']): ?>
              <?= e($r['group_name']) ?>
            <?php else: ?>
              <span class="badge danger">belum dipetakan</span>
            <?php endif; ?>
          </td>
          <td><span class="badge"><?= e($r['kind']) ?></span></td>
          <td class="num"><?= (int) $r['placement_count'] ?></td>
          <td class="nowrap">
            <?php if (Auth::canEdit()): ?>
              <form class="inline" method="post" action="<?= BASEURL ?>/units/unit_delete"
                    data-confirm="Hapus unit ini dari periode?" onsubmit="return confirm(this.dataset.confirm)">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn small danger">Hapus</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
