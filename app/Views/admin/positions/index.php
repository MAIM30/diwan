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

$total  = array_sum($counts);
$canEdit = Auth::isSuperAdmin();

// Saringan struktur: $structure = pilihan yang sah (['abbr' => 'DPP', 'level' => 'DPW'|''] ) atau null; $structureFilters = tombolnya.
$stAbbr  = $structure['abbr'] ?? '';
$stLevel = $structure['level'] ?? '';
$stQuery = $stAbbr !== '' ? '?' . http_build_query(array_filter(['st' => $stAbbr, 'lv' => $stLevel], function ($v) { return $v !== ''; })) : '';
// "DPP", atau "DPP › DPW" bila jenjangnya dipilih; "DPP saja" untuk jenjang pusatnya sendiri.
$stLabel = $stAbbr === '' ? '' : ($stLevel === '' ? $stAbbr : ($stLevel === $stAbbr ? $stAbbr . ' saja' : $stAbbr . ' › ' . $stLevel));

$qs = function (array $over = []) use ($appliesTo, $keyword, $stAbbr, $stLevel) {
    $p = array_merge(['at' => $appliesTo, 'q' => $keyword, 'st' => $stAbbr, 'lv' => $stLevel], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/positions' . ($p ? '?' . http_build_query($p) : '');
};
$chip = function ($label, $on, $href, $title = '') {
    return '<a class="chip' . ($on ? ' on' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="true"' : '')
        . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</a>';
};
// Judul kartu + tombol penyaring struktur di sampingnya: Semua · DSO · DSY · DPK · DPP. Tombol DPP membuka baris jenjang di
// bawahnya (DPP saja, DPW, DPD, MWP, MWW, MWD) untuk mempersempit lagi. Menekan tombol yang sedang aktif membatalkannya.
$cardHead = function ($count) use ($atLabels, $appliesTo, $keyword, $stAbbr, $stLevel, $stLabel, $structureFilters, $qs, $chip) {
    ob_start(); ?>
    <div class="card-head" style="flex-wrap:wrap;gap:10px 18px">
      <h2 style="font-weight:700">
        <?= (int) $count ?> jabatan
        <?php if ($appliesTo !== ''): ?><span class="hint">&mdash; <?= e($atLabels[$appliesTo] ?? $appliesTo) ?></span><?php endif; ?>
        <?php if ($keyword !== ''): ?><span class="hint">&mdash; &ldquo;<?= e($keyword) ?>&rdquo;</span><?php endif; ?>
        <?php if ($stAbbr !== ''): ?><span class="hint">&mdash; dipakai di <?= e($stLabel) ?></span><?php endif; ?>
      </h2>
      <div class="chips" role="group" aria-label="Saring menurut struktur">
        <?= $chip('Semua', $stAbbr === '', $qs(['st' => '', 'lv' => ''])) ?>
        <?php foreach ($structureFilters as $f): $on = $stAbbr === $f['abbr']; ?>
          <?= $chip($f['abbr'], $on, $on && $stLevel === '' ? $qs(['st' => '', 'lv' => '']) : $qs(['st' => $f['abbr'], 'lv' => '']), $f['name']) ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php foreach ($structureFilters as $f): if ($stAbbr !== $f['abbr'] || count($f['levels']) < 2) continue; ?>
      <div class="chips" role="group" aria-label="Jenjang di bawah <?= e($f['abbr']) ?>"
           style="margin:-4px 0 16px 6px;padding-left:12px;border-left:3px solid var(--primer-terang);align-items:center">
        <span class="small muted">Jenjang:</span>
        <?php foreach ($f['levels'] as $lv): $lon = $stLevel === $lv; ?>
          <?= $chip($lv === $f['abbr'] ? $lv . ' saja' : $lv, $lon, $qs(['lv' => $lon ? '' : $lv]), $lv === $f['abbr'] ? 'Hanya struktur ' . $lv . ' sendiri, tanpa jenjang di bawahnya' : 'Hanya struktur berjenjang ' . $lv) ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
    return ob_get_clean();
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
    <?php if ($stAbbr !== ''): ?><input type="hidden" name="st" value="<?= e($stAbbr) ?>"><?php endif; ?>
    <?php if ($stLevel !== ''): ?><input type="hidden" name="lv" value="<?= e($stLevel) ?>"><?php endif; ?>
  </form>
  <?php if ($appliesTo !== '' || $keyword !== '' || $stAbbr !== ''): ?>
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
    <input type="hidden" name="q_filter" value="<?= e($keyword) ?>"><input type="hidden" name="st_filter" value="<?= e($stAbbr) ?>"><input type="hidden" name="lv_filter" value="<?= e($stLevel) ?>">
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
          <span>Termasuk Pengurus Inti</span>
        </label>
        <div class="help">Berlaku bila jabatan dipegang di tingkat struktur. Di dalam unit tidak pernah dihitung inti.</div>
      </div>
      <div class="field">
        <label>Jenis unit</label>
        <div style="display:flex;flex-wrap:wrap;gap:2px 14px">
          <?php foreach (PositionModel::UNIT_KINDS as $k => $kl): ?>
            <label class="check"><input type="checkbox" name="unit_kinds[]" value="<?= e($k) ?>"><span><?= e($kl) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="help">Untuk jabatan yang berlaku di unit: pilih jenis unit tempatnya (mis. &ldquo;Ketua Biro&rdquo; hanya Biro). Kosong = semua jenis unit.</div>
      </div>
    </div>
    <input type="hidden" name="is_active" value="1">
    <button type="submit" class="btn">Tambah</button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="card">
    <?= $cardHead(0) ?>
    <p class="muted" style="margin:0">
      <?php if ($stAbbr !== ''): ?>
        Tidak ada jabatan<?= ($keyword !== '' || $appliesTo !== '') ? ' yang cocok dengan saringan itu' : '' ?> yang dipakai di <?= e($stLabel) ?>.
      <?php else: ?>
        <?= $keyword !== '' ? 'Tidak ada jabatan yang cocok dengan pencarian itu.' : 'Belum ada jabatan.' ?>
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="card">
    <?= $cardHead(count($rows)) ?>
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
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['id']; $usedPl = (int) $r['used_placements']; $usedHead = (int) $r['used_as_head']; ?>
          <tr>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="small mono"><?= e($r['code']) ?></td>
            <td>
              <span class="badge"><?= e($atLabels[$r['applies_to']] ?? $r['applies_to']) ?></span>
              <?php if ($r['applies_to'] !== 'structure' && ($kindsText = PositionModel::kindsLabel($r['unit_kinds'] ?? '')) !== ''): ?>
                <div class="small muted" title="Jenis unit tempat jabatan ini berlaku" style="margin-top:3px"><?= e($kindsText) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['is_core']): ?>
                <span class="badge ok">Inti</span>
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
                <?php if ($usedHead > 0 && $stAbbr === ''): /* kepala unit ditetapkan per jenis unit, bukan per struktur: tak ikut disaring */ ?>
                  <br><span class="small muted">kepala <?= $usedHead ?> jenis unit</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['is_active']): ?><span class="badge ok">aktif</span>
              <?php else: ?><span class="badge">nonaktif</span><?php endif; ?>
            </td>
            <td class="nowrap">
              <div style="display:flex;gap:6px;align-items:center">
                <?php if ($usedPl > 0): ?>
                  <a class="icon-btn" href="<?= BASEURL ?>/positions/members/<?= $id ?><?= e($stQuery) ?>"
                     title="Lihat pengurus yang memegang jabatan ini" aria-label="Lihat pengurus yang memegang jabatan <?= e($r['name']) ?>"><?= Ui::icon('eye', 16) ?></a>
                <?php else: ?>
                  <?php /* Ikon redup, bukan dihilangkan: menjaga ikon Ubah/Hapus tetap sejajar antar baris. */ ?>
                  <span class="icon-btn" aria-hidden="true" style="opacity:.35;cursor:default" title="Belum dipakai penempatan mana pun"><?= Ui::icon('eye', 16) ?></span>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                  <button type="button" class="icon-btn" data-toggle-edit="pos-<?= $id ?>" title="Ubah"><?= Ui::icon('edit', 16) ?></button>
                  <form method="post" action="<?= BASEURL ?>/positions/delete"
                        data-confirm="Hapus jabatan &ldquo;<?= e($r['name']) ?>&rdquo;?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="at_filter" value="<?= e($appliesTo) ?>">
                    <input type="hidden" name="q_filter" value="<?= e($keyword) ?>"><input type="hidden" name="st_filter" value="<?= e($stAbbr) ?>"><input type="hidden" name="lv_filter" value="<?= e($stLevel) ?>">
                    <button type="submit" class="icon-btn" title="Hapus"><?= Ui::icon('trash', 16) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php if ($canEdit): ?>
            <tr id="pos-<?= $id ?>" hidden>
              <td colspan="8">
                <form method="post" action="<?= BASEURL ?>/positions/save">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="at_filter" value="<?= e($appliesTo) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>"><input type="hidden" name="st_filter" value="<?= e($stAbbr) ?>"><input type="hidden" name="lv_filter" value="<?= e($stLevel) ?>">
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
                        <span>Termasuk Pengurus Inti</span>
                      </label>
                      <div class="help">Berlaku bila jabatan dipegang di tingkat struktur. Di dalam unit tidak pernah dihitung inti.</div>
                    </div>
                    <div class="field">
                      <label>Jenis unit</label>
                      <?php $rowKinds = PositionModel::kindList($r['unit_kinds'] ?? ''); ?>
                      <div style="display:flex;flex-wrap:wrap;gap:2px 14px">
                        <?php foreach (PositionModel::UNIT_KINDS as $k => $kl): ?>
                          <label class="check"><input type="checkbox" name="unit_kinds[]" value="<?= e($k) ?>" <?= in_array($k, $rowKinds, true) ? 'checked' : '' ?>><span><?= e($kl) ?></span></label>
                        <?php endforeach; ?>
                      </div>
                      <div class="help">Untuk jabatan yang berlaku di unit. Kosong = semua jenis unit.</div>
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
