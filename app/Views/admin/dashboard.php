<?php
// PATH LOKASI FILE: app/Views/admin/dashboard.php
// Dasbor. Setiap kartu berasal dari data kepengurusan yang benar-benar ada -- tidak ada widget
// hiasan: ringkasan angka, komposisi, struktur terbesar, periode yang segera berakhir, kelengkapan
// data, pendaftar yang menunggu, dan aktivitas terbaru.
$this->view('layouts/header', ['title' => $title]);

$days = function ($n) {
    $n = (int) $n;
    if ($n < 0)   return ['Lewat ' . abs($n) . ' hari', true, 'alert'];
    if ($n === 0) return ['Berakhir hari ini', false, 'clock'];
    if ($n <= 60) return [$n . ' hari lagi', false, 'clock'];
    return [floor($n / 30) . ' bulan lagi', false, 'clock'];
};
$qWA  = (int) $quality['total'] - (int) $quality['tanpa_hp'];
$qNIK = (int) $quality['total'] - (int) $quality['tanpa_nik'];
$pct  = function ($have, $total) { return $total > 0 ? round($have / $total * 100) : 100; };

$qa = [];
if (Auth::canEdit()) {
    $qa[] = ['people/form',  'user-plus',       'Tambah Orang', 0];
    $qa[] = ['terms',        'briefcase',       'Penempatan Baru', 0];
    $qa[] = ['terms/form',   'calendar-check',  'Periode Baru', 0];
}
if (Auth::canVerify()) $qa[] = ['registrations', 'clipboard-check', 'Verifikasi', (int) $stats['pending']];
$qa[] = ['people',     'users',   'Semua Pengurus', 0];
$qa[] = ['structures', 'network', 'Struktur', 0];
$qa = array_slice($qa, 0, 4);
?>

<div class="page-head">
  <div class="hello">
    <h1><?= e(Ui::greeting()) ?>, <?= e(Ui::firstName(Auth::name())) ?></h1>
    <p>Ringkasan kepengurusan di lingkup <strong><?= e(AdminScope::label() ?: '—') ?></strong>.</p>
  </div>
  <div class="actions"><span class="date-chip"><?= Ui::icon('calendar', 17) ?> <?= e(Ui::dateLong()) ?></span></div>
</div>

<?php if ((int) $stats['pending'] > 0 && Auth::canVerify()): ?>
  <div class="alert warn" role="status">
    Ada <strong><?= (int) $stats['pending'] ?></strong> pendaftaran menunggu verifikasi di lingkup Anda.
    <a href="<?= BASEURL ?>/registrations">Tinjau sekarang</a>.
  </div>
<?php endif; ?>

<!-- ============ Kartu ringkasan ============ -->
<div class="stats">
  <a class="stat" href="<?= BASEURL ?>/people?menjabat=1">
    <div class="stat-top"><span class="chip-ic"><?= Ui::icon('users', 19) ?></span><span class="lbl">Pengurus aktif</span></div>
    <div class="num"><?= Ui::n($stats['pengurus_aktif']) ?></div>
    <?php if ((int) $stats['penempatan_baru_30'] > 0): ?>
      <span class="delta up"><?= Ui::icon('arrow-up', 13) ?> +<?= Ui::n($stats['penempatan_baru_30']) ?> baru · 30 hari terakhir</span>
    <?php else: ?>
      <span class="delta">Tidak ada yang baru · 30 hari terakhir</span>
    <?php endif; ?>
  </a>

  <a class="stat t-green" href="<?= BASEURL ?>/terms">
    <div class="stat-top"><span class="chip-ic"><?= Ui::icon('calendar-check', 19) ?></span><span class="lbl">Periode berjalan</span></div>
    <div class="num"><?= Ui::n($stats['periode_berjalan']) ?></div>
    <?php if ((int) $stats['tanpa_periode'] > 0): ?>
      <span class="delta warn"><?= Ui::icon('alert', 13) ?> <?= (int) $stats['tanpa_periode'] ?> struktur belum punya periode</span>
    <?php else: ?>
      <span class="delta up"><?= Ui::icon('check', 13) ?> Semua struktur punya periode</span>
    <?php endif; ?>
  </a>

  <a class="stat t-violet" href="<?= BASEURL ?>/structures">
    <div class="stat-top"><span class="chip-ic"><?= Ui::icon('network', 19) ?></span><span class="lbl">Struktur aktif</span></div>
    <div class="num"><?= Ui::n($stats['struktur']) ?></div>
    <span class="delta">Di lingkup akses Anda</span>
  </a>

  <a class="stat t-rose" href="<?= BASEURL ?>/registrations">
    <div class="stat-top"><span class="chip-ic"><?= Ui::icon('inbox', 19) ?></span><span class="lbl">Menunggu verifikasi</span></div>
    <div class="num"><?= Ui::n($stats['pending']) ?></div>
    <?php if ((int) $stats['pending_baru_7'] > 0): ?>
      <span class="delta warn"><?= Ui::icon('arrow-up', 13) ?> <?= (int) $stats['pending_baru_7'] ?> baru · 7 hari terakhir</span>
    <?php else: ?>
      <span class="delta">Tidak ada yang baru · 7 hari terakhir</span>
    <?php endif; ?>
  </a>
</div>

<div class="dash">
  <div class="dash-main">

    <div class="row2">
      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('trending-up', 19) ?> Penempatan Dicatat per Bulan</h2></div>
        <p class="card-sub">12 bulan terakhir. Mengukur aktivitas pencatatan, bukan jumlah pengurus &mdash; impor SK massal membuat satu bulan tampak tinggi.</p>
        <?= $areaChart ?>
      </section>

      <section class="card">
        <div class="card-head"><h2><?= Ui::icon('layers', 19) ?> Komposisi Pengurus</h2></div>
        <p class="card-sub">Pengurus aktif per tingkat struktur.</p>
        <?= $tierDonut ?>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
          <div class="small muted" style="margin-bottom:10px;font-weight:600">Jenis kelamin</div>
          <?= $genderBar ?>
        </div>
      </section>
    </div>

    <div class="row2">
      <section class="card">
        <div class="card-head">
          <h2><?= Ui::icon('network', 19) ?> Pengurus per Struktur</h2>
          <a class="more" href="<?= BASEURL ?>/structures">Semua <?= Ui::icon('arrow-right', 14) ?></a>
        </div>
        <p class="card-sub">Struktur dengan pengurus aktif terbanyak.</p>
        <?= $structBars ?>
      </section>

      <section class="card">
        <div class="card-head">
          <h2><?= Ui::icon('clock', 19) ?> Periode Segera Berakhir</h2>
          <a class="more" href="<?= BASEURL ?>/terms">Semua <?= Ui::icon('arrow-right', 14) ?></a>
        </div>
        <p class="card-sub">Menurut tanggal akhir rencana di SK, 12 bulan ke depan. &ldquo;Lewat&rdquo; berarti serah terima belum dicatat.</p>
        <?php if (empty($endings)): ?>
          <div class="empty"><?= Ui::icon('calendar-check', 26) ?><span>Tidak ada periode yang berakhir dalam 12 bulan ke depan.</span></div>
        <?php else: ?>
          <ul class="endlist">
            <?php foreach ($endings as $t): [$dayText, $late, $dayIcon] = $days($t['days_left']); ?>
              <li>
                <span class="fi <?= $late ? 't-amber' : '' ?>"><?= Ui::icon('calendar', 17) ?></span>
                <div class="e-main">
                  <strong><a href="<?= BASEURL ?>/terms/detail/<?= (int) $t['id'] ?>"><?= e($t['level'] . ' ' . $t['structure_name']) ?></a></strong>
                  <span><?= e($t['name']) ?> · s.d. <?= e(Ui::date($t['end_date_planned'])) ?></span>
                </div>
                <span class="days<?= $late ? ' late' : '' ?>"><?= Ui::icon($dayIcon, 14) ?> <?= e($dayText) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>

      <section class="card">
        <div class="card-head">
          <h2><?= Ui::icon('users', 19) ?> Pengurus Terbaru</h2>
          <a class="more" href="<?= BASEURL ?>/people">Lihat semua <?= Ui::icon('arrow-right', 14) ?></a>
        </div>
        <?php if (empty($recentPeople)): ?>
          <div class="empty"><?= Ui::icon('user-plus', 26) ?><span>Belum ada data pengurus di lingkup Anda.</span></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Nama</th><th>Jabatan</th><th>Dicatat</th></tr></thead>
              <tbody>
              <?php foreach ($recentPeople as $p): ?>
                <tr>
                  <td><?= Ui::personCell($p, BASEURL . '/people/detail/' . (int) $p['id']) ?></td>
                  <td class="small">
                    <?php if ($p['position_name']): ?>
                      <strong><?= e($p['position_name']) ?></strong>
                      <div class="muted"><?= e($p['structure_label']) ?></div>
                    <?php else: ?>
                      <span class="muted">Belum ditempatkan</span>
                    <?php endif; ?>
                  </td>
                  <td class="small muted nowrap"><?= e(Ui::ago($p['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

  </div>

  <aside class="dash-side">

    <section class="card">
      <div class="card-head"><h2>Aksi Cepat</h2></div>
      <div class="qa">
        <?php foreach ($qa as [$href, $icon, $label, $badge]): ?>
          <a href="<?= BASEURL ?>/<?= e($href) ?>">
            <?php if ($badge > 0): ?><span class="qa-badge"><?= (int) $badge ?></span><?php endif; ?>
            <span class="chip-ic"><?= Ui::icon($icon, 20) ?></span><?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if (Auth::canVerify()): ?>
    <section class="card">
      <div class="card-head">
        <h2><?= Ui::icon('inbox', 19) ?> Menunggu Verifikasi</h2>
        <a class="more" href="<?= BASEURL ?>/registrations">Semua <?= Ui::icon('arrow-right', 14) ?></a>
      </div>
      <?php if (empty($pending)): ?>
        <div class="empty"><?= Ui::icon('check', 26) ?><span>Tidak ada pendaftaran yang menunggu.</span></div>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($pending as $r): ?>
            <li>
              <?= Ui::avatar($r, 'md') ?>
              <div class="fb">
                <div class="ft"><?= Ui::nameHtml($r) ?></div>
                <div class="fs"><?= e($r['level'] . ' ' . $r['structure_name']) ?> · <?= e(Ui::ago($r['created_at'])) ?></div>
              </div>
              <a class="btn small secondary" href="<?= BASEURL ?>/registrations/review/<?= (int) $r['id'] ?>">Tinjau</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('shield', 19) ?> Kelengkapan Data</h2></div>
      <p class="card-sub">Dari <?= Ui::n($quality['total']) ?> pengurus aktif.</p>

      <div class="meter-row">
        <div class="meter-top"><span>Nomor WhatsApp terisi</span><strong><?= $pct($qWA, $quality['total']) ?>%</strong></div>
        <div class="meter" role="img" aria-label="<?= $pct($qWA, $quality['total']) ?> persen"><i style="width:<?= $pct($qWA, $quality['total']) ?>%"></i></div>
        <?php if ((int) $quality['tanpa_hp'] > 0): ?>
          <div class="small muted" style="margin-top:5px"><?= Ui::n($quality['tanpa_hp']) ?> belum ·
            <a href="<?= BASEURL ?>/people?filter=tanpa_hp&amp;menjabat=1">lihat daftarnya</a></div>
        <?php endif; ?>
      </div>

      <div class="meter-row">
        <div class="meter-top"><span>NIK terisi</span><strong><?= $pct($qNIK, $quality['total']) ?>%</strong></div>
        <div class="meter" role="img" aria-label="<?= $pct($qNIK, $quality['total']) ?> persen"><i style="width:<?= $pct($qNIK, $quality['total']) ?>%"></i></div>
        <?php if ((int) $quality['tanpa_nik'] > 0): ?>
          <div class="small muted" style="margin-top:5px"><?= Ui::n($quality['tanpa_nik']) ?> belum ·
            <a href="<?= BASEURL ?>/people?filter=tanpa_nik&amp;menjabat=1">lihat daftarnya</a></div>
        <?php endif; ?>
      </div>

      <?php if ((int) $quality['unit_tanpa_bidang'] > 0): ?>
        <div class="note" style="margin-bottom:0"><?= Ui::icon('alert', 15) ?>
          <?= (int) $quality['unit_tanpa_bidang'] ?> unit di periode berjalan belum dipetakan ke Bidang.</div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= Ui::icon('activity', 19) ?> Aktivitas Terbaru</h2></div>
      <?php if (empty($activity)): ?>
        <div class="empty"><?= Ui::icon('activity', 26) ?><span>Belum ada aktivitas di lingkup Anda.</span></div>
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

  </aside>
</div>

<?php $this->view('layouts/footer'); ?>
