<?php
// PATH LOKASI FILE: tests/portal_beranda_test.php
// Uji Beranda Portal Pengurus: bahan perhitungan (PortalHome, Ui::spanParts), kelengkapan data dan aturan privasinya
// (MemberModel::completeness), tampilan untuk empat keadaan (satu jabatan, rangkap, tidak menjabat, masa jabatan lewat),
// dan Beranda sungguhan lewat PengurusController untuk beberapa pengurus nyata di database lokal (hanya BACA).
//
//   php tests/portal_beranda_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'Member', 'PortalHome'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['PositionModel', 'PlacementModel', 'MemberModel', 'BiodataModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";
require_once ROOT_DIR . '/app/Controllers/PengurusController.php';

class Redirected extends RuntimeException {}
// Tanpa kerangka halaman (header/footer): yang diuji isi Beranda.
class TV extends PengurusController {
    public function show($v, array $d) { ob_start(); $this->view($v, $d); return ob_get_clean(); }
    public function home() { ob_start(); $this->index(); return ob_get_clean(); }
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); require ROOT_DIR . "/app/Views/$v.php";
    }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function has($html, $needle) { return strpos($html, $needle) !== false; }
// Potongan HTML sebuah kartu (dari kelas penandanya sampai </section> pertama sesudahnya).
function tile($html, $cls) {
    $a = strpos($html, $cls);
    if ($a === false) return '';
    $b = strpos($html, '</section>', $a);
    return substr($html, $a, $b === false ? null : $b - $a);
}

$TODAY = '2026-11-06';
$members = new MemberModel();

// ---------- 1. Durasi: span() tetap sama, spanParts() sumber tunggalnya ----------
foreach ([[0, '0 hr'], [15, '15 hr'], [55, '55 hr'], [59, '59 hr'], [60, '2 bln'], [100, '3 bln'], [364, '12 bln'], [365, '1 th'], [400, '1 th 1 bln'], [1771, '4 th 10 bln'], [-5, '0 hr']] as [$d, $exp]) {
    ok(Ui::span($d) === $exp, "Ui::span($d)", Ui::span($d));
}
ok(Ui::spanParts(55) === [[55, 'hari']], 'spanParts 55 hari');
ok(Ui::spanParts(1771) === [[4, 'tahun'], [10, 'bulan']], 'spanParts 4 tahun 10 bulan');
ok(Ui::spanParts(365) === [[1, 'tahun']], 'spanParts tepat setahun tanpa bagian bulan');
ok(Ui::spanParts(-3) === [[0, 'hari']], 'spanParts negatif dianggap 0');

// ---------- 2. Jabatan utama: Pengurus Inti dulu, urutan lain tetap ----------
function pl(array $o = []) {
    return $o + ['position_name' => 'Anggota', 'level' => 'DPD', 'structure_name' => 'Kota Kendari', 'unit_name' => null, 'structure_unit_id' => null,
        'is_core' => 0, 'term_id' => 1, 'structure_id' => 10, 'start_date' => null, 'term_start' => '2021-01-01', 'end_date' => null,
        'end_date_actual' => null, 'end_date_planned' => '2026-12-31', 'is_current' => 1];
}
$cur = [
    pl(['position_name' => 'A-unit', 'unit_name' => 'Departemen Hukum', 'structure_unit_id' => 5, 'is_core' => 1]),   // inti TAPI di unit: bukan Pengurus Inti
    pl(['position_name' => 'B-lain']),                                                                              // tingkat struktur, tidak inti
    pl(['position_name' => 'C-inti', 'is_core' => 1]),
    pl(['position_name' => 'D-inti', 'is_core' => 1]),
];
$ord = array_column(PortalHome::orderCurrent($cur), 'position_name');
ok($ord === ['C-inti', 'D-inti', 'A-unit', 'B-lain'], 'orderCurrent: inti tingkat struktur dulu, sisanya urutan semula', implode(',', $ord));
ok(array_column(PortalHome::orderCurrent([]), 'position_name') === [], 'orderCurrent kosong');
ok(PortalHome::placeLabel(pl(['is_core' => 1])) === 'Pengurus Inti', 'placeLabel inti');
ok(PortalHome::placeLabel(pl()) === 'Pimpinan Lainnya', 'placeLabel tingkat struktur non-inti');
ok(PortalHome::placeLabel(pl(['unit_name' => 'Biro Hukum'])) === 'Biro Hukum', 'placeLabel unit');
ok(PortalHome::colleaguesTitle(pl(['unit_name' => 'Biro Hukum'])) === 'Rekan di Biro Hukum', 'judul rekan se-unit');
ok(PortalHome::colleaguesTitle(pl()) === 'Pimpinan DPD Kota Kendari', 'judul rekan tingkat struktur');
ok(PortalHome::structureLabel('DPP', 'Dewan Pimpinan Pusat') === 'DPP', 'structureLabel: DPP tanpa jabaran');
ok(PortalHome::structureLabel('DPK', 'Dewan Pengawas Keuangan') === 'Dewan Pengawas Keuangan', 'structureLabel: dewan lain memakai namanya');
ok(PortalHome::structureLabel('DSO', 'Dewan Syura') === 'Dewan Syura', 'structureLabel: Dewan Syura');
ok(PortalHome::structureLabel('MWW', 'Sulawesi Tenggara') === 'MWW Sulawesi Tenggara', 'structureLabel: struktur biasa = jenjang + nama');

// ---------- 3. Garis periode ----------
$life = MemberModel::life(pl(), $TODAY);
ok($life && $life['pct'] === 97 && $life['days_left'] === 55 && !$life['late'], 'life 2021-2026 pada 6 Nov 2026: 97%, sisa 55 hari', json_encode($life));
$ticks = PortalHome::yearTicks($life);
ok(count($ticks) === 5 && $ticks === array_values(array_unique($ticks)) && min($ticks) > 1 && max($ticks) < 99, 'yearTicks 2021-2026: lima tanda 2022..2026', json_encode($ticks));
ok(abs($ticks[2] - 50.0) < 0.2, 'yearTicks: 1 Jan 2024 di tengah periode', (string) $ticks[2]);
$life2 = MemberModel::life(pl(['term_start' => '2026-09-13', 'end_date_planned' => '2031-09-12']), $TODAY);
ok(count(PortalHome::yearTicks($life2)) === 5, 'yearTicks 2026-09..2031-09: 2027..2031');
ok(PortalHome::yearTicks(['start' => '2026-01-01', 'end' => '2026-06-30']) === [], 'yearTicks periode di dalam satu tahun: kosong');
ok(PortalHome::bubbleStyle(97) === 'right:0' && PortalHome::bubbleStyle(3) === 'left:0' && has(PortalHome::bubbleStyle(50), 'left:50%'), 'bubbleStyle ujung dan tengah');
$late = MemberModel::life(pl(['end_date_planned' => '2026-10-22']), $TODAY);
$cdLate = PortalHome::countdown($late);
ok($late['late'] && $cdLate['late'] && $cdLate['parts'] === [[15, 'hari']] && has($cdLate['label'], 'Lewat'), 'countdown lewat 15 hari', json_encode($cdLate));
$cd = PortalHome::countdown($life);
ok(!$cd['late'] && $cd['parts'] === [[55, 'hari']] && $cd['label'] === 'Sisa masa jabatan', 'countdown sisa 55 hari');

// ---------- 4. Perjalanan saya, waktu masuk, sapaan ----------
$hist = [
    pl(['position_name' => 'Sekretaris', 'is_core' => 1]),
    pl(['position_name' => 'Anggota', 'unit_name' => 'Departemen Tarbiyah', 'term_start' => '2016-01-01', 'end_date_planned' => '2021-12-31', 'is_current' => 0]),
    pl(['position_name' => 'Staf', 'term_start' => '2011-01-01', 'end_date_planned' => '2016-12-31', 'is_current' => 0]),
    pl(['position_name' => 'Staf lama', 'term_start' => '2006-01-01', 'end_date_planned' => '2006-12-31', 'is_current' => 0]),
    pl(['position_name' => 'Tanpa akhir', 'end_date_planned' => null, 'term_start' => '2000-05-05', 'is_current' => 0]),
];
$car = PortalHome::career($hist, 3, $TODAY);
ok(count($car['rows']) === 3 && $car['more'] === 2, 'career: 3 baris dan 2 sisanya');
ok($car['rows'][0]['years'] === '2021 – 2026' && $car['rows'][0]['now'] === true && $car['rows'][0]['sub'] === 'DPD Kota Kendari · Pengurus Inti', 'career baris berjalan', json_encode($car['rows'][0], JSON_UNESCAPED_UNICODE));
ok($car['rows'][1]['now'] === false && $car['rows'][1]['sub'] === 'DPD Kota Kendari · Departemen Tarbiyah', 'career baris arsip memuat unit');
ok(PortalHome::career([$hist[4]], 3, $TODAY)['rows'][0]['years'] === 'sejak 2000', 'career tanpa tanggal akhir: "sejak"');
$now = strtotime('2026-11-06 12:00:00');
ok(PortalHome::when('2026-11-06 07:31:00', $now) === 'Hari ini, 07:31', 'when hari ini');
ok(PortalHome::when('2026-11-05 19:40:00', $now) === 'Kemarin, 19:40', 'when kemarin');
ok(PortalHome::when('2026-11-03 06:58:00', $now) === '3 Nov 2026, 06:58', 'when tanggal', PortalHome::when('2026-11-03 06:58:00', $now));
ok(PortalHome::when('', $now) === '—', 'when kosong');
$g = PortalHome::greeting('Muhammad Yusuf Tahir');
ok($g['first'] === 'Muhammad Yusuf' && $g['last'] === 'Tahir' && $g['size'] === ' is-m', 'greeting nama 3 kata', json_encode($g));
ok(PortalHome::greeting('Ruslan')['last'] === '' && PortalHome::greeting('Ruslan')['first'] === 'Ruslan', 'greeting satu kata tidak diwarnai');
ok(PortalHome::greeting(str_repeat('Abdurrahman ', 4))['size'] === ' is-xs', 'greeting nama sangat panjang dikecilkan');

// ---------- 5. Kelengkapan data dan privasi ----------
$P = ['id' => 7, 'uuid' => 'u-7', 'title_prefix' => 'Ust.', 'name' => 'Muhammad Yusuf Tahir', 'title_suffix' => 'S.H.', 'full_name' => 'Ust. Muhammad Yusuf Tahir, S.H.',
      'phone' => '081200000456', 'nik' => '7471010101880456', 'email' => 'yusuf.tahir@contoh.id', 'birth_date' => '1988-03-14',
      'address_text' => 'Jl. Mekar Rahasia No. 99', 'photo_path' => '', 'biodata_photo_id' => ''];
$items = $members->completeness($P);
$by = []; foreach ($items as $i) $by[$i['key']] = $i;
ok(count($items) === 6, 'completeness: enam butir');
ok($by['email']['filled'] && $by['email']['value'] === Text::maskEmail('yusuf.tahir@contoh.id') && !has($by['email']['value'], 'yusuf.tahir'), 'email tersamar', (string) $by['email']['value']);
ok($by['birth_date']['filled'] && $by['birth_date']['value'] === null, 'tanggal lahir terisi tapi nilainya tidak ditampilkan');
ok($by['address_text']['filled'] && $by['address_text']['value'] === null, 'alamat jalan terisi tapi nilainya tidak ditampilkan');
ok($by['phone']['value'] === '081200000456' && $by['nik']['value'] === '7471010101880456', 'WhatsApp dan NIK utuh untuk pemiliknya');
ok(!$by['photo_path']['filled'] && $by['photo_path']['value'] === null, 'foto belum ada');
$items2 = $members->completeness(['biodata_photo_id' => 'FILE123'] + $P);
$by2 = []; foreach ($items2 as $i) $by2[$i['key']] = $i;
ok($by2['photo_path']['filled'] && $by2['photo_path']['value'] === 'Terpasang', 'foto Biodata dihitung sebagai foto profil');
$empty = $members->completeness(['id' => 1]);
ok(count(array_filter($empty, function ($i) { return $i['filled']; })) === 0, 'orang tanpa data: nol terisi');
ok(count(array_filter($members->completeness(['phone' => ' ', 'nik' => '', 'email' => null] + $P), function ($i) { return !$i['filled']; })) === 4, 'spasi dan kosong tidak dihitung terisi');
foreach ($items as $i) {
    $selfEditable = isset(MemberModel::SELF_FIELDS[$i['key']]);
    ok($i['self'] === $selfEditable, "butir {$i['key']}: 'bisa diisi sendiri' = ada di SELF_FIELDS", $i['self'] ? 'ya' : 'tidak');
}

// ---------- 6. Tampilan: empat keadaan ----------
function person(array $o = []) {
    global $P;
    return $o + $P;
}
function render(array $o) {
    global $members, $TODAY;
    $person = $o['person'] ?? person();
    $current = $o['current'] ?? [];
    $d = $o + [
        'title' => 'Beranda', 'person' => $person, 'history' => $current, 'current' => $current,
        'lives' => array_map(function ($c) use ($TODAY) { return MemberModel::life($c, $TODAY); }, $current),
        'chain' => [['level' => 'DPP', 'name' => 'Wahdah Islamiyah'], ['level' => 'DPW', 'name' => 'Sulawesi Tenggara'], ['level' => 'DPD', 'name' => 'Kota Kendari']],
        'colleagues' => [], 'colleaguesTotal' => 0, 'data' => $members->completeness($person), 'logins' => [], 'biodata' => null,
    ];
    return (new TV)->show('pengurus/index', $d);
}
function colleague($name, $pos) { return ['id' => crc32($name), 'uuid' => 'c-' . crc32($name), 'title_prefix' => '', 'name' => $name, 'title_suffix' => '', 'full_name' => $name, 'position_name' => $pos, 'is_core' => 0]; }

// 6a. Satu jabatan di unit
$h = render(['current' => [pl(['position_name' => 'Anggota', 'unit_name' => 'Departemen Kaderisasi', 'structure_unit_id' => 4])],
    'colleagues' => [colleague('Ridwan Hakim', 'Ketua'), colleague('Nur Alamsyah', 'Wakil Ketua'), colleague('Hasbi Ashiddiq', 'Sekretaris'), colleague('Rahmat Hidayat', 'Anggota')], 'colleaguesTotal' => 11]);
ok(has($h, 'class="bn-hero bn-t-hero"') && has($h, '>Anggota</h2>') && has($h, 'Departemen Kaderisasi'), '6a: kartu gelap berisi jabatan dan unit');
ok(has($h, '<span class="u">hari</span>') && has($h, '>Sisa masa jabatan<') && !has($h, 'bn-count late'), '6a: sisa waktu dalam hari, tidak lewat');
ok(substr_count($h, 'class="tk"') === 5 && has($h, '97% berjalan'), '6a: lima tanda tahun dan gelembung 97%');
ok(substr_count($h, 'class="c') === 3 && has($h, 'class="c last">DPD Kota Kendari<') && has($h, '>DPW Sulawesi Tenggara<'), '6a: rantai struktur tiga tingkat, yang terakhir ditandai');
ok(!has($h, 'id="bn-lain"') && !has($h, 'bn-pill'), '6a: tanpa jabatan lain tidak ada kartu maupun tautannya');
ok(has($h, 'Rekan di Departemen Kaderisasi') && substr_count(tile($h, 'bn-t-rekan'), '<li>') === 4 && has($h, '+ 7 rekan lainnya'), '6a: empat rekan dan "+ 7 rekan lainnya"');

// 6b. Rangkap: jabatan inti jadi utama, sisanya kartu
$cur = PortalHome::orderCurrent([
    pl(['position_name' => 'Ketua', 'unit_name' => 'Departemen Hukum', 'structure_unit_id' => 4]),
    pl(['position_name' => 'Sekretaris', 'is_core' => 1]),
    pl(['position_name' => 'Anggota', 'unit_name' => 'Biro Hukum', 'structure_unit_id' => 9, 'level' => 'DPW', 'structure_name' => 'Sulawesi Tenggara']),
    pl(['position_name' => 'Anggota DPK', 'is_core' => 1, 'level' => 'DPK', 'structure_name' => 'Dewan Pengawas Keuangan', 'term_start' => '2026-09-13', 'end_date_planned' => '2031-09-12']),
]);
$h = render(['current' => $cur, 'biodata' => ['updated_at' => '2026-10-12 10:00:00', 'gdrive_file_id' => 'abc'],
    'logins' => [['created_at' => '2026-11-06 07:31:00', 'ip' => inet_pton('114.122.9.9')], ['created_at' => '2026-11-05 21:05:00', 'ip' => inet_pton('36.74.1.2')]]]);
ok(has($h, '>Sekretaris</h2>') && has($h, 'Pengurus Inti'), '6b: Pengurus Inti menjadi jabatan utama');
ok(has($h, '+3 jabatan lain') && has($h, 'id="bn-lain"') && substr_count($h, 'class="bn-oc"') === 3, '6b: tiga kartu jabatan lain dan tautan "+3"');
ok(has($h, 'sisa 4 th 10 bln') && has($h, 'sisa 55 hr'), '6b: sisa waktu tiap jabatan lain dalam singkatan');
ok(has($h, 'bn-chip core">inti<'), '6b: jabatan lain yang inti bertanda');
ok(has($h, 'Pimpinan DPD Kota Kendari') === false, '6b: tanpa rekan, blok rekan tidak ditampilkan');
ok(has($h, 'Ubah</a>') && has($h, 'Unduh PDF') && has($h, 'terkirim ke Drive'), '6b: Biodata sudah ada: Ubah, Unduh PDF, status Drive');
ok(has($h, 'Terakhir masuk') && substr_count(tile($h, 'bn-t-aman'), '<li>') === 2 && has($h, '07:31') && has($h, '21:05'), '6b: riwayat masuk: terakhir dan daftar dua entri');
ok(has($h, '114.122.9.***') && !has($h, '114.122.9.9'), '6b: IP tersamar');
ok(has($h, 'Tidak mengenali salah satunya?'), '6b: catatan keamanan lama tetap ada');
// pengurus rangkap 10 jabatan: hanya OTHERS_MAX kartu + "+N jabatan lainnya"
$many = [pl(['position_name' => 'Utama', 'is_core' => 1])];
for ($i = 1; $i <= 9; $i++) $many[] = pl(['position_name' => "Lain $i", 'unit_name' => "Unit $i", 'structure_unit_id' => 100 + $i]);
$h = render(['current' => $many]);
ok(substr_count($h, 'class="bn-oc"') === PortalHome::OTHERS_MAX && has($h, '+ 5 jabatan lainnya') && has($h, '+9 jabatan lain'), '6b: sepuluh jabatan: 4 kartu, "+ 5 jabatan lainnya", tautan "+9"', (string) substr_count($h, 'class="bn-oc"'));

// 6c. Privasi di tampilan
ok(has($h, '7471010101880456') && has($h, '081200000456'), '6c: NIK dan WhatsApp utuh');
ok(!has($h, 'yusuf.tahir@contoh.id') && has($h, Text::maskEmail('yusuf.tahir@contoh.id')), '6c: email hanya tersamar');
ok(!has($h, '14 Mar 1988') && !has($h, '1988-03-14') && !has($h, 'Mekar Rahasia'), '6c: tanggal lahir dan alamat jalan tidak ditampilkan');
ok(has($h, 'Terisi'), '6c: butir yang terisi bertanda "Terisi"');
$hMiss = render(['person' => person(['address_text' => '', 'email' => '', 'photo_path' => '']), 'current' => [pl()]]);
ok(has($hMiss, '/pengurus/ubah#address_text') && has($hMiss, '/pengurus/ubah#email'), '6c: butir yang bisa diisi sendiri punya tombol Isi ke kolomnya');
ok(!has($hMiss, 'ubah#photo_path') && has($hMiss, 'bn-adm'), '6c: foto profil hanya admin, tanpa tombol');
ok(has($hMiss, 'Belum: Email, Alamat, Foto profil') && has($hMiss, '3 dari 6 terisi'), '6c: ringkasan "Belum: ..." dan 3 dari 6', '');

// 6d. Tidak menjabat
$hist = [pl(['position_name' => 'Anggota', 'unit_name' => 'Departemen Tarbiyah', 'level' => 'MWD', 'structure_name' => 'Kota Baubau', 'term_start' => '2016-01-01', 'end_date_planned' => '2021-12-31', 'is_current' => 0])];
$h = render(['current' => [], 'history' => $hist, 'chain' => []]);
ok(has($h, 'Tidak sedang menjabat') && has($h, 'bn-idle') && !has($h, 'class="bn-hero'), '6d: kartu terang "Tidak sedang menjabat"');
ok(has($h, 'Anggota · Departemen Tarbiyah') && has($h, 'MWD Kota Baubau · berakhir 31 Des 2021'), '6d: jabatan terakhir tampil');
ok(!has($h, 'id="bn-lain"') && !has($h, 'bn-t-rekan') && has($h, 'bn-t-riw') && has($h, 'arsip'), '6d: tanpa blok rekan; perjalanan tampil sebagai arsip');
$h = render(['current' => [], 'history' => [], 'chain' => []]);
ok(has($h, 'Tidak sedang menjabat') && !has($h, 'bn-t-riw') && !has($h, 'bn-last'), '6d: tanpa riwayat sama sekali: tidak ada baris jabatan terakhir maupun perjalanan');

// 6e. Masa jabatan lewat (kuning, tenang)
$h = render(['current' => [pl(['position_name' => 'Ketua', 'is_core' => 1, 'end_date_planned' => '2026-10-22'])], 'person' => person()]);
ok(has($h, 'bn-count late') && has($h, 'bn-track late') && has($h, '+15<span class="u">hari</span>'), '6e: "+15 hari" kuning dan garis kuning');
ok(has($h, 'Lewat dari tanggal akhir rencana') && has($h, '100% berjalan'), '6e: label lewat dan 100%');
// periode tanpa tanggal akhir
$h = render(['current' => [pl(['end_date_planned' => null])]]);
ok(has($h, 'Periode ini belum punya tanggal akhir.') && !has($h, 'bn-track'), '6e: tanpa tanggal akhir: tanpa garis periode');

// 6f. Lengkap, Biodata belum ada, dan keamanan keluaran
$full = person(['photo_path' => 'uploads/x.jpg']);
$h = render(['person' => $full, 'current' => [pl()]]);
ok(has($h, 'Data Anda lengkap') && has($h, '6/6') && has($h, 'bn-comp ok'), '6f: data lengkap: kartu hijau 6/6');
ok(has($h, 'Isi Biodata') && has($h, 'Belum diisi') && !has($h, 'Unduh PDF'), '6f: Biodata belum ada: Isi Biodata, tanpa Unduh PDF');
$h = render(['person' => person(['name' => '<script>alert(1)</script> Ali']), 'current' => [pl(['position_name' => '<img src=x onerror=1>'])]]);
ok(!has($h, '<script>alert') && !has($h, '<img src=x') && has($h, '&lt;img src=x'), '6f: nama dan jabatan di-escape');

// ---------- 7. Beranda sungguhan (controller + database lokal, hanya baca) ----------
$pdo = Database::getInstance()->getConnection();
$cond = "p.deleted_at IS NULL AND p.status = 'active' AND p.portal_enabled = 1";
$curSql = "SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 WHERE pl.person_id = p.id AND pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())";
$pick = function ($having) use ($pdo, $cond, $curSql) {
    return (int) $pdo->query("SELECT p.id FROM people p WHERE $cond AND ($curSql) $having ORDER BY p.id LIMIT 1")->fetchColumn();
};
$people = ['satu' => $pick('= 1'), 'rangkap' => $pick('>= 3'), 'tidak' => $pick('= 0')];
function realHome($pid) {
    $_SESSION = ['member' => ['person_id' => $pid, 'uuid' => 'x', 'since' => time(), 'last' => time()]];
    return (new TV)->home();
}
foreach ($people as $kind => $pid) {
    if (!$pid) { echo "LEWAT: tidak ada pengurus '$kind' di database lokal\n"; continue; }
    $h = realHome($pid);
    $nCur = (int) $pdo->query("SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 WHERE pl.person_id = $pid AND pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())")->fetchColumn();
    ok(has($h, 'bn-hello') && has($h, 'bn-t-data') && has($h, 'Data saya'), "7 $kind (#$pid): halaman tampil utuh");
    if ($kind === 'tidak') {
        ok(has($h, 'Tidak sedang menjabat') && !has($h, 'class="bn-hero'), "7 $kind: kartu terang");
    } else {
        ok(has($h, 'class="bn-hero bn-t-hero"'), "7 $kind: kartu gelap");
        $others = $nCur - 1;
        $cards = substr_count($h, 'class="bn-oc"');
        ok($cards === min($others, PortalHome::OTHERS_MAX), "7 $kind: kartu jabatan lain = min(jabatan lain, " . PortalHome::OTHERS_MAX . ")", "$cards vs $others");
        ok($others === 0 ? !has($h, 'bn-pill') : has($h, "+$others jabatan lain"), "7 $kind: tautan \"+N jabatan lain\" sesuai jumlah jabatan");
        // Pengurus Inti tingkat struktur, bila punya, harus jadi jabatan utama.
        $hasTop = (int) $pdo->query("SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 JOIN positions po ON po.id = pl.position_id WHERE pl.person_id = $pid AND pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND pl.structure_unit_id IS NULL AND " . PositionModel::CORE_SQL)->fetchColumn();
        if ($hasTop) ok(has($h, 'class="bn-skuo">Pengurus Inti<'), "7 $kind: punya Pengurus Inti, maka itu jabatan utama");
    }
}
// Jumlah rekan: hitungan = daftar tanpa batas, untuk tiga tempat jabatan nyata (unit dan non-unit).
$rows = $pdo->query("SELECT pl.* FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND pl.structure_unit_id IS NOT NULL ORDER BY pl.id LIMIT 3")->fetchAll();
$rows = array_merge($rows, $pdo->query("SELECT pl.* FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND pl.structure_unit_id IS NULL ORDER BY pl.id LIMIT 3")->fetchAll());
foreach ($rows as $r) {
    $all = $members->colleagues($r, $r['person_id'], 100000);
    ok($members->colleaguesCount($r, $r['person_id']) === count($all), "7 rekan: hitungan sama dengan daftar (penempatan #{$r['id']})", $members->colleaguesCount($r, $r['person_id']) . ' vs ' . count($all));
    ok(count($members->colleagues($r, $r['person_id'], 4)) === min(4, count($all)), "7 rekan: batas 4 (penempatan #{$r['id']})");
    foreach ($all as $c) { if ((int) $c['id'] === (int) $r['person_id']) { ok(false, "7 rekan: diri sendiri tidak ikut (penempatan #{$r['id']})"); break; } }
}

// ---------- 8. Penjaga kode dan gaya ----------
$view = file_get_contents(ROOT_DIR . '/app/Views/pengurus/index.php');
$css = file_get_contents(ROOT_DIR . '/public/assets/css/app.css');
$hdr = file_get_contents(ROOT_DIR . '/app/Views/layouts/member_header.php');
preg_match_all("/Ui::icon\('([a-z0-9-]+)'/", $view . $hdr, $m);
foreach (array_unique($m[1]) as $name) {
    ok($name === 'circle' || Ui::icon($name, 16) !== Ui::icon('circle', 16), "ikon '$name' ada di Ui::\$icons");
}
foreach (['bn-t-hero' => 1, 'bn-t-bio' => 2, 'bn-t-lain' => 3, 'bn-t-data' => 4, 'bn-t-rekan' => 5, 'bn-t-riw' => 6, 'bn-t-aman' => 7] as $cls => $n) {
    ok(has($css, ".$cls { order: $n; }"), "CSS: urutan ponsel $cls = $n");
}
foreach (['m-home', 'home-id', 'home-welcome', 'hw-ring', 'hw-role', 'life-big', 'chain-item'] as $dead) {
    ok(!has($css, $dead) && !has($view, $dead) && !has($hdr, $dead), "tidak ada sisa gaya/markup lama: $dead");
}
ok(!has($hdr, '$hero') && !has($view, "'hero'"), 'penanda hero dihapus dari header dan view');
// Semua kelas bn-* yang dipakai view punya aturan di CSS.
preg_match_all('/\bbn-[a-z0-9-]+/', $view, $m2);
foreach (array_unique($m2[0]) as $cls) {
    if (in_array($cls, ['bn-hero-title', 'bn-lain'], true)) continue;   // id (aria-labelledby, tautan #bn-lain), bukan kelas
    ok(has($css, $cls), "CSS memuat kelas $cls");
}

echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
