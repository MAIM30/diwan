<?php
// PATH LOKASI FILE: tests/people_new_structure_test.php
// Uji: struktur BARU langsung tampil di Data Pengurus, tanpa menunggu ada pengurusnya. Skenario aslinya: Super Admin menambah DPLN Arab
// Saudi dan DPLN Yaman, tetapi chip Jenjang tak memuat DPLN dan kedua struktur tak muncul di Per Struktur (daftar dibangun dari penempatan).
// Menyisipkan struktur uji di dalam SATU transaksi yang selalu di-rollback, jadi database lokal tak berubah.
//
//   php tests/people_new_structure_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/PeopleController.php';
foreach (['PersonModel', 'StructureModel', 'PlacementModel', 'UnitModel', 'TermModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class TNS extends PeopleController {
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
function page(array $get) { $_GET = $get; ob_start(); (new TNS)->index(); return ob_get_clean(); }
function fragment($termId, array $get = []) { $_GET = $get; ob_start(); (new TNS)->struktur($termId); return ob_get_clean(); }

$pdo = Database::getInstance()->getConnection();
national();
$dpp = $pdo->query("SELECT id, path, depth FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1")->fetch();
if (!$dpp) { echo "LEWAT: tak ada DPP di database lokal.\n"; exit(0); }
if ((int) $pdo->query("SELECT COUNT(*) FROM structures WHERE level = 'DPLN' AND deleted_at IS NULL")->fetchColumn() > 0) { echo "LEWAT: database lokal sudah punya DPLN; uji ini membutuhkan jenjang yang belum ada.\n"; exit(0); }

$sm = new StructureModel();
ok(!isset($sm->levelCountsInScope()['DPLN']), 'sebelum: DPLN belum ada di jumlah struktur per jenjang');
$h0 = page([]);
ok(preg_match('/>DPLN <span class="chip-n">/', $h0) === 0, 'sebelum: tak ada chip DPLN');

register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
$pdo->beginTransaction();
$ids = [];
foreach (['Arab Saudi', 'Yaman'] as $nm) {
    $pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id, is_active) VALUES (?, 'DPLN', ?, ?, 1)")->execute([Uuid::v4(), $nm, $dpp['id']]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE structures SET path = ?, depth = ? WHERE id = ?")->execute([$dpp['path'] . $id . '/', $dpp['depth'] + 1, $id]);
    $ids[$nm] = $id;
}

// ---------- Struktur baru, belum punya periode maupun pengurus ----------
ok(($sm->levelCountsInScope()['DPLN'] ?? 0) === 2, 'jumlah struktur DPLN = 2 begitu dibuat');
$h = page([]);
ok(preg_match('/>DPLN <span class="chip-n">0<\/span>/', $h) === 1, 'chip DPLN tampil (0 orang) walau belum ada pengurus');
ok(strpos($h, 'title="Dewan Pimpinan Luar Negeri · 2 struktur"') !== false, 'tooltip chip menyebut jumlah struktur');
ok(strpos($h, '<b>DPLN</b>') !== false && strpos($h, '<b>Arab Saudi</b>') !== false && strpos($h, '<b>Yaman</b>') !== false, 'Per Struktur memuat jenjang DPLN beserta Arab Saudi dan Yaman');
ok(substr_count($h, 'Belum punya periode berjalan') >= 2 && strpos($h, 'terms/form/0/' . $ids['Arab Saudi'] . '"') !== false && strpos($h, 'terms/form/0/' . $ids['Yaman'] . '"') !== false,
   'tanpa periode: baris statis dengan tautan Buat periode ke struktur yang benar');
ok(preg_match('/<details class="mon-g mon-lvl umum" open[^>]*>\s*<summary[^>]*>\s*<span class="mon-t">.*?<b>DPLN<\/b>/s', $h) === 1, 'jenjang kecil DPLN ikut terbuka bawaan, jadi struktur barunya langsung terlihat');
$hl = page(['lv' => 'DPLN']);
ok(substr_count($hl, 'class="mon-row mon-noterm"') === 2 && strpos($hl, '<b>DPW</b>') === false && strpos($hl, 'Aceh') === false, '?lv=DPLN: hanya kedua struktur DPLN');

// ---------- Sudah punya periode, belum punya pengurus ----------
$gp = $pdo->query("SELECT governance_period_id FROM terms WHERE structure_id = {$dpp['id']} AND is_current = 1 LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO terms (uuid, governance_period_id, structure_id, name, start_date, status, is_current) VALUES (?, ?, ?, '2026-2031', '2026-01-01', 'active', 1)")
    ->execute([Uuid::v4(), $gp, $ids['Arab Saudi']]);
$termId = (int) $pdo->lastInsertId();
$h = page([]);
ok(strpos($h, 'data-roster-url="' . BASEURL . '/people/struktur/' . $termId . '"') !== false, 'sudah berperiode: barisnya bisa dibuka (memuat isinya lewat people/struktur)');
ok(substr_count($h, 'terms/form/0/' . $ids['Arab Saudi'] . '"') === 0 && substr_count($h, 'terms/form/0/' . $ids['Yaman'] . '"') === 1, 'tautan Buat periode hilang dari yang sudah berperiode; tetap ada untuk yang belum');
$f = fragment($termId);
ok(strpos($f, 'Belum ada pengurus yang tercatat di struktur ini.') !== false && strpos($f, 'Ketua, Sekretaris, Bendahara') !== false && strpos($f, 'belum ada pengurusnya di Pengurus Inti') !== false,
   'isi struktur kosong: pesan jelas dan catatan Ketua/Sekretaris/Bendahara belum ada');

// ---------- Menyembunyikan, dan penyaring orang ----------
$h = page(['kosong' => '0']);
ok(strpos($h, '<b>Arab Saudi</b>') === false && strpos($h, '<b>Yaman</b>') === false && preg_match('/>DPLN <span class="chip-n">0<\/span>/', $h) === 1, '?kosong=0: struktur kosong disembunyikan, chip jenjang tetap ada');
ok(strpos($h, 'Sembunyikan struktur tanpa pengurus') !== false && strpos($h, 'class="chip on"') !== false, '?kosong=0: tombolnya tampil dalam keadaan aktif');
$h = page(['q' => 'ahmad', 'susun' => 'struktur']);
ok(strpos($h, '<b>Arab Saudi</b>') === false && strpos($h, 'Sembunyikan struktur tanpa pengurus') === false, 'dengan pencarian orang: struktur kosong tak disertakan dan tombolnya tak ditawarkan');
$h = page(['susun' => 'daftar']);
ok(strpos($h, 'Sembunyikan struktur tanpa pengurus') === false, 'tampilan Daftar: tombol struktur kosong tak ada');
$h = page(['lv' => ['x'], 'q' => ['y'], 'kosong' => ['z']]);
ok(strlen($h) > 1000, 'parameter berbentuk larik tidak menjatuhkan halaman');

// ---------- Lingkup admin ----------
$dpd = (int) $pdo->query("SELECT s.id FROM structures s WHERE s.level = 'DPD' AND s.deleted_at IS NULL LIMIT 1")->fetchColumn();
scoped([$dpd]);
$h = page([]);
ok(preg_match('/>DPLN <span class="chip-n">/', $h) === 0 && strpos($h, '<b>Arab Saudi</b>') === false, 'admin DPD: struktur DPLN di luar lingkupnya tidak terlihat');
national();

$pdo->rollBack();
ok((int) $pdo->query("SELECT COUNT(*) FROM structures WHERE level = 'DPLN'")->fetchColumn() === 0, 'sesudah rollback: tak ada DPLN tersisa');
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
