<?php
// PATH LOKASI FILE: app/Views/admin/units/catalog.php
// Master Data > Unit Organisasi: katalog nama unit yang dipakai BERSAMA lintas struktur.
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
// $counts (jumlah per jenis, TIDAK tersaring), $kind, $keyword.

$this->view('layouts/header', ['title' => $title]);

$kinds = ['departemen' => 'Departemen', 'lembaga' => 'Lembaga', 'biro' => 'Biro',
          'badan' => 'Badan', 'pusat' => 'Pusat', 'divisi' => 'Divisi', 'lainnya' => 'Lainnya'];
// Ikon + warna per jenis, tetap sama di kartu dan di lencana tabel supaya mata bisa
// menghubungkan keduanya tanpa membaca labelnya.
$kindIcon = ['departemen' => 'grid', 'lembaga' => 'briefcase', 'biro' => 'layers',
             'badan' => 'shield', 'pusat' => 'network', 'divisi' => 'activity', 'lainnya' => 'circle'];
$kindTone = ['departemen' => 't-teal', 'lembaga' => 't-green', 'biro' => 't-violet',
             'badan' => 't-amber', 'pusat' => 't-rose', 'divisi' => 't-teal', 'lainnya' => ''];

$total = array_sum($counts);
$canEdit = Auth::canEdit();

$qs = function (array $over = []) use ($kind, $keyword) {
    $p = array_merge(['kind' => $kind, 'q' => $keyword], $over);
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
$grouped = ($kind === '' && $keyword === '');
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

<div class="note">
  Katalog ini <strong>dipakai bersama</strong> lintas struktur: &ldquo;Departemen Kesehatan&rdquo;
  cukup ada satu baris di sini, lalu dipakai DPP maupun MWP.
  <strong>Bidang tidak ada di sini</strong> &mdash; ia bukan jenis unit, melainkan wadah yang hanya
  hidup di dalam satu struktur pada satu periode, jadi tempatnya di
  <a href="<?= BASEURL ?>/units/grouping">Pengelompokan Unit</a>.
</div>

<div class="stats recap">
  <?php foreach ($kinds as $k => $label): ?>
    <?= $rc($label, (int) ($counts[$k] ?? 0), $kindIcon[$k], $kind === $k,
            $qs(['kind' => $kind === $k ? '' : $k]), $kindTone[$k]) ?>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <form class="q" method="get" action="<?= BASEURL ?>/units/catalog" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($keyword) ?>" aria-label="Cari unit"
           placeholder="Cari nama atau singkatan unit">
    <?php if ($kind): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
  </form>
  <?php if ($kind !== '' || $keyword !== ''): ?>
    <a class="btn small secondary" href="<?= BASEURL ?>/units/catalog">Tampilkan semua</a>
  <?php endif; ?>
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
          <?php foreach ($kinds as $k => $label): ?>
            <option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Jenis menentukan istilah kepala unitnya: Pusat &rarr; &ldquo;Kepala&rdquo;,
          Divisi &rarr; &ldquo;Koordinator&rdquo;, sisanya &rarr; &ldquo;Ketua&rdquo;.</div>
      </div>
    </div>
    <input type="hidden" name="is_active" value="1">
    <button type="submit" class="btn">Tambah</button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted" style="margin:0">
    <?= $keyword !== '' ? 'Tidak ada unit yang cocok dengan pencarian itu.' : 'Katalog masih kosong.' ?>
  </p></div>
<?php endif; ?>

<?php foreach ($sections as $sk => $list): ?>
  <div class="card">
    <h2>
      <?php if ($sk !== ''): ?>
        <?= Ui::icon($kindIcon[$sk], 19) ?> <?= e($kinds[$sk]) ?>
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
            <th>Nama</th>
            <th>Singkatan</th>
            <?php if ($sk === ''): ?><th>Jenis</th><?php endif; ?>
            <th>Kepala Unit</th>
            <th class="num">Dipakai</th>
            <th>Status</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $r): $id = (int) $r['id']; $used = (int) $r['used_count']; ?>
          <tr>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="small mono"><?= e($r['abbreviation'] ?: '—') ?></td>
            <?php if ($sk === ''): ?>
              <td><span class="badge"><?= e($kinds[$r['kind']] ?? $r['kind']) ?></span></td>
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
                <button type="button" class="icon-btn" data-toggle-edit="unit-<?= $id ?>" title="Ubah"><?= Ui::icon('edit', 16) ?></button>
                <form method="post" action="<?= BASEURL ?>/units/catalog_delete" style="display:inline"
                      data-confirm="Hapus unit &ldquo;<?= e($r['name']) ?>&rdquo; dari katalog?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="kind_filter" value="<?= e($kind) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
                  <button type="submit" class="icon-btn" title="Hapus"><?= Ui::icon('trash', 16) ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
          <?php if ($canEdit): ?>
            <tr id="unit-<?= $id ?>" hidden>
              <td colspan="<?= $sk === '' ? 7 : 6 ?>">
                <form method="post" action="<?= BASEURL ?>/units/catalog_save">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="kind_filter" value="<?= e($kind) ?>">
                  <input type="hidden" name="q_filter" value="<?= e($keyword) ?>">
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
                        <?php foreach ($kinds as $k => $label): ?>
                          <option value="<?= $k ?>" <?= $r['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="help">Mengubah jenis ikut mengubah istilah kepala unitnya.</div>
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

<?php $this->view('layouts/footer'); ?>
