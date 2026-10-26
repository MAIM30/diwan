<?php
// PATH LOKASI FILE: app/Views/admin/people/detail.php
// Profil pengurus. Riwayat jabatan lintas periode dan lintas struktur adalah tampilan yang tidak
// mungkin dibuat di skema syuro, karena di sana masa amanah hanya dua kolom di baris user.
$this->view('layouts/header', ['title' => $title]);

[$pre, $nameOnly, $suf] = Ui::nameParts($person);
$periods = count(array_unique(array_column($history, 'term_id')));
$structs = count(array_unique(array_column($history, 'structure_id')));
$statusLabel = ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'deceased' => 'Wafat'][$person['status']] ?? $person['status'];
$info = function ($icon, $key, $val, $sub = '') {
    return '<li><span class="ii">' . Ui::icon($icon, 18) . '</span><span class="k">' . e($key) . '</span>'
        . '<span class="v">' . $val . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</span></li>';
};
$dash = '<span class="muted">—</span>';
?>

<div class="crumb"><a href="<?= BASEURL ?>/people">Data Pengurus</a> &rsaquo; <?= e($nameOnly) ?></div>

<div class="card">
  <div class="profile">
    <div class="avatar-edit">
      <?= Ui::avatar($person, 'xl') ?>
      <?php if (Auth::canEdit()): ?>
        <form method="post" action="<?= BASEURL ?>/people/photo" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
          <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/webp" data-autosubmit>
          <label class="cam" for="photo-input" title="Ubah foto" aria-label="Ubah foto"><?= Ui::icon('camera', 16) ?></label>
        </form>
      <?php endif; ?>
    </div>

    <div class="profile-main">
      <h1><?= e($nameOnly) ?></h1>
      <div class="titles">
        <?php if ($pre): ?><span class="t"><small>Gelar depan</small><b><?= e($pre) ?></b></span><?php endif; ?>
        <?php if ($suf): ?><span class="t"><small>Gelar belakang</small><b><?= e($suf) ?></b></span><?php endif; ?>
        <?php if (!$pre && !$suf): ?><span class="small muted">Tanpa gelar</span><?php endif; ?>
      </div>
      <div class="meta-chips">
        <span class="meta-chip"><?= Ui::icon('user', 14) ?> <?= $person['gender'] === 'P' ? 'Perempuan' : 'Laki-laki' ?></span>
        <?php if ($person['phone']): ?><span class="meta-chip mono"><?= Ui::icon('phone', 14) ?> <?= e($person['phone']) ?></span><?php endif; ?>
        <?php if ($person['city']): ?><span class="meta-chip"><?= Ui::icon('map-pin', 14) ?> <?= e($person['city']) ?></span><?php endif; ?>
        <span class="badge <?= $person['status'] === 'active' ? 'ok' : 'ended' ?>"><?= e($statusLabel) ?></span>
      </div>
    </div>

    <?php if (Auth::canEdit()): ?>
      <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn secondary" href="<?= BASEURL ?>/people/form/<?= (int) $person['id'] ?>"><?= Ui::icon('edit', 16) ?> Ubah Data</a>
        <?php if ($person['photo_path']): ?>
          <form class="inline" method="post" action="<?= BASEURL ?>/people/photo" data-confirm="Hapus foto profil ini?" onsubmit="return confirm(this.dataset.confirm)">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
            <input type="hidden" name="remove" value="1">
            <button type="submit" class="btn secondary"><?= Ui::icon('trash', 16) ?> Hapus Foto</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div data-tabs>
  <div class="tabs" role="tablist" aria-label="Bagian profil">
    <button class="tab" role="tab" id="tab-ringkasan" aria-controls="panel-ringkasan" aria-selected="true">Ringkasan</button>
    <button class="tab" role="tab" id="tab-biodata" aria-controls="panel-biodata" aria-selected="false">Biodata</button>
    <button class="tab" role="tab" id="tab-riwayat" aria-controls="panel-riwayat" aria-selected="false">Riwayat Jabatan (<?= count($history) ?>)</button>
    <button class="tab" role="tab" id="tab-aktivitas" aria-controls="panel-aktivitas" aria-selected="false">Aktivitas</button>
  </div>

  <!-- ============ Ringkasan ============ -->
  <div role="tabpanel" id="panel-ringkasan" aria-labelledby="tab-ringkasan">
    <div class="split">
      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('briefcase', 19) ?> Jabatan Saat Ini</h2></div>
        <?php if (empty($current)): ?>
          <div class="empty"><?= Ui::icon('briefcase', 26) ?><span>Tidak sedang menjabat di periode berjalan.</span></div>
        <?php else: ?>
          <div class="tiles">
            <?php foreach ($current as $h): ?>
              <div class="tile">
                <div class="t-pos"><?= e($h['position_name']) ?>
                  <?php if ($h['is_core'] && !$h['unit_name']): ?><span class="badge core">inti</span><?php endif; ?></div>
                <div class="t-struct"><?= e($h['level'] . ' ' . $h['structure_name']) ?></div>
                <div class="t-meta"><?= $h['unit_name'] ? e($h['unit_name']) . ' · ' : 'Pucuk pimpinan · ' ?>Periode <?= e($h['term_name']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('id-card', 19) ?> Informasi Dasar</h2></div>
        <ul class="info">
          <?= $info('phone', 'Nomor WA', $person['phone'] ? '<span class="mono">' . e($person['phone']) . '</span>' : $dash) ?>
          <?= $info('mail', 'Email', $person['email'] ? e($person['email']) : $dash) ?>
          <?= $info('calendar', 'Tanggal lahir', $person['birth_date'] ? e(Ui::date($person['birth_date'])) : $dash, $person['birth_place'] ?: '') ?>
          <?= $info('map-pin', 'Lokasi', ($person['city'] || $person['province']) ? e(trim($person['city'] . ', ' . $person['province'], ', ')) : $dash) ?>
        </ul>
      </section>
    </div>

    <div class="stats mini">
      <div class="stat"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('briefcase', 19) ?></span><span class="lbl">Penempatan</span></div><div class="num"><?= count($history) ?></div></div>
      <div class="stat t-green"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('calendar-check', 19) ?></span><span class="lbl">Periode</span></div><div class="num"><?= $periods ?></div></div>
      <div class="stat t-violet"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('network', 19) ?></span><span class="lbl">Struktur</span></div><div class="num"><?= $structs ?></div></div>
    </div>
  </div>

  <!-- ============ Biodata ============ -->
  <div role="tabpanel" id="panel-biodata" aria-labelledby="tab-biodata" hidden>
    <div class="split">
      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('user', 19) ?> Nama &amp; Identitas</h2></div>
        <ul class="info">
          <?= $info('user', 'Nama asli', '<strong>' . e($nameOnly) . '</strong>') ?>
          <?= $info('id-card', 'Gelar depan', $pre ? e($pre) : $dash) ?>
          <?= $info('id-card', 'Gelar belakang', $suf ? e($suf) : $dash) ?>
          <?= $info('file-text', 'Nama lengkap', e(Ui::fullName($person))) ?>
          <?= $info('user', 'Jenis kelamin', $person['gender'] === 'P' ? 'Perempuan' : 'Laki-laki') ?>
          <?= $info('briefcase', 'Pekerjaan', $person['occupation'] ? e($person['occupation']) : $dash) ?>
          <?= $info('shield', 'NIK', '<span class="mono">' . e($person['nik'] ?: '—') . '</span>',
                    'Data pribadi. Bukan kunci untuk aplikasi lain.') ?>
        </ul>
      </section>

      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('map-pin', 19) ?> Kontak &amp; Alamat</h2></div>
        <ul class="info">
          <?= $info('phone', 'Nomor WA', $person['phone'] ? '<span class="mono">' . e($person['phone']) . '</span>' : $dash) ?>
          <?= $info('phone', 'HP keluarga', $person['family_phone']
                ? '<span class="mono">' . e(Auth::canEdit() ? $person['family_phone'] : Text::maskPhone($person['family_phone'])) . '</span>' : $dash,
                $person['family_phone'] ? 'Kontak darurat' : '') ?>
          <?= $info('mail', 'Email', $person['email'] ? e($person['email']) : $dash) ?>
          <?= $info('calendar', 'Tanggal lahir', $person['birth_date'] ? e(Ui::date($person['birth_date'])) : $dash) ?>
          <?= $info('map-pin', 'Tempat lahir', $person['birth_place'] ? e($person['birth_place']) : $dash) ?>
          <?= $info('map-pin', 'Alamat', Ui::address($person) !== '' ? e(Ui::address($person)) : $dash) ?>
        </ul>
      </section>
    </div>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('key', 19) ?> Rujukan Sistem</h2></div>
      <ul class="info">
        <?= $info('key', 'UUID', '<span class="mono">' . e($person['uuid']) . '</span>',
                  'Identitas permanen. Inilah yang disimpan syuro dan aplikasi lain; nilainya tidak pernah berubah.') ?>
        <?php
        $portalText = [
            'ok'           => ['<span class="badge ok">Aktif</span>', ($portalLast ? 'Terakhir masuk ' . Ui::ago($portalLast) : 'Belum pernah masuk')
                                         . ' · masuk dengan ' . (!empty($person['phone']) && empty($person['nik']) ? 'nomor WA' : (empty($person['phone']) ? 'NIK' : 'nomor WA atau NIK'))],
            'revoked'      => ['<span class="badge danger">Dicabut admin</span>', 'Dapat diaktifkan kembali di Ubah Data.'],
            'no_cred'      => ['<span class="badge ended">Belum bisa</span>', 'Nomor WhatsApp dan NIK belum diisi.'],
            'phone_shared' => ['<span class="badge ended">Belum bisa</span>', 'Nomor WA dipakai orang lain juga, dan NIK belum diisi. Isi NIK, atau beri nomor yang berbeda.'],
            'inactive'     => ['<span class="badge ended">Belum bisa</span>', 'Status orang ini nonaktif/wafat.'],
            'no_placement' => ['<span class="badge ended">Belum bisa</span>', 'Belum punya penempatan.'],
        ][$portal];
        ?>
        <?= $info('log-out', 'Akses Portal Pengurus', $portalText[0], $portalText[1]) ?>
        <?= $info('clock', 'Dibuat', e(Ui::date($person['created_at']))) ?>
        <?= $info('clock', 'Terakhir diubah', e(Ui::ago($person['updated_at']))) ?>
      </ul>
      <?php if (!empty($person['notes'])): ?><div class="note"><?= e($person['notes']) ?></div><?php endif; ?>
    </section>
  </div>

  <!-- ============ Riwayat Jabatan ============ -->
  <div role="tabpanel" id="panel-riwayat" aria-labelledby="tab-riwayat" hidden>
    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('layers', 19) ?> Riwayat Jabatan <span class="hint muted" style="font-weight:400;font-size:13px">&mdash; seluruh periode, terbaru di atas</span></h2></div>
      <?php if (empty($history)): ?>
        <div class="empty"><?= Ui::icon('briefcase', 26) ?><span>Belum ada penempatan tercatat untuk orang ini.</span></div>
      <?php else: ?>
        <ol class="tl">
          <?php foreach ($history as $h):
            $now = !empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= date('Y-m-d')); ?>
            <li class="<?= $now ? 'now' : '' ?>">
              <div class="l-head">
                <strong><?= e($h['position_name']) ?></strong>
                <?php if ($h['is_core'] && !$h['unit_name']): ?><span class="badge core">inti</span><?php endif; ?>
                <?php if ($now): ?><span class="badge current">berjalan</span>
                <?php elseif ($h['term_status'] === 'ended'): ?><span class="badge ended">arsip</span><?php endif; ?>
                <?php if ($h['wing'] === 'muslimah'): ?><span class="badge muslimah">Muslimah</span><?php endif; ?>
              </div>
              <div class="l-sub">
                <a href="<?= BASEURL ?>/terms/detail/<?= (int) $h['term_id'] ?>"><?= e($h['level'] . ' ' . $h['structure_name']) ?></a>
                <?= $h['unit_name'] ? ' · ' . e($h['unit_name']) : ' · <span class="muted">pucuk pimpinan</span>' ?>
              </div>
              <div class="l-meta">
                Periode <?= e($h['term_name']) ?> ·
                <?php if ($h['end_date']): ?>
                  berakhir <?= e(Ui::date($h['end_date'])) ?> (<?= e(str_replace('_', ' ', (string) $h['end_reason'])) ?>)
                <?php else: ?>
                  <?= e(Ui::date($h['start_date'] ?: $h['term_start'])) ?> &rarr;
                  <?= e($h['end_date_actual'] ? Ui::date($h['end_date_actual']) : ($h['end_date_planned'] ? Ui::date($h['end_date_planned']) : 'sekarang')) ?>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <?php if ($hidden > 0): ?>
        <div class="note"><?= (int) $hidden ?> penempatan lain berada di luar lingkup akses Anda dan tidak ditampilkan.</div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ============ Aktivitas ============ -->
  <div role="tabpanel" id="panel-aktivitas" aria-labelledby="tab-aktivitas" hidden>
    <section class="card" style="max-width:720px">
      <div class="card-head"><h2><?= Ui::icon('activity', 19) ?> Aktivitas Terkait</h2></div>
      <?php if (empty($activity)): ?>
        <div class="empty"><?= Ui::icon('activity', 26) ?><span>Belum ada aktivitas tercatat.</span></div>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($activity as $a): ?>
            <li>
              <span class="fi t-<?= e($a['tone']) ?>"><?= Ui::icon($a['icon'], 17) ?></span>
              <div class="fb">
                <div class="ft"><?= e($a['title']) ?></div>
                <?php if ($a['sub'] !== ''): ?><div class="fs"><?= e($a['sub']) ?></div><?php endif; ?>
              </div>
              <span class="ftime"><?= e(Ui::ago($a['time'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
