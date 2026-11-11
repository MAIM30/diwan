<?php
// PATH LOKASI FILE: tests/jenis_unit_test.php
// Uji jenis unit (Departemen, Lembaga, Biro, ...) sebagai daftar yang DIKELOLA (tabel unit_kinds, migrasi 025):
//   - "Lainnya" diganti "Belum ditentukan" (kode 'belum'); tak ada lagi ENUM maupun unit/jabatan berkode 'lainnya'
//   - halaman Unit Organisasi menampilkan semua jenis (juga 'komisi', yang dulu tak ada di daftar tampilan) tanpa peringatan PHP
//   - membuat, mengubah, menonaktifkan, dan menghapus jenis (yang masih dipakai ditolak; bawaan sistem tak bisa dihapus)
//   - jenis baru langsung bisa dipakai: katalog unit, batas jenis pada jabatan, kelompok bawaan, formulir pendaftaran publik
//   - hanya super admin yang melihat tombol pengelolaan
// Menjalankan pengendali SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback.
//
//   php tests/jenis_unit_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview', 'UnitKinds'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['UnitsController', 'PositionsController', 'DaftarController'] as $c) require_once ROOT_DIR . "/app/Controllers/$c.php";
foreach (['UnitModel', 'PositionModel', 'StructureModel', 'RegistrationModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

function asSuper() { $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']]; }
function asViewer() { $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['viewer']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']]; }
asSuper();
$_SERVER['REMOTE_ADDR'] = '10.255.255.8';

class Redirected extends RuntimeException {}
trait Capture {
    public $rendered = []; public $html = '';
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) {
        $this->rendered[$v] = $d;
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); ob_start(); require ROOT_DIR . "/app/Views/$v.php"; $this->html .= ob_get_clean();
    }
}
class TUnits extends UnitsController { use Capture; }
class TPositions extends PositionsController { use Capture; }
class TDaftar extends DaftarController { use Capture; }

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function call($class, $method, array $post = [], array $get = []) {
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    unset($_SESSION['_flash']);
    $c = new $class; $redir = null;
    try { $c->$method(); } catch (Redirected $r) { $redir = $r->getMessage(); }
    return [$redir, $_SESSION['_flash'] ?? [], $c];
}
function flashText(array $f) { return implode(' | ', array_map(function ($x) { return $x['type'] . ': ' . $x['message']; }, $f)); }
function threw(callable $fn) { try { $fn(); return null; } catch (RuntimeException $e) { return $e->getMessage(); } }

$pdo = Database::getInstance()->getConnection();
$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
$um = new UnitModel(); $pm = new PositionModel();

// ---------- Skema dan data setelah migrasi 025 ----------
$type = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'unit_catalog' AND column_name = 'kind'")->fetchColumn();
ok(stripos($type, 'varchar') === 0, 'unit_catalog.kind bukan lagi ENUM (daftar jenis ada di tabel unit_kinds)', $type);
ok((int) $pdo->query("SELECT COUNT(*) FROM unit_catalog WHERE kind = 'lainnya'")->fetchColumn() === 0, 'tak ada unit katalog berjenis "lainnya"');
ok((int) $pdo->query("SELECT COUNT(*) FROM positions WHERE FIND_IN_SET('lainnya', unit_kinds) > 0")->fetchColumn() === 0, 'tak ada jabatan yang batas jenis unitnya memuat "lainnya"');
ok((int) $pdo->query("SELECT COUNT(*) FROM unit_catalog WHERE kind NOT IN (SELECT code FROM unit_kinds)")->fetchColumn() === 0, 'setiap unit katalog berjenis yang ada di daftar jenis');

// ---------- UnitKinds ----------
UnitKinds::reset();
$labels = UnitKinds::labels();
ok(($labels['belum'] ?? '') === 'Belum ditentukan' && !isset($labels['lainnya']) && !in_array('Lainnya', $labels, true), '"Belum ditentukan" menggantikan "Lainnya"');
ok(isset($labels['departemen'], $labels['lembaga'], $labels['biro'], $labels['badan'], $labels['pusat'], $labels['divisi'], $labels['komisi']), 'jenis bawaan (termasuk komisi) ada');
ok(UnitKinds::normalize('lainnya') === 'belum' && UnitKinds::normalize('') === 'belum' && UnitKinds::normalize('biro') === 'biro', 'normalize: kode tak dikenal jatuh ke "belum"');
ok(UnitKinds::group('biro') === ['Biro-Biro', 910] && UnitKinds::group('badan') === ['Badan-Badan', 900] && UnitKinds::group('pusat') === ['Pusat-Pusat', 890] && UnitKinds::group('departemen') === null, 'kelompok bawaan Biro / Badan / Pusat tetap sama; Departemen tanpa kelompok');
ok(array_search('departemen', UnitKinds::codes()) < array_search('lembaga', UnitKinds::codes()) && array_search('komisi', UnitKinds::codes()) < array_search('belum', UnitKinds::codes()), 'urutan: Departemen, Lembaga, ..., Belum ditentukan di akhir');

// ---------- Halaman Unit Organisasi ----------
[, , $c] = call('TUnits', 'catalog');
$h = $c->html;
ok(strpos($h, 'id="jenis-unit"') !== false && strpos($h, 'Belum ditentukan') !== false && strpos($h, '>Lainnya<') === false, 'halaman Unit Organisasi: kartu Jenis unit ada, tanpa "Lainnya"');
ok(preg_match('~<option value="komisi" selected>~', $h) === 1, 'unit berjenis Komisi: pilihan jenisnya tetap Komisi di formulir Ubah (dulu hilang dari pilihan)');
ok(strpos($h, 'name="label"') !== false && strpos($h, '/units/kind_save') !== false, 'super admin: formulir kelola jenis tampil');
asViewer();
[, , $c] = call('TUnits', 'catalog');
ok(strpos($c->html, 'id="jenis-unit"') !== false && strpos($c->html, '/units/kind_save') === false && strpos($c->html, 'Hanya super admin') !== false, 'bukan super admin: daftar jenis tampil, tanpa tombol kelola');
asSuper();

// ---------- Membuat jenis ----------
$code = $um->kindSave(null, ['label' => 'Satuan Uji', 'group_name' => '']);
ok($code === 'satuan_uji' && UnitKinds::label('satuan_uji') === 'Satuan Uji' && UnitKinds::find('satuan_uji')['is_active'] == 1, 'jenis baru: kode dari nama, langsung aktif');
ok(array_search('satuan_uji', UnitKinds::codes()) < array_search('belum', UnitKinds::codes()), 'jenis baru diurutkan sebelum "Belum ditentukan"');
ok(strpos((string) threw(function () use ($um) { $um->kindSave(null, ['label' => 'satuan uji']); }), 'sudah ada') !== false, 'nama yang sama (beda huruf besar-kecil) ditolak');
ok(strpos((string) threw(function () use ($um) { $um->kindSave(null, ['label' => 'Departemen']); }), 'sudah ada') !== false, 'nama jenis bawaan tak bisa dibuat ulang');
ok(strpos((string) threw(function () use ($um) { $um->kindSave(null, ['label' => '   ']); }), 'wajib') !== false, 'nama kosong ditolak');
ok(strpos((string) threw(function () use ($um) { $um->kindSave(null, ['label' => '###']); }), 'huruf atau angka') !== false, 'nama tanpa huruf/angka ditolak');
ok(strpos((string) threw(function () use ($um) { $um->kindSave(null, ['label' => str_repeat('a', 61)]); }), 'terlalu panjang') !== false, 'nama lebih dari 60 huruf ditolak');
ok(strpos((string) threw(function () use ($um) { $um->kindSave('tidak_ada', ['label' => 'Apa Saja']); }), 'tidak ditemukan') !== false, 'mengubah jenis yang tak ada ditolak');

// ---------- Jenis baru langsung dipakai ----------
$cid = $um->catalogSave(null, ['name' => 'Unit Uji Jenis Baru', 'abbreviation' => '', 'kind' => 'satuan_uji', 'wing' => 'umum', 'is_active' => true]);
ok($pdo->query("SELECT kind FROM unit_catalog WHERE id = $cid")->fetchColumn() === 'satuan_uji', 'katalog unit menyimpan jenis baru');
$cid2 = $um->catalogSave(null, ['name' => 'Unit Uji Kode Liar', 'abbreviation' => '', 'kind' => 'lainnya', 'wing' => 'umum', 'is_active' => true]);
ok($pdo->query("SELECT kind FROM unit_catalog WHERE id = $cid2")->fetchColumn() === 'belum', 'kode lama/tak dikenal ("lainnya") tersimpan sebagai "belum"');
// Jabatan uji sendiri (tanpa penempatan), supaya penyempitan batas jenis unit tak terhalang penempatan yang sudah ada.
$posId = $pm->save(null, ['name' => 'Jabatan Uji Jenis', 'code' => 'zz_uji_jenis_' . substr(md5((string) microtime(true)), 0, 6), 'applies_to' => 'both', 'unit_kinds' => [], 'sort_order' => 500, 'is_active' => 1]);
$pos = ['id' => $posId, 'name' => 'Jabatan Uji Jenis'];
$pm->save((int) $pos['id'], ['name' => $pos['name'], 'code' => $pdo->query("SELECT code FROM positions WHERE id = {$pos['id']}")->fetchColumn(), 'applies_to' => 'both', 'unit_kinds' => ['satuan_uji', 'departemen'], 'sort_order' => 500, 'is_active' => 1]);
ok(PositionModel::kindList($pdo->query("SELECT unit_kinds FROM positions WHERE id = {$pos['id']}")->fetchColumn()) === ['departemen', 'satuan_uji'], 'batas jenis unit pada jabatan menerima jenis baru (urut menurut daftar jenis)');
ok(strpos((string) PositionModel::mismatch(['name' => 'Ketua Uji', 'applies_to' => 'unit', 'unit_kinds' => 'satuan_uji'], 'departemen'), 'Satuan Uji') !== false, 'pesan penolakan jabatan menyebut label jenis baru');
[, , $c] = call('TPositions', 'index');
ok(strpos($c->html, 'value="satuan_uji"') !== false && strpos($c->html, 'Belum ditentukan') !== false && strpos($c->html, '>Lainnya<') === false, 'halaman Jabatan: kotak jenis unit memuat jenis baru dan "Belum ditentukan", tanpa "Lainnya"');

// ---------- Mengubah dan menonaktifkan ----------
$um->kindSave('satuan_uji', ['label' => 'Satuan Baru', 'sort_order' => '75', 'group_name' => 'Satuan-Satuan', 'group_sort' => '880', 'is_active' => true]);
$k = UnitKinds::find('satuan_uji');
ok($k['label'] === 'Satuan Baru' && (int) $k['sort_order'] === 75 && $k['group_name'] === 'Satuan-Satuan' && (int) $k['group_sort'] === 880 && $k['code'] === 'satuan_uji', 'mengubah nama, urutan, kelompok bawaan; kode tetap');
$um->kindSave('belum', ['label' => 'Belum ditentukan', 'is_active' => false]);
ok((int) UnitKinds::find('belum')['is_active'] === 1, 'jenis bawaan sistem tak bisa dinonaktifkan');
$um->kindSave('satuan_uji', ['label' => 'Satuan Baru', 'is_active' => false]);
ok(!isset(UnitKinds::labels(true)['satuan_uji']) && isset(UnitKinds::labels()['satuan_uji']), 'jenis nonaktif tak ditawarkan, tetapi tetap dikenali');
[, , $c] = call('TUnits', 'catalog');
ok(strpos($c->html, '<option value="satuan_uji" >') === false && strpos($c->html, '<option value="satuan_uji" selected>') !== false, 'formulir unit: jenis nonaktif tak ditawarkan, kecuali pada unit yang sudah berjenis itu');
$um->kindSave('satuan_uji', ['label' => 'Satuan Baru', 'group_name' => 'Satuan-Satuan', 'group_sort' => '880', 'is_active' => true]);

// ---------- Kelompok bawaan jenis baru ----------
$term = $pdo->query("SELECT t.id, t.structure_id FROM terms t JOIN structures s ON s.id = t.structure_id AND s.level = 'MWP' WHERE t.is_current = 1 AND t.deleted_at IS NULL LIMIT 1")->fetch();
if ($term) {
    $before = count($um->groupsByTerm($term['id']));
    [$gid, $note] = $um->groupForKind($term['structure_id'], $term['id'], 'satuan_uji');
    ok($gid > 0 && strpos($note, 'Satuan-Satuan') !== false && count($um->groupsByTerm($term['id'])) === $before + 1, 'jenis baru dengan kelompok bawaan: kelompoknya dibuat di periode');
    [$gid2] = $um->groupForKind($term['structure_id'], $term['id'], 'satuan_uji');
    ok($gid2 === $gid, 'dipanggil lagi: kelompok yang sama dipakai, tak dobel');
    ok($um->groupForKind($term['structure_id'], $term['id'], 'belum') === [null, ''], '"Belum ditentukan" tak punya kelompok bawaan');
}

// ---------- Menghapus ----------
$msg = (string) threw(function () use ($um) { $um->kindDelete('departemen'); });
ok(strpos($msg, 'masih dipakai') !== false && UnitKinds::exists('departemen'), 'jenis yang masih dipakai unit tak bisa dihapus');
ok(strpos((string) threw(function () use ($um) { $um->kindDelete('belum'); }), 'bawaan sistem') !== false && UnitKinds::exists('belum'), 'jenis bawaan sistem tak bisa dihapus');
ok(strpos((string) threw(function () use ($um) { $um->kindDelete('satuan_uji'); }), 'masih dipakai 1 unit') !== false, 'jenis baru yang sudah dipakai sebuah unit tak bisa dihapus');
$pdo->prepare('UPDATE unit_catalog SET kind = ? WHERE id = ?')->execute(['belum', $cid]);
$msg = (string) threw(function () use ($um) { $um->kindDelete('satuan_uji'); });
ok(strpos($msg, 'batas jenis unit') !== false && UnitKinds::exists('satuan_uji'), 'jenis yang masih tercantum pada batas jabatan tak bisa dihapus');
$pm->save((int) $pos['id'], ['name' => $pos['name'], 'code' => $pdo->query("SELECT code FROM positions WHERE id = {$pos['id']}")->fetchColumn(), 'applies_to' => 'both', 'unit_kinds' => ['departemen'], 'sort_order' => 500, 'is_active' => 1]);
ok(threw(function () use ($um) { $um->kindDelete('satuan_uji'); }) === null && !UnitKinds::exists('satuan_uji'), 'jenis yang tak dipakai lagi bisa dihapus');
ok(strpos((string) threw(function () use ($um) { $um->kindDelete('satuan_uji'); }), 'tidak ditemukan') !== false, 'menghapus yang sudah tak ada ditolak');

// ---------- Lewat pengendali ----------
[$redir, $fl] = call('TUnits', 'kind_save', ['label' => 'Gugus Uji', 'sort_order' => '', 'group_name' => '', 'group_sort' => '']);
ok($redir === 'units/catalog#jenis-unit' && strpos(flashText($fl), 'success') === 0 && UnitKinds::exists('gugus_uji'), 'kind_save (baru): redirect ke kartu Jenis unit, pesan sukses');
[$redir, $fl] = call('TUnits', 'kind_save', ['label' => 'Gugus Uji']);
ok(strpos(flashText($fl), 'error') === 0 && strpos(flashText($fl), 'sudah ada') !== false, 'kind_save (nama kembar): pesan galat, bukan pengecualian mentah');
[$redir, $fl] = call('TUnits', 'kind_save', ['code' => 'gugus_uji', 'label' => 'Gugus Terubah', 'sort_order' => '77', 'is_active' => '1']);
ok(UnitKinds::label('gugus_uji') === 'Gugus Terubah' && (int) UnitKinds::find('gugus_uji')['sort_order'] === 77, 'kind_save (ubah): tersimpan');
[$redir, $fl] = call('TUnits', 'kind_delete', ['code' => 'departemen']);
ok(strpos(flashText($fl), 'error') === 0 && UnitKinds::exists('departemen'), 'kind_delete (dipakai): pesan galat, jenis utuh');
[$redir, $fl] = call('TUnits', 'kind_delete', ['code' => 'gugus_uji']);
ok(strpos(flashText($fl), 'success') === 0 && !UnitKinds::exists('gugus_uji'), 'kind_delete (tak dipakai): terhapus');

// ---------- Lencana di susunan dan formulir pendaftaran ----------
$units = $pdo->query("SELECT su.term_id, uc.kind FROM structure_units su JOIN unit_catalog uc ON uc.id = su.unit_catalog_id WHERE su.deleted_at IS NULL AND uc.kind IN ('departemen', 'belum') GROUP BY su.term_id, uc.kind")->fetchAll();
$seen = [];
foreach ($units as $u) {
    foreach ($um->unitsByTerm($u['term_id']) as $row) {
        if ($row['kind'] === 'departemen') $seen['dept'] = $row['kind_badge'] === 'Departemen';
        if ($row['kind'] === 'belum') $seen['belum'] = $row['kind_badge'] === null;
    }
}
ok(($seen['dept'] ?? true) === true && ($seen['belum'] ?? true) === true, 'lencana susunan: nama jenis; kosong untuk "belum ditentukan"');
[, , $c] = call('TDaftar', 'index');
$groups = $c->rendered['public/daftar']['unitsByWing'] ?? [];
$allLabels = [];
foreach ($groups as $wing) foreach ($wing as $g) $allLabels[] = $g['label'];
ok(in_array('Belum ditentukan', $allLabels, true) && !in_array('Lainnya', $allLabels, true), 'formulir pendaftaran publik: kelompok "Belum ditentukan", tanpa "Lainnya"');
ok(array_search('Departemen', $allLabels) < array_search('Belum ditentukan', $allLabels), 'formulir pendaftaran: urutan kelompok mengikuti daftar jenis');

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
