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
ok('chipGroups: dikelompokkan per sayap, jenjang kosong dibuang', PeopleOverview::chipGroups(['DPW' => 3, 'DPD' => 0, 'MWP' => 2, 'DPK' => 1]) === ['umum' => ['DPW'], 'musl' => ['MWP'], 'dewan' => ['DPK']], js(PeopleOverview::chipGroups(['DPW' => 3, 'DPD' => 0, 'MWP' => 2, 'DPK' => 1])));

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
        $s(30, 'MWD', 'Muslimah Wahdah Islamiyah Daerah Kab. Gowa', 7, [31, 'MWW', 'Sulawesi Selatan']),
        $s(40, 'DSO', 'Dewan Syura', 75),
    ],
    'levels' => ['DPP' => $n(568), 'DPW' => $n(177), 'DPD' => $n(98), 'MWD' => $n(7), 'DSO' => $n(75)],
    'parents' => [11 => $n(86), 10 => $n(9), 31 => $n(7)],
];
$inti = [1 => $full, 10 => $full, 11 => $full, 20 => $full, 21 => ['k' => true, 's' => true, 'b' => false], 22 => $full, 40 => $full];
$blocks = PeopleOverview::build($overview, $inti);
$by = array_column($blocks, null, 'level');

ok('urutan jenjang mengikuti LEVEL_ORDER', array_column($blocks, 'level') === ['DPP', 'DPW', 'DPD', 'MWD', 'DSO'], js(array_column($blocks, 'level')));
ok('jenjang tunggal: single, membawa term_id dan flags', $by['DPP']['single'] === true && $by['DPP']['term_id'] === 1001 && $by['DPP']['flags'] === $full);
ok('jenjang banyak struktur: bukan single, tanpa term_id', $by['DPW']['single'] === false && $by['DPW']['term_id'] === null && $by['DPW']['strukt'] === 2);
ok('angka jenjang diambil dari overview.levels (orang tanpa dobel)', $by['DPW']['num']['orang'] === 177 && $by['DPD']['num']['orang'] === 98);
ok('DPW tidak dikelompokkan (induknya akar)', $by['DPW']['groups'] === [] && count($by['DPW']['rows']) === 2);
ok('DPW: keterangan jumlah DPD per baris', array_column($by['DPW']['rows'], 'sub', 'name') === ['Aceh' => '1 DPD', 'Sulawesi Selatan' => '2 DPD'], js(array_column($by['DPW']['rows'], 'sub', 'name')));
ok('DPD dikelompokkan di bawah DPW induknya, urut nama', array_column($by['DPD']['groups'], 'title') === ['DPW Aceh', 'DPW Sulawesi Selatan'], js(array_column($by['DPD']['groups'], 'title')));
ok('DPD tanpa induk berdiri sendiri di bawah jenjang', count($by['DPD']['rows']) === 1 && $by['DPD']['rows'][0]['name'] === 'Kab. Tanpa Induk');
$g = array_column($by['DPD']['groups'], null, 'parent_id');
ok('angka kelompok induk dari overview.parents; struktur anak dihitung', $g[11]['num']['orang'] === 86 && $g[11]['strukt'] === 2 && $g[10]['strukt'] === 1);
ok('inti lengkap per kelompok: Sulsel 1 dari 2 (Barru tanpa Bendahara)', $g[11]['inti_lengkap'] === 1 && $g[10]['inti_lengkap'] === 1);
ok('inti lengkap per jenjang: DPD 2 dari 4 (Barru kurang, "Tanpa Induk" tanpa data inti)', $by['DPD']['inti_lengkap'] === 2 && $by['DPD']['strukt'] === 4, js([$by['DPD']['inti_lengkap'], $by['DPD']['strukt']]));
$barru = array_column($g[11]['rows'], null, 'name')['Kab. Barru'];
ok('struktur tanpa Bendahara: inti_ok false, flags apa adanya', $barru['inti_ok'] === false && $barru['flags']['b'] === false);
$tanpa = $by['DPD']['rows'][0];
ok('struktur tanpa catatan inti sama sekali: ketiganya kosong', $tanpa['flags'] === ['k' => false, 's' => false, 'b' => false] && $tanpa['inti_ok'] === false);
ok('nama baris MW dipendekkan; induk MWW tanpa nama panjang', $by['MWD']['groups'][0]['rows'][0]['name'] === 'Kab. Gowa' && $by['MWD']['groups'][0]['title'] === 'MWW Sulawesi Selatan', js($by['MWD']['groups'][0]));
ok('Dewan: sayap dewan, nama resmi', $by['DSO']['wing'] === 'dewan' && $by['DSO']['name'] === 'Dewan Syura');
ok('kelompok induk tanpa angka di parents -> nol, bukan galat', (function () use ($s, $n) {
    $o = ['structures' => [$s(5, 'DPD', 'X', 1, [9, 'DPW', 'Y'])], 'levels' => ['DPD' => $n(1)], 'parents' => []];
    return PeopleOverview::build($o, [])[0]['groups'][0]['num'] === ['orang' => 0, 'lengkap' => 0, 'l' => 0, 'p' => 0];
})());
ok('hasil kosong untuk overview kosong', PeopleOverview::build(['structures' => [], 'levels' => [], 'parents' => []], []) === []);
ok('jenjang tanpa struktur diabaikan (levels tanpa baris struktur)', PeopleOverview::build(['structures' => [], 'levels' => ['DPW' => $n(1)], 'parents' => []], []) === []);

// ---- jenjang terbuka sejak awal ----
ok('defaultOpen: dua jenjang berstruktur banyak yang pertama (DPP satu-struktur tidak dibuka otomatis)', PeopleOverview::defaultOpen($blocks) === ['DPW', 'DPD'], js(PeopleOverview::defaultOpen($blocks)));
ok('defaultOpen: jenjang terpilih saja', PeopleOverview::defaultOpen($blocks, 'DPD') === ['DPD']);
ok('defaultOpen: hanya jenjang satu-struktur -> jenjang pertama dibuka (halaman tak tampak kosong)', PeopleOverview::defaultOpen(array_slice($blocks, 0, 1)) === ['DPP']);
ok('defaultOpen: tanpa jenjang sama sekali', PeopleOverview::defaultOpen([]) === []);

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
