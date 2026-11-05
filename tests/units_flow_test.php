<?php
// PATH LOKASI FILE: tests/units_flow_test.php
// Uji alur unit: "Tambah unit" di tingkat struktur (kelompok otomatis menurut jenis), "Pasang ke struktur" dari katalog, dan saringan
// halaman Pengelompokan Unit. Menjalankan UnitsController SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu
// di-rollback, jadi data lokal tidak berubah. Syarat: database lokal berisi periode berjalan MWP dan MWW (data biasa proyek ini).
//
//   php tests/units_flow_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/UnitsController.php';
require_once ROOT_DIR . '/app/Models/UnitModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
$_SERVER['REQUEST_METHOD'] = 'GET';

// Pengendali uji: redirect menjadi pengecualian, tampilan dicatat (atau dirender bila $render), login dilewati.
class Redirected extends RuntimeException {}
class TU extends UnitsController {
    public $render = false; public $viewName; public $viewData; public $html = '';
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        if ($this->viewName === null || !$this->render) { $this->viewName = $v; $this->viewData = $d; }
        if ($this->render) { extract($d); ob_start(); require ROOT_DIR . "/app/Views/$v.php"; $this->html .= ob_get_clean(); }
    }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}

// Menjalankan satu aksi POST/GET; mengembalikan [alamat redirect atau null, daftar pesan flash, pengendalinya].
function call($method, array $post = [], array $get = [], $render = false) {
    // Seperti peramban: nilai query string dan formulir selalu teks.
    $text = function ($a) { return array_map(function ($v) { return is_array($v) ? $v : (string) $v; }, $a); };
    $_POST = $text($post); $_GET = $text($get); $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    unset($_SESSION['_flash']);
    $c = new TU; $c->render = $render; $redir = null;
    try { $c->$method(); } catch (Redirected $r) { $redir = $r->getMessage(); }
    return [$redir, $_SESSION['_flash'] ?? [], $c];
}
function flashText(array $f) { return implode(' | ', array_map(function ($x) { return $x['type'] . ': ' . $x['message']; }, $f)); }

$pdo = Database::getInstance()->getConnection();
$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

$termOf = function ($level) use ($pdo) {
    $r = $pdo->query("SELECT t.id, t.structure_id FROM terms t JOIN structures s ON s.id = t.structure_id
                      WHERE t.is_current = 1 AND t.deleted_at IS NULL AND s.level = '$level' ORDER BY t.id LIMIT 1")->fetch();
    if (!$r) { echo "LEWAT: tak ada periode berjalan $level di database lokal.\n"; exit(0); }
    return $r;
};
$mwp = $termOf('MWP'); $mww = $termOf('MWW');
$groupCount = function ($termId) use ($pdo) { return (int) $pdo->query("SELECT COUNT(*) FROM unit_groups WHERE term_id = " . (int) $termId . " AND deleted_at IS NULL")->fetchColumn(); };
$unitRow = function ($termId, $name) use ($pdo) {
    $s = $pdo->prepare("SELECT su.*, c.kind, c.wing, g.name AS group_name, g.kind AS group_kind, g.sort_order AS group_sort
                        FROM structure_units su JOIN unit_catalog c ON c.id = su.unit_catalog_id LEFT JOIN unit_groups g ON g.id = su.unit_group_id
                        WHERE su.term_id = ? AND c.name = ? AND su.deleted_at IS NULL");
    $s->execute([$termId, $name]);
    return $s->fetch();
};

// Pastikan titik awal bersih: MWP belum punya "Badan-Badan" ataupun "Badan Anggaran" (kalau sudah, hapus di dalam transaksi uji).
$pdo->exec("UPDATE structure_units su JOIN unit_catalog c ON c.id = su.unit_catalog_id SET su.deleted_at = NOW(3) WHERE su.term_id IN ({$mwp['id']}, {$mww['id']}) AND c.name IN ('Badan Anggaran', 'Departemen Uji Alur')");
$pdo->exec("UPDATE unit_groups SET deleted_at = NOW(3) WHERE term_id IN ({$mwp['id']}, {$mww['id']}) AND name = 'Badan-Badan'");
$mwpGroups0 = $groupCount($mwp['id']); $mwwGroups0 = $groupCount($mww['id']);

// ---------- Model: kelompok otomatis menurut jenis ----------
$um = new UnitModel();
[$gid, $note] = $um->groupForKind($mwp['structure_id'], $mwp['id'], 'badan');
$g = $pdo->query("SELECT * FROM unit_groups WHERE id = " . (int) $gid)->fetch();
ok($gid > 0 && $g['name'] === 'Badan-Badan' && $g['kind'] === 'grup' && (int) $g['sort_order'] === 900, 'Badan: kelompok Badan-Badan dibuat (jenis grup, urutan 900)');
ok(strpos($note, 'baru dibuat') !== false, 'Badan: keterangan menyebut kelompok baru dibuat', $note);
[$gid2, $note2] = $um->groupForKind($mwp['structure_id'], $mwp['id'], 'badan');
ok($gid2 === $gid && strpos($note2, 'baru dibuat') === false, 'Badan: panggilan kedua memakai kelompok yang sama, tak membuat lagi', $note2);
ok($groupCount($mwp['id']) === $mwpGroups0 + 1, 'Badan: tepat satu kelompok baru');
ok($um->groupForKind($mwp['structure_id'], $mwp['id'], 'departemen') === [null, ''], 'Departemen: tak punya kelompok bawaan');
[$bid] = $um->groupForKind($mwp['structure_id'], $mwp['id'], 'biro');
$biro = $pdo->query("SELECT name FROM unit_groups WHERE id = " . (int) $bid)->fetchColumn();
ok(stripos((string) $biro, 'biro') !== false, 'Biro: memakai kelompok Biro yang sudah ada / dibuat', (string) $biro);
$pdo->exec("UPDATE unit_groups SET deleted_at = NOW(3) WHERE id = " . (int) $gid);   // kembalikan titik awal untuk uji controller
$mwpGroups0 = $groupCount($mwp['id']);

// ---------- Controller: Tambah unit di tingkat struktur ----------
$base = ['term_id' => $mwp['id'], 'return' => 'grouping', 'f_lv' => 'MWP', 'f_st' => 'disusun', 'f_q' => 'pusat'];
[$redir, $fl] = call('unit_save', $base + ['new_name' => 'Badan Anggaran', 'new_kind' => 'badan', 'masuk' => 'auto']);
$u = $unitRow($mwp['id'], 'Badan Anggaran');
ok($u && $u['wing'] === 'muslimah', 'Badan Anggaran dibuat di katalog Muslimah', $u ? $u['wing'] : 'tak ada');
ok($u && $u['group_name'] === 'Badan-Badan' && $u['group_kind'] === 'grup', 'Badan Anggaran masuk ke kelompok Badan-Badan', $u ? (string) $u['group_name'] : 'tak ada');
ok(strpos(flashText($fl), 'success') === 0 && strpos(flashText($fl), 'Badan-Badan (baru dibuat)') !== false, 'pesan menyebut kelompok yang dibuat otomatis', flashText($fl));
ok($redir !== null && strpos($redir, 'units/grouping?') === 0 && strpos($redir, 'open=' . $mwp['id']) !== false
   && strpos($redir, 'lv=MWP') !== false && strpos($redir, 'st=disusun') !== false && strpos($redir, 'q=pusat') !== false
   && substr($redir, -strlen('#t' . $mwp['id'])) === '#t' . $mwp['id'], 'pulang ke daftar yang sama (saringan dibawa)', (string) $redir);

// Dobel: ditolak, dan tak meninggalkan kelompok baru.
$pdo->exec("UPDATE unit_groups SET deleted_at = NOW(3) WHERE term_id = {$mwp['id']} AND name = 'Badan-Badan' AND id <> " . (int) $u['unit_group_id']);
$before = $groupCount($mwp['id']);
[, $fl] = call('unit_save', $base + ['new_name' => 'Badan Anggaran', 'new_kind' => 'badan', 'masuk' => 'auto']);
ok(strpos(flashText($fl), 'sudah terdaftar') !== false, 'unit yang sama ditolak', flashText($fl));
ok($groupCount($mwp['id']) === $before, 'penolakan tak membuat kelompok tambahan');

// Sayap tak cocok: katalog Umum ke struktur Muslimah ditolak SEBELUM kelompok dibuat.
$umum = $pdo->query("SELECT id FROM unit_catalog WHERE name = 'Badan Anggaran' AND wing = 'umum'")->fetchColumn();
if ($umum) {
    [, $fl] = call('unit_save', ['term_id' => $mww['id'], 'unit_catalog_id' => $umum, 'masuk' => 'auto', 'return' => 'grouping']);
    ok(strpos(flashText($fl), 'error') === 0 && stripos(flashText($fl), 'sayap') !== false, 'katalog sayap Umum ditolak di struktur Muslimah', flashText($fl));
    ok($groupCount($mww['id']) === $mwwGroups0, 'penolakan sayap tak membuat kelompok di MWW');
}

// Jenis tanpa kelompok bawaan: masuk tanpa Bidang, dan pesannya menyebut itu.
[, $fl] = call('unit_save', $base + ['new_name' => 'Departemen Uji Alur', 'new_kind' => 'departemen', 'masuk' => 'auto']);
$d = $unitRow($mwp['id'], 'Departemen Uji Alur');
ok($d && $d['unit_group_id'] === null, 'Departemen otomatis: tanpa Bidang');
ok(strpos(flashText($fl), 'tanpa Bidang') !== false, 'pesan Departemen menyebut tanpa Bidang', flashText($fl));

// Kelompok eksplisit: angka = kelompok itu; kelompok milik periode lain ditolak dan katalog tak tercemar.
$other = $pdo->query("SELECT id FROM unit_groups WHERE term_id = {$mwp['id']} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
[, $fl] = call('unit_save', ['term_id' => $mww['id'], 'new_name' => 'Zzz Uji Tolak', 'new_kind' => 'departemen', 'masuk' => (string) $other, 'return' => 'grouping']);
ok(strpos(flashText($fl), 'bukan milik periode ini') !== false, 'kelompok milik periode lain ditolak', flashText($fl));
ok(!$pdo->query("SELECT COUNT(*) FROM unit_catalog WHERE name = 'Zzz Uji Tolak'")->fetchColumn(), 'penolakan kelompok tak membuat entri katalog');

// Formulir lama di dalam kelompok (unit_group_id) tetap bekerja.
[, $fl] = call('unit_save', ['term_id' => $mwp['id'], 'new_name' => 'Departemen Uji Alur Dua', 'new_kind' => 'departemen', 'unit_group_id' => (string) $other, 'return' => 'grouping']);
$d2 = $unitRow($mwp['id'], 'Departemen Uji Alur Dua');
ok($d2 && (int) $d2['unit_group_id'] === (int) $other, 'formulir di dalam kelompok tetap memasang ke kelompok itu', flashText($fl));

// ---------- Controller: katalog ----------
[, $fl] = call('catalog_save', ['name' => 'Unit Uji Tanpa Sayap', 'kind' => 'lainnya']);
ok(strpos(flashText($fl), 'Pilih sayap') !== false, 'unit baru tanpa sayap ditolak', flashText($fl));
ok(!$pdo->query("SELECT COUNT(*) FROM unit_catalog WHERE name = 'Unit Uji Tanpa Sayap'")->fetchColumn(), 'tanpa sayap: tak ada entri dibuat');
[$redir, $fl] = call('catalog_save', ['name' => 'Unit Uji Muslimah', 'kind' => 'badan', 'wing' => 'muslimah', 'is_active' => '1']);
$newId = (int) $pdo->query("SELECT id FROM unit_catalog WHERE name = 'Unit Uji Muslimah' AND wing = 'muslimah'")->fetchColumn();
ok($newId > 0, 'unit baru dengan sayap tersimpan');
ok($redir !== null && strpos($redir, 'baru=' . $newId) !== false, 'sesudah menambah, halaman diarahkan dengan ?baru=', (string) $redir);

[, , $c] = call('catalog', [], ['baru' => $newId, 'w' => 'muslimah'], true);
ok(!empty($c->viewData['baru']) && (int) $c->viewData['baru']['id'] === $newId, 'katalog: kotak "baru" terisi untuk unit yang belum terpasang');
ok(strpos($c->html, 'Pasang &ldquo;Unit Uji Muslimah&rdquo;') !== false && strpos($c->html, 'name="masuk" value="auto"') !== false, 'katalog: kotak Pasang tampil dan memakai masuk=auto');
ok(strpos($c->html, 'data-toggle-edit="pasang-' . $newId . '"') !== false, 'katalog: baris yang belum terpasang punya tombol Pasang');
$usedId = (int) $u['unit_catalog_id'];
[, , $c2] = call('catalog', [], ['baru' => $usedId], false);
ok(empty($c2->viewData['baru']), 'katalog: unit yang sudah terpasang tidak menampilkan kotak baru');
$muslimahTargets = array_filter($c->viewData['targets'], function ($t) { return $t['wing'] === 'muslimah'; });
ok(count($muslimahTargets) > 0 && in_array((int) $mwp['id'], array_map('intval', array_column($muslimahTargets, 'term_id')), true), 'katalog: MWP ada di pilihan struktur');

// Pasang dari katalog.
[$redir, $fl] = call('unit_save', ['term_id' => $mwp['id'], 'unit_catalog_id' => $newId, 'masuk' => 'auto', 'return' => 'grouping']);
$p = $unitRow($mwp['id'], 'Unit Uji Muslimah');
ok($p && $p['group_name'] === 'Badan-Badan', 'Pasang dari katalog: masuk ke Badan-Badan otomatis', flashText($fl));
ok($redir !== null && strpos($redir, 'open=' . $mwp['id']) !== false, 'Pasang: pulang ke struktur tujuan', (string) $redir);

// ---------- Controller: saringan halaman Pengelompokan ----------
[, , $c] = call('grouping', [], []);
$vd = $c->viewData;
ok($vd['st'] === 'disusun', 'bawaan: status Sudah disusun', $vd['st']);
ok(count($vd['entries']) >= 2 && count($vd['entries']) < 20, 'bawaan: hanya struktur yang sudah disusun (bukan ratusan)', (string) count($vd['entries']));
ok($vd['totalAll'] > 100, 'jumlah seluruh struktur berperiode tetap dilaporkan', (string) $vd['totalAll']);
$levels = array_unique(array_map(function ($e) { return $e['structure']['level']; }, $vd['entries']));
ok(in_array('MWP', $levels, true) && in_array('DPP', $levels, true), 'bawaan: DPP dan MWP ikut (sudah disusun)', implode(',', $levels));

[, , $c] = call('grouping', [], ['lv' => 'MWP']);
$only = array_unique(array_map(function ($e) { return $e['structure']['level']; }, $c->viewData['entries']));
ok($only === ['MWP'], 'jenjang MWP: hanya MWP', implode(',', $only));

[, , $c] = call('grouping', [], ['lv' => 'MWD', 'st' => 'belum']);
ok(count($c->viewData['entries']) === UnitsController::GROUPING_MAX_ROWS && $c->viewData['matched'] > UnitsController::GROUPING_MAX_ROWS, 'MWD belum disusun: dibatasi ' . UnitsController::GROUPING_MAX_ROWS . ' baris, jumlah cocok tetap dilaporkan');

[, , $c] = call('grouping', [], ['st' => 'semua', 'q' => 'barru']);
$names = array_map(function ($e) { return $e['structure']['level'] . ' ' . $e['structure']['name']; }, $c->viewData['entries']);
ok($names && count(array_filter($names, function ($n) { return stripos($n, 'barru') !== false; })) === count($names), 'cari "barru": hanya yang namanya memuat itu', implode('; ', $names));

[, , $c] = call('grouping', [], ['lv' => 'DPW', 'st' => 'disusun', 'open' => $mww['id']]);
$ids = array_map(function ($e) { return (int) $e['term']['id']; }, $c->viewData['entries']);
ok(in_array((int) $mww['id'], $ids, true), 'open= selalu ikut tampil walau di luar saringan');

[, , $c] = call('grouping', [], ['lv' => 'XYZ', 'st' => 'asal']);
ok($c->viewData['lv'] === '' && in_array($c->viewData['st'], ['disusun', 'semua'], true), 'nilai saringan asal-asalan diabaikan');
[, , $c] = call('grouping', [], ['lv' => ['x'], 'st' => ['y'], 'q' => ['z'], 'open' => ['1']]);
ok($c->viewData['lv'] === '' && $c->viewData['q'] === '' && (int) $c->viewData['openTerm'] === 0, 'parameter berbentuk larik tak menjatuhkan halaman');

// Render halaman: kerangka formulir baru dan datalist.
[, , $c] = call('grouping', [], ['lv' => 'MWP', 'open' => $mwp['id']], true);
$h = $c->html;
ok(strpos($h, 'data-unit-add') !== false && strpos($h, 'name="masuk"') !== false && strpos($h, 'Otomatis menurut jenis unit') !== false, 'halaman: formulir Tambah unit tingkat struktur ada');
ok(strpos($h, 'list="katalog-unit-muslimah"') !== false, 'halaman: isian nama memakai katalog sayap Muslimah');
ok(preg_match('/<datalist id="katalog-unit-muslimah">.*?data-kind="badan".*?<\/datalist>/s', $h) === 1, 'halaman: saran katalog membawa jenisnya (data-kind)');
ok(strpos($h, 'Jenis kelompok') !== false && strpos($h, 'Jenis unit (nama baru)') !== false, 'halaman: label Jenis dibedakan (kelompok vs unit)');
ok(strpos($h, 'name="f_lv" value="MWP"') !== false, 'halaman: saringan ikut di formulir (f_lv)');
ok(strpos($h, 'aria-label="Saring menurut jenjang"') !== false && strpos($h, 'aria-label="Saring menurut status"') !== false, 'halaman: chip jenjang dan status tampil');

$pdo->rollBack();
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
