<?php
// PATH LOKASI FILE: tests/terms_page_test.php
// Uji halaman Periode Kepengurusan (saringan jenjang / acuan / status / cari, tabel berhalaman, ?structure=) terhadap database lokal. Angka
// harapan DIHITUNG LEWAT SQL, bukan lewat logika yang sama dengan controller. Hanya membaca.
//
//   php tests/terms_page_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/TermsController.php';
foreach (['TermModel', 'StructureModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class TTT extends TermsController {
    protected function requireLogin($roles = null) {}
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
function national() {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function scoped(array $roots) {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']],
                 'scope' => ['all' => false, 'root_ids' => $roots, 'structure_ids' => (new StructureModel())->descendantIdsOfMany($roots), 'is_national' => false, 'label' => 'Uji']];
}
function page(array $get) { $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); (new TTT)->index(); return ob_get_clean(); }
function dataRows($h) { return max(0, substr_count($h, '<tr>') - 1); }   // tanpa baris judul
function sql($pdo, $q) { return (int) $pdo->query($q)->fetchColumn(); }
function chipCount($h, $label) {   // angka pada chip berlabel $label (mis. "DPD", "Kosong")
    return preg_match('/<a class="chip[^"]*"[^>]*>' . preg_quote($label, '/') . ' <span class="chip-n">([\d.]+)<\/span>/u', $h, $m) ? (int) str_replace('.', '', $m[1]) : -1;
}

$pdo = Database::getInstance()->getConnection();
national();
$base = "FROM terms t JOIN structures s ON s.id = t.structure_id JOIN governance_periods gp ON gp.id = t.governance_period_id WHERE t.deleted_at IS NULL";
$all = sql($pdo, "SELECT COUNT(*) $base");
if ($all < 100) { echo "LEWAT: data periode di database lokal terlalu sedikit.\n"; exit(0); }

// ---------- Halaman bawaan ----------
$h = page([]);
ok(has($h, '<h1>Periode Kepengurusan</h1>') && has($h, Ui::n($all) . ' periode'), 'judul dan jumlah periode', (string) $all);
ok(dataRows($h) === 50, 'bawaan 50 baris per halaman', (string) dataRows($h));
ok(has($h, 'Menampilkan <b>' . Ui::n($all) . '</b> dari ' . Ui::n($all) . ' periode'), 'keterangan jumlah');
ok(!has($h, 'row-group'), 'tanpa baris pengelompok lama');
ok(has($h, 'class="pager"') && has($h, 'Menampilkan 1&ndash;50 dari <b>' . Ui::n($all) . '</b> periode'), 'penomoran halaman');
ok(has($h, 'Acuan periode dapat digunakan oleh banyak struktur'), 'catatan acuan periode tetap ada');
function has($h, $n) { return strpos($h, $n) !== false; }

// ---------- Chip jenjang ----------
$levels = $pdo->query("SELECT s.level, COUNT(*) c $base GROUP BY s.level")->fetchAll(PDO::FETCH_KEY_PAIR);
ok(count($levels) >= 4, 'ada beberapa jenjang yang punya periode', implode(',', array_keys($levels)));
foreach ($levels as $lv => $n) ok(chipCount($h, $lv) === (int) $n, "chip jenjang $lv = jumlah periodenya", chipCount($h, $lv) . ' vs ' . $n);
ok(chipCount($h, 'Semua') === $all, 'chip Semua (jenjang) = seluruh periode');
$lvBig = array_search(max($levels), $levels);
$h = page(['lv' => $lvBig, 'per_page' => '100']);
ok(has($h, 'jenjang <b>' . $lvBig . '</b>') && dataRows($h) === min(100, (int) $levels[$lvBig]), "?lv=$lvBig: baris = jumlah periodenya", (string) dataRows($h));
ok(substr_count($h, '<span class="badge lv">') === 0, 'tidak ada lencana jenjang terpisah (jenjang ikut nama)');
$h = page(['lv' => 'TIDAKADA']);
ok(dataRows($h) === 50 && !has($h, 'jenjang <b>'), 'jenjang tak dikenal diabaikan');

// ---------- Acuan periode ----------
$periods = $pdo->query("SELECT gp.id, gp.name, COUNT(*) c $base GROUP BY gp.id, gp.name ORDER BY gp.id DESC")->fetchAll();
$h = page([]);
if (count($periods) >= 2) {
    ok(has($h, 'aria-label="Saring menurut acuan periode"'), 'baris Acuan tampil bila ada lebih dari satu acuan');
    foreach ($periods as $p) ok(chipCount($h, $p['name']) === (int) $p['c'], "chip acuan {$p['name']} = jumlah periodenya", chipCount($h, $p['name']) . ' vs ' . $p['c']);
    $pBig = $periods[0];
    foreach ($periods as $p) if ($p['c'] > $pBig['c']) $pBig = $p;
    $h = page(['pr' => $pBig['id'], 'per_page' => '100']);
    ok(dataRows($h) === min(100, (int) $pBig['c']), "?pr={$pBig['id']}: baris = jumlah periodenya", (string) dataRows($h));
    // Angka jenjang mengikuti acuan yang dipilih.
    $lvInPr = $pdo->query("SELECT s.level, COUNT(*) c $base AND t.governance_period_id = " . (int) $pBig['id'] . " GROUP BY s.level")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($lvInPr as $lv => $n) ok(chipCount($h, $lv) === (int) $n, "dengan ?pr, chip jenjang $lv mengikuti acuan", chipCount($h, $lv) . ' vs ' . $n);
} else {
    ok(!has($h, 'aria-label="Saring menurut acuan periode"'), 'satu acuan: baris Acuan tidak tampil');
}
$h = page(['pr' => '999999']);
ok(dataRows($h) === 50, 'acuan tak dikenal diabaikan');

// ---------- Chip status ----------
$counts = [
    'Berjalan' => sql($pdo, "SELECT COUNT(*) $base AND t.is_current = 1"),
    'Arsip' => sql($pdo, "SELECT COUNT(*) $base AND t.is_current = 0"),
    'Belum ada pengurus' => sql($pdo, "SELECT COUNT(*) $base AND NOT EXISTS (SELECT 1 FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL)"),
    'Ada SK' => sql($pdo, "SELECT COUNT(*) $base AND t.sk_number IS NOT NULL AND TRIM(t.sk_number) <> ''"),
    'Tanpa SK' => sql($pdo, "SELECT COUNT(*) $base AND (t.sk_number IS NULL OR TRIM(t.sk_number) = '')"),
];
$h = page([]);
foreach ($counts as $label => $n) ok(chipCount($h, $label) === $n, "chip status $label = SQL", chipCount($h, $label) . ' vs ' . $n);
ok($counts['Berjalan'] + $counts['Arsip'] === $all && $counts['Ada SK'] + $counts['Tanpa SK'] === $all, 'status saling melengkapi');
foreach (['berjalan' => 'Berjalan', 'kosong' => 'Belum ada pengurus', 'tanpa_sk' => 'Tanpa SK'] as $key => $label) {
    $h = page(['f' => $key, 'per_page' => '100']);
    ok(dataRows($h) === min(100, $counts[$label]), "?f=$key: baris = SQL", (string) dataRows($h));
}
$h = page(['f' => 'ngawur']);
ok(dataRows($h) === 50 && !has($h, ' &middot; ngawur'), 'status tak dikenal diabaikan');
// Angka chip jenjang mengikuti status yang dipilih (dan sebaliknya).
$h = page(['f' => 'kosong']);
$lvEmpty = $pdo->query("SELECT s.level, COUNT(*) c $base AND NOT EXISTS (SELECT 1 FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) GROUP BY s.level")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($levels as $lv => $n) ok(chipCount($h, $lv) === (int) ($lvEmpty[$lv] ?? 0), "dengan ?f=kosong, chip jenjang $lv mengikuti status", chipCount($h, $lv) . ' vs ' . ($lvEmpty[$lv] ?? 0));
$h = page(['lv' => $lvBig]);
$kosongLv = sql($pdo, "SELECT COUNT(*) $base AND s.level = " . $pdo->quote($lvBig) . " AND NOT EXISTS (SELECT 1 FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL)");
ok(chipCount($h, 'Belum ada pengurus') === $kosongLv, 'dengan ?lv, chip status mengikuti jenjang', chipCount($h, 'Belum ada pengurus') . ' vs ' . $kosongLv);

// ---------- Cari ----------
$samp = $pdo->query("SELECT s.name, s.level, t.id $base AND s.level = 'DPD' LIMIT 1")->fetch();
if ($samp) {
    $word = explode(' ', trim(preg_replace('/^\S+\.\s*/', '', $samp['name'])))[0];
    $exp = sql($pdo, "SELECT COUNT(*) $base AND CONCAT(s.level, ' ', s.name) LIKE " . $pdo->quote('%' . $word . '%'));
    $h = page(['q' => $word, 'per_page' => '100']);
    ok(dataRows($h) >= 1 && has($h, 'pencarian &ldquo;' . e($word) . '&rdquo;'), "?q=$word menemukan baris", (string) dataRows($h));
    ok(dataRows($h) >= min(100, $exp), 'pencarian memuat sedikitnya semua yang cocok menurut jenjang + nama struktur', dataRows($h) . ' vs ' . $exp);
    ok(strpos($h, e($word)) !== false, 'kata yang dicari tampil di hasil');
}
$h = page(['q' => 'kab maros']);   // kata-kata terpisah: "Kab. Maros" harus cocok
$expMaros = sql($pdo, "SELECT COUNT(*) $base AND s.name LIKE '%Maros%' AND s.name LIKE '%Kab%'");
ok(dataRows($h) === $expMaros && $expMaros >= 1, 'cari dua kata terpisah ("kab maros") cocok dengan "Kab. Maros"', dataRows($h) . ' vs ' . $expMaros);
$h = page(['q' => '2026-2031']);
$exp2031 = sql($pdo, "SELECT COUNT(*) $base AND (gp.name LIKE '%2026-2031%' OR t.name LIKE '%2026-2031%')");
ok(dataRows($h) === $exp2031, 'cari nama acuan periode', dataRows($h) . ' vs ' . $exp2031);
$h = page(['q' => '%']);
ok(dataRows($h) === 0 && has($h, 'Tidak ada periode yang cocok') && has($h, 'Hapus penyaringan'), '% dicari apa adanya: tak ada hasil, ada tombol hapus penyaringan');
$h = page(['q' => ['x']]);
ok(dataRows($h) === 50, '?q[]= tidak membuat galat dan diabaikan');
$h = page(['lv' => ['DPD'], 'f' => ['kosong'], 'pr' => ['1'], 'structure' => ['1'], 'page' => ['2'], 'per_page' => ['25']]);
ok(dataRows($h) === 50, 'semua parameter berupa larik diabaikan tanpa galat');

// ---------- ?structure= (tautan dari Struktur dan dasbor) ----------
$sid = sql($pdo, "SELECT t.structure_id $base GROUP BY t.structure_id HAVING COUNT(*) >= 1 ORDER BY COUNT(*) DESC, t.structure_id LIMIT 1");
$nS = sql($pdo, "SELECT COUNT(*) $base AND t.structure_id = $sid");
$h = page(['structure' => (string) $sid]);
ok(dataRows($h) === $nS && has($h, ' &middot; struktur <b>'), "?structure=$sid: hanya periode struktur itu", dataRows($h) . ' vs ' . $nS);
ok(has($h, 'structure=' . $sid) && has($h, 'Hapus penyaringan'), '?structure= terbawa di tautan halaman dan bisa dihapus');
ok(substr_count($h, 'class="st-name"') === 1, 'nama struktur hanya di baris pertama per struktur', (string) substr_count($h, 'class="st-name"'));
$h = page(['structure' => '99999999']);
ok(dataRows($h) === 0 && has($h, 'Tidak ada periode yang cocok'), 'struktur tak ada: kosong dengan pesan');

// ---------- Halaman ----------
$pagesN = (int) ceil($all / 50);
$h = page(['page' => (string) $pagesN]);
ok(dataRows($h) === $all - 50 * ($pagesN - 1), 'halaman terakhir berisi sisanya', (string) dataRows($h));
$h = page(['page' => '9999']);
ok(dataRows($h) === $all - 50 * ($pagesN - 1), 'halaman di luar batas dijepit ke halaman terakhir');
$h = page(['page' => '0']);
ok(has($h, 'Menampilkan 1&ndash;50'), 'halaman 0 jatuh ke halaman 1');
$h = page(['per_page' => '25']);
ok(dataRows($h) === 25, 'per_page=25');
$h = page(['per_page' => '7']);
ok(dataRows($h) === 50, 'per_page tak dikenal jatuh ke 50');
$h1 = page(['per_page' => '100', 'page' => '1']);
$h2 = page(['per_page' => '100', 'page' => '2']);
preg_match_all('#/terms/detail/(\d+)"><strong>#', $h1, $a1); preg_match_all('#/terms/detail/(\d+)"><strong>#', $h2, $a2);
ok(!array_intersect($a1[1], $a2[1]) && count($a1[1]) === 100, 'halaman 1 dan 2 tidak bertumpuk');

// ---------- Urutan ----------
$h = page(['per_page' => '100']);
preg_match_all('#<div class="st-name"[^>]*>(\w+) #u', $h, $mm);
$rank = array_flip(PeopleOverview::orderedLevels(array_keys($levels)));
$seq = array_map(function ($l) use ($rank) { return $rank[$l] ?? 99; }, $mm[1]);
$sorted = $seq; sort($sorted);
ok($seq === $sorted && count($seq) > 0, 'urutan menurut jenjang baku', implode(',', array_slice($mm[1], 0, 6)));

// ---------- Lingkup admin ----------
$dpw = sql($pdo, "SELECT s.id FROM structures s WHERE s.level = 'DPW' AND s.deleted_at IS NULL AND EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.deleted_at IS NULL) ORDER BY s.id LIMIT 1");
if ($dpw) {
    scoped([$dpw]);
    $ids = (new StructureModel())->descendantIdsOfMany([$dpw]);
    $nScope = sql($pdo, "SELECT COUNT(*) $base AND t.structure_id IN (" . implode(',', array_map('intval', $ids)) . ")");
    $h = page(['per_page' => '100']);
    ok(has($h, Ui::n($nScope) . ' periode') && dataRows($h) === min(100, $nScope), 'admin wilayah: hanya periode dalam lingkupnya', dataRows($h) . ' vs ' . $nScope);
    $parentName = $pdo->query("SELECT p.name FROM structures s JOIN structures p ON p.id = s.parent_id WHERE s.id = " . (int) $dpw)->fetchColumn();
    // Induk (DPP) di luar lingkup: namanya tidak boleh bocor, baik di baris maupun lewat pencarian.
    ok(!has($h, 'di bawah DPP Dewan') && !has($h, 'Dewan Pimpinan Pusat'), 'nama induk di luar lingkup tidak bocor di baris');
    $h = page(['q' => 'Dewan Pimpinan Pusat']);
    ok(dataRows($h) === 0, 'pencarian nama induk di luar lingkup tidak menemukan apa pun', (string) dataRows($h));
    national();
}

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
