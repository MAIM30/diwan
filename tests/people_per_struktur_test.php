<?php
// PATH LOKASI FILE: tests/people_per_struktur_test.php
// Uji Data Pengurus > Per Struktur terhadap database lokal. HANYA MEMBACA (tak mengubah apa pun):
//   - menekan jenjang langsung menampilkan struktur-strukturnya: MWW = seluruh provinsi, MWD = seluruh kabupaten/kota (tanpa kelompok
//     perantara "MWP" atau "MWW Aceh"); induk hanya keterangan di bawah nama
//   - induk di luar lingkup admin tak pernah bocor (admin DPD tidak melihat nama DPW di atasnya)
//   - menekan struktur tanpa Bidang dan unit menampilkan nama dan jabatan pengurus langsung; yang punya Bidang atau unit tetap pohon
//   - rangkap = ORANG yang memegang lebih dari satu jabatan berjalan (struktur sama pun rangkap); kartu, penyaring, dan lencana "rangkap"
//     di setiap baris orang itu (Susunan Periode dan Per Struktur) memakai dasar yang sama; kerangka Susunan Periode tidak berubah
//   - Daftar: arti "Lengkap" dijelaskan (tooltip dan satu baris keterangan), dan padanan PHP-nya (PersonModel::missingFields) sepakat
//     dengan predikat SQL-nya (COMPLETE_SQL) untuk seluruh orang
// Syarat: database lokal berisi data pengurus biasa proyek ini (MWW/MWD/DPD, DPP, MWP).
//
//   php tests/people_per_struktur_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/PeopleController.php';
foreach (['PersonModel', 'StructureModel', 'PlacementModel', 'UnitModel', 'TermModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class TPS extends PeopleController {
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); require ROOT_DIR . "/app/Views/$v.php";
    }
    public function render($v, $d) { ob_start(); $this->view($v, $d); return ob_get_clean(); }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function national() {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function scoped(array $roots) {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']],
                 'scope' => ['all' => false, 'root_ids' => $roots, 'structure_ids' => (new StructureModel())->descendantIdsOfMany($roots), 'is_national' => false, 'label' => 'Uji']];
}
function page(array $get) { $_GET = $get; ob_start(); (new TPS)->index(); return ob_get_clean(); }
function fragment($termId, array $get = []) { $_GET = $get; ob_start(); (new TPS)->struktur($termId); return ob_get_clean(); }
function overview() {
    return (new PersonModel())->overviewByStructure('', ['gender' => '', 'filter' => '', 'serving' => false, 'level' => '']);
}
function ofLevel(array $ov, $level) { return array_values(array_filter($ov['structures'], function ($s) use ($level) { return $s['level'] === $level; })); }

$pdo = Database::getInstance()->getConnection();
national();
$ov = overview();
$mww = ofLevel($ov, 'MWW'); $mwd = ofLevel($ov, 'MWD'); $dpd = ofLevel($ov, 'DPD'); $dpw = ofLevel($ov, 'DPW');
if (count($mww) < 2 || count($mwd) < 2 || count($dpd) < 2) { echo "LEWAT: data MWW/MWD/DPD di database lokal terlalu sedikit.\n"; exit(0); }

// ---------- Aturan induk (PersonModel::overviewByStructure) ----------
ok(!array_key_exists('parents', $ov), 'overview tak lagi membawa agregat kelompok induk');
ok(count(array_filter($mww, function ($s) { return $s['parent_id'] !== null; })) === 0, 'MWW: tak punya induk (MWP adalah pucuk nasional, bukan keterangan)', 'MWW berinduk: ' . count(array_filter($mww, function ($s) { return $s['parent_id'] !== null; })));
ok(count(array_filter($mwd, function ($s) { return $s['parent_level'] !== 'MWW'; })) === 0, 'MWD: induknya selalu MWW (provinsi)');
ok(count(array_filter($dpd, function ($s) { return $s['parent_level'] !== 'DPW'; })) === 0, 'DPD: induknya selalu DPW (provinsi)');
ok(count(array_filter($dpw, function ($s) { return $s['parent_id'] !== null; })) === 0, 'DPW: tanpa induk (DPP adalah pucuk nasional)');

// ---------- Blok (PeopleOverview::build) pada data sungguhan ----------
$blocks = PeopleOverview::build($ov, (new PersonModel())->intiFlags());
$by = array_column($blocks, null, 'level');
ok(array_reduce($blocks, function ($c, $b) { return $c && !array_key_exists('groups', $b) && count($b['rows']) === $b['strukt']; }, true), 'tiap jenjang: struktur langsung di bawahnya, tanpa kelompok');
ok(count(array_filter($by['MWW']['rows'], function ($r) { return $r['context'] !== ''; })) === 0, 'MWW: seluruh provinsi tanpa keterangan induk');
ok(count(array_filter($by['MWD']['rows'], function ($r) { return $r['context'] === '' || strpos($r['context'], 'Muslimah') === 0; })) === 0, 'MWD: tiap kabupaten/kota membawa nama provinsi pendek sebagai keterangan');
$sorted = true; $rows = $by['MWD']['rows'];
for ($i = 1; $i < count($rows); $i++) if (strnatcasecmp($rows[$i - 1]['context'], $rows[$i]['context']) > 0) $sorted = false;
ok($sorted, 'MWD: diurut provinsi lebih dulu (kabupaten satu provinsi berdampingan)');
ok(count(array_filter($by['MWD']['rows'], function ($r) { return strpos($r['name'], 'Muslimah Wahdah') !== false; })) === 0, 'MWD: nama kabupaten dipendekkan');

// ---------- Lingkup admin: induk di luar lingkup tak bocor ----------
$dpdRow = $dpd[0];
$dpwId = (int) $dpdRow['parent_id'];
scoped([$dpwId]);
$o = overview(); $inScope = ofLevel($o, 'DPD');
ok(count($inScope) >= 1 && count(array_filter($inScope, function ($s) { return $s['parent_name'] === null; })) === 0, 'admin DPW: DPD-nya membawa nama DPW induknya (induk dalam lingkup)', 'DPD: ' . count($inScope));
scoped([(int) $dpdRow['id']]);
$o = overview(); $mine = ofLevel($o, 'DPD');
ok(count($mine) === 1 && $mine[0]['parent_id'] === null && $mine[0]['parent_name'] === null, 'admin DPD: nama DPW di atasnya TIDAK terlihat (di luar lingkup)', json_encode($mine));
$blocksD = PeopleOverview::build($o, []);
ok($blocksD && $blocksD[0]['rows'][0]['context'] === '', 'admin DPD: keterangan induk kosong');
national();

// ---------- Struktur tanpa pengurus / tanpa periode ikut (overview $includeEmpty) ----------
$sc = (new StructureModel())->levelCountsInScope();
$ovAll = (new PersonModel())->overviewByStructure('', ['gender' => '', 'filter' => '', 'serving' => false, 'level' => ''], true);
$perLevel = [];
foreach ($ovAll['structures'] as $s) $perLevel[$s['level']] = ($perLevel[$s['level']] ?? 0) + 1;
ok($perLevel == $sc, 'includeEmpty: setiap struktur dalam lingkup ada di overview (jumlah per jenjang sama dengan tabel structures)', json_encode(array_diff_assoc($sc, $perLevel)));
$noTerm = (int) $pdo->query("SELECT COUNT(*) FROM structures s WHERE s.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.is_current = 1 AND t.deleted_at IS NULL)")->fetchColumn();
ok(count(array_filter($ovAll['structures'], function ($s) { return $s['term_id'] === null; })) === $noTerm, 'includeEmpty: term_id null tepat untuk struktur yang belum punya periode berjalan (' . $noTerm . ')');
ok(count(array_filter($ovAll['structures'], function ($s) { return $s['orang'] === 0; })) >= $noTerm, 'includeEmpty: struktur kosong berangka nol');
ok(count($ovAll['structures']) > count($ov['structures']), 'tanpa includeEmpty (bawaan model): hanya struktur yang berpenghuni, seperti sebelumnya');
function structRows($h) { return substr_count($h, 'class="mon-s"') + substr_count($h, 'class="mon-row mon-noterm"'); }

// ---------- Halaman ----------
$h = page(['lv' => 'MWW']);
ok(structRows($h) === $sc['MWW'], 'halaman ?lv=MWW: satu baris per provinsi, termasuk yang belum punya periode (' . $sc['MWW'] . ')', (string) structRows($h));
ok(strpos($h, 'mon-grp') === false && strpos($h, '<b>MWP ') === false && strpos($h, '<small>' . $sc['MWW'] . ' MWW</small>') === false, 'halaman ?lv=MWW: tak ada kelompok "MWP" (dengan "36 MWW") di antaranya');
ok(strpos($h, 'Jenjang &middot; struktur') !== false, 'judul kolom pertama: "Jenjang · struktur"');
$h = page(['lv' => 'MWD']);
ok(structRows($h) === $sc['MWD'], 'halaman ?lv=MWD: satu baris per kabupaten/kota, termasuk yang belum punya periode (' . $sc['MWD'] . ')', (string) structRows($h));
ok(strpos($h, 'mon-grp') === false && preg_match('/<small>' . preg_quote($by['MWD']['rows'][0]['context'], '/') . '<\/small>/', $h) === 1, 'halaman ?lv=MWD: tanpa kelompok provinsi; provinsi jadi keterangan kecil');
$h = page([]);
preg_match_all('/<details class="mon-g mon-lvl [a-z]+"( open)?[^>]*>\s*<summary[^>]*>\s*<span class="mon-t">.*?<b>([A-Za-z]+)<\/b>/s', $h, $m, PREG_SET_ORDER);
$open = []; foreach ($m as $x) $open[$x[2]] = trim($x[1]) === 'open';
ok(!empty($open['DPW']) && !empty($open['MWW']) && empty($open['DPD']) && empty($open['MWD']), 'halaman bawaan: DPW dan MWW terbuka; DPD dan MWD (ratusan baris) tertutup', json_encode($open));
$h = page(['lv' => 'DPD']);
ok(structRows($h) === $sc['DPD'], 'halaman ?lv=DPD: jenjang yang dipilih tetap terbuka walau panjang (' . $sc['DPD'] . ' struktur)', (string) structRows($h));
$h = page(['lv' => 'DPD', 'kosong' => '0']);
ok(structRows($h) === count($dpd), 'halaman ?lv=DPD&kosong=0: kembali hanya struktur yang berpenghuni (' . count($dpd) . ')', (string) structRows($h));

// ---------- Isi struktur ----------
// MWW yang paling banyak pengurusnya DAN tak punya unit (yang punya unit, mis. Sulawesi Tenggara, memang tetap pohon).
$one = $pdo->query("SELECT t.id, s.name FROM terms t JOIN structures s ON s.id = t.structure_id AND s.level = 'MWW'
                    WHERE t.is_current = 1 AND t.deleted_at IS NULL
                      AND NOT EXISTS (SELECT 1 FROM structure_units su WHERE su.term_id = t.id AND su.deleted_at IS NULL)
                      AND NOT EXISTS (SELECT 1 FROM unit_groups g WHERE g.term_id = t.id AND g.deleted_at IS NULL)
                    ORDER BY (SELECT COUNT(*) FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) DESC LIMIT 1")->fetch();
$tid = (int) $one['id'];
$people = $pdo->query("SELECT p.name FROM placements pl JOIN people p ON p.id = pl.person_id WHERE pl.term_id = $tid AND pl.deleted_at IS NULL
                       AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) ORDER BY pl.id")->fetchAll(PDO::FETCH_COLUMN);
$f = fragment($tid);
ok(strpos($f, '<details') === false && strpos($f, 'mon-flat"') !== false, 'MWW ' . $one['name'] . ': daftar langsung, tanpa simpul untuk dibuka');
ok(strpos($f, 'Pengurus Inti') !== false && strpos($f, '<th>Jabatan</th>') !== false && strpos($f, '<th>Nama</th>') !== false && strpos($f, '<th>Kontak</th>') !== false, 'daftar rata: judul Pengurus Inti dan kolom Jabatan, Nama, Kontak');
$allShown = true; foreach ($people as $nm) if (strpos($f, e($nm)) === false) $allShown = false;
ok($people && $allShown, 'daftar rata: seluruh pengurus (' . count($people) . ') tampil dengan namanya');
// Lencana "rangkap" menurut SQL mandiri: orang yang punya >1 penempatan berjalan (seluruh struktur), pada baris yang sendirinya berjalan.
$curN = "(SELECT COUNT(*) FROM placements x JOIN terms tx ON tx.id = x.term_id AND tx.is_current = 1 AND tx.deleted_at IS NULL
          WHERE x.person_id = pl.person_id AND x.deleted_at IS NULL AND (x.end_date IS NULL OR x.end_date >= CURDATE()))";
$expectBadge = function ($termId) use ($pdo, $curN) {
    return (int) $pdo->query("SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1
        WHERE pl.term_id = " . (int) $termId . " AND pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND $curN > 1")->fetchColumn();
};
ok(substr_count($f, '>rangkap<') === $expectBadge($tid), 'daftar pengurus: lencana "rangkap" tepat pada baris orang yang memegang >1 jabatan (' . $expectBadge($tid) . ')', (string) substr_count($f, '>rangkap<'));
ok(strpos($f, '>penuh periode<') === false || strpos($f, '<th>Masa</th>') !== false, 'kolom Masa tak ada bila tak satu pun penempatan bertanggal (tak ada "penuh periode" berulang)');

// Penyaring: tetap rata, hanya yang cocok; tanpa hasil -> pesan.
$q = $people[0];
$fq = fragment($tid, ['q' => $q]);
ok(strpos($fq, '<details') === false && strpos($fq, e($q)) !== false && substr_count($fq, '<tr>') <= substr_count($f, '<tr>'), 'penyaring: tetap daftar rata dan memuat yang dicari');
$none = fragment($tid, ['q' => 'zzzqqqxxx']);
ok(strpos($none, 'Tidak ada pengurus di struktur ini yang cocok dengan penyaringan') !== false && strpos($none, '<table') === false, 'penyaring tanpa hasil: pesan, bukan tabel kosong');

// Struktur yang punya Bidang atau unit tetap pohon.
foreach (['MWP', 'DPP'] as $level) {
    $t = (int) $pdo->query("SELECT t.id FROM terms t JOIN structures s ON s.id = t.structure_id AND s.level = '$level' WHERE t.is_current = 1 AND t.deleted_at IS NULL LIMIT 1")->fetchColumn();
    if (!$t) continue;
    $f2 = fragment($t);
    ok(substr_count($f2, '<details') > 10 && strpos($f2, 'mon-flat"') === false, "$level (punya Bidang dan unit): tetap pohon yang bisa dibuka");
}

// ---------- Rangkap: orangnya, bukan bendera "utama" ----------
$pm = new PlacementModel(); $um = new UnitModel();

// Kartu dan penyaring: orang dengan >1 penempatan berjalan, walau semuanya di satu struktur.
$sqlRangkap = (int) $pdo->query("SELECT COUNT(*) FROM people p WHERE p.deleted_at IS NULL AND (SELECT COUNT(*) FROM placements x
    JOIN terms tx ON tx.id = x.term_id AND tx.is_current = 1 AND tx.deleted_at IS NULL
    WHERE x.person_id = p.id AND x.deleted_at IS NULL AND (x.end_date IS NULL OR x.end_date >= CURDATE())) > 1")->fetchColumn();
$recapR = (new PersonModel())->recapCounts();
ok($recapR['rangkap'] === $sqlRangkap && $sqlRangkap > 100, 'kartu Rangkap = orang dengan >1 jabatan berjalan (' . $sqlRangkap . ')', (string) $recapR['rangkap']);
ok($recapR['rangkap'] + $recapR['tunggal'] === $recapR['menjabat'], 'Rangkap + tunggal = Menjabat');
ok((new PersonModel())->countInScope('', ['filter' => 'rangkap']) === $sqlRangkap, 'penyaring ?filter=rangkap: jumlahnya sama dengan kartu');
ok((new PersonModel())->countInScope('', ['filter' => 'tidak_rangkap']) === $recapR['total'] - $sqlRangkap, 'penyaring tidak_rangkap: sisanya (tunggal + tidak menjabat)');
$sameStruct = $pdo->query("SELECT pl.person_id FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 AND t.deleted_at IS NULL
    JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
    WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
    GROUP BY pl.person_id HAVING COUNT(*) > 1 AND COUNT(DISTINCT pl.structure_id) = 1 LIMIT 1")->fetchColumn();
if ($sameStruct) {
    $in = (new PersonModel())->idsInScope('', ['gender' => '', 'filter' => 'rangkap', 'serving' => false]);
    ok(in_array((int) $sameStruct, $in, true), 'dua jabatan di SATU struktur tetap dihitung rangkap (orang #' . $sameStruct . ')');
}

// Lencana di tiap baris (Susunan Periode, tanpa $metrics): tiap baris cocok dengan hitungan mandiri; baris orang rangkap SEMUA berlencana.
$byCount = $pdo->query("SELECT t.id FROM terms t JOIN placements pl ON pl.term_id = t.id AND pl.deleted_at IS NULL
    WHERE t.is_current = 1 AND t.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND $curN > 1
    GROUP BY t.id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
if ($byCount) {
    $byCount = (int) $byCount;
    $rowsT = $pm->byTerm($byCount);
    $want = $pdo->query("SELECT pl.id, ($curN > 1) AS r FROM placements pl WHERE pl.term_id = $byCount AND pl.deleted_at IS NULL
                         AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())")->fetchAll(PDO::FETCH_KEY_PAIR);
    $mismatch = 0;
    foreach ($rowsT as $r) {
        $active = empty($r['end_date']) || $r['end_date'] >= date('Y-m-d');
        if (!empty($r['is_rangkap']) !== ($active && !empty($want[$r['id']]))) $mismatch++;
    }
    ok($rowsT && $mismatch === 0, 'byTerm: is_rangkap tiap baris sesuai hitungan mandiri (' . count($rowsT) . ' baris)', "tak sesuai: $mismatch");
    $tree = Susunan::arrange($rowsT, $um->groupsByTerm($byCount), $um->unitsByTerm($byCount));
    $html = (new TPS)->render('admin/_susunan', ['tree' => $tree, 'treeId' => 'uji']);
    $shown = substr_count($html, '>rangkap<');
    ok($shown === count(array_filter($rowsT, function ($r) { return !empty($r['is_rangkap']); })) && $shown > 0, 'Susunan Periode: satu lencana "rangkap" per baris orang rangkap (' . $shown . ')');
    ok(strpos($html, '<details') !== false && strpos($html, 'mon-flat') === false, 'Susunan Periode: kerangka simpul tidak berubah');
    ok(preg_match('/title="Memegang \d+ jabatan berjalan">rangkap</', $html) === 1, 'lencana "rangkap" menyebut jumlah jabatan di tooltipnya');
}

// Periode lampau (bukan berjalan): tak ada lencana, karena rangkap diukur dari jabatan berjalan.
$pastTerm = (int) $pdo->query("SELECT t.id FROM terms t WHERE t.is_current = 0 AND t.deleted_at IS NULL AND EXISTS
    (SELECT 1 FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) LIMIT 1")->fetchColumn();
if ($pastTerm) {
    ok(count(array_filter($pm->byTerm($pastTerm), function ($r) { return !empty($r['is_rangkap']); })) === 0, 'periode yang tidak berjalan: tanpa lencana "rangkap"');
}

// ---------- Daftar: arti "Lengkap" ----------
// Padanan PHP (label "Kurang: ...") harus sepakat dengan predikat SQL (hitungan kartu dan filter) untuk SEMUA orang. Bila NIK kelak
// diwajibkan, keduanya berubah bersama; ujian ini menangkap kalau salah satunya tertinggal.
$all = $pdo->query("SELECT p.id, p.phone, p.gender, " . PersonModel::COMPLETE_SQL . " AS complete FROM people p WHERE p.deleted_at IS NULL")->fetchAll();
$disagree = count(array_filter($all, function ($r) { return (bool) $r['complete'] !== (PersonModel::missingFields($r) === []); }));
ok(count($all) > 100 && $disagree === 0, 'missingFields() sepakat dengan COMPLETE_SQL untuk seluruh orang (' . count($all) . ')', "tak sepakat: $disagree");
ok(PersonModel::missingFields(['phone' => '', 'gender' => 'L']) === ['WA'] && PersonModel::missingFields(['phone' => '62812', 'gender' => '']) === ['jenis kelamin']
   && PersonModel::missingFields(['phone' => null, 'gender' => null]) === ['WA', 'jenis kelamin'] && PersonModel::missingFields(['phone' => '62812', 'gender' => 'P']) === [],
   'missingFields(): WA kosong, jenis kelamin kosong, keduanya, tak ada yang kurang');

$rule = PersonModel::COMPLETE_RULE;
$h = page(['susun' => 'daftar', 'filter' => 'lengkap']);
ok(substr_count($h, 'class="badge ok" title="' . e('Lengkap: ' . $rule) . '"') >= 10 && strpos($h, 'Kurang:') === false, 'Daftar (filter lengkap): setiap baris berbadge Lengkap dengan tooltip aturannya');
$h = page(['susun' => 'daftar', 'filter' => 'belum_lengkap']);
ok(substr_count($h, 'class="badge warn" title="' . e('Belum lengkap. Syaratnya: ' . $rule) . '"') >= 10 && strpos($h, '>Lengkap</span>') === false, 'Daftar (filter belum lengkap): setiap baris berbadge Kurang dengan tooltip aturannya');
ok(strpos($h, '<th title="' . e('Lengkap = ' . $rule) . '">Kelengkapan</th>') !== false, 'Daftar: judul kolom Kelengkapan menjelaskan aturannya');
ok(strpos($h, 'Lengkap = ' . $rule . ' Kolom Kontak tetap menampilkan NIK bila ada.') !== false, 'Daftar: satu baris keterangan di bawah tabel (NIK belum dihitung; kolom Kontak tetap menampilkannya)');
$h = page([]);
ok(strpos($h, 'Kolom Kontak tetap menampilkan NIK') === false && strpos($h, '&middot; WA dan jenis kelamin') !== false && strpos($h, 'Data lengkap: WA dan jenis kelamin terisi.') !== false,
   'Per Struktur: keterangan Daftar tak ikut; kartu dan legenda tetap memakai teks yang sama seperti sebelumnya');

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
