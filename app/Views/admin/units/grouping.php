<?php
// PATH LOKASI FILE: app/Views/admin/units/grouping.php
// Pengelompokan Unit: tiap struktur yang punya periode berjalan menjadi satu simpul <details> berisi Bidang/kelompok-nya, dan di tiap
// Bidang unit-unitnya (Departemen, Lembaga, ...). Semuanya disusun dan diubah di sini:
//   - struktur: tambah Bidang atau kelompok (Bidang, Subbidang, Pengurus Harian, Biro-Biro, Pusat-Pusat, ...)
//   - Bidang/kelompok: tambah unit (pilih dari katalog atau ketik nama baru), ubah, hapus
//   - unit: Ubah (nama tampil, urutan) / Hapus; pemilih Bidang di tiap unit disimpan sekaligus per struktur (units/grouping_save)
// Pemilih memakai atribut form="regroup-<periode>" supaya tak bersarang di formulir lain; panel Ubah dan Tambah adalah <details>.
//
// Daftarnya disaring (jenjang, status Sudah/Belum disusun, nama struktur) karena ada 500+ struktur dan hampir semuanya masih kosong.
// "Tambah unit" ada di TINGKAT STRUKTUR: satu isian nama (saran dari katalog sesayap), kelompok tujuannya otomatis menurut jenis unit
// (Biro-Biro, Badan-Badan, Pusat-Pusat), jadi orang tak perlu lebih dulu membuat kelompok dan membuka-bukanya. Formulir di dalam tiap
// kelompok tetap ada untuk menambah langsung ke kelompok itu.
//
// Pengurus Inti tampil di tiap struktur sebagai entri pertama SKUO, hanya-baca: ia penempatan tingkat struktur (tanpa unit), jadi tak
// ikut disusun atau dipindah di sini; angkanya dari $e['inti'] (UnitModel::intiCountsByTerm).
// Variabel: $entries (struktur yang TAMPIL + periode berjalan + Bidang + unit + jumlah orang inti), $without (struktur tanpa periode berjalan),
// $openTerm (id periode yang dibuka), $catalog (nama katalog beserta sayap dan jenisnya, untuk saran isian),
// $lv / $st / $q (saringan yang dipakai), $levelCounts / $statusCounts (angka chip), $totalAll (struktur berperiode), $matched (yang cocok), $maxRows.
$this->view('layouts/header', ['title' => $title]);

$canEdit = Auth::canEdit();
// Saran nama pada "Tambah unit" mengikuti SAYAP struktur: unit Muslimah dipilih dari katalog Muslimah, bukan katalog DPP (UnitModel::WINGS).
$wingOfTerm = [];
foreach ($entries as $e0) $wingOfTerm[(int) $e0['term']['id']] = $e0['structure']['wing'];
$groupRank = ['bidang' => 0, 'subbidang' => 1];
$unitKinds = UnitKinds::labels(true);   // jenis unit yang masih ditawarkan (dikelola di halaman Unit Organisasi)
$groupKinds = ['bidang' => 'Bidang', 'subbidang' => 'Subbidang (sayap Muslimah)', 'grup' => 'Kelompok lain (mis. Pengurus Harian, Biro-Biro, Pusat-Pusat)'];

// Jenis unit yang paling wajar untuk kelompok bernama ini; isian awal pada "Tambah unit".
$defaultUnitKind = function ($groupName) {
    foreach (array_diff(UnitKinds::codes(true), ['departemen', 'lembaga', UnitKinds::FALLBACK]) as $kind) if (stripos($groupName, $kind) !== false) return $kind;
    return 'departemen';
};
$fid = 0;
$nextId = function () use (&$fid) { return 'f' . (++$fid); };
// Isian tersembunyi yang dibutuhkan setiap formulir: token CSRF, periode, dan penanda kembali ke halaman ini.
// Saringan halaman (f_lv, f_st, f_q) ikut dikirim supaya sesudah menyimpan pengguna kembali ke daftar yang sama, bukan ke daftar penuh.
$ctx = function ($tid) use ($lv, $st, $q) {
    return Csrf::field() . '<input type="hidden" name="term_id" value="' . (int) $tid . '"><input type="hidden" name="return" value="grouping">'
         . '<input type="hidden" name="f_lv" value="' . e($lv) . '"><input type="hidden" name="f_st" value="' . e($st) . '"><input type="hidden" name="f_q" value="' . e($q) . '">';
};
// Alamat halaman ini dengan sebagian saringan diganti.
$url = function (array $over = []) use ($lv, $st, $q) {
    $p = array_filter(array_merge(['lv' => $lv, 'st' => $st, 'q' => $q], $over), function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/units/grouping' . ($p ? '?' . http_build_query($p) : '');
};
$options = function (array $map, $selected) {
    $h = '';
    foreach ($map as $value => $label) $h .= '<option value="' . e($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    return $h;
};

// Satu Bidang/kelompok (atau "Unit tanpa Bidang", $g = null) beserta unit-unitnya. $pick = Bidang yang bisa dipilih di tiap unit
// (kosong = tanpa pemilih), $formId = formulir simpan pengelompokan periode ini.
// $coords = calon Ketua Bidang periode ini (UnitModel::coordinatorCandidates); hanya dipakai pada Bidang/Subbidang, bukan kelompok biasa.
$bucket = function ($title, array $list, array $pick, $empty, $g, $tid, $formId, array $coords = []) use ($canEdit, $unitKinds, $groupKinds, $defaultUnitKind, $nextId, $ctx, $options, $wingOfTerm) {
    $list = Susunan::sortUnits($list);
    $hasLead = $g && $g['kind'] !== 'grup';
    $leadWord = $g && $g['kind'] === 'subbidang' ? 'Subbidang' : 'Bidang';
    ?>
    <li class="sn">
      <details>
        <summary>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main"><span class="sn-name"><?= e($title) ?></span>
            <?php if ($hasLead && !empty($g['coordinator_name'])): ?><span class="small muted"> &middot; Ketua <?= $leadWord ?>: <?= e($g['coordinator_name']) ?></span>
            <?php elseif ($hasLead): ?><span class="small muted"> &middot; Ketua <?= $leadWord ?> belum ditetapkan</span><?php endif; ?></span>
          <span class="sn-n"><?= $list ? count($list) . ' unit' : 'belum ada unit' ?></span>
        </summary>
        <div class="sn-body sn-roster">
          <?php if ($canEdit && $g): ?>
            <div class="sn-tools" style="margin:2px 10px 8px">
              <details class="sn-edit">
                <summary class="btn small secondary">Tambah unit</summary>
                <div class="sn-panel">
                  <form method="post" action="<?= BASEURL ?>/units/unit_save">
                    <?= $ctx($tid) ?>
                    <input type="hidden" name="unit_group_id" value="<?= (int) $g['id'] ?>">
                    <div class="sn-form">
                      <?php $a = $nextId(); $b = $nextId(); ?>
                      <div class="field"><label for="<?= $a ?>">Nama unit</label>
                        <input type="text" id="<?= $a ?>" name="new_name" list="katalog-unit-<?= e($wingOfTerm[(int) $tid] ?? 'umum') ?>" required maxlength="200" autocomplete="off" placeholder="Pilih dari katalog atau ketik nama baru"></div>
                      <div class="field"><label for="<?= $b ?>">Jenis unit (bila nama baru)</label>
                        <select id="<?= $b ?>" name="new_kind"><?= $options($unitKinds, $defaultUnitKind($g['name'])) ?></select></div>
                      <button type="submit" class="btn">Tambah</button>
                    </div>
                  </form>
                </div>
              </details>
              <details class="sn-edit">
                <summary class="btn small secondary"><?= $g['kind'] === 'grup' ? 'Ubah kelompok' : 'Ubah Bidang' ?></summary>
                <div class="sn-panel">
                  <form method="post" action="<?= BASEURL ?>/units/group_save">
                    <?= $ctx($tid) ?>
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <div class="sn-form">
                      <?php $a = $nextId(); $b = $nextId(); $c = $nextId(); ?>
                      <div class="field"><label for="<?= $a ?>">Nama</label>
                        <input type="text" id="<?= $a ?>" name="name" required maxlength="200" value="<?= e($g['name']) ?>"></div>
                      <div class="field"><label for="<?= $b ?>">Jenis kelompok</label>
                        <select id="<?= $b ?>" name="kind"><?= $options($groupKinds, $g['kind']) ?></select></div>
                      <div class="field"><label for="<?= $c ?>">Urutan</label>
                        <input type="number" id="<?= $c ?>" name="sort_order" min="0" max="65535" value="<?= (int) $g['sort_order'] ?>"></div>
                      <?php if ($hasLead): $d = $nextId(); ?>
                        <div class="field"><label for="<?= $d ?>">Ketua <?= $leadWord ?></label>
                          <select id="<?= $d ?>" name="coordinator_placement_id">
                            <option value="">&mdash; belum ditetapkan &mdash;</option>
                            <?php foreach ($coords as $co): ?>
                              <option value="<?= (int) $co['id'] ?>"<?= (int) ($g['coordinator_id'] ?? 0) === (int) $co['id'] ? ' selected' : '' ?>><?= e($co['full_name']) ?> (<?= e($co['position_name']) ?>)</option>
                            <?php endforeach; ?>
                          </select></div>
                      <?php endif; ?>
                      <button type="submit" class="btn">Simpan</button>
                    </div>
                  </form>
                  <?php if ((int) $g['unit_count'] > 0): ?>
                    <p class="muted small" style="margin:10px 0 0">Tidak bisa dihapus: masih menaungi <?= (int) $g['unit_count'] ?> unit. Pindahkan unitnya dulu.</p>
                  <?php else: ?>
                    <form class="inline" method="post" action="<?= BASEURL ?>/units/group_delete" style="margin-top:10px"
                          data-confirm="Hapus <?= e($g['name']) ?>?" onsubmit="return confirm(this.dataset.confirm)">
                      <?= $ctx($tid) ?>
                      <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                      <button type="submit" class="btn small danger">Hapus</button>
                    </form>
                  <?php endif; ?>
                </div>
              </details>
            </div>
          <?php endif; ?>

          <?php if (!$list): ?>
            <p class="muted small" style="margin:6px 10px"><?= e($empty) ?></p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="sn-units">
                <thead><tr><th>Unit</th><?php if ($pick): ?><th>Bidang</th><?php elseif ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($list as $u): $name = $u['display_name'] ?: $u['catalog_name']; ?>
                  <tr>
                    <td>
                      <strong><?= e($name) ?></strong>
                      <?php if (!empty($u['kind_badge'])): ?><span class="badge"><?= e($u['kind_badge']) ?></span><?php endif; ?>
                      <?php if ($u['abbreviation']): ?><span class="small muted mono">(<?= e($u['abbreviation']) ?>)</span><?php endif; ?>
                    </td>
                    <?php if ($pick || $canEdit): ?>
                      <td>
                        <div class="sn-tools">
                          <?php if ($pick): ?>
                            <div class="sn-pickwrap">
                              <select class="sn-pick" form="<?= e($formId) ?>" name="group[<?= (int) $u['id'] ?>]" aria-label="Bidang untuk <?= e($name) ?>">
                                <option value="">— tanpa Bidang —</option>
                                <?php foreach ($pick as $p): ?>
                                  <option value="<?= (int) $p['id'] ?>"<?= (int) $u['unit_group_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                          <?php endif; ?>
                          <?php if ($canEdit): ?>
                            <details class="sn-edit">
                              <summary class="btn small secondary" aria-label="Ubah <?= e($name) ?>">Ubah</summary>
                              <div class="sn-panel">
                                <form method="post" action="<?= BASEURL ?>/units/unit_save">
                                  <?= $ctx($tid) ?>
                                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                  <div class="sn-form">
                                    <?php $a = $nextId(); $b = $nextId(); ?>
                                    <div class="field"><label for="<?= $a ?>">Nama tampil</label>
                                      <input type="text" id="<?= $a ?>" name="display_name" maxlength="200" value="<?= e($u['display_name']) ?>" placeholder="<?= e($u['catalog_name']) ?>"></div>
                                    <div class="field"><label for="<?= $b ?>">Urutan</label>
                                      <input type="number" id="<?= $b ?>" name="sort_order" min="0" max="65535" value="<?= (int) $u['sort_order'] ?>"></div>
                                    <button type="submit" class="btn">Simpan</button>
                                  </div>
                                </form>
                                <?php if ((int) $u['placement_count'] > 0): ?>
                                  <p class="muted small" style="margin:10px 0 0">Tidak bisa dihapus: berisi <?= (int) $u['placement_count'] ?> penempatan pengurus.</p>
                                <?php else: ?>
                                  <form class="inline" method="post" action="<?= BASEURL ?>/units/unit_delete" style="margin-top:10px"
                                        data-confirm="Hapus <?= e($name) ?> dari periode ini?" onsubmit="return confirm(this.dataset.confirm)">
                                    <?= $ctx($tid) ?>
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn small danger">Hapus unit</button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </details>
                          <?php endif; ?>
                        </div>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </details>
    </li>
    <?php
};

$structureNode = function (array $e) use ($bucket, $canEdit, $groupRank, $openTerm, $entries, $groupKinds, $unitKinds, $nextId, $ctx, $options) {
    $s = $e['structure']; $t = $e['term']; $tid = (int) $t['id'];
    $groups = $e['groups']; $units = $e['units'];
    usort($groups, function ($a, $b) use ($groupRank) { return ($groupRank[$a['kind']] ?? 2) <=> ($groupRank[$b['kind']] ?? 2); });

    $inGroup = []; $loose = [];
    foreach ($units as $u) {
        if ($u['unit_group_id'] !== null) $inGroup[(int) $u['unit_group_id']][] = $u; else $loose[] = $u;
    }
    $nBidang = count(array_filter($groups, function ($g) { return $g['kind'] === 'bidang'; }));
    $nSub = count(array_filter($groups, function ($g) { return $g['kind'] === 'subbidang'; }));

    $meta = [];
    if ($nBidang) $meta[] = $nBidang . ' Bidang';
    if ($nSub) $meta[] = $nSub . ' Subbidang';
    $meta[] = $units ? count($units) . ' unit' : 'belum ada unit';
    if (!$nBidang && !$nSub && $units) $meta[] = 'belum ada Bidang';
    elseif ($loose) $meta[] = count($loose) . ' tanpa Bidang';

    $batch = $canEdit && $groups && $units;   // pemilih Bidang per unit + satu tombol simpan; tanpa Bidang tak ada yang bisa dipilih
    $formId = 'regroup-' . $tid;
    $pick = $batch ? $groups : [];
    ?>
    <li class="sn">
      <details id="t<?= $tid ?>"<?= ($openTerm === $tid || count($entries) === 1) ? ' open' : '' ?>>
        <summary>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main">
            <span class="sn-name"><?= e($s['level'] . ' ' . $s['name']) ?></span>
            <span class="badge"><?= e($t['name']) ?></span>
          </span>
          <span class="sn-n"><?= e(implode(' · ', $meta)) ?></span>
        </summary>

        <div class="sn-body">
          <p class="small" style="margin:0 0 10px">
            <?= Ui::icon('users', 15) ?> <strong>Pengurus Inti</strong> <span class="badge">bawaan sistem</span>
            <span class="muted">&middot; <?= $e['inti'] > 0 ? Ui::n($e['inti']) . ' orang' : 'belum ada pengurus inti' ?>
              &middot; entri pertama SKUO; tanpa unit, jadi tak diatur di sini</span>
            &middot; <a href="<?= BASEURL ?>/terms/detail/<?= $tid ?>">Lihat susunan</a>
          </p>
          <div class="sn-actions">
            <?php if ($canEdit): $wingKey = $s['wing'] === 'muslimah' ? 'muslimah' : 'umum'; ?>
              <details class="sn-edit">
                <summary class="btn small">Tambah unit</summary>
                <div class="sn-panel">
                  <form method="post" action="<?= BASEURL ?>/units/unit_save" data-unit-add data-wing-label="<?= e(UnitModel::WINGS[$wingKey]) ?>">
                    <?= $ctx($tid) ?>
                    <div class="sn-form">
                      <?php $a = $nextId(); $b = $nextId(); $c = $nextId(); ?>
                      <div class="field"><label for="<?= $a ?>">Nama unit</label>
                        <input type="text" id="<?= $a ?>" name="new_name" list="katalog-unit-<?= e($wingKey) ?>" required maxlength="200" autocomplete="off" placeholder="mis. Badan Anggaran"></div>
                      <div class="field" data-kind-field><label for="<?= $b ?>">Jenis unit (nama baru)</label>
                        <select id="<?= $b ?>" name="new_kind"><?= $options($unitKinds, 'departemen') ?></select></div>
                      <div class="field"><label for="<?= $c ?>">Masuk ke</label>
                        <select id="<?= $c ?>" name="masuk">
                          <option value="auto">Otomatis menurut jenis unit</option>
                          <option value="none">Tanpa Bidang</option>
                          <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
                        </select></div>
                      <button type="submit" class="btn">Tambah</button>
                    </div>
                    <p class="help" data-name-hint style="margin:8px 0 0">Pilih dari katalog <?= e(UnitModel::WINGS[$wingKey]) ?>, atau ketik nama baru.</p>
                    <p class="help" data-masuk-hint style="margin:2px 0 0">Biro, Badan, dan Pusat masuk otomatis ke kelompok Biro-Biro / Badan-Badan / Pusat-Pusat (dibuat bila belum ada). Jenis lain: pilih Bidangnya.</p>
                  </form>
                </div>
              </details>
              <details class="sn-edit">
                <summary class="btn small secondary">Tambah Bidang atau kelompok</summary>
                <div class="sn-panel">
                  <form method="post" action="<?= BASEURL ?>/units/group_save">
                    <?= $ctx($tid) ?>
                    <div class="sn-form">
                      <?php $a = $nextId(); $b = $nextId(); ?>
                      <div class="field"><label for="<?= $a ?>">Nama</label>
                        <input type="text" id="<?= $a ?>" name="name" required maxlength="200" placeholder="mis. Bidang Pendidikan, Pengurus Harian"></div>
                      <div class="field"><label for="<?= $b ?>">Jenis kelompok</label>
                        <select id="<?= $b ?>" name="kind"><?= $options($groupKinds, $s['wing'] === 'muslimah' ? 'subbidang' : 'bidang') ?></select></div>
                      <button type="submit" class="btn">Tambah</button>
                    </div>
                  </form>
                  <p class="help" style="margin:8px 0 0">Ini untuk Bidang atau kelompok baru. Biro-Biro, Badan-Badan, dan Pusat-Pusat tidak perlu dibuat di sini: dibuat otomatis saat unit jenis itu ditambahkan.</p>
                </div>
              </details>
            <?php endif; ?>
            <a class="btn small secondary" href="<?= BASEURL ?>/terms/detail/<?= $tid ?>">Susunan Pengurus</a>
          </div>
          <?php if (!$groups): ?>
            <p class="muted small" style="margin:0">
              Belum ada Bidang di periode ini.<?php if ($canEdit): ?> Tambahkan lewat tombol di atas, lalu isi dengan unit.<?php endif; ?>
            </p>
          <?php endif; ?>
        </div>

        <?php if ($groups || $loose): ?>
          <ul>
            <?php foreach ($groups as $g) $bucket($g['name'], $inGroup[(int) $g['id']] ?? [], $pick, 'Belum ada unit di sini.', $g, $tid, $formId, $e['coordinators'] ?? []); ?>
            <?php if ($loose) $bucket('Unit tanpa Bidang', $loose, $pick, '', null, $tid, $formId); ?>
          </ul>
        <?php endif; ?>
        <?php if ($batch): ?>
          <form id="<?= e($formId) ?>" method="post" action="<?= BASEURL ?>/units/grouping_save">
            <?= $ctx($tid) ?>
          </form>
          <div class="sn-save"><button type="submit" form="<?= e($formId) ?>" class="btn">Simpan pengelompokan</button></div>
        <?php endif; ?>
      </details>
    </li>
    <?php
};
?>

<div class="page-head">
  <h1>Pengelompokan Unit</h1>
  <span class="badge"><?= Ui::n($totalAll) ?> struktur</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/units/catalog">Unit Organisasi</a>
  </div>
</div>

<?php if ($totalAll > 0): ?>
<?php $chipGroups = PeopleOverview::chipGroups($levelCounts); ?>
<div class="mon-strip" role="group" aria-label="Saring menurut jenjang">
  <span class="mon-strip-l">Jenjang</span>
  <a class="chip<?= $lv === '' ? ' on' : '' ?>" href="<?= e($url(['lv' => ''])) ?>"<?= $lv === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n(array_sum($levelCounts)) ?></span></a>
  <?php foreach ($chipGroups as $wing => $list): ?>
    <span class="mon-sep" aria-hidden="true"></span>
    <?php foreach ($list as $level): $on = $lv === $level; ?>
      <a class="chip<?= $on ? ' on' : '' ?>" href="<?= e($url(['lv' => $on ? '' : $level])) ?>"<?= $on ? ' aria-current="true"' : '' ?>
         title="<?= e(PeopleOverview::levelName($level)) ?>"><?= e($level) ?> <span class="chip-n"><?= Ui::n($levelCounts[$level]) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<div class="toolbar" style="margin-top:10px">
  <form class="q" method="get" action="<?= BASEURL ?>/units/grouping" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($q) ?>" aria-label="Cari struktur" placeholder="Cari nama struktur, mis. MWP atau Kab. Barru">
    <?php if ($lv !== ''): ?><input type="hidden" name="lv" value="<?= e($lv) ?>"><?php endif; ?>
    <input type="hidden" name="st" value="<?= e($st) ?>">
  </form>
  <div class="chips" role="group" aria-label="Saring menurut status">
    <?php foreach (UnitsController::GROUPING_STATUS as $key => $label): $on = $st === $key; ?>
      <a class="chip<?= $on ? ' on' : '' ?>" href="<?= e($url(['st' => $key])) ?>"<?= $on ? ' aria-current="true"' : '' ?>
         title="<?= $key === 'belum' ? 'Belum punya Bidang maupun unit di periode berjalan' : ($key === 'disusun' ? 'Sudah punya Bidang atau unit di periode berjalan' : '') ?>"><?= e($label) ?> <span class="chip-n"><?= Ui::n($statusCounts[$key]) ?></span></a>
    <?php endforeach; ?>
  </div>
  <?php if ($entries): ?>
    <span class="mon-bulk">
      <button type="button" class="btn small secondary" data-tree="pengelompokan" data-tree-all="open">Buka semua</button>
      <button type="button" class="btn small secondary" data-tree="pengelompokan" data-tree-all="close">Tutup semua</button>
    </span>
  <?php endif; ?>
</div>

<p class="mon-ctx">
  <span>Menampilkan <b><?= Ui::n(count($entries)) ?></b> dari <?= Ui::n($matched) ?> struktur<?= $lv !== '' ? ' ' . e($lv) : '' ?><?= $q !== '' ? ' &middot; pencarian &ldquo;' . e($q) . '&rdquo;' : '' ?>
    <?php if ($matched > count($entries)): ?> &middot; persempit dengan kolom cari atau pilih jenjang<?php endif; ?></span>
  <?php if ($lv !== '' || $q !== ''): ?><a href="<?= e($url(['lv' => '', 'q' => ''])) ?>">Hapus penyaringan</a><?php endif; ?>
</p>
<?php endif; ?>

<div class="card">
  <?php if (!$entries && !$without): ?>
    <div class="empty">
      <span><?= $totalAll === 0 ? 'Belum ada struktur dalam lingkup Anda.' : 'Tidak ada struktur yang cocok dengan penyaringan ini.' ?></span>
      <?php if ($totalAll > 0 && $st === 'disusun' && $statusCounts['belum'] > 0): ?>
        <a class="btn small secondary" href="<?= e($url(['st' => 'belum'])) ?>">Lihat yang belum disusun (<?= Ui::n($statusCounts['belum']) ?>)</a>
      <?php elseif ($totalAll > 0): ?>
        <a class="btn small secondary" href="<?= e($url(['lv' => '', 'q' => '', 'st' => 'semua'])) ?>">Hapus penyaringan</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <ul class="stree" id="pengelompokan">
      <?php foreach ($entries as $e) $structureNode($e); ?>

      <?php if ($without): ?>
        <li class="sn">
          <details<?= $entries ? '' : ' open' ?>>
            <summary>
              <?= Ui::icon('chevron-right', 16, 'caret') ?>
              <span class="sn-main"><span class="sn-name">Belum punya periode berjalan</span></span>
              <span class="sn-n"><?= count($without) ?> struktur</span>
            </summary>
            <div class="sn-body sn-roster">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Struktur</th><th>Tingkat</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                  <tbody>
                  <?php foreach ($without as $s): ?>
                    <tr>
                      <td><strong><?= e($s['level'] . ' ' . $s['name']) ?></strong></td>
                      <td class="small"><?= e(Ui::tierName($s['tier'])) ?></td>
                      <?php if ($canEdit): ?>
                        <td class="nowrap"><a class="btn small secondary" href="<?= BASEURL ?>/terms/form/0/<?= (int) $s['id'] ?>">Buat periode</a></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </details>
        </li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($canEdit && $catalog): ?>
  <?php foreach (array_keys(UnitModel::WINGS) as $w): ?>
    <datalist id="katalog-unit-<?= $w ?>">
      <?php foreach ($catalog as $c): if ($c['wing'] !== $w) continue; ?><option value="<?= e($c['name']) ?>" data-kind="<?= e($c['kind']) ?>"><?php endforeach; ?>
    </datalist>
  <?php endforeach; ?>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
