<?php
// PATH LOKASI FILE: app/Views/admin/people/confirm_duplicate.php
// Peringatan kemungkinan data ganda. SENGAJA tidak menggabungkan otomatis: kasus yang meragukan
// selalu dikembalikan ke manusia, mengikuti aturan yang sudah terbukti di impor SK syuro.
$this->view('layouts/header', ['title' => $title]);

$label = [
  'exact'      => 'Sangat mungkin orang yang sama',
  'normalized' => 'Nama cocok setelah gelar diabaikan',
  'ambiguous'  => 'Ada lebih dari satu kemungkinan — perlu diperiksa manual',
];
$inputName = Text::joinName($input['title_prefix'], $input['name'], $input['title_suffix']);
?>

<div class="page-head"><h1>Kemungkinan Data Ganda</h1></div>

<div class="alert warn">
  <strong><?= e($label[$match['confidence']] ?? $match['confidence']) ?>.</strong>
  Sistem tidak akan menggabungkan data secara otomatis &mdash; Anda yang memutuskan.
</div>

<div class="split">
  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('user-plus', 19) ?> Yang akan Anda simpan</h2></div>
    <ul class="info">
      <li><span class="ii"><?= Ui::icon('user', 18) ?></span><span class="k">Nama asli</span><span class="v"><?= e($input['name']) ?></span></li>
      <li><span class="ii"><?= Ui::icon('id-card', 18) ?></span><span class="k">Gelar</span>
        <span class="v"><?= e(trim(($input['title_prefix'] ?? '') . ' / ' . ($input['title_suffix'] ?? ''), ' /') ?: '—') ?></span></li>
      <li><span class="ii"><?= Ui::icon('user', 18) ?></span><span class="k">Jenis kelamin</span><span class="v"><?= $input['gender'] === 'P' ? 'Perempuan' : 'Laki-laki' ?></span></li>
      <li><span class="ii"><?= Ui::icon('phone', 18) ?></span><span class="k">Nomor WA</span><span class="v mono"><?= e($input['phone'] ?: '—') ?></span></li>
      <li><span class="ii"><?= Ui::icon('shield', 18) ?></span><span class="k">NIK</span>
        <span class="v mono"><?= e($input['nik'] ? Text::normalizeNik($input['nik']) : '—') ?></span></li>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('users', 19) ?> Sudah ada di database</h2></div>
    <p class="card-sub">Cocok pada: <?= e(str_replace('_', ' ', (string) $match['matched_on'])) ?></p>
    <?php foreach ($match['candidates'] as $c): ?>
      <div class="tile" style="margin-bottom:10px">
        <?php if (!empty($c['out_of_scope'])): ?>
          <div class="pcell">
            <?= Ui::avatar($c, 'md') ?>
            <div class="pcell-text">
              <span class="pcell-name"><?= Ui::nameHtml($c) ?></span>
              <span class="pcell-sub">Di luar lingkup akses Anda &mdash; biodatanya tidak ditampilkan.
                Hubungi admin struktur terkait bila perlu memastikan.</span>
            </div>
          </div>
        <?php else: ?>
          <?= Ui::personCell($c, BASEURL . '/people/detail/' . (int) $c['id'], '<span class="mono">' . e($c['phone'] ?: 'tanpa nomor WA')
              . '</span> · NIK ' . e($c['nik'] ?: '—')) ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<div class="card">
  <h2>Keputusan Anda</h2>
  <p class="small muted">
    Kalau ini memang orang yang berbeda (nama sama itu lumrah), lanjutkan menyimpan sebagai data
    baru. Kalau ternyata orang yang sama, batalkan lalu tambahkan penempatan baru pada data yang
    sudah ada &mdash; jangan buat barisnya dua kali.
  </p>

  <form method="post" action="<?= BASEURL ?>/people/save">
    <?= Csrf::field() ?>
    <input type="hidden" name="confirm_duplicate" value="1">
    <?php foreach ($input as $k => $v): if ($v === null) continue; ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
    <div class="btn-row" style="margin-top:0">
      <button type="submit" class="btn">Ya, ini orang yang berbeda &mdash; simpan &ldquo;<?= e($inputName) ?>&rdquo; sebagai data baru</button>
      <a class="btn secondary" href="<?= BASEURL ?>/people">Batalkan</a>
    </div>
  </form>
</div>

<?php $this->view('layouts/footer'); ?>
