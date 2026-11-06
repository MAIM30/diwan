<?php
// PATH LOKASI FILE: tests/structures_page_test.php
// Uji halaman Struktur Organisasi (saringan, Daftar, Pohon) terhadap database lokal. Angka harapan DIHITUNG LEWAT SQL, bukan lewat logika
// yang sama dengan controller. Menyisipkan struktur uji (DPLN) di dalam SATU transaksi yang selalu di-rollback, jadi database lokal tak berubah.
//
//   php tests/structures_page_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview', 'AdminNaming'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/StructuresController.php';
foreach (['StructureModel', 'AccountModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class Redirected extends RuntimeException {}
class TST extends StructuresController {
    protected function requireLogin($roles = null) {}
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
function national($role = 'super_admin') {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => [$role]], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function scoped(array $roots) {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']],
                 'scope' => ['all' => false, 'root_ids' => $roots, 'structure_ids' => (new StructureModel())->descendantIdsOfMany($roots), 'is_national' => false, 'label' => 'Uji']];
}
function page(array $get) { $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); (new TST)->index(); return ob_get_clean(); }
function dataRows($h) { return max(0, substr_count($h, '<tr>') - 1); }   // tanpa baris judul
function sql($pdo, $q) { return (int) $pdo->query($q)->fetchColumn(); }
function chipCountS($h, $label) { return preg_match('/<a class="chip[^"]*"[^>]*>' . preg_quote($label, '/') . ' <span class="chip-n">([\d.]+)<\/span>/u', $h, $m) ? (int) str_replace('.', '', $m[1]) : -1; }

$pdo = Database::getInstance()->getConnection();
national();
$sc = (new StructureModel())->levelCountsInScope();
$total = array_sum($sc);
if ($total < 200) { echo "LEWAT: data struktur di database lokal terlalu sedikit.\n"; exit(0); }

// ---------- Halaman bawaan ----------
$h = page([]);
ok(strpos($h, '<span class="badge">' . Ui::n($total) . ' struktur</span>') !== false, 'judul: jumlah seluruh struktur dalam lingkup (' . $total . ')');
ok(dataRows($h) === 50, 'Daftar bawaan: 50 baris per halaman', (string) dataRows($h));
ok(strlen($h) < 400 * 1024, 'halaman bawaan ringan (dulu ~2 MB karena seluruh pohon dimuat sekaligus)', round(strlen($h) / 1024) . ' KB');
$allChips = true;
foreach ($sc as $level => $n) if (strpos($h, e($level) . ' <span class="chip-n">' . Ui::n($n) . '</span>') === false) $allChips = false;
ok($allChips, 'chip Jenjang untuk SETIAP jenjang yang ada, dengan jumlah strukturnya');
ok(strpos($h, 'aria-label="Saring menurut status"') !== false && strpos($h, 'Belum punya periode') !== false && strpos($h, 'Punya admin') !== false, 'chip status tampil (periode, admin)');
ok(strpos($h, 'Nonaktif') === false || sql($pdo, "SELECT COUNT(*) FROM structures WHERE deleted_at IS NULL AND is_active = 0") > 0, 'chip Nonaktif hanya ditawarkan bila ada struktur nonaktif');

// ---------- Jenjang ----------
$h = page(['lv' => 'MWW']);
ok(dataRows($h) === min(50, $sc['MWW']) && substr_count($h, '<span class="badge lv">MWW</span>') === dataRows($h), 'lv=MWW: hanya MWW, jumlah sesuai tabel structures (' . $sc['MWW'] . ')');
ok(strpos($h, 'Menampilkan <b>' . Ui::n($sc['MWW']) . '</b> dari') !== false, 'lv=MWW: keterangan jumlah yang cocok');
$h = page(['lv' => 'DPD', 'page' => '2']);
ok(dataRows($h) === 50 && strpos($h, 'Menampilkan 51&ndash;100 dari <b>' . Ui::n($sc['DPD']) . '</b>') !== false, 'lv=DPD halaman 2: baris 51-100 dari ' . $sc['DPD']);
$h = page(['lv' => 'DPD', 'page' => '999']);
ok(dataRows($h) > 0 && strpos($h, 'aria-current="page">' . ceil($sc['DPD'] / 50) . '<') !== false, 'halaman di luar batas dirapatkan ke halaman terakhir');
$h = page(['lv' => 'DPD', 'per_page' => '100']);
ok(dataRows($h) === 100, 'per_page=100 diterima; nilai lain diabaikan');
$h = page(['lv' => 'DPD', 'per_page' => '7']);
ok(dataRows($h) === 50, 'per_page tak sah kembali ke 50');
$h = page(['lv' => 'XYZ']);
ok(dataRows($h) === 50, 'jenjang yang tak ada diabaikan');

// ---------- Pencarian ----------
$want = sql($pdo, "SELECT COUNT(*) FROM structures WHERE deleted_at IS NULL AND name LIKE '%Kab. Barru%'");
$h = page(['q' => 'Kab. Barru']);
ok($want >= 2 && dataRows($h) === $want, 'q="Kab. Barru": semua struktur bernama itu, di semua jenjang (' . $want . ')', (string) dataRows($h));
$code = $pdo->query("SELECT region_code FROM structures WHERE deleted_at IS NULL AND level = 'DPD' AND region_code LIKE '73.%' LIMIT 1")->fetchColumn();
$wantCode = sql($pdo, "SELECT COUNT(*) FROM structures WHERE deleted_at IS NULL AND region_code = " . $pdo->quote($code));
$h = page(['q' => $code]);
ok($code && dataRows($h) >= $wantCode && strpos($h, '>' . e($code) . '<') !== false, 'q=kode wilayah (' . $code . '): ditemukan lewat kodenya');
$wantInduk = sql($pdo, "SELECT COUNT(*) FROM structures s JOIN structures p ON p.id = s.parent_id WHERE s.deleted_at IS NULL AND s.level = 'DPD' AND p.name = 'Sulawesi Selatan'");
$h = page(['q' => 'Sulawesi Selatan', 'lv' => 'DPD']);
ok($wantInduk > 0 && dataRows($h) === min(50, $wantInduk) && strpos($h, 'Menampilkan <b>' . Ui::n($wantInduk) . '</b>') !== false, 'q=nama induk + lv=DPD: seluruh DPD di bawah Sulawesi Selatan (' . $wantInduk . ')');
$h = page(['q' => 'zzzqqqxxx']);
ok(strpos($h, 'Tidak ada struktur yang cocok dengan penyaringan ini.') !== false && strpos($h, '<table') === false, 'tanpa hasil: pesan, bukan tabel kosong');
$h = page(['q' => 'barru']);
ok(preg_match('/>MWD <span class="chip-n">1<\/span>/', $h) === 1 && preg_match('/>DPD <span class="chip-n">1<\/span>/', $h) === 1 && preg_match('/>DPW <span class="chip-n">0<\/span>/', $h) === 1, 'angka chip jenjang mengikuti pencarian');
ok(strpos($h, 'chip zero') !== false, 'chip berangka nol dibuat redup (kelas zero)');

// ---------- Status ----------
// Tiga keadaan periode yang saling lepas: berjalan; ada periode (draf/aktif) tetapi belum ditandai berjalan; belum punya periode (atau hanya yang sudah selesai).
$hasCur = "EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.is_current = 1 AND t.deleted_at IS NULL)";
$hasPend = "EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.is_current = 0 AND t.status <> 'ended' AND t.deleted_at IS NULL)";
$withTerm = sql($pdo, "SELECT COUNT(*) FROM structures s WHERE s.deleted_at IS NULL AND $hasCur");
$pendN = sql($pdo, "SELECT COUNT(*) FROM structures s WHERE s.deleted_at IS NULL AND NOT $hasCur AND $hasPend");
$noTerm = sql($pdo, "SELECT COUNT(*) FROM structures s WHERE s.deleted_at IS NULL AND NOT $hasCur AND NOT $hasPend");
ok($withTerm + $pendN + $noTerm === $total, 'tiga keadaan periode saling lepas dan menjumlah ke seluruh struktur', "$withTerm + $pendN + $noTerm vs $total");
$h = page(['f' => 'tanpa_periode']);
ok(strpos($h, 'Menampilkan <b>' . Ui::n($noTerm) . '</b>') !== false && substr_count($h, 'Belum punya periode berjalan') >= 40, 'f=tanpa_periode: tepat yang belum punya periode sama sekali (' . $noTerm . ')');
$h = page(['f' => 'berperiode']);
ok(strpos($h, 'Menampilkan <b>' . Ui::n($withTerm) . '</b>') !== false, 'f=berperiode: tepat yang punya periode berjalan (' . $withTerm . ')');
if ($pendN > 0) {
    $h = page(['f' => 'belum_berjalan']);
    ok(strpos($h, 'Menampilkan <b>' . Ui::n($pendN) . '</b>') !== false && strpos($h, 'belum ditandai berjalan') !== false && strpos($h, 'Tandai berjalan') !== false
        && strpos($h, 'Buat periode') === false, 'f=belum_berjalan: ' . $pendN . ' struktur, tertulis "belum ditandai berjalan" + Tandai berjalan, tanpa Buat periode');
    ok(chipCountS(page([]), 'Periode belum ditandai berjalan') === $pendN, 'chip "Periode belum ditandai berjalan" = SQL');
} else {
    ok(strpos(page([]), 'Periode belum ditandai berjalan') === false, 'tanpa struktur seperti itu, chipnya tidak ditawarkan');
}
$admins = sql($pdo, "SELECT COUNT(DISTINCT scope_structure_id) FROM account_roles WHERE role = 'admin_struktur'");
$h = page(['f' => 'punya_admin']);
ok($admins > 0 && dataRows($h) === $admins, 'f=punya_admin: tepat struktur yang punya admin (' . $admins . ')', (string) dataRows($h));
$h = page(['f' => 'tanpa_periode', 'lv' => 'DPD']);
$wantBoth = sql($pdo, "SELECT COUNT(*) FROM structures s WHERE s.deleted_at IS NULL AND s.level = 'DPD' AND NOT $hasCur AND NOT $hasPend");
ok(strpos($h, 'Menampilkan <b>' . Ui::n($wantBoth) . '</b>') !== false, 'jenjang + status digabung (' . $wantBoth . ' DPD tanpa periode)');
$h = page(['f' => 'asal']);
ok(dataRows($h) === 50, 'status tak sah diabaikan');
$h = page(['lv' => ['x'], 'q' => ['y'], 'f' => ['z'], 'tampil' => ['p'], 'page' => ['2'], 'per_page' => ['25']]);
ok(strlen($h) > 1000 && dataRows($h) === 50, 'parameter berbentuk larik tidak menjatuhkan halaman');

// ---------- Pohon ----------
$h = page(['tampil' => 'pohon']);
ok(substr_count($h, '<li class="sn">') === $total, 'Pohon tanpa saringan: seluruh ' . $total . ' simpul', (string) substr_count($h, '<li class="sn">'));
ok(strpos($h, 'data-tree-all="open"') !== false, 'Pohon: tombol Buka semua / Tutup semua ada');
$h = page(['tampil' => 'pohon', 'q' => 'Kab. Barru', 'lv' => 'DPD']);
$ctx = substr_count($h, 'class="sn sn-ctx"');
ok(substr_count($h, '<li class="sn">') === 1 && $ctx >= 2 && strpos($h, '<details open>') !== false, 'Pohon tersaring: yang cocok (1) beserta induk-induknya (' . $ctx . ') yang dibuka, redup');
ok(substr_count($h, 'class="sn-body"') === 1, 'induk yang hanya jadi jalur tidak menampilkan isi (Periode, Admin, tombol); hanya yang cocok');
ok(strpos($h, '<a class="chip on"') !== false || strpos($h, 'class="chip on"') !== false, 'Pohon: chip yang dipilih tampil aktif');

// ---------- Peran dan lingkup ----------
national('viewer');
$h = page([]);
ok(strpos($h, '<th>Admin</th>') === false && strpos($h, 'structures/delete') === false && strpos($h, 'Tambah Struktur') === false && strpos($h, 'Punya admin') === false, 'peran viewer: tanpa kolom Admin, aksi ubah/hapus, tombol tambah, dan chip admin');
national();
$dpd = $pdo->query("SELECT s.id, s.name FROM structures s WHERE s.level = 'DPD' AND s.deleted_at IS NULL LIMIT 1")->fetch();
$parentName = $pdo->query("SELECT p.name FROM structures p JOIN structures s ON s.parent_id = p.id WHERE s.id = " . (int) $dpd['id'])->fetchColumn();
scoped([(int) $dpd['id']]);
$h = page([]);
ok(dataRows($h) === 1 && strpos($h, e($dpd['name'])) !== false, 'admin DPD: hanya struktur dalam lingkupnya');
preg_match('/<table.*?<\/table>/s', $h, $tbl);
ok(!empty($tbl[0]) && strpos($tbl[0], e($parentName)) === false && strpos($tbl[0], 'di bawah') === false, 'admin DPD: nama DPW induk di luar lingkup tidak bocor (isi tabel)', (string) $parentName);
national();

// ---------- Struktur baru (DPLN) dan Hapus ----------
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
$dplnBefore = sql($pdo, "SELECT COUNT(*) FROM structures WHERE level = 'DPLN'");   // termasuk yang terhapus
if (!isset($sc['DPLN'])) {
    $pdo->beginTransaction();
    $dpp = $pdo->query("SELECT id, path, depth FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1")->fetch();
    $new = [];
    foreach (['Arab Saudi', 'Yaman'] as $nm) {
        $pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id, is_active) VALUES (?, 'DPLN', ?, ?, 1)")->execute([Uuid::v4(), $nm, $dpp['id']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE structures SET path = ?, depth = ? WHERE id = ?")->execute([$dpp['path'] . $id . '/', $dpp['depth'] + 1, $id]);
        $new[$nm] = $id;
    }
    $h = page([]);
    ok(preg_match('/>DPLN <span class="chip-n">2<\/span>/', $h) === 1, 'DPLN baru: chip Jenjang langsung tampil (2)');
    $h = page(['lv' => 'DPLN']);
    ok(dataRows($h) === 2 && strpos($h, 'Arab Saudi') !== false && strpos($h, 'Yaman') !== false && substr_count($h, 'Buat periode') === 2 && strpos($h, 'di bawah DPP<') !== false,
       'DPLN baru: kedua struktur tampil, belum berperiode (Buat periode), induknya cukup "DPP"');

    // Hapus kembali ke daftar yang sama (saringan terbawa); tujuan tak sah ditolak.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $go = function ($back) use ($new) {
        $_POST = ['id' => (string) $new['Yaman'], 'back' => $back];
        try { (new TST)->delete(); } catch (Redirected $r) { return $r->getMessage(); }
        return null;
    };
    $to = $go('lv=DPLN&f=tanpa_periode&page=2');
    ok($to === 'structures?lv=DPLN&f=tanpa_periode&page=2', 'Hapus: kembali ke daftar yang sama (saringan dan halaman terbawa)', (string) $to);
    ok((int) $pdo->query("SELECT COUNT(*) FROM structures WHERE id = {$new['Yaman']} AND deleted_at IS NOT NULL")->fetchColumn() === 1, 'Hapus: strukturnya terhapus (soft delete)');
    $_POST = ['id' => (string) $new['Arab Saudi'], 'back' => '//evil.example/x?a=1'];
    try { (new TST)->delete(); $to2 = null; } catch (Redirected $r) { $to2 = $r->getMessage(); }
    ok($to2 === 'structures', 'Hapus: tujuan dengan karakter di luar query string biasa ditolak (kembali ke daftar polos)', (string) $to2);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $pdo->rollBack();
}
ok(sql($pdo, "SELECT COUNT(*) FROM structures WHERE level = 'DPLN'") === $dplnBefore, 'sesudah rollback: tak ada struktur uji tersisa (jumlah DPLN sama seperti semula)');

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
