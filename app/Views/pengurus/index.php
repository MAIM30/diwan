<?php
// PATH LOKASI FILE: app/Views/pengurus/index.php
// Beranda Portal Pengurus. NIK dan nomor WA ditampilkan utuh (milik sendiri). Susunan rekan ada di halaman Susunan.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'beranda']);

[$pre, $nameOnly, $suf] = Ui::nameParts($person);
$periods = count(array_unique(array_column($history, 'term_id')));
$structs = count(array_unique(array_column($history, 'structure_id')));
?>

<section class="m-hero">
  <?= Ui::avatar($person, 'xl') ?>
  <div class="m-hero-main">
    <div class="eyebrow"><?= e(Ui::greeting()) ?></div>
    <h1><?= e($nameOnly) ?></h1>
    <div class="titles">
      <?php if ($pre): ?><span class="t"><small>Gelar depan</small><b><?= e($pre) ?></b></span><?php endif; ?>
      <?php if ($suf): ?><span class="t"><small>Gelar belakang</small><b><?= e($suf) ?></b></span><?php endif; ?>
    </div>
    <div class="meta-chips">
      <?php if (!empty($person['nik'])): ?>
        <span class="meta-chip"><?= Ui::icon('shield', 14) ?> <span class="mono"><?= e($person['nik']) ?></span></span>
      <?php endif; ?>
      <?php if (!empty($person['phone'])): ?>
        <span class="meta-chip"><?= Ui::icon('phone', 14) ?> <span class="mono"><?= e($person['phone']) ?></span></span>
      <?php endif; ?>
      <span class="meta-chip"><?= Ui::icon('briefcase', 14) ?>
        <?= count($current) > 0 ? count($current) . ' jabatan aktif' : 'Tidak sedang menjabat' ?></span>
    </div>
  </div>
</section>

<div class="stats mini">
  <div class="stat"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('briefcase', 19) ?></span><span class="lbl">Penempatan</span></div><div class="num"><?= count($history) ?></div></div>
  <div class="stat t-green"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('calendar-check', 19) ?></span><span class="lbl">Periode</span></div><div class="num"><?= $periods ?></div></div>
  <div class="stat t-violet"><div class="stat-top"><span class="chip-ic"><?= Ui::icon('network', 19) ?></span><span class="lbl">Struktur</span></div><div class="num"><?= $structs ?></div></div>
</div>

<div class="m-grid">
  <div class="m-col">

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('briefcase', 19) ?> Jabatan Saya Saat Ini</h2>
        <?php if (!empty($current)): ?><a class="more" href="<?= BASEURL ?>/pengurus/susunan">Lihat susunan <?= Ui::icon('arrow-right', 14) ?></a><?php endif; ?>
      </div>
      <?php if (empty($current)): ?>
        <div class="empty"><?= Ui::icon('briefcase', 26) ?><span>Anda tidak sedang menjabat di periode berjalan.</span></div>
      <?php else: ?>
        <div class="tiles">
          <?php foreach ($current as $i => $c): $life = $lives[$i] ?? null; ?>
            <div class="tile">
              <div class="t-pos"><?= e($c['position_name']) ?>
                <?php if ($c['is_core'] && !$c['unit_name']): ?><span class="badge core">inti</span><?php endif; ?></div>
              <div class="t-struct"><?= e($c['level'] . ' ' . $c['structure_name']) ?></div>
              <div class="t-meta"><?= $c['unit_name'] ? e($c['unit_name']) : 'Pucuk pimpinan' ?> · Periode <?= e($c['term_name']) ?></div>

              <?php if ($life): $late = $life['late']; ?>
                <?php // Progres masa jabatan: angka besar = sisa waktu; bar satu hue (tanpa gradasi merah-hijau,
                      // yang akan memperlakukan "lama menjabat" sebagai status baik/buruk). Molor = kuning + ikon + label. ?>
                <div class="life<?= $late ? ' is-late' : '' ?>">
                  <div class="life-top">
                    <span class="life-big"><?= e($late ? 'Lewat ' . Ui::span(abs($life['days_left'])) : Ui::span($life['days_left'])) ?></span>
                    <span class="delta<?= $late ? ' warn' : '' ?>"><?= Ui::icon($late ? 'alert' : 'clock', 13) ?> <?= (int) $life['pct'] ?>% berjalan</span>
                  </div>
                  <div class="life-lbl"><?= $late ? 'Melewati tanggal akhir rencana' : 'Sisa masa jabatan' ?></div>
                  <div class="meter lg" role="img" aria-label="<?= (int) $life['pct'] ?> persen masa jabatan telah berjalan"><i style="width:<?= (int) $life['pct'] ?>%"></i></div>
                  <div class="life-dates"><span><?= e(Ui::date($life['start'])) ?></span><span><?= e(Ui::date($life['end'])) ?></span></div>
                </div>
              <?php else: ?>
                <div class="small muted" style="margin-top:10px">Periode ini belum punya tanggal akhir.</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('file-text', 19) ?> Biodata Pengurus</h2>
        <?php if ($biodata): ?><span class="badge <?= $biodata['gdrive_file_id'] ? 'ok' : '' ?>">
          <?= $biodata['gdrive_file_id'] ? 'Terkirim' : 'Tersimpan' ?></span><?php endif; ?>
      </div>
      <?php if (!$biodata): ?>
        <p class="small muted" style="margin:0 0 10px">Lengkapi profil singkat dan pernyataan kesiapan Anda sebagai pengurus.</p>
      <?php else: ?>
        <p class="small muted" style="margin:0 0 10px">
          Terakhir diperbarui <?= e(Ui::date($biodata['updated_at'])) ?>.
          <?= $biodata['gdrive_file_id'] ? 'Sudah tersimpan di Google Drive.' : '' ?>
        </p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:0">
        <a class="btn secondary" href="<?= BASEURL ?>/pengurus/biodata"><?= Ui::icon('edit', 15) ?> <?= $biodata ? 'Lihat / Ubah' : 'Isi Sekarang' ?></a>
        <?php if ($biodata): ?><a class="btn secondary" href="<?= BASEURL ?>/pengurus/biodata_pdf" target="_blank" rel="noopener"><?= Ui::icon('file-text', 15) ?> Unduh PDF</a><?php endif; ?>
      </div>
    </section>

  </div>

  <div class="m-col">

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('network', 19) ?> Struktur Saya</h2></div>
      <?php if (empty($chains)): ?>
        <div class="empty" style="padding:12px"><span>Belum ada.</span></div>
      <?php else: foreach ($chains as $chain): ?>
        <div class="chain">
          <?php foreach ($chain as $k => $s): ?>
            <?= $k > 0 ? Ui::icon('chevron-right', 14, 'sep') : '' ?>
            <span class="chain-item"><b><?= e($s['level']) ?></b> <?= e($s['name']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endforeach; endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('shield', 19) ?> Kelengkapan Data Saya</h2></div>
      <?php if (empty($missing)): ?>
        <div class="alert success" style="margin:0"><?= Ui::icon('check', 15) ?> Data Anda sudah lengkap.</div>
      <?php else: ?>
        <p class="small muted" style="margin:0 0 10px">Belum terisi:</p>
        <div class="chips">
          <?php foreach ($missing as $f): ?><span class="chip"><?= e($f) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('clock', 19) ?> Riwayat Masuk</h2></div>
      <?php if (empty($logins)): ?>
        <div class="empty" style="padding:12px"><span>Belum ada.</span></div>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($logins as $k => $l): ?>
            <li>
              <span class="fi t-slate"><?= Ui::icon('log-out', 16) ?></span>
              <div class="fb">
                <div class="ft"><?= e(Ui::date($l['created_at'])) ?>, <?= e(date('H:i', strtotime($l['created_at']))) ?><?= $k === 0 ? ' <span class="badge">terbaru</span>' : '' ?></div>
                <div class="fs">Jaringan <span class="mono"><?= e(Text::maskIp($l['ip'])) ?></span></div>
              </div>
              <span class="ftime"><?= e(Ui::ago($l['created_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="note" style="margin-bottom:0">Tidak mengenali salah satunya? Hubungi admin struktur Anda agar akses portal Anda dicabut.</div>
    </section>

  </div>
</div>

<?php $this->view('layouts/member_footer'); ?>
