<?php
// PATH LOKASI FILE: tests/people_overview_test.php
// Uji PeopleOverview (penyusun tampilan "Per Struktur" di Data Pengurus) dengan angka rekaan: tanpa basis data.
//   php tests/people_overview_test.php        (keluar 1 bila ada yang gagal)

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/PeopleOverview.php';

$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++;
    echo "GAGAL  $label" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}
function js($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }

$n = fn($orang, $lengkap = null) => ['orang' => $orang, 'lengkap' => $lengkap ?? $orang, 'l' => $orang, 'p' => 0];
$s = fn($id, $level, $name, $orang, $parent = null) => ['id' => $id, 'term_id' => $id + 1000, 'level' => $level, 'name' => $name,
    'parent_id' => $parent ? $parent[0] : null, 'parent_level' => $parent ? $parent[1] : null, 'parent_name' => $parent ? $parent[2] : null] + $n($orang);
$full = ['k' => true, 's' => true, 'b' => true];

// ---- jenjang, sayap, nama ----
ok('wingOf: DPW umum, MWD musl, DSO dewan', PeopleOverview::wingOf('DPW') === 'umum' && PeopleOverview::wingOf('MWD') === 'musl' && PeopleOverview::wingOf('DSO') === 'dewan');
ok('shortName membuang awalan Muslimah', PeopleOverview::shortName('Muslimah Wahdah Islamiyah Wilayah Aceh') === 'Aceh' && PeopleOverview::shortName('Muslimah Wahdah Islamiyah Daerah Kab. Gowa') === 'Kab. Gowa');
ok('shortName membiarkan nama biasa dan nama Pusat', PeopleOverview::shortName('Sulawesi Selatan') === 'Sulawesi Selatan' && PeopleOverview::shortName('Muslimah Wahdah Islamiyah Pusat') === 'Muslimah Wahdah Islamiyah Pusat');
ok('orderedLevels: urutan baku, jenjang tak dikenal di akhir', PeopleOverview::orderedLevels(['MWD', 'XYZ', 'DPD', 'DPP', 'DSO']) === ['DPP', 'DPD', 'MWD', 'DSO', 'XYZ'], js(PeopleOverview::orderedLevels(['MWD', 'XYZ', 'DPD', 'DPP', 'DSO'])));
ok('chipGroups: dikelompokkan per sayap dan urutan baku; jenjang yang orangnya nol ikut (jenjang baru seperti DPLN langsung tampil)', PeopleOverview::chipGroups(['DPW' => 3, 'DPD' => 0, 'DPLN' => 0, 'MWP' => 2, 'DPK' => 1]) === ['umum' => ['DPW', 'DPLN', 'DPD'], 'musl' => ['MWP'], 'dewan' => ['DPK']], js(PeopleOverview::chipGroups(['DPW' => 3, 'DPD' => 0, 'DPLN' => 0, 'MWP' => 2, 'DPK' => 1])));
ok('chipGroups: sayap tanpa jenjang tidak muncul', PeopleOverview::chipGroups(['DPW' => 1]) === ['umum' => ['DPW']]);

// ---- build ----
$overview = [
    'structures' => [
        $s(1, 'DPP', 'Dewan Pimpinan Pusat', 568),
        $s(10, 'DPW', 'Aceh', 14),
        $s(11, 'DPW', 'Sulawesi Selatan', 163),
        $s(20, 'DPD', 'Kab. Bone', 80, [11, 'DPW', 'Sulawesi Selatan']),
        $s(21, 'DPD', 'Kab. Barru', 6, [11, 'DPW', 'Sulawesi Selatan']),
        $s(22, 'DPD', 'Kota Banda Aceh', 9, [10, 'DPW', 'Aceh']),
        $s(23, 'DPD', 'Kab. Tanpa Induk', 3),
        $s(30, 'MWD', 'Muslimah Wahdah Islamiyah Daerah Kab. Gowa', 7, [31, 'MWW', 'Muslimah Wahdah Islamiyah Wilayah Sulawesi Selatan']),
        $s(31, 'MWW', 'Muslimah Wahdah Islamiyah Wilayah Sulawesi Selatan', 12),
        $s(32, 'MWW', 'Muslimah Wahdah Islamiyah Wilayah Aceh', 5),
        $s(40, 'DSO', 'Dewan Syura', 75),
    ],
    'levels' => ['DPP' => $n(568), 'DPW' => $n(177), 'DPD' => $n(98), 'MWW' => $n(17), 'MWD' => $n(7), 'DSO' => $n(75)],
];
$inti = [1 => $full, 10 => $full, 11 => $full, 20 => $full, 21 => ['k' => true, 's' => true, 'b' => false], 22 => $full, 40 => $full];
$blocks = PeopleOverview::build($overview, $inti);
$by = array_column($blocks, null, 'level');

ok('urutan jenjang mengikuti LEVEL_ORDER', array_column($blocks, 'level') === ['DPP', 'DPW', 'DPD', 'MWW', 'MWD', 'DSO'], js(array_column($blocks, 'level')));
ok('jenjang tunggal: single, membawa term_id dan flags', $by['DPP']['single'] === true && $by['DPP']['term_id'] === 1001 && $by['DPP']['flags'] === $full);
ok('jenjang banyak struktur: bukan single, tanpa term_id', $by['DPW']['single'] === false && $by['DPW']['term_id'] === null && $by['DPW']['strukt'] === 2);
ok('angka jenjang diambil dari overview.levels (orang tanpa dobel)', $by['DPW']['num']['orang'] === 177 && $by['DPD']['num']['orang'] === 98);
ok('tak ada lagi kelompok induk di blok mana pun: struktur langsung di bawah jenjang', array_reduce($blocks, function ($c, $b) { return $c && !array_key_exists('groups', $b); }, true));
ok('jumlah baris = jumlah struktur jenjang', array_reduce($blocks, function ($c, $b) { return $c && count($b['rows']) === $b['strukt']; }, true));
ok('DPW: keterangan jumlah DPD per baris', array_column($by['DPW']['rows'], 'sub', 'name') === ['Aceh' => '1 DPD', 'Sulawesi Selatan' => '2 DPD'], js(array_column($by['DPW']['rows'], 'sub', 'name')));
ok('DPW: tanpa induk, keterangan induk kosong', array_column($by['DPW']['rows'], 'context') === ['', '']);

// DPD: semua struktur dalam satu daftar rata; induknya (provinsi) hanya keterangan dan pengurut, supaya satu provinsi berdampingan.
ok('DPD: daftar rata terurut induk lalu nama (tanpa induk lebih dulu)', array_column($by['DPD']['rows'], 'name') === ['Kab. Tanpa Induk', 'Kota Banda Aceh', 'Kab. Barru', 'Kab. Bone'], js(array_column($by['DPD']['rows'], 'name')));
ok('DPD: keterangan = nama provinsi induknya', array_column($by['DPD']['rows'], 'context', 'name') === ['Kab. Tanpa Induk' => '', 'Kota Banda Aceh' => 'Aceh', 'Kab. Barru' => 'Sulawesi Selatan', 'Kab. Bone' => 'Sulawesi Selatan'], js(array_column($by['DPD']['rows'], 'context', 'name')));
ok('inti lengkap per jenjang: DPD 2 dari 4 (Barru kurang, "Tanpa Induk" tanpa data inti)', $by['DPD']['inti_lengkap'] === 2 && $by['DPD']['strukt'] === 4, js([$by['DPD']['inti_lengkap'], $by['DPD']['strukt']]));
$barru = array_column($by['DPD']['rows'], null, 'name')['Kab. Barru'];
ok('struktur tanpa Bendahara: inti_ok false, flags apa adanya', $barru['inti_ok'] === false && $barru['flags']['b'] === false);
$tanpa = array_column($by['DPD']['rows'], null, 'name')['Kab. Tanpa Induk'];
ok('struktur tanpa catatan inti sama sekali: ketiganya kosong', $tanpa['flags'] === ['k' => false, 's' => false, 'b' => false] && $tanpa['inti_ok'] === false);

// MWW = provinsi, MWD = kabupaten/kota: nama dipendekkan, tanpa awalan "Muslimah Wahdah Islamiyah ...".
ok('MWW: seluruh provinsi langsung, terurut nama', array_column($by['MWW']['rows'], 'name') === ['Aceh', 'Sulawesi Selatan'], js(array_column($by['MWW']['rows'], 'name')));
ok('MWW: tanpa keterangan induk; jumlah MWD per provinsi', array_column($by['MWW']['rows'], 'sub', 'name') === ['Aceh' => '', 'Sulawesi Selatan' => '1 MWD'] && array_column($by['MWW']['rows'], 'context') === ['', ''], js(array_column($by['MWW']['rows'], 'sub', 'name')));
ok('MWD: nama kabupaten dipendekkan, provinsinya jadi keterangan', $by['MWD']['rows'][0]['name'] === 'Kab. Gowa' && $by['MWD']['rows'][0]['context'] === 'Sulawesi Selatan', js($by['MWD']['rows'][0]));
ok('Dewan: sayap dewan, nama resmi', $by['DSO']['wing'] === 'dewan' && $by['DSO']['name'] === 'Dewan Syura');
ok('urutan alami: "Kab. 2" mendahului "Kab. 10"', (function () use ($s) {
    $o = ['structures' => [$s(1, 'DPD', 'Kab. 10', 1, [9, 'DPW', 'X']), $s(2, 'DPD', 'Kab. 2', 1, [9, 'DPW', 'X'])], 'levels' => ['DPD' => ['orang' => 2, 'lengkap' => 2, 'l' => 2, 'p' => 0]]];
    return array_column(PeopleOverview::build($o, [])[0]['rows'], 'name') === ['Kab. 2', 'Kab. 10'];
})());
// Struktur tanpa pengurus dan tanpa periode (mis. DPLN yang baru dibuat): ikut sebagai baris angka nol, term_id null.
$kosong = fn($id, $level, $name, $termId = null) => ['id' => $id, 'term_id' => $termId, 'level' => $level, 'name' => $name, 'parent_id' => null, 'parent_level' => null, 'parent_name' => null] + $n(0);
$ov = ['structures' => [$kosong(60, 'DPLN', 'Yaman'), $kosong(61, 'DPLN', 'Arab Saudi'), $kosong(62, 'DPLN', 'Mesir', 1062)], 'levels' => ['DPLN' => $n(0)]];
$dpln = PeopleOverview::build($ov, [])[0];
ok('jenjang tanpa orang: tetap satu blok dengan semua strukturnya (terurut nama), bukan dibuang', $dpln['level'] === 'DPLN' && $dpln['strukt'] === 3 && array_column($dpln['rows'], 'name') === ['Arab Saudi', 'Mesir', 'Yaman'], js(array_column($dpln['rows'], 'name')));
ok('struktur kosong: angka nol, Ketua/Sekretaris/Bendahara kosong, inti tak lengkap', array_reduce($dpln['rows'], function ($c, $r) { return $c && $r['num']['orang'] === 0 && $r['flags'] === ['k' => false, 's' => false, 'b' => false] && $r['inti_ok'] === false; }, true) && $dpln['inti_lengkap'] === 0);
ok('struktur tanpa periode berjalan: term_id null; yang punya periode membawa term_id-nya', array_column($dpln['rows'], 'term_id', 'name') === ['Arab Saudi' => null, 'Mesir' => 1062, 'Yaman' => null], js(array_column($dpln['rows'], 'term_id', 'name')));
$satu = PeopleOverview::build(['structures' => [$kosong(70, 'DPLN', 'Arab Saudi')], 'levels' => ['DPLN' => $n(0)]], [])[0];
ok('jenjang satu-struktur tanpa periode: single dengan term_id null (barisnya statis, tak bisa dimuat)', $satu['single'] === true && $satu['term_id'] === null && $satu['rows'][0]['name'] === 'Arab Saudi');
ok('hasil kosong untuk overview kosong', PeopleOverview::build(['structures' => [], 'levels' => []], []) === []);
ok('jenjang tanpa struktur diabaikan (levels tanpa baris struktur)', PeopleOverview::build(['structures' => [], 'levels' => ['DPW' => $n(1)]], []) === []);

// ---- jenjang terbuka sejak awal ----
ok('defaultOpen: dua jenjang berstruktur banyak yang pertama (DPP satu-struktur tidak dibuka otomatis)', PeopleOverview::defaultOpen($blocks) === ['DPW', 'DPD'], js(PeopleOverview::defaultOpen($blocks)));
ok('defaultOpen: jenjang terpilih saja', PeopleOverview::defaultOpen($blocks, 'DPD') === ['DPD']);
ok('defaultOpen: hanya jenjang satu-struktur -> jenjang pertama dibuka (halaman tak tampak kosong)', PeopleOverview::defaultOpen(array_slice($blocks, 0, 1)) === ['DPP']);
ok('defaultOpen: tanpa jenjang sama sekali', PeopleOverview::defaultOpen([]) === []);
$big = ['level' => 'DPD', 'single' => false, 'strukt' => PeopleOverview::OPEN_MAX_ROWS + 1];
$small = ['level' => 'MWW', 'single' => false, 'strukt' => 36];
$mixed = [['level' => 'DPW', 'single' => false, 'strukt' => 37], $big, $small];
ok('defaultOpen: jenjang yang terlalu panjang (DPD/MWD ratusan baris) dilewati', PeopleOverview::defaultOpen($mixed) === ['DPW', 'MWW'], js(PeopleOverview::defaultOpen($mixed)));
ok('defaultOpen: jenjang panjang tetap terbuka bila dipilih', PeopleOverview::defaultOpen([$big], 'DPD') === ['DPD']);
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
