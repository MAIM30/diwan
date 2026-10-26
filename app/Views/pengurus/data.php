<?php
// PATH LOKASI FILE: app/Views/pengurus/data.php
// Data pribadi pengurus yang dicatat di sistem. NIK dan nomor WA tampil utuh (data milik sendiri); HP keluarga dan email tersamar;
// tanggal lahir, tempat lahir, dan alamat jalan TIDAK ditampilkan, hanya domisili kasar (kelurahan sampai provinsi).
// Perubahannya lewat halaman Ubah data saya.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'data']);

[$pre, $nameOnly, $suf] = Ui::nameParts($person);
$statusLabel = ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'deceased' => 'Wafat'][$person['status']] ?? $person['status'];
$info = function ($icon, $key, $val) {
    return '<li><span class="ii">' . Ui::icon($icon, 18) . '</span><span class="k">' . e($key) . '</span><span class="v">' . $val . '</span></li>';
};
$dash = '<span class="muted">—</span>';
?>

<div class="page-head">
  <h1>Data Saya</h1>
  <div class="actions"><a class="btn" href="<?= BASEURL ?>/pengurus/ubah"><?= Ui::icon('edit', 17) ?> Ubah data saya</a></div>
</div>

<div class="m-grid">
  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('user', 19) ?> Nama &amp; Identitas</h2></div>
    <ul class="info">
      <?= $info('user', 'Nama asli', '<strong>' . e($nameOnly) . '</strong>') ?>
      <?= $info('id-card', 'Gelar depan', $pre ? e($pre) : $dash) ?>
      <?= $info('id-card', 'Gelar belakang', $suf ? e($suf) : $dash) ?>
      <?= $info('user', 'Jenis kelamin', $person['gender'] === 'P' ? 'Perempuan' : 'Laki-laki') ?>
      <?= $info('briefcase', 'Pekerjaan', $person['occupation'] ? e($person['occupation']) : $dash) ?>
      <?= $info('shield', 'NIK', $person['nik'] ? '<span class="mono">' . e($person['nik']) . '</span>' : $dash) ?>
      <?= $info('check', 'Status', e($statusLabel)) ?>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= Ui::icon('phone', 19) ?> Kontak</h2></div>
    <ul class="info">
      <?= $info('phone', 'Nomor WA', $person['phone'] ? '<span class="mono">' . e($person['phone']) . '</span>' : $dash) ?>
      <?= $info('phone', 'HP keluarga', $person['family_phone'] ? '<span class="mono">' . e(Text::maskPhone($person['family_phone'])) . '</span>' : $dash) ?>
      <?= $info('mail', 'Email', $person['email'] ? '<span class="mono">' . e(Text::maskEmail($person['email'])) . '</span>' : $dash) ?>
      <?php // Alamat jalan tak ditampilkan; yang kasar saja: kelurahan, kecamatan, kota, provinsi. ?>
      <?php $coarse = Ui::address(['village' => $person['village'] ?? null, 'district' => $person['district'] ?? null, 'city' => $person['city'], 'province' => $person['province']]); ?>
      <?= $info('map-pin', 'Domisili', $coarse !== '' ? e($coarse) : $dash) ?>
      <?php $last = $members->recentSelfEdits($person['id'], 1); ?>
      <?= $info('clock', 'Perubahan terakhir', $last ? e(Ui::ago($last[0]['at'])) . ' <span class="muted">(' . e(implode(', ', array_map('mb_strtolower', $last[0]['fields']))) . ')</span>' : $dash) ?>
    </ul>
  </section>
</div>

<?php $this->view('layouts/member_footer'); ?>
