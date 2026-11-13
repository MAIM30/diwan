<?php
// PATH LOKASI FILE: app/Views/admin/levels/index.php
// Master Data > Tingkat Struktur: daftar tingkat (DPP, DPW, DPLN, ...) yang dikelola. Hanya super admin (LevelsController).
//
// Dari controller: $rows = StructureLevelModel::levelList() (tiap tingkat + structures, removed, children).

$this->view('layouts/header', ['title' => $title]);

$groups = StructureLevels::GROUPS;
$tiers = StructureLevels::TIER_NAMES;
$codes = array_column($rows, 'code');
$total = count($rows);
$used = array_sum(array_column($rows, 'structures'));

// Kolom isian yang sama untuk tingkat baru dan untuk mengubah: $r = baris tingkat (kosong untuk yang baru), $id = awalan id elemen.
$fields = function (array $r, $id, $isNew) use ($groups, $tiers, $rows) {
    $system = !empty($r['is_system']);
    ob_start(); ?>
    <div class="sn-form">
      <?php if ($isNew): ?>
        <div class="field"><label for="<?= $id ?>-c">Singkatan</label>
          <input type="text" id="<?= $id ?>-c" name="code" required minlength="2" maxlength="10" pattern="[A-Za-z][A-Za-z0-9]{1,9}" placeholder="mis. DPLN" autocomplete="off"></div>
      <?php else: ?>
        <div class="field"><label for="<?= $id ?>-c">Singkatan</label>
          <input type="text" id="<?= $id ?>-c" name="new_code" required minlength="2" maxlength="10" pattern="[A-Za-z][A-Za-z0-9]{1,9}" value="<?= e($r['code']) ?>" autocomplete="off"></div>
      <?php endif; ?>
      <div class="field" style="grid-column:span 2"><label for="<?= $id ?>-n">Nama lengkap</label>
        <input type="text" id="<?= $id ?>-n" name="name" required maxlength="100" value="<?= e($r['name'] ?? '') ?>" placeholder="mis. Dewan Pimpinan Luar Negeri"></div>
      <div class="field"><label for="<?= $id ?>-g">Kelompok</label>
        <select id="<?= $id ?>-g" name="grp" <?= $system ? 'disabled' : 'required' ?>>
          <?php foreach ($groups as $gk => $gl): ?>
            <option value="<?= $gk ?>" <?= ($r['grp'] ?? 'umum') === $gk ? 'selected' : '' ?>><?= e($gl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="<?= $id ?>-t">Setingkat</label>
        <select id="<?= $id ?>-t" name="tier" <?= $system ? 'disabled' : 'required' ?>>
          <?php foreach ($tiers as $tk => $tl): ?>
            <option value="<?= $tk ?>" <?= (int) ($r['tier'] ?? 0) === $tk ? 'selected' : '' ?>><?= $tk ?> &middot; <?= e($tl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="<?= $id ?>-p">Berada di bawah</label>
        <select id="<?= $id ?>-p" name="parent_code" <?= $system ? 'disabled' : '' ?>>
          <option value="">&mdash; tanpa induk tingkat &mdash;</option>
          <?php foreach ($rows as $o): if (!$isNew && strcasecmp($o['code'], $r['code']) === 0) continue; ?>
            <option value="<?= e($o['code']) ?>" <?= strcasecmp((string) ($r['parent_code'] ?? ''), $o['code']) === 0 ? 'selected' : '' ?>><?= e($o['code']) ?> &mdash; <?= e($o['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="<?= $id ?>-s">Sebutan di nama admin</label>
        <input type="text" id="<?= $id ?>-s" name="short_label" maxlength="30" value="<?= e((string) ($r['short_label'] ?? '')) ?>" placeholder="opsional, mis. Luar Negeri"></div>
      <div class="field"><label for="<?= $id ?>-o">Urutan</label>
        <input type="number" id="<?= $id ?>-o" name="sort_order" min="0" max="65535" value="<?= $isNew ? '' : (int) $r['sort_order'] ?>" placeholder="otomatis"></div>
    </div>
    <?php if (!$system): ?>
      <label class="check" style="margin-top:8px"><input type="checkbox" name="parent_strict" value="1" <?= !empty($r['parent_strict']) ? 'checked' : '' ?>>
        <span>Wajib: struktur tingkat ini hanya boleh dipasang di bawah tingkat di atas</span></label>
    <?php endif; ?>
    <?php if (!$isNew && !$system): ?>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>>
        <span>Aktif &mdash; tingkat nonaktif tidak ditawarkan untuk struktur baru</span></label>
    <?php endif; ?>
    <?php return ob_get_clean();
};
?>

<div class="page-head">
  <h1>Tingkat Struktur</h1>
  <span class="badge"><?= Ui::n($total) ?> tingkat</span>
  <div class="actions">
    <a class="btn secondary" href="<?= BASEURL ?>/structures"><?= Ui::icon('network', 17) ?> Struktur</a>
  </div>
</div>

<div class="note">
  Daftar ini menjadi pilihan <strong>Tingkat</strong> pada formulir <a href="<?= BASEURL ?>/structures">Struktur</a>, menentukan urutan dan
  pengelompokan jenjang (DPP, DPW, MWW, ...) di Data Pengurus, Struktur, dan Periode, serta nilai <code>level</code>, <code>wing</code>, dan
  <code>tier</code> di API. <strong>Singkatan</strong>, nama, kelompok, setingkat, induk, urutan, dan status diubah lewat tombol <strong>Ubah</strong>
  pada barisnya. Mengganti <strong>singkatan</strong> ikut mengganti nilai <code>level</code> semua struktur bertingkat itu, termasuk di API:
  aplikasi konsumen yang mencocokkan <code>level</code> dengan singkatan lama perlu disesuaikan (yang berpegang pada <code>uuid</code> tidak terpengaruh).
  Tingkat yang masih dipakai struktur tidak bisa dihapus: ubah dulu tingkat strukturnya, atau nonaktifkan bila hanya tak ingin ditawarkan lagi.
</div>

<div class="card">
  <h2><?= Ui::icon('route', 19) ?> Daftar tingkat <span class="hint">&mdash; <?= Ui::n($used) ?> struktur memakainya</span></h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Singkatan</th><th>Nama lengkap</th><th>Kelompok</th><th>Setingkat</th><th>Berada di bawah</th><th class="num">Struktur</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $cid = 'lv-' . $r['code']; ?>
        <tr>
          <td class="mono"><strong><?= e($r['code']) ?></strong><?php if (!empty($r['is_system'])): ?> <span class="badge">bawaan sistem</span><?php endif; ?></td>
          <td><?= e($r['name']) ?></td>
          <td><span class="badge<?= $r['grp'] === 'musl' ? ' muslimah' : '' ?>"><?= e($groups[$r['grp']] ?? $r['grp']) ?></span></td>
          <td class="small"><?= (int) $r['tier'] ?> &middot; <?= e($tiers[(int) $r['tier']] ?? '—') ?>
            <?php if (trim((string) $r['short_label']) !== ''): ?><div class="muted">admin &ldquo;<?= e($r['short_label']) ?>&rdquo;</div><?php endif; ?></td>
          <td class="small">
            <?php if ((string) $r['parent_code'] !== ''): ?>
              <?= e($r['parent_code']) ?><?= !empty($r['parent_strict']) ? ' <span class="badge warn" title="Struktur tingkat ini wajib berinduk tingkat itu">wajib</span>' : '' ?>
            <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($r['structures'] > 0): ?><a href="<?= BASEURL ?>/structures?lv=<?= e(rawurlencode($r['code'])) ?>"><?= Ui::n($r['structures']) ?></a>
            <?php else: ?><span class="muted">0</span><?php endif; ?>
          </td>
          <td><?= !empty($r['is_active']) ? '<span class="badge ok">aktif</span>' : '<span class="badge ended">nonaktif</span>' ?></td>
          <td class="nowrap">
            <button type="button" class="btn small secondary" data-toggle-edit="<?= e($cid) ?>" aria-label="Ubah tingkat <?= e($r['code']) ?>">Ubah</button>
            <?php if (empty($r['is_system'])): ?>
              <?php $blocked = $r['structures'] + $r['removed'] + count($r['children']); ?>
              <form class="inline" method="post" action="<?= BASEURL ?>/levels/delete" onsubmit="return confirm(this.dataset.confirm)"
                    data-confirm="Hapus tingkat &quot;<?= e($r['code']) ?>&quot;? Tidak bisa dibatalkan.">
                <?= Csrf::field() ?>
                <input type="hidden" name="code" value="<?= e($r['code']) ?>">
                <button type="submit" class="btn small secondary" <?= $blocked > 0 ? 'disabled title="Masih dipakai: ' . (int) $r['structures'] . ' struktur'
                    . ($r['removed'] > 0 ? ' (+' . (int) $r['removed'] . ' yang sudah dihapus)' : '') . (count($r['children']) ? ', induk bagi ' . e(implode(', ', $r['children'])) : '') . '"' : '' ?>>Hapus</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <tr id="<?= e($cid) ?>" hidden>
          <td colspan="8">
            <form method="post" action="<?= BASEURL ?>/levels/save" data-level-form data-code="<?= e($r['code']) ?>" data-structures="<?= (int) $r['structures'] ?>">
              <?= Csrf::field() ?>
              <input type="hidden" name="edit" value="1">
              <input type="hidden" name="code" value="<?= e($r['code']) ?>">
              <?= $fields($r, $cid, false) ?>
              <p class="help" style="margin:8px 0 0">
                <strong>Singkatan</strong>: mengganti <?= e($r['code']) ?> ikut mengganti <code>level</code> <?= Ui::n($r['structures']) ?> struktur bertingkat ini,
                termasuk di API (aplikasi konsumen yang mencocokkan kode lama perlu disesuaikan), dan alamat dengan <code>?lv=<?= e($r['code']) ?></code> tak lagi berlaku.
              </p>
              <?php if (!empty($r['is_system'])): ?>
              <p class="help" style="margin:8px 0 0"><?= e($r['code']) ?> adalah pucuk nasional (admin yang berlingkup di sini dikenali sebagai admin pusat), jadi kelompok, setingkat, induk, dan statusnya terkunci; singkatan, nama, sebutan, dan urutan boleh diubah.</p>
              <?php elseif ($r['structures'] > 0): ?>
              <p class="help" style="margin:8px 0 0">Mengubah &ldquo;Setingkat&rdquo; ikut mengubah tier <?= Ui::n($r['structures']) ?> struktur <?= e($r['code']) ?> (dan di API). Kelompok Umum/Muslimah tidak bisa berganti selama masih ada struktur bertingkat ini.</p>
              <?php endif; ?>
              <button type="submit" class="btn" style="margin-top:10px">Simpan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <details class="sn-edit" style="margin-top:12px">
    <summary class="btn small">Tambah tingkat</summary>
    <div class="sn-panel">
      <form method="post" action="<?= BASEURL ?>/levels/save">
        <?= Csrf::field() ?>
        <?= $fields([], 'lv-new', true) ?>
        <p class="help" style="margin:8px 0 0">
          <strong>Setingkat</strong> menentukan tier di API dan pengelompokan di dasbor (Pusat sampai Ranting): mis. DPLN setingkat Wilayah seperti DPW.
          <strong>Kelompok</strong> menentukan sayap: Umum dan Dewan (lembaga tinggi setara DPP, seperti DSO, DSY, DPK) memakai katalog unit sayap Umum,
          Muslimah memakai katalog Muslimah. Urutan tingkat di seluruh halaman mengikuti kolom <strong>Urutan</strong> (angka kecil lebih dulu).
          <strong>Berada di bawah</strong> menentukan tingkat apa yang boleh dibuat admin di bawah strukturnya; dengan &ldquo;Wajib&rdquo; struktur
          tingkat ini juga tidak bisa dipasang di bawah tingkat lain. Singkatan bisa diganti kapan saja lewat <strong>Ubah</strong>, tetapi mengubah
          nilai <code>level</code> struktur yang sudah memakainya (juga di API), jadi pilih yang akan dipakai lama.
        </p>
        <button type="submit" class="btn" style="margin-top:10px">Tambah</button>
      </form>
    </div>
  </details>
</div>

<script>
// Mengganti singkatan mengubah `level` banyak struktur dan nilai di API: minta konfirmasi, menyebut jumlahnya.
(function () {
  document.querySelectorAll('form[data-level-form]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      var field = form.querySelector('[name="new_code"]');
      var old = form.getAttribute('data-code');
      if (!field || field.value.trim() === '' || field.value.trim() === old) return;
      var n = form.getAttribute('data-structures');
      if (!confirm('Ganti singkatan ' + old + ' menjadi ' + field.value.trim() + '?\n\n' + n + ' struktur ikut berganti, dan nilai level di API berubah. Aplikasi konsumen yang memakai kode lama perlu disesuaikan.')) ev.preventDefault();
    });
  });
})();
</script>

<?php $this->view('layouts/footer'); ?>
