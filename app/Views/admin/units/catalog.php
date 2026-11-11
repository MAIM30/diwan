<?php
// PATH LOKASI FILE: app/Views/admin/units/catalog.php
// Master Data > Unit Organisasi: katalog nama unit yang dipakai bersama lintas struktur SESAYAP. Katalog dipisah per SAYAP (Umum:
// DPP, DPW, DPD, ...; Muslimah: MWP, MWW, MWD, ...), karena susunan unit Muslimah memang berbeda dengan DPP.
//
// Tata letaknya mengikuti pola Data Pengurus (kartu rekap sebagai saringan, lalu tabel), karena
// pertanyaan adminnya sama bentuknya: "ada berapa, jenisnya apa, mana yang perlu dibenahi".
// Tanpa saringan, katalog dipecah menjadi satu kartu per JENIS -- itulah cara orang menyebutnya
// ("Pusat-pusat", "Biro-biro"), dan 105 baris datar tidak bisa dibaca begitu.
//
// Kolom "Dipakai" adalah inti halaman ini: ia menjawab apakah satu entri katalog masih hidup,
// satu-satunya hal yang tidak bisa dilihat dari daftar nama.
//
// Dari controller: $rows (sudah tersaring, berisi used_count/used_structures/head_name),
// $counts (jumlah per jenis pada sayap yang dipilih, TIDAK tersaring menurut jenis/pencarian), $wing ('' | umum | muslimah), $wingCounts, $kind, $keyword,
// $urut (true = satu daftar menurut urutan resmi SKUO, bukan per jenis), $inti (UnitModel::intiInfo), $nextOrder (nomor urut berikutnya),
// $baru (entri yang baru ditambahkan dan belum terpasang di mana pun, atau null: halaman menawarkan "Pasang ke struktur"),
// $targets (UnitModel::installTargets, untuk pilihan struktur pada "Pasang").
//
// PENGURUS INTI bukan baris katalog: ia penempatan tingkat struktur (tanpa unit), jadi hanya DITAMPILKAN di sini sebagai entri
// bawaan sistem. Jabatan yang inti diatur di Jabatan (/positions), bukan di sini.

$this->view('layouts/header', ['title' => $title]);

// Jenis unit adalah daftar yang dikelola (UnitKinds; kartu "Jenis unit" di bawah halaman ini), bukan deretan tetap di tampilan.
$kinds = UnitKinds::labels();
$kindsActive = UnitKinds::labels(true);
// Ikon + warna per jenis, tetap sama di kartu dan di lencana tabel supaya mata bisa
// menghubungkan keduanya tanpa membaca labelnya.
$kindIcon = ['departemen' => 'grid', 'lembaga' => 'briefcase', 'biro' => 'layers',
             'badan' => 'shield', 'pusat' => 'network', 'divisi' => 'activity', 'komisi' => 'users', 'belum' => 'circle'];   // jenis baru: 'circle'
$kindTone = ['departemen' => 't-teal', 'lembaga' => 't-green', 'biro' => 't-violet',
             'badan' => 't-amber', 'pusat' => 't-rose', 'divisi' => 't-teal', 'komisi' => 't-green', 'belum' => ''];

$total = array_sum($counts);
$canEdit = Auth::canEdit();
$wings = UnitModel::WINGS;

$qs = function (array $over = []) use ($kind, $keyword, $urut, $wing) {
    $p = array_merge(['w' => $wing, 'kind' => $kind, 'q' => $keyword, 'urut' => $urut ? 'skuo' : ''], $over);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return BASEURL . '/units/catalog' . ($p ? '?' . http_build_query($p) : '');
};
$rc = function ($label, $count, $icon, $active, $href, $tone = '') {
    $cls = 'stat' . ($tone ? ' ' . $tone : '') . ($active ? ' active' : '');
    return '<a class="' . $cls . '" href="' . e($href) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . '<div class="stat-top"><span class="chip-ic">' . Ui::icon($icon, 18) . '</span>'
        . '<span class="lbl">' . e($label) . '</span></div>'
        . '<div class="num">' . Ui::n($count) . '</div></a>';
};

// Tanpa saringan jenis, baris dipecah per jenis menjadi beberapa kartu. Dengan saringan (atau
// saat mencari), satu kartu saja -- memecah hasil pencarian per jenis justru menyembunyikannya.
$grouped = ($kind === '' && $keyword === '' && !$urut);
$sections = [];
if ($grouped) {
    foreach ($rows as $r) $sections[$r['kind']][] = $r;
    $sections = array_filter(array_merge(array_fill_keys(array_keys($kinds), []), $sections));
} else {
    $sections[''] = $rows;
}
?>

<div class="page-head">
  <h1>Unit Organisasi</h1>
  <span class="badge"><?= Ui::n($total) ?> unit</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/units/grouping">
      <?= Ui::icon('layers', 17) ?> Pengelompokan Unit
    </a>
  </div>
</div>

<?php if ($canEdit && !empty($baru)): ?>
<div class="card pasang-callout">
  <h2>Pasang &ldquo;<?= e($baru['name']) ?>&rdquo; ke struktur
    <span class="badge<?= $baru['wing'] === 'muslimah' ? ' ok' : '' ?>">sayap <?= e($wings[$baru['wing']] ?? $baru['wing']) ?></span></h2>
  <p style="margin:0 0 10px">Unit ini sudah masuk katalog, tetapi <strong>belum muncul di struktur mana pun</strong> (Data Pengurus, Pengelompokan Unit) sampai dipasang ke sebuah struktur.</p>
  <?php $this->view('admin/units/_pasang', ['u' => $baru, 'targets' => $targets, 'idp' => 'baru']); ?>
</div>
<?php endif; ?>

<div class="note">
  Katalog ini <strong>dipakai bersama</strong> lintas struktur <strong>sesayap</strong>: &ldquo;Departemen Kesehatan&rdquo;
  cukup ada satu baris di sayap Umum, lalu dipakai DPP maupun DPW. Sayap <strong>Muslimah</strong> (MWP, MWW, MWD, ...) punya katalog
  sendiri karena susunan unitnya berbeda; nama yang sama boleh ada di kedua sayap, sebagai dua entri.
  <strong>Bidang tidak ada di sini</strong> &mdash; ia bukan jenis unit, melainkan wadah yang hanya
  hidup di dalam satu struktur pada satu periode, jadi tempatnya di
  <a href="<?= BASEURL ?>/units/grouping">Pengelompokan Unit</a>.
</div>

<?php
$intiNames = array_column($inti['positions'], 'name');
?>
<div class="card">
  <h2><?= Ui::icon('users', 19) ?> PENGURUS INTI <span class="badge">bawaan sistem</span>
    <span class="hint">&mdash; urutan pertama SKUO</span></h2>
  <p style="margin:0 0 8px">
    Wadah pimpinan tingkat struktur: <?= e(implode(', ', $intiNames)) ?>.
    <strong>Ketua dan Wakil Ketua</strong> di sini adalah pemimpin Bidang
    (atur di <a href="<?= BASEURL ?>/units/grouping">Pengelompokan Unit</a> &rarr; Ubah Bidang &rarr; Ketua Bidang).
  </p>
  <p class="small muted" style="margin:0">
    Dipakai <?= (int) $inti['placements'] ?> penempatan di <?= (int) $inti['structures'] ?> struktur pada periode berjalan.
    Dipilih sebagai &ldquo;Pengurus Inti&rdquo; (tanpa unit) di formulir penempatan; daftar jabatan inti diatur di
    <a href="<?= BASEURL ?>/positions">Jabatan</a>, jadi tidak bisa diubah atau dihapus dari sini.
  </p>
</div>

<div class="chips" role="group" aria-label="Saring menurut sayap" style="margin-bottom:12px;align-items:center">
  <span class="small muted">Sayap:</span>
  <a class="chip<?= $wing === '' ? ' on' : '' ?>" href="<?= e($qs(['w' => ''])) ?>"<?= $wing === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n(array_sum($wingCounts)) ?></span></a>
  <?php foreach ($wings as $wk => $wl): ?>
    <a class="chip<?= $wing === $wk ? ' on' : '' ?>" href="<?= e($qs(['w' => $wing === $wk ? '' : $wk])) ?>"<?= $wing === $wk ? ' aria-current="true"' : '' ?>><?= e($wl) ?> <span class="chip-n"><?= Ui::n((int) ($wingCounts[$wk] ?? 0)) ?></span></a>
  <?php endforeach; ?>
</div>

<div class="stats recap">
  <?php foreach ($kinds as $k => $label): ?>
    <?= $rc($label, (int) ($counts[$k] ?? 0), $kindIcon[$k] ?? 'circle', $kind === $k,
            $qs(['kind' => $kind === $k ? '' : $k]), $kindTone[$k] ?? '') ?>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/units/catalog" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari unit"
           placeholder="Cari nama atau singkatan unit">
    <?php if ($kind): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
    <?php if ($wing): ?><input type="hidden" name="w" value="<?= e($wing) ?>"><?php endif; ?>
    <?php if ($urut): ?><input type="hidden" name="urut" value="skuo"><?php endif; ?>
  </form>
  <?php if ($kind !== '' || $keyword !== ''): ?>
    <a class="btn small secondary" href="<?= e($qs(['kind' => '', 'q' => ''])) ?>">Tampilkan semua</a>
  <?php endif; ?>
  <a class="btn small secondary" href="<?= e($qs(['urut' => $urut ? '' : 'skuo'])) ?>" title="Tampilkan sebagai satu daftar menurut urutan resmi SKUO">
    <?= Ui::icon('layers', 15) ?> <?= $urut ? 'Per jenis' : 'Urutan SKUO' ?>
  </a>
  <label class="per-page">
    <select data-per-page="unit-table">
      <?php foreach ([10, 25, 50, 100] as $n): ?>
        <option value="<?= $n ?>" <?= $n === 25 ? 'selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
    <span>per halaman</span>
  </label>
</div>

<?php if ($canEdit): ?>
<div class="card">
  <h2>Tambah Unit ke Katalog</h2>
  <form method="post" action="<?= BASEURL ?>/units/catalog_save">
    <?= Csrf::field() ?>
    <input type="hidden" name="kind_filter" value="<?= e($kind) ?>">
    <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
    <input type="hidden" name="w_filter" value="<?= e($wing) ?>">
    <input type="hidden" name="urut_filter" value="<?= $urut ? 'skuo' : '' ?>">
    <div class="grid-3">
      <div class="field">
        <label for="name">Nama Unit</label>
        <input type="text" id="name" name="name" required placeholder="mis. Departemen Kesehatan">
      </div>
      <div class="field">
        <label for="abbreviation">Singkatan</label>
        <input type="text" id="abbreviation" name="abbreviation" placeholder="mis. Depkes">
      </div>
      <div class="field">
        <label for="new_kind">Jenis</label>
        <select id="new_kind" name="kind">
          <?php foreach ($kindsActive as $k => $label): ?>
            <option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Jenis menentukan istilah kepala unitnya: Pusat &rarr; &ldquo;Kepala&rdquo;,
          Divisi &rarr; &ldquo;Koordinator&rdquo;, Departemen / Lembaga / Biro / Badan &rarr; &ldquo;Ketua&rdquo; menurut jenisnya
          (mis. &ldquo;Ketua Biro&rdquo;), sisanya &rarr; &ldquo;Ketua&rdquo;. Jenis tidak bisa diubah selama penempatan di unit ini
          berjabatan khusus jenis lain.</div>
      </div>
    </div>
    <div class="grid-3">
      <div class="field">
        <label for="new_wing">Sayap</label>
        <select id="new_wing" name="wing" required>
          <?php if ($wing === ''): ?><option value="">&mdash; pilih sayap &mdash;</option><?php endif; ?>
          <?php foreach ($wings as $wk => $wl): ?>
            <option value="<?= $wk ?>" <?= $wing === $wk ? 'selected' : '' ?>><?= e($wl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Umum = DPP, DPW, DPD, ...; Muslimah = MWP, MWW, MWD, ... Unit hanya bisa dipasang pada struktur sesayap.</div>
      </div>
      <div class="field">
        <label for="new_sort">Urutan SKUO</label>
        <input type="number" id="new_sort" name="sort_order" min="0" max="65535" placeholder="<?= (int) $nextOrder ?> (urutan terakhir)">
        <div class="help">Angka kecil tampil lebih dulu. Kosongkan untuk menaruhnya di urutan terakhir; pakai angka di antara
          dua unit (mis. 25 di antara 20 dan 30) untuk menyisipkan.</div>
      </div>
    </div>
    <input type="hidden" name="is_active" value="1">
    <button type="submit" class="btn">Tambah</button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted" style="margin:0">
    <?= $keyword !== '' ? 'Tidak ada unit yang cocok dengan pencarian itu.' : ($wing !== '' ? 'Katalog sayap ' . $wings[$wing] . ' masih kosong.' : 'Katalog masih kosong.') ?>
  </p></div>
<?php endif; ?>

<?php foreach ($sections as $sk => $list): ?>
  <div class="card">
    <h2>
      <?php if ($sk !== ''): ?>
        <?= Ui::icon($kindIcon[$sk] ?? 'circle', 19) ?> <?= e($kinds[$sk] ?? $sk) ?>
        <span class="hint">&mdash; <?= count($list) ?> unit</span>
      <?php else: ?>
        <?= count($list) ?> unit
        <?php if ($keyword !== ''): ?><span class="hint">&mdash; hasil pencarian &ldquo;<?= e($keyword) ?>&rdquo;</span><?php endif; ?>
      <?php endif; ?>
    </h2>
    <div class="table-wrap">
      <table id="unit-table">
        <thead>
          <tr>
            <th class="num" title="Urutan resmi SKUO; angka kecil tampil lebih dulu">Urutan</th>
            <th>Nama</th>
            <th>Singkatan</th>
            <?php if ($sk === ''): ?><th>Jenis</th><?php endif; ?>
            <?php if ($wing === ''): ?><th>Sayap</th><?php endif; ?>
            <th>Kepala Unit</th>
            <th class="num">Dipakai</th>
            <th>Status</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $r): $id = (int) $r['id']; $used = (int) $r['used_count']; ?>
          <tr>
            <td class="num small mono"><?= (int) $r['sort_order'] === 500 ? '<span class="muted" title="Belum diurutkan">&mdash;</span>' : (int) $r['sort_order'] ?></td>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="small mono"><?= e($r['abbreviation'] ?: '—') ?></td>
            <?php if ($sk === ''): ?>
              <td><span class="badge"><?= e($kinds[$r['kind']] ?? $r['kind']) ?></span></td>
            <?php endif; ?>
            <?php if ($wing === ''): ?>
              <td><span class="badge<?= $r['wing'] === 'muslimah' ? ' ok' : '' ?>"><?= e($wings[$r['wing']] ?? $r['wing']) ?></span></td>
            <?php endif; ?>
            <td class="small"><?= e($r['head_name'] ?: '—') ?></td>
            <td class="num">
              <?php if ($used === 0): ?>
                <span class="badge" title="Belum dipakai periode mana pun">belum</span>
              <?php else: ?>
                <strong><?= $used ?></strong>
                <span class="small muted">di <?= (int) $r['used_structures'] ?> struktur</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['is_active']): ?><span class="badge ok">aktif</span>
              <?php else: ?><span class="badge">nonaktif</span><?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
              <td class="nowrap">
                <?php if ($used === 0): ?>
                  <button type="button" class="btn small" data-toggle-edit="pasang-<?= $id ?>" title="Pasang ke struktur">Pasang</button>
                <?php endif; ?>
                <button type="button" class="icon-btn" data-toggle-edit="unit-<?= $id ?>" title="Ubah"><?= Ui::icon('edit', 16) ?></button>
                <form method="post" action="<?= BASEURL ?>/units/catalog_delete" style="display:inline"
                      data-confirm="Hapus unit &ldquo;<?= e($r['name']) ?>&rdquo; dari katalog?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="kind_filter" value="<?= e($kind) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
                  <input type="hidden" name="w_filter" value="<?= e($wing) ?>">
                  <input type="hidden" name="urut_filter" value="<?= $urut ? 'skuo' : '' ?>">
                  <button type="submit" class="icon-btn" title="Hapus"><?= Ui::icon('trash', 16) ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
          <?php if ($canEdit && $used === 0): ?>
            <tr id="pasang-<?= $id ?>" hidden>
              <td colspan="<?= ($sk === '' ? 8 : 7) + ($wing === '' ? 1 : 0) ?>">
                <?php $this->view('admin/units/_pasang', ['u' => $r, 'targets' => $targets, 'idp' => 'ps' . $id]); ?>
              </td>
            </tr>
          <?php endif; ?>
          <?php if ($canEdit): ?>
            <tr id="unit-<?= $id ?>" hidden>
              <td colspan="<?= ($sk === '' ? 8 : 7) + ($wing === '' ? 1 : 0) ?>">
                <form method="post" action="<?= BASEURL ?>/units/catalog_save">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="kind_filter" value="<?= e($kind) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
                  <input type="hidden" name="w_filter" value="<?= e($wing) ?>">
                  <input type="hidden" name="urut_filter" value="<?= $urut ? 'skuo' : '' ?>">
                  <div class="grid-3">
                    <div class="field">
                      <label for="n<?= $id ?>">Nama Unit</label>
                      <input type="text" id="n<?= $id ?>" name="name" required maxlength="200" value="<?= e($r['name']) ?>">
                    </div>
                    <div class="field">
                      <label for="a<?= $id ?>">Singkatan</label>
                      <input type="text" id="a<?= $id ?>" name="abbreviation" maxlength="50" value="<?= e($r['abbreviation']) ?>">
                    </div>
                    <div class="field">
                      <label for="k<?= $id ?>">Jenis</label>
                      <select id="k<?= $id ?>" name="kind">
                        <?php foreach ($kinds as $k => $label): if (!isset($kindsActive[$k]) && $r['kind'] !== $k) continue; ?>
                          <option value="<?= $k ?>" <?= $r['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="help">Mengubah jenis ikut mengubah istilah kepala unitnya.</div>
                    </div>
                    <div class="field">
                      <label for="w<?= $id ?>">Sayap</label>
                      <select id="w<?= $id ?>" name="wing">
                        <?php foreach ($wings as $wk => $wl): ?>
                          <option value="<?= $wk ?>" <?= $r['wing'] === $wk ? 'selected' : '' ?>><?= e($wl) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="help">Tak bisa diubah selama unit ini dipakai di struktur sayap lain.</div>
                    </div>
                    <div class="field">
                      <label for="o<?= $id ?>">Urutan SKUO</label>
                      <input type="number" id="o<?= $id ?>" name="sort_order" min="0" max="65535" value="<?= (int) $r['sort_order'] ?>">
                      <div class="help">Berlaku sebagai bawaan unit yang ditambahkan ke periode baru; unit di periode lama tidak berubah.</div>
                    </div>
                  </div>
                  <label class="check">
                    <input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>>
                    <span>Aktif &mdash; unit nonaktif tidak disarankan lagi saat menambah unit ke periode baru</span>
                  </label>
                  <?php if ($used > 0): ?>
                    <p class="small muted" style="margin:10px 0 0">
                      Dipakai <?= $used ?> kali di <?= (int) $r['used_structures'] ?> struktur. Mengubah
                      namanya di sini ikut mengubah tampilannya di semua periode itu.
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
    <div class="pager" data-pager-for="unit-table"></div>
  </div>
<?php endforeach; ?>

<?php
// ---------- Jenis unit: daftar yang dikelola (UnitKinds) ----------
$kindAdmin = Auth::isSuperAdmin();
?>
<div class="card" id="jenis-unit">
  <h2><?= Ui::icon('layers', 19) ?> Jenis unit <span class="hint">&mdash; Departemen, Lembaga, Biro, dan seterusnya</span></h2>
  <p class="small muted" style="margin:0 0 12px">
    Daftar ini dipakai pada pilihan <strong>Jenis</strong> di atas, pada batas jenis unit di halaman <a href="<?= BASEURL ?>/positions">Jabatan</a>,
    dan sebagai kode <code>kind</code> di API. Nama, urutan, dan kelompok bawaan boleh diubah kapan saja; <strong>kodenya tetap</strong>.
    Jenis yang masih dipakai unit atau jabatan tidak bisa dihapus: ubah dulu jenis unitnya, atau nonaktifkan bila hanya tak ingin ditawarkan lagi.
    &ldquo;Belum ditentukan&rdquo; adalah penampung bawaan sistem untuk unit yang jenisnya belum ditetapkan.
    <?php if (!$kindAdmin): ?>Hanya super admin yang dapat mengubah daftar ini.<?php endif; ?>
  </p>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th class="num">Urut</th><th>Nama</th><th>Kode</th><th>Kelompok bawaan</th><th class="num">Unit</th><th class="num">Jabatan</th><th>Status</th><?php if ($kindAdmin): ?><th></th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($kindRows as $kr): $kid = 'kd-' . $kr['code']; $kUsed = (int) $kr['units'] + (int) $kr['positions']; ?>
        <tr>
          <td class="num small"><?= (int) $kr['sort_order'] ?></td>
          <td>
            <strong><?= e($kr['label']) ?></strong>
            <?php if (!empty($kr['is_system'])): ?><span class="badge">bawaan sistem</span><?php endif; ?>
          </td>
          <td class="small mono"><?= e($kr['code']) ?></td>
          <td class="small"><?= $kr['group_name'] !== null && $kr['group_name'] !== '' ? e($kr['group_name']) : '<span class="muted">&mdash;</span>' ?></td>
          <td class="num"><?php if ($kr['units'] > 0): ?><a href="<?= e($qs(['kind' => $kr['code']])) ?>"><?= Ui::n($kr['units']) ?></a><?php else: ?><span class="muted">0</span><?php endif; ?></td>
          <td class="num"><?= $kr['positions'] > 0 ? Ui::n($kr['positions']) : '<span class="muted">0</span>' ?></td>
          <td><?= !empty($kr['is_active']) ? '<span class="badge ok">aktif</span>' : '<span class="badge ended">nonaktif</span>' ?></td>
          <?php if ($kindAdmin): ?>
          <td class="nowrap">
            <details class="sn-edit" style="display:inline-block">
              <summary class="btn small secondary" aria-label="Ubah jenis <?= e($kr['label']) ?>">Ubah</summary>
              <div class="sn-panel">
                <form method="post" action="<?= BASEURL ?>/units/kind_save">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="code" value="<?= e($kr['code']) ?>">
                  <div class="sn-form">
                    <div class="field"><label for="<?= $kid ?>-l">Nama</label>
                      <input type="text" id="<?= $kid ?>-l" name="label" required maxlength="60" value="<?= e($kr['label']) ?>"></div>
                    <div class="field"><label for="<?= $kid ?>-s">Urutan</label>
                      <input type="number" id="<?= $kid ?>-s" name="sort_order" min="0" max="65535" value="<?= (int) $kr['sort_order'] ?>"></div>
                    <div class="field"><label for="<?= $kid ?>-g">Kelompok bawaan</label>
                      <input type="text" id="<?= $kid ?>-g" name="group_name" maxlength="100" value="<?= e((string) $kr['group_name']) ?>" placeholder="mis. Biro-Biro"></div>
                    <div class="field"><label for="<?= $kid ?>-o">Urutan kelompok</label>
                      <input type="number" id="<?= $kid ?>-o" name="group_sort" min="0" max="65535" value="<?= $kr['group_sort'] !== null ? (int) $kr['group_sort'] : '' ?>" placeholder="500"></div>
                  </div>
                  <?php if (empty($kr['is_system'])): ?>
                    <label class="check"><input type="checkbox" name="is_active" value="1" <?= !empty($kr['is_active']) ? 'checked' : '' ?>><span>Aktif &mdash; jenis nonaktif tidak ditawarkan untuk unit atau jabatan baru</span></label>
                  <?php endif; ?>
                  <p class="help" style="margin:8px 0 0">Kelompok bawaan: unit baru berjenis ini (dengan pilihan &ldquo;Otomatis menurut jenis unit&rdquo;) masuk ke kelompok itu, dibuat bila periodenya belum punya. Kosongkan bila jenis ini hidup di dalam Bidang.</p>
                  <button type="submit" class="btn" style="margin-top:10px">Simpan</button>
                </form>
              </div>
            </details>
            <?php if (empty($kr['is_system'])): ?>
              <form class="inline" method="post" action="<?= BASEURL ?>/units/kind_delete" onsubmit="return confirm(this.dataset.confirm)"
                    data-confirm="Hapus jenis unit &quot;<?= e($kr['label']) ?>&quot;? Tidak bisa dibatalkan.">
                <?= Csrf::field() ?>
                <input type="hidden" name="code" value="<?= e($kr['code']) ?>">
                <button type="submit" class="btn small secondary" <?= $kUsed > 0 ? 'disabled title="Masih dipakai: ' . (int) $kr['units'] . ' unit, ' . (int) $kr['positions'] . ' jabatan"' : '' ?>>Hapus</button>
              </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($kindAdmin): ?>
    <details class="sn-edit" style="margin-top:12px">
      <summary class="btn small">Tambah jenis unit</summary>
      <div class="sn-panel">
        <form method="post" action="<?= BASEURL ?>/units/kind_save">
          <?= Csrf::field() ?>
          <div class="sn-form">
            <div class="field"><label for="kd-new-l">Nama jenis</label>
              <input type="text" id="kd-new-l" name="label" required maxlength="60" placeholder="mis. Satuan"></div>
            <div class="field"><label for="kd-new-s">Urutan</label>
              <input type="number" id="kd-new-s" name="sort_order" min="0" max="65535" placeholder="otomatis"></div>
            <div class="field"><label for="kd-new-g">Kelompok bawaan</label>
              <input type="text" id="kd-new-g" name="group_name" maxlength="100" placeholder="opsional, mis. Satuan-Satuan"></div>
          </div>
          <p class="help" style="margin:8px 0 0">Kodenya dibuat dari nama dan tidak berubah lagi. Sesudah dibuat, jenis ini langsung bisa dipilih untuk unit dan sebagai batas jenis unit pada jabatan.</p>
          <button type="submit" class="btn" style="margin-top:10px">Tambah</button>
        </form>
      </div>
    </details>
  <?php endif; ?>
</div>
<?php $this->view('layouts/footer'); ?>
