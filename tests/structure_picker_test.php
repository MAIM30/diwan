<?php
// PATH LOKASI FILE: tests/structure_picker_test.php
// Uji pemilih struktur untuk Tambah Jabatan: pencarian (TermModel::pickerSearch), titik JSON placements/structures, dan pemasangannya di
// profil (modal Tambah Jabatan) serta formulir penempatan. Angka harapan DIHITUNG LEWAT SQL. Hanya membaca database lokal.
//
//   php tests/structure_picker_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
ob_start();   // header() di titik JSON tak boleh bertabrakan dengan keluaran uji

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'PeopleOverview', 'Susunan', 'AdminNaming', 'Photo', 'Lokasi'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/PlacementsController.php';
require_once ROOT_DIR . '/app/Controllers/PeopleController.php';
foreach (['TermModel', 'StructureModel', 'PersonModel', 'PlacementModel', 'PositionModel', 'UnitModel', 'DashboardModel', 'MemberModel', 'BiodataModel', 'AccountModel'] as $m) {
    if (is_file(ROOT_DIR . "/app/Models/$m.php")) require_once ROOT_DIR . "/app/Models/$m.php";
}

class Denied extends RuntimeException {}
class TPl extends PlacementsController {
    protected function requireLogin($roles = null) {}
    protected function denied($message = '') { throw new Denied($message); }
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
    public function json() { ob_start(); $this->structures(); return ob_get_clean(); }
    public function formHtml($term, $id = null) { ob_start(); $this->form($term, $id); return ob_get_clean(); }
}
class TPe extends PeopleController {
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
    public function detailHtml($id) { ob_start(); $this->detail($id); return ob_get_clean(); }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function has($h, $n) { return strpos($h, $n) !== false; }
function national($role = 'super_admin') {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => [$role]], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function scoped(array $roots) {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']],
                 'scope' => ['all' => false, 'root_ids' => $roots, 'structure_ids' => (new StructureModel())->descendantIdsOfMany($roots), 'is_national' => false, 'label' => 'Uji']];
}
function sql($pdo, $q) { return (int) $pdo->query($q)->fetchColumn(); }
function api(array $get) { $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET'; $j = (new TPl)->json(); $d = json_decode($j, true); return $d === null ? ['_raw' => $j] : $d; }

$pdo = Database::getInstance()->getConnection();
national();
$tm = new TermModel();
$base = "FROM terms t JOIN structures s ON s.id = t.structure_id AND s.deleted_at IS NULL JOIN governance_periods gp ON gp.id = t.governance_period_id WHERE t.deleted_at IS NULL";
$allUmum = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'umum'");
$allMusl = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'muslimah'");
$allAny = sql($pdo, "SELECT COUNT(*) $base");
if ($allUmum < 100 || $allMusl < 50) { echo "LEWAT: data periode di database lokal terlalu sedikit.\n"; exit(0); }

// ---------- 1. Pencarian ----------
$r = $tm->pickerSearch(['wing' => 'umum']);
ok($r['total'] === $allUmum, 'sayap Umum: total = SQL', $r['total'] . ' vs ' . $allUmum);
ok(count($r['items']) === TermModel::PICKER_LIMIT, 'hanya ' . TermModel::PICKER_LIMIT . ' baris yang dikirim', (string) count($r['items']));
ok(array_sum($r['levels']) === $allUmum, 'jumlah per jenjang = total');
ok(count(array_unique(array_column($r['items'], 'wing'))) === 1 && $r['items'][0]['wing'] === 'umum', 'semua baris sayap Umum');
$expLv = $pdo->query("SELECT s.level, COUNT(*) c $base AND s.wing = 'umum' GROUP BY s.level")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($expLv as $lv => $n) ok(($r['levels'][$lv] ?? -1) === (int) $n, "jenjang $lv = SQL", ($r['levels'][$lv] ?? -1) . ' vs ' . $n);
ok(array_keys($r['levels']) === PeopleOverview::orderedLevels(array_keys($r['levels'])), 'jenjang berurut baku (DPP, DPW, DPLN, DPD, ...)', implode(',', array_keys($r['levels'])));
$r2 = $tm->pickerSearch(['wing' => 'muslimah']);
ok($r2['total'] === $allMusl && $r2['items'][0]['wing'] === 'muslimah', 'sayap Muslimah: total = SQL dan barisnya Muslimah', $r2['total'] . ' vs ' . $allMusl);
$r3 = $tm->pickerSearch(['wing' => 'sembarang']);
ok($r3['total'] === $allAny, 'sayap tak dikenal = semua sayap', $r3['total'] . ' vs ' . $allAny);

// Periode berjalan lebih dulu, lalu jenjang baku.
$r = $tm->pickerSearch(['wing' => 'umum', 'limit' => 15]);
$cur = array_column($r['items'], 'current');
$firstOther = array_search(false, $cur, true);
ok($firstOther === false || !in_array(true, array_slice($cur, $firstOther), true), 'periode berjalan lebih dulu daripada periode lain');
ok($r['items'][0]['level'] === 'DPP', 'baris pertama tanpa saringan: DPP', $r['items'][0]['level']);

// Saringan jenjang: baris = jenjang itu, angka jenjang tidak berubah.
$rd = $tm->pickerSearch(['wing' => 'umum', 'lv' => 'DPD', 'limit' => 15]);
ok($rd['total'] === (int) $expLv['DPD'] && count(array_unique(array_column($rd['items'], 'level'))) === 1 && $rd['items'][0]['level'] === 'DPD', 'lv=DPD: total dan baris', (string) $rd['total']);
ok($rd['levels'] === $tm->pickerSearch(['wing' => 'umum'])['levels'], 'angka jenjang tidak berubah saat jenjang dipilih (chip tetap lengkap)');
ok($tm->pickerSearch(['wing' => 'umum', 'lv' => 'MWD'])['total'] === 0, 'jenjang dari sayap lain: tak ada hasil');
ok($tm->pickerSearch(['wing' => 'umum', 'lv' => ['DPD']])['total'] === $allUmum, 'lv berupa larik diabaikan');

// Cari: kata-kata terpisah, semuanya harus cocok.
$rm = $tm->pickerSearch(['wing' => 'umum', 'q' => 'kab maros']);
$expM = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'umum' AND s.name LIKE '%Maros%' AND s.name LIKE '%Kab%'");
ok($rm['total'] === $expM && $expM >= 1 && strpos($rm['items'][0]['name'], 'Maros') !== false, '"kab maros" menemukan Kab. Maros', $rm['total'] . ' vs ' . $expM);
ok($rm['items'][0]['parent'] !== '' && strpos($rm['items'][0]['parent'], 'DPW') === 0, 'baris memuat induknya ("DPW Sulawesi Selatan")', $rm['items'][0]['parent']);
ok(array_sum($rm['levels']) === $rm['total'], 'angka jenjang mengikuti pencarian');
$rp = $tm->pickerSearch(['wing' => 'umum', 'q' => 'sulawesi selatan', 'lv' => 'DPD', 'limit' => 15]);
// Setiap kata harus ada di jenjang, nama, induk, kode wilayah, atau acuan (boleh di bidang berbeda): "selatan" bisa dari nama ("Konawe Selatan"), "sulawesi" dari induknya.
$hay = "CONCAT_WS(' ', s.level, s.name, p.level, p.name, s.region_code, gp.name)";
$expP = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'umum' AND s.level = 'DPD' AND EXISTS (SELECT 1 FROM structures s2 LEFT JOIN structures p ON p.id = s2.parent_id WHERE s2.id = s.id AND $hay LIKE '%sulawesi%' AND $hay LIKE '%selatan%')");
ok($rp['total'] === $expP && $expP >= 20, 'cari "sulawesi selatan" + jenjang DPD: setiap kata harus cocok', $rp['total'] . ' vs ' . $expP);
$exact = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'umum' AND s.level = 'DPD' AND EXISTS (SELECT 1 FROM structures p WHERE p.id = s.parent_id AND p.name = 'Sulawesi Selatan')");
ok($exact >= 5 && $exact <= $rp['total'], 'semua DPD di bawah DPW Sulawesi Selatan ikut ditemukan', $exact . ' <= ' . $rp['total']);
ok($tm->pickerSearch(['q' => '%'])['total'] === 0 && $tm->pickerSearch(['q' => '_'])['total'] === 0, '% dan _ dicari apa adanya (bukan karakter pengganti)');
ok($tm->pickerSearch(['q' => "x' OR '1'='1"])['total'] === 0, 'tanda kutip tidak merusak kueri');
ok($tm->pickerSearch(['q' => str_repeat('a ', 200)])['total'] === $tm->pickerSearch(['q' => 'a'])['total'], 'pencarian sangat panjang dipotong (empat kata) dan aman');
ok(count($tm->pickerSearch(['wing' => 'umum', 'limit' => 3])['items']) === 3 && count($tm->pickerSearch(['limit' => 999])['items']) === TermModel::PICKER_MAX_LIMIT, 'limit dijepit');

// Acuan periode (formulir penempatan): hanya struktur yang memakai acuan itu.
$gp = $pdo->query("SELECT gp.id, COUNT(*) c $base AND s.wing = 'umum' GROUP BY gp.id ORDER BY c DESC LIMIT 1")->fetch();
$rg = $tm->pickerSearch(['wing' => 'umum', 'gp' => $gp['id']]);
ok($rg['total'] === (int) $gp['c'], 'gp: total = periode dengan acuan itu', $rg['total'] . ' vs ' . $gp['c']);
$other = sql($pdo, "SELECT COUNT(DISTINCT gp.id) $base AND s.wing = 'umum'");
if ($other >= 2) ok($rg['total'] < $allUmum, 'gp mempersempit daftar');

// ---------- 2. Lingkup admin ----------
$dpw = sql($pdo, "SELECT s.id FROM structures s WHERE s.level = 'DPW' AND s.wing = 'umum' AND s.deleted_at IS NULL AND EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.deleted_at IS NULL) AND EXISTS (SELECT 1 FROM structures c WHERE c.parent_id = s.id) ORDER BY s.id LIMIT 1");
ok($dpw > 0, 'ada DPW berisi DPD untuk uji lingkup');
if ($dpw) {
    scoped([$dpw]);
    $ids = (new StructureModel())->descendantIdsOfMany([$dpw]);
    $in = implode(',', array_map('intval', $ids));
    $expScope = sql($pdo, "SELECT COUNT(*) $base AND s.wing = 'umum' AND t.structure_id IN ($in)");
    $rs = $tm->pickerSearch(['wing' => 'umum', 'limit' => 15]);
    ok($rs['total'] === $expScope && $expScope > 1, 'admin wilayah: hanya struktur dalam lingkupnya', $rs['total'] . ' vs ' . $expScope);
    ok(!isset($rs['levels']['DPP']) && isset($rs['levels']['DPW']), 'admin wilayah: DPW-nya sendiri ada, DPP tidak', implode(',', array_keys($rs['levels'])));
    $top = $rs['items'][0];
    ok($top['level'] === 'DPW' && $top['parent'] === '', 'induk (DPP) di luar lingkup tidak ditampilkan pada barisnya', json_encode($top, JSON_UNESCAPED_UNICODE));
    ok($tm->pickerSearch(['wing' => 'umum', 'q' => 'Dewan Pimpinan Pusat'])['total'] === 0, 'pencarian nama induk di luar lingkup tak menemukan apa pun (tak bocor)');
    ok($tm->pickerSearch(['wing' => 'umum', 'q' => 'DPP'])['total'] === 0, 'pencarian "DPP" juga tidak');
    $none = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => false, 'root_ids' => [], 'structure_ids' => [], 'is_national' => false, 'label' => 'Kosong']];
    $_SESSION = $none;
    ok($tm->pickerSearch(['wing' => 'umum'])['total'] === 0, 'admin tanpa lingkup: kosong');
    national();
}

// ---------- 3. Titik JSON ----------
$j = api(['wing' => 'umum', 'q' => 'maros']);
ok(isset($j['items'], $j['levels'], $j['total']) && $j['total'] === $expM && $j['items'][0]['id'] > 0, 'JSON: bentuk dan isi', json_encode($j, JSON_UNESCAPED_UNICODE));
ok(array_keys($j['items'][0]) === ['id', 'level', 'name', 'parent', 'period', 'current', 'wing'], 'JSON: kunci tiap baris', implode(',', array_keys($j['items'][0])));
$j = api(['q' => ['a'], 'lv' => ['b'], 'wing' => ['umum'], 'gp' => ['1']]);
ok(isset($j['total']) && $j['total'] === $allAny, 'JSON: parameter berupa larik diabaikan tanpa galat', (string) ($j['total'] ?? 'x'));
$j = api([]);
ok($j['total'] === $allAny, 'JSON: tanpa parameter = semua sayap');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
national('viewer');
try { (new TPl)->json(); $res = 'tidak ditolak'; } catch (Denied $e) { $res = 'ditolak'; }
ok($res === 'ditolak', 'JSON: peninjau (tak boleh mengubah) ditolak');
national();

// ---------- 4. Terpasang di profil dan formulir penempatan ----------
$male = sql($pdo, "SELECT p.id FROM people p WHERE p.deleted_at IS NULL AND p.gender = 'L' AND EXISTS (SELECT 1 FROM placements pl WHERE pl.person_id = p.id AND pl.deleted_at IS NULL) ORDER BY p.id LIMIT 1");
$fem = sql($pdo, "SELECT p.id FROM people p WHERE p.deleted_at IS NULL AND p.gender = 'P' AND EXISTS (SELECT 1 FROM placements pl WHERE pl.person_id = p.id AND pl.deleted_at IS NULL) ORDER BY p.id LIMIT 1");
foreach (['umum' => $male, 'muslimah' => $fem] as $wing => $pid) {
    if (!$pid) { echo "LEWAT: tidak ada orang sayap $wing berpenempatan\n"; continue; }
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
    $h = (new TPe)->detailHtml($pid);
    ok(has($h, 'id="placement-modal"') && has($h, 'data-struct-picker') && has($h, 'data-wing="' . $wing . '"'), "profil #$pid: modal memakai pemilih sayap $wing");
    ok(!has($h, '<select id="placement-modal-structure"'), "profil #$pid: dropdown struktur lama hilang");
    $nOpt = substr_count($h, '<option');
    $oldOpts = $wing === 'umum' ? $allUmum : $allMusl;
    ok($nOpt < $oldOpts / 2, "profil #$pid: halaman tidak lagi memuat daftar struktur (option: $nOpt, dulu +$oldOpts)", (string) $nOpt);
    ok((has($h, 'placements/options/') || has($h, 'placements\/options\/')) && has($h, 'id="placement-modal-structure"') && has($h, 'type="hidden" id="placement-modal-structure"'), "profil #$pid: pilihan struktur tetap memuat unit dan jabatan");
}
// Formulir penempatan: pilihan awal = periode yang dibuka, acuan periode dan sayap menurut orangnya.
$pl = $pdo->query("SELECT pl.id, pl.term_id, pl.person_id, p.gender, t.governance_period_id FROM placements pl JOIN people p ON p.id = pl.person_id JOIN terms t ON t.id = pl.term_id WHERE pl.deleted_at IS NULL AND p.gender = 'P' ORDER BY pl.id LIMIT 1")->fetch();
if ($pl) {
    $h = (new TPl)->formHtml($pl['term_id'], $pl['id']);
    ok(has($h, 'data-struct-picker') && has($h, 'data-gp="' . (int) $pl['governance_period_id'] . '"') && has($h, 'data-wing="muslimah"'), 'form ubah: pemilih dengan acuan periode dan sayap orangnya');
    ok(has($h, 'type="hidden" id="target_term_id" name="target_term_id" value="' . (int) $pl['term_id'] . '"'), 'form ubah: struktur sekarang terpilih (nilai tersembunyi = periode ini)');
    ok(!has($h, '<select id="target_term_id"') && !has($h, 'structure_search'), 'form ubah: <select> dan kotak cari lama hilang');
    ok(substr_count($h, '<option') < 400, 'form ubah: tak memuat ratusan struktur', (string) substr_count($h, '<option'));
    ok(has($h, "form.addEventListener('submit'") && has($h, 'Pilih strukturnya dulu.'), 'form ubah: kirim tanpa struktur dicegah');
}
$term = sql($pdo, "SELECT t.id FROM terms t JOIN structures s ON s.id = t.structure_id WHERE t.deleted_at IS NULL AND s.level = 'DPD' ORDER BY t.id LIMIT 1");
$_GET = [];
$h = (new TPl)->formHtml($term);
ok(has($h, 'data-wing=""') && has($h, 'data-struct-picker'), 'form tambah tanpa orang: semua sayap');
$_GET = ['person_id' => (string) $male];
$h = (new TPl)->formHtml($term);
ok(has($h, 'data-wing="umum"'), 'form tambah dengan ?person_id= laki-laki: sayap Umum');
$termM = sql($pdo, "SELECT t.id FROM terms t JOIN structures s ON s.id = t.structure_id WHERE t.deleted_at IS NULL AND s.level = 'DPD' AND s.wing = 'umum' ORDER BY t.id LIMIT 1");
$_GET = ['person_id' => (string) $fem];
$h = (new TPl)->formHtml($termM);
ok(has($h, 'data-wing="muslimah"') && has($h, 'data-selected-wing="umum"'), 'form tambah: struktur awal Umum + orang perempuan: sayap tak cocok diketahui (app.js membuangnya)');

// ---------- 5. Berkas statis ----------
$js = file_get_contents(ROOT_DIR . '/public/assets/js/app.js');
$css = file_get_contents(ROOT_DIR . '/public/assets/css/app.css');
ok(has($js, '[data-struct-picker]') && has($js, "new CustomEvent('sp:change'") && has($js, 'structPicker'), 'app.js memuat pemilih struktur');
foreach (['sp-picked', 'sp-chips', 'sp-box', 'sp-list', 'sp-item', 'sp-period', 'sp-note', 'sp-empty', 'sp-badge', 'sp-item-text'] as $cls) ok(has($css, '.' . $cls), "CSS memuat .$cls");
$view = file_get_contents(ROOT_DIR . '/app/Views/admin/_struktur_picker.php');
preg_match_all("/\\.(sp-[a-z-]+)/", $js, $mj);
foreach (array_unique($mj[1]) as $cls) ok(has($css, '.' . $cls) || has($view, str_replace('sp-', 'sp-', $cls)), "kelas $cls dipakai app.js punya gaya atau markup");
foreach (['sp-find', 'sp-picked', 'sp-chips', 'sp-q', 'sp-list', 'sp-note', 'data-sp-change'] as $hook) ok(has($view, $hook) && has($js, $hook), "kait $hook ada di markup dan di app.js");

$out = ob_get_clean();
echo $out;
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
