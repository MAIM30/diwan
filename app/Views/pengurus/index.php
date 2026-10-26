<?php
// PATH LOKASI FILE: app/Views/pengurus/index.php
// Beranda Portal Pengurus. NIK dan nomor WA ditampilkan utuh (milik sendiri). Susunan rekan ada di halaman Susunan.
// Susunan halaman: latar atas melengkung (bilah atas + kartu identitas: salam, nama, gelar, kontak) -> kartu sambutan
// (jabatan, aksi Biodata, cincin progres masa jabatan) -> angka ringkas -> kartu-kartu rincian.
$this->view('layouts/member_header', ['title' => $title, 'person' => $person, 'active' => 'beranda', 'hero' => true]);

[, $nameOnly] = Ui::nameParts($person);
$periods = count(array_unique(array_column($history, 'term_id')));
$structs = count(array_unique(array_column($history, 'structure_id')));

// Nama dua warna: semua kata kecuali yang terakhir gelap, kata terakhir berwarna (nama satu kata: gelap saja).
$words = preg_split('/\s+/u', trim($nameOnly), -1, PREG_SPLIT_NO_EMPTY) ?: [(string) $nameOnly];
$lastWord = count($words) > 1 ? array_pop($words) : '';
$firstWords = implode(' ', $words);

// Nama selalu tampil utuh; yang panjang dikecilkan hurufnya supaya tetap muat di kartu (batas panjang -> kelas ukuran).
$nameLen = mb_strlen(trim($nameOnly));
$nameSize = $nameLen > 34 ? ' is-xs' : ($nameLen > 24 ? ' is-s' : ($nameLen > 16 ? ' is-m' : ''));
$gelar = Ui::titlesLine($person);

$main = $current[0] ?? null;
$life = $lives[0] ?? null;
?>

<section class="home-id">
  <?= Ui::avatar($person, 'xl') ?>
  <div class="home-id-card">
    <div class="home-greet"><?= e(Ui::greeting()) ?></div>
    <h1 class="home-name<?= $nameSize ?>"><?= e($firstWords) ?><?= $lastWord !== '' ? ' <b>' . e($lastWord) . '</b>' : '' ?></h1>
    <?php if ($gelar !== ''): ?><div class="home-titles"><?= e($gelar) ?></div><?php endif; ?>
    <div class="home-meta">
      <?php if (!empty($person['phone'])): ?><span><?= Ui::icon('phone', 13) ?> <span class="mono"><?= e($person['phone']) ?></span></span><?php endif; ?>
      <?php if (!empty($person['nik'])): ?><span><?= Ui::icon('shield', 13) ?> <span class="mono"><?= e($person['nik']) ?></span></span><?php endif; ?>
    </div>
    <div class="home-id-foot">
      <span><?= Ui::icon('calendar', 14) ?> <?= e(Ui::dateLong()) ?></span>
      <a href="<?= BASEURL ?>/pengurus/ubah">Ubah data <?= Ui::icon('edit', 13) ?></a>
    </div>
  </div>
</section>

<section class="home-welcome">
  <div class="hw-main">
    <?php if ($main): ?>
      <div class="hw-role"><?= Ui::icon('briefcase', 16) ?> <?= e($main['position_name']) ?></div>
      <p class="hw-note">
        <?= e($main['level'] . ' ' . $main['structure_name']) ?>
        <?= count($current) > 1 ? ' · +' . (count($current) - 1) . ' jabatan lain' : '' ?>
        <?php if ($life): ?> · <?= $life['late'] ? 'lewat ' . e(Ui::span(abs($life['days_left']))) : 'sisa ' . e(Ui::span($life['days_left'])) ?><?php endif; ?>
      </p>
    <?php else: ?>
      <div class="hw-role"><?= Ui::icon('briefcase', 16) ?> Tidak sedang menjabat</div>
      <p class="hw-note">Anda tidak sedang menjabat di periode berjalan.</p>
    <?php endif; ?>
  </div>

  <?php if ($life): $pct = max(0, min(100, (int) $life['pct'])); ?>
    <div class="hw-ring<?= $life['late'] ? ' is-late' : '' ?>">
      <span class="hw-ring-date"><?= Ui::icon('calendar', 13) ?> <?= e(Ui::date($life['end'])) ?></span>
      <div class="hw-ring-box" role="img" aria-label="<?= $pct ?> persen masa jabatan telah berjalan">
        <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
          <circle class="trk" cx="50" cy="50" r="42"/>
          <circle class="bar" cx="50" cy="50" r="42" stroke-dasharray="<?= round($pct * 2.639, 1) ?> 263.9"/>
        </svg>
        <div class="hw-ring-num"><b><?= $pct ?>%</b><small>berjalan</small></div>
      </div>
      <span class="hw-ring-date"><?= Ui::icon('calendar', 13) ?> <?= e(Ui::date($life['start'])) ?></span>
    </div>
  <?php endif; ?>

  <div class="hw-actions">
    <div class="hw-act">
      <a class="btn hw-btn" href="<?= BASEURL ?>/pengurus/biodata"><?= Ui::icon('file-text', 15) ?> <?= $biodata ? 'Ubah Biodata' : 'Isi Biodata' ?></a>
      <span class="hw-sub"><?= Ui::icon('calendar', 13) ?> <?= $biodata ? 'Diperbarui ' . e(Ui::date($biodata['updated_at'])) : 'Belum diisi' ?></span>
    </div>
    <?php if ($biodata): ?>
      <div class="hw-act">
        <a class="btn hw-btn orange" href="<?= BASEURL ?>/pengurus/biodata_pdf" target="_blank" rel="noopener"><?= Ui::icon('file-text', 15) ?> Unduh PDF</a>
        <span class="hw-sub"><?= Ui::icon($biodata['gdrive_file_id'] ? 'check' : 'clock', 13) ?> <?= $biodata['gdrive_file_id'] ? 'Terkirim ke Drive' : 'Tersimpan' ?></span>
      </div>
    <?php endif; ?>
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
              <div class="t-meta"><?= $c['unit_name'] ? e($c['unit_name']) . ' · ' : '' ?>Periode <?= e($c['term_name']) ?></div>

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
