<?php
// PATH LOKASI FILE: app/Views/admin/positions/index.php
// Master Data > Jabatan: daftar jabatan struktural dan unit.
//
// Dari controller: $rows (sudah tersaring, berisi used_placements/used_structures/used_as_head),
// $counts (jumlah per applies_to, TIDAK tersaring), $appliesTo, $keyword.

$this->view('layouts/header', ['title' => $title]);

$atLabels = ['structure' => 'Struktur', 'unit' => 'Unit', 'both' => 'Keduanya'];
$atIcon   = ['structure' => 'network', 'unit' => 'grid', 'both' => 'layers'];
$atTone   = ['structure' => 't-teal', 'unit' => 't-violet', 'both' => 't-green'];

$tierLabels = [1 => 'Pusat (DPP/MWP)', 2 => 'Wilayah (DPW/MWW)', 3 => 'Daerah (DPD/MWD)'];

$total   = array_sum($counts);
$canEdit = Auth::isSuperAdmin();

$qs = function (array $over = []) use ($appliesTo, $keyword) {
    $p = array_merge(['at' => $appliesTo, 'q' => $keyword], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/positions' . ($p ? '?' . http_build_query($p) : '');
};
$rc = function ($label, $count, $icon, $active, $href, $tone = '') {
    $cls = 'stat' . ($tone ? ' ' . $tone : '') . ($active ? ' active' : '');
    return '<a class="' . $cls . '" href="' . e($href) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . '<div class="stat-top"><span class="chip-ic">' . Ui::icon($icon, 18) . '</span>'
        . '<span class="lbl">' . e($label) . '</span></div>'
        . '<div class="num">' . Ui::n($count) . '</div></a>';
};
?>

<div class="page-head">
  <h1>Jabatan</h1>
  <span class="badge"><?= Ui::n($total) ?> jabatan</span>
</div>

<div class="note">
  Jabatan yang tersedia saat menempatkan pengurus ke struktur atau ke unit. Kolom
  <strong>Inti</strong> menentukan siapa yang tampil sebagai Pengurus Inti di roster periode.
</div>

<div class="stats recap">
  <?php foreach ($atLabels as $k => $label): ?>
    <?= $rc($label, (int) ($counts[$k] ?? 0), $atIcon[$k], $appliesTo === $k,
            $qs(['at' => $appliesTo === $k ? '' : $k]), $atTone[$k]) ?>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/positions" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari jabatan"
           placeholder="Cari nama atau kode jabatan">
    <?php if ($appliesTo): ?><input type="hidden" name="at" value="<?= e($appliesTo) ?>"><?php endif; ?>
  </form>
  <?php if ($appliesTo !== '' || $keyword !== ''): ?>
    <a class="btn small secondary" href="<?= BASEURL ?>/positions">Tampilkan semua</a>
  <?php endif; ?>
  <label class="per-page">
    <select data-per-page="pos-table">
      <?php foreach ([10, 25, 50, 100] as $n): ?>
        <option value="<?= $n ?>" <?= $n === 25 ? 'selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
    <span>per halaman</span>
  </label>
</div>

<?php if ($canEdit): ?>
<div class="card">
  <h2>Tambah Jabatan</h2>
  <form method="post" action="<?= BASEURL ?>/positions/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="at_filter" value="<?= e($appliesTo) ?>">
    <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
    <div class="grid-3">
      <div class="field">
        <label for="name">Nama Jabatan</label>
        <input type="text" id="name" name="name" required maxlength="100" placeholder="mis. Wakil Koordinator">
      </div>
      <div class="field">
        <label for="code">Kode</label>
        <input type="text" id="code" name="code" maxlength="40" placeholder="otomatis dari nama">
        <div class="help">Huruf kecil, garis bawah. Kosongkan untuk otomatis.</div>
      </div>
      <div class="field">
        <label for="new_applies_to">Berlaku di</label>
        <select id="new_applies_to" name="applies_to">
          <option value="both">Keduanya (struktur &amp; unit)</option>
          <option value="structure">Hanya struktur</option>
          <option value="unit">Hanya unit</option>
        </select>
      </div>
    </div>
    <div class="grid-3">
      <div class="field">
        <label for="new_sort">Urutan Tampil</label>
        <input type="number" id="new_sort" name="sort_order" value="500" min="0" max="65535">
      </div>
      <div class="field">
        <label>Pengurus Inti</label>
        <label class="check">
          <input type="checkbox" name="is_core" value="1">
          <span>Selalu inti di semua jenjang</span>
        </label>
      </div>
      <div class="field">
        <label for="new_cft">Inti mulai jenjang</label>
        <select id="new_cft" name="core_from_tier">
          <option value="">— tidak —</option>
          <?php foreach ($tierLabels as $t => $tl): ?>
            <option value="<?= $t ?>"><?= e($tl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Inti hanya di jenjang ini dan di bawahnya (bukan di atasnya).</div>
      </div>
    </div>
    <input type="hidden" name="is_active" value="1">
    <button type="submit" class="btn">Tambah</button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted" style="margin:0">
    <?= $keyword !== '' ? 'Tidak ada jabatan yang cocok dengan pencarian itu.' : 'Belum ada jabatan.' ?>
  </p></div>
<?php else: ?>
  <div class="card">
    <h2>
      <?= count($rows) ?> jabatan
      <?php if ($appliesTo !== ''): ?>
        <span class="hint">&mdash; <?= e($atLabels[$appliesTo] ?? $appliesTo) ?></span>
      <?php endif; ?>
      <?php if ($keyword !== ''): ?>
        <span class="hint">&mdash; &ldquo;<?= e($keyword) ?>&rdquo;</span>
      <?php endif; ?>
    </h2>
    <div class="table-wrap">
      <table id="pos-table">
        <thead>
          <tr>
            <th>Nama</th>
            <th>Kode</th>
            <th>Berlaku di</th>
            <th>Inti</th>
            <th class="num">Urutan</th>
            <th class="num">Dipakai</th>
            <th>Status</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['id']; $usedPl = (int) $r['used_placements']; $usedHead = (int) $r['used_as_head']; ?>
          <tr>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="small mono"><?= e($r['code']) ?></td>
            <td><span class="badge"><?= e($atLabels[$r['applies_to']] ?? $r['applies_to']) ?></span></td>
            <td>
              <?php if ($r['is_core']): ?>
                <span class="badge ok">Selalu</span>
              <?php elseif ($r['core_from_tier'] !== null): ?>
                <span class="badge" title="Inti mulai <?= e($tierLabels[(int) $r['core_from_tier']] ?? 'tier ' . $r['core_from_tier']) ?>">
                  &ge; <?= e($tierLabels[(int) $r['core_from_tier']] ?? 'Tier ' . $r['core_from_tier']) ?>
                </span>
              <?php else: ?>
                <span class="muted">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int) $r['sort_order'] ?></td>
            <td class="num">
              <?php if ($usedPl === 0 && $usedHead === 0): ?>
                <span class="badge" title="Belum dipakai penempatan atau kepala unit mana pun">belum</span>
              <?php else: ?>
                <strong><?= Ui::n($usedPl) ?></strong>
                <span class="small muted">di <?= (int) $r['used_structures'] ?> struktur</span>
                <?php if ($usedHead > 0): ?>
                  <br><span class="small muted">kepala <?= $usedHead ?> jenis unit</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['is_active']): ?><span class="badge ok">aktif</span>
              <?php else: ?><span class="badge">nonaktif</span><?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
              <td class="nowrap">
                <button type="button" class="icon-btn" data-toggle-edit="pos-<?= $id ?>" title="Ubah"><?= Ui::icon('edit', 16) ?></button>
                <form method="post" action="<?= BASEURL ?>/positions/delete" style="display:inline"
                      data-confirm="Hapus jabatan &ldquo;<?= e($r['name']) ?>&rdquo;?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="at_filter" value="<?= e($appliesTo) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
                  <button type="submit" class="icon-btn" title="Hapus"><?= Ui::icon('trash', 16) ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
          <?php if ($canEdit): ?>
            <tr id="pos-<?= $id ?>" hidden>
              <td colspan="8">
                <form method="post" action="<?= BASEURL ?>/positions/save">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="at_filter" value="<?= e($appliesTo) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
                  <div class="grid-3">
                    <div class="field">
                      <label for="n<?= $id ?>">Nama Jabatan</label>
                      <input type="text" id="n<?= $id ?>" name="name" required maxlength="100" value="<?= e($r['name']) ?>">
                    </div>
                    <div class="field">
                      <label for="c<?= $id ?>">Kode</label>
                      <input type="text" id="c<?= $id ?>" name="code" required maxlength="40" value="<?= e($r['code']) ?>">
                    </div>
                    <div class="field">
                      <label for="at<?= $id ?>">Berlaku di</label>
                      <select id="at<?= $id ?>" name="applies_to">
                        <option value="both" <?= $r['applies_to'] === 'both' ? 'selected' : '' ?>>Keduanya</option>
                        <option value="structure" <?= $r['applies_to'] === 'structure' ? 'selected' : '' ?>>Hanya struktur</option>
                        <option value="unit" <?= $r['applies_to'] === 'unit' ? 'selected' : '' ?>>Hanya unit</option>
                      </select>
                    </div>
                  </div>
                  <div class="grid-3">
                    <div class="field">
                      <label for="s<?= $id ?>">Urutan Tampil</label>
                      <input type="number" id="s<?= $id ?>" name="sort_order" value="<?= (int) $r['sort_order'] ?>" min="0" max="65535">
                    </div>
                    <div class="field">
                      <label>Pengurus Inti</label>
                      <label class="check">
                        <input type="checkbox" name="is_core" value="1" <?= $r['is_core'] ? 'checked' : '' ?>>
                        <span>Selalu inti di semua jenjang</span>
                      </label>
                    </div>
                    <div class="field">
                      <label for="cft<?= $id ?>">Inti mulai jenjang</label>
                      <select id="cft<?= $id ?>" name="core_from_tier">
                        <option value="">— tidak —</option>
                        <?php foreach ($tierLabels as $t => $tl): ?>
                          <option value="<?= $t ?>" <?= (int) $r['core_from_tier'] === $t && $r['core_from_tier'] !== null ? 'selected' : '' ?>><?= e($tl) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <label class="check">
                    <input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>>
                    <span>Aktif &mdash; jabatan nonaktif tidak ditawarkan saat menempatkan pengurus baru</span>
                  </label>
                  <?php if ($usedPl > 0): ?>
                    <p class="small muted" style="margin:10px 0 0">
                      Dipakai <?= Ui::n($usedPl) ?> penempatan di <?= (int) $r['used_structures'] ?> struktur.
                      Mengubah namanya di sini ikut mengubah tampilannya di semua penempatan itu.
                    </p>
                  <?php endif; ?>
                  <button type="submit" class="btn" style="margin-top:10px">Simpan Perubahan</button>
                </form>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pager" data-pager-for="pos-table"></div>
  </div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
