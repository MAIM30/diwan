<?php
// PATH LOKASI FILE: app/Views/admin/structures/index.php
// Struktur Organisasi: baris Jenjang (chip), kotak cari, pilihan tampilan, chip status, lalu satu dari dua tampilan:
//   "Daftar"  tabel berhalaman, terurut jenjang -> induk -> nama (bawaan: ringan dan mudah dipindai; dulu seluruh ~1.100 simpul pohon dimuat
//             sekaligus, sekitar 2 MB, tanpa satu pun saringan)
//   "Pohon"   hierarki yang bisa dibuka; dengan saringan hanya yang cocok beserta induk-induknya (induk yang tak cocok tampil redup)
// Chip Jenjang berasal dari struktur yang ADA (bukan dari yang punya pengurus), jadi jenjang baru seperti DPLN langsung tampil.
//
// Dari controller: $canEdit, $allCount (struktur dalam lingkup), $total (yang cocok), $rows (satu halaman Daftar), $tops / $kids / $matchIds /
// $openIds (Pohon), $filtered, $levelCounts (level => jumlah, sudah menurut pencarian + status), $filters (kunci => label), $filterCounts,
// $lv / $q / $f / $tampil, $page / $perPage / $perPageOptions.
$this->view('layouts/header', ['title' => $title]);

$pages = max(1, (int) ceil($total / $perPage));
$base = ['lv' => $lv, 'q' => $q, 'f' => $f, 'tampil' => $tampil === 'pohon' ? 'pohon' : '', 'per_page' => $perPage !== 50 ? $perPage : ''];
$keep = function ($v) { return $v !== '' && $v !== null; };
// Satu-satunya tempat parameter halaman ini disusun: tiap tautan membawa semua pilihan yang sedang aktif.
$qs = function (array $over = []) use ($base, $keep) {
    $p = array_filter(array_merge($base, $over), $keep);
    return BASEURL . '/structures' . ($p ? '?' . http_build_query($p) : '');
};
$backQuery = http_build_query(array_filter($base + ['page' => $page > 1 ? $page : ''], $keep));   // dikirim aksi Hapus supaya kembali ke daftar yang sama
$backPath = 'structures' . ($backQuery !== '' ? '?' . $backQuery : '');                          // dan tombol Tandai berjalan
$chipGroups = PeopleOverview::chipGroups($levelCounts);

// Simpul pohon: <details>; mengetuknya membuka keterangan dan anak-anaknya (tanpa JavaScript pun berfungsi).
$node = function (array $r) use (&$node, $kids, $openIds, $matchIds, $filtered, $canEdit, $backQuery, $backPath) {
    $id = (int) $r['id'];
    $children = $kids[$id] ?? [];
    $context = $filtered && !isset($matchIds[$id]);   // hanya induk dari yang cocok (jalan menuju yang cocok): tampil redup, tanpa isi
    ?>
    <li class="sn<?= $context ? ' sn-ctx' : '' ?>">
      <details<?= isset($openIds[$id]) ? ' open' : '' ?>>
        <summary>
          <?= Ui::icon('chevron-right', 16, 'caret') ?>
          <span class="sn-main">
            <span class="sn-name"><?= e($r['name']) ?></span>
            <span class="badge"><?= e($r['level']) ?></span>
            <?php if ($r['wing'] === 'muslimah'): ?><span class="badge muslimah">Muslimah</span><?php endif; ?>
            <?php if (!$r['is_active']): ?><span class="badge">nonaktif</span><?php endif; ?>
          </span>
          <?php if ($children): ?><span class="sn-n"><?= count($children) ?> di bawahnya</span><?php endif; ?>
        </summary>

        <?php if (!$context): ?>
        <div class="sn-body">
          <dl class="sn-facts">
            <?php if ($r['region_code']): ?><div><dt>Kode wilayah</dt><dd class="mono"><?= e($r['region_code']) ?></dd></div><?php endif; ?>
            <div><dt>Periode</dt><dd><?php if ($r['term']): ?><a href="<?= BASEURL ?>/terms?structure=<?= $id ?>"><?= e($r['term']['name']) ?> (berjalan)</a>
              <?php else: ?><span class="small"><?= Ui::termGap($id, $r['pending'], $backPath) ?></span><?php endif; ?></dd></div>
            <?php if ($canEdit): ?>
              <div>
                <dt>Admin struktur ini</dt>
                <dd class="small">
                  <?php foreach ($r['admins'] as $a): ?>
                    <div><a href="<?= BASEURL ?>/accounts/form/<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></a>
                      <?php if ($a['status'] !== 'active'): ?><span class="badge">ditangguhkan</span><?php endif; ?>
                      <?php if (AdminNaming::isPlaceholderPhone($a['phone'])): ?><span class="badge core" title="Nomor pengganti: ganti dengan nomor WA admin sebenarnya lewat Ubah akun">nomor pengganti</span><?php endif; ?></div>
                  <?php endforeach; ?>
                  <?php if (empty($r['admins'])): ?><span class="muted">belum ada</span><?php endif; ?>
                  <div><a href="<?= BASEURL ?>/accounts/form?scope=<?= $id ?>">+ Buat admin</a></div>
                </dd>
              </div>
            <?php endif; ?>
          </dl>

          <?php if ($canEdit): ?>
            <div class="sn-actions">
              <a class="btn small secondary" href="<?= BASEURL ?>/structures/form/<?= $id ?>">Ubah</a>
              <form class="inline" method="post" action="<?= BASEURL ?>/structures/delete"
                    data-confirm="Hapus struktur <?= e($r['name']) ?>?" onsubmit="return confirm(this.dataset.confirm)">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                <button type="submit" class="btn small danger">Hapus</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($children): ?>
          <ul>
            <?php foreach ($children as $c) $node($c); ?>
          </ul>
        <?php endif; ?>
      </details>
    </li>
    <?php
};
?>

<div class="page-head">
  <h1>Struktur Organisasi</h1>
  <span class="badge"><?= Ui::n($allCount) ?> struktur</span>
  <div class="actions">
    <?php if ($canEdit): ?>
      <a class="btn" href="<?= BASEURL ?>/structures/form">Tambah Struktur</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($allCount > 0): ?>
<div class="mon-strip" role="group" aria-label="Saring menurut jenjang">
  <span class="mon-strip-l">Jenjang</span>
  <a class="chip<?= $lv === '' ? ' on' : '' ?>" href="<?= e($qs(['lv' => '', 'page' => ''])) ?>"<?= $lv === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n(array_sum($levelCounts)) ?></span></a>
  <?php foreach ($chipGroups as $wing => $list): ?>
    <span class="mon-sep" aria-hidden="true"></span>
    <?php foreach ($list as $level): $on = $lv === $level; ?>
      <a class="chip<?= $on ? ' on' : ($levelCounts[$level] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['lv' => $on ? '' : $level, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>
         title="<?= e(PeopleOverview::levelName($level)) ?>"><?= e($level) ?> <span class="chip-n"><?= Ui::n($levelCounts[$level]) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<div class="toolbar" style="margin-top:10px">
  <form class="q" method="get" action="<?= BASEURL ?>/structures" role="search">
    <?= Ui::icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($q) ?>" aria-label="Cari struktur" placeholder="Nama, kode wilayah, atau induk, mis. Barru, 73.05, Sulawesi Selatan">
    <?php if ($lv !== ''): ?><input type="hidden" name="lv" value="<?= e($lv) ?>"><?php endif; ?>
    <?php if ($f !== ''): ?><input type="hidden" name="f" value="<?= e($f) ?>"><?php endif; ?>
    <?php if ($tampil === 'pohon'): ?><input type="hidden" name="tampil" value="pohon"><?php endif; ?>
  </form>
  <div class="view-seg" role="group" aria-label="Tampilan">
    <a href="<?= e($qs(['tampil' => '', 'page' => ''])) ?>" class="<?= $tampil === 'daftar' ? 'on' : '' ?>"<?= $tampil === 'daftar' ? ' aria-current="true"' : '' ?>>Daftar</a>
    <a href="<?= e($qs(['tampil' => 'pohon', 'page' => ''])) ?>" class="<?= $tampil === 'pohon' ? 'on' : '' ?>"<?= $tampil === 'pohon' ? ' aria-current="true"' : '' ?>>Pohon</a>
  </div>
  <div class="chips" role="group" aria-label="Saring menurut status">
    <a class="chip<?= $f === '' ? ' on' : '' ?>" href="<?= e($qs(['f' => '', 'page' => ''])) ?>"<?= $f === '' ? ' aria-current="true"' : '' ?>>Semua <span class="chip-n"><?= Ui::n($filterCounts['']) ?></span></a>
    <?php foreach ($filters as $key => $label): $on = $f === $key; ?>
      <a class="chip<?= $on ? ' on' : ($filterCounts[$key] === 0 ? ' zero' : '') ?>" href="<?= e($qs(['f' => $on ? '' : $key, 'page' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span class="chip-n"><?= Ui::n($filterCounts[$key]) ?></span></a>
    <?php endforeach; ?>
  </div>
  <?php if ($tampil === 'pohon' && $tops): ?>
    <span class="mon-bulk">
      <button type="button" class="btn small secondary" data-tree="struktur" data-tree-all="open">Buka semua</button>
      <button type="button" class="btn small secondary" data-tree="struktur" data-tree-all="close">Tutup semua</button>
    </span>
  <?php elseif ($tampil === 'daftar'): ?>
    <label class="per-page">
      Tampilkan
      <select onchange="window.location.href=this.value" aria-label="Jumlah baris per halaman">
        <?php foreach ($perPageOptions as $opt): ?>
          <option value="<?= e($qs(['per_page' => $opt === 50 ? '' : $opt, 'page' => ''])) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= (int) $opt ?> baris</option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
</div>

<p class="mon-ctx">
  <span>Menampilkan <b><?= Ui::n($total) ?></b> dari <?= Ui::n($allCount) ?> struktur<?= $lv !== '' ? ' &middot; jenjang <b>' . e($lv) . '</b>' : '' ?><?= $f !== '' ? ' &middot; ' . e(mb_strtolower($filters[$f])) : '' ?><?= $q !== '' ? ' &middot; pencarian &ldquo;' . e($q) . '&rdquo;' : '' ?></span>
  <?php if ($filtered): ?><a href="<?= e($qs(['lv' => '', 'q' => '', 'f' => '', 'page' => ''])) ?>">Hapus penyaringan</a><?php endif; ?>
</p>
<?php endif; ?>

<div class="card">
  <?php if ($allCount === 0): ?>
    <div class="empty"><span>Belum ada struktur dalam lingkup Anda.</span></div>
  <?php elseif ($total === 0): ?>
    <div class="empty">
      <?= Ui::icon('layers', 28) ?>
      <span>Tidak ada struktur yang cocok dengan penyaringan ini.</span>
      <a class="btn small secondary" href="<?= e($qs(['lv' => '', 'q' => '', 'f' => '', 'page' => ''])) ?>">Hapus penyaringan</a>
    </div>
  <?php elseif ($tampil === 'pohon'): ?>
    <ul class="stree" id="struktur">
      <?php foreach ($tops as $t) $node($t); ?>
    </ul>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Struktur</th><th>Jenjang</th><th>Kode wilayah</th><th>Periode berjalan</th><?php if ($canEdit): ?><th>Admin</th><th></th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
          <tr>
            <td>
              <div class="st-name"<?= $r['short'] !== $r['name'] ? ' title="' . e($r['name']) . '"' : '' ?>><?= e($r['short']) ?>
                <?php if (!$r['is_active']): ?><span class="badge ended">nonaktif</span><?php endif; ?></div>
              <?php if ($r['parent_label'] !== ''): ?><div class="small muted">di bawah <?= e($r['parent_label']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge lv"><?= e($r['level']) ?></span></td>
            <td class="small mono"><?= e($r['region_code'] ?: '—') ?></td>
            <td class="small">
              <?php if ($r['term']): ?>
                <a href="<?= BASEURL ?>/terms?structure=<?= $id ?>"><?= e($r['term']['name']) ?></a>
              <?php else: ?>
                <span class="muted"><?= Ui::termGap($id, $r['pending'], $backPath) ?></span>
              <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
              <td class="small">
                <?php foreach (array_slice($r['admins'], 0, 2) as $a): ?>
                  <div><a href="<?= BASEURL ?>/accounts/form/<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></a>
                    <?php if ($a['status'] !== 'active'): ?><span class="badge">ditangguhkan</span><?php endif; ?>
                    <?php if (AdminNaming::isPlaceholderPhone($a['phone'])): ?><span class="badge core" title="Nomor pengganti: ganti dengan nomor WA admin sebenarnya lewat Ubah akun">nomor pengganti</span><?php endif; ?></div>
                <?php endforeach; ?>
                <?php if (count($r['admins']) > 2): ?><div class="muted">+<?= count($r['admins']) - 2 ?> lagi</div><?php endif; ?>
                <?php if (empty($r['admins'])): ?><a class="muted" href="<?= BASEURL ?>/accounts/form?scope=<?= $id ?>">+ Buat admin</a><?php endif; ?>
              </td>
              <td class="nowrap">
                <span class="row-actions">
                  <a class="row-btn" href="<?= BASEURL ?>/structures/form/<?= $id ?>" title="Ubah" aria-label="Ubah <?= e($r['name']) ?>"><?= Ui::icon('edit', 15) ?></a>
                  <form class="inline" method="post" action="<?= BASEURL ?>/structures/delete"
                        data-confirm="Hapus struktur <?= e($r['name']) ?>?" onsubmit="return confirm(this.dataset.confirm)">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                    <button type="submit" class="row-btn danger" title="Hapus struktur" aria-label="Hapus <?= e($r['name']) ?>"><?= Ui::icon('trash', 15) ?></button>
                  </form>
                </span>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="mon-count">Menampilkan <?= Ui::n(($page - 1) * $perPage + 1) ?>&ndash;<?= Ui::n(min($total, $page * $perPage)) ?> dari <b><?= Ui::n($total) ?></b> struktur</p>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Halaman">
        <?php
        $win = array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2)))));
        sort($win);
        $prev = 0;
        foreach ($win as $i):
          if ($prev && $i - $prev > 1) echo '<span class="gap">…</span>';
          echo $i === $page
            ? '<span class="cur" aria-current="page">' . $i . '</span>'
            : '<a href="' . e($qs(['page' => $i])) . '">' . $i . '</a>';
          $prev = $i;
        endforeach;
        ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="note">
  Sayap Muslimah (<?= e(implode('/', array_filter(StructureLevels::codes(true), function ($c) { return StructureLevels::group($c) === 'musl'; }))) ?>) adalah cabang paralel di bawah akar yang sama, bukan label lain
  dari struktur umum: &ldquo;DPW Sulawesi Tenggara&rdquo; dan &ldquo;MWW Sulawesi Tenggara&rdquo;
  adalah dua baris berbeda. Lingkup admin tidak pernah menyeberang antar cabang.
</div>

<?php $this->view('layouts/footer'); ?>
