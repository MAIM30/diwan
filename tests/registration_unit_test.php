<?php
// PATH LOKASI FILE: tests/registration_unit_test.php
// Uji pilihan "Unit Organisasi" di Form Pengajuan (pendaftaran publik) dan tampilannya bagi admin:
//   1. Formulir: daftar unit per sayap (hanya yang aktif, sayapnya benar) dan kolom pilihannya.
//   2. Kirim pengajuan: unit tersimpan; tanpa unit tetap sah; unit tak dikenal / nonaktif / beda sayap ditolak dan tidak menyimpan apa pun.
//   3. Admin: daftar dan halaman tinjau menampilkan unitnya; unit yang sudah terpasang di struktur itu langsung terpilih saat aktivasi,
//      yang belum terpasang diberi catatan.
// Menjalankan DaftarController dan RegistrationsController SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback.
//
//   php tests/registration_unit_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'WaNotify'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/DaftarController.php';
require_once ROOT_DIR . '/app/Controllers/RegistrationsController.php';
require_once ROOT_DIR . '/app/Models/UnitModel.php';
require_once ROOT_DIR . '/app/Models/TermModel.php';
require_once ROOT_DIR . '/app/Models/RegistrationModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
$_SERVER['REMOTE_ADDR'] = '10.255.255.9';

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
class TD extends DaftarController { use Capture; public function daftarKirim() { $this->submit(); } }
class TR extends RegistrationsController { use Capture; }

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function flashText() { return implode(' | ', array_map(function ($x) { return $x['message']; }, $_SESSION['_flash'] ?? [])); }

$pdo = Database::getInstance()->getConnection();
$dpp = (int) $pdo->query("SELECT id FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$mwp = (int) $pdo->query("SELECT id FROM structures WHERE level = 'MWP' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$termId = (int) (new TermModel())->currentOfStructure($dpp)['id'];
if (!$dpp || !$mwp || !$termId) { echo "LEWAT: butuh DPP, MWP, dan periode berjalan DPP di database lokal.\n"; exit(0); }
$units = new UnitModel();
$installed = $units->unitsByTerm($termId);
$installedCatalogId = $installed ? (int) $installed[0]['unit_catalog_id'] : 0;
$installedSuId = $installed ? (int) $installed[0]['id'] : 0;
$notInstalled = $pdo->prepare("SELECT c.id, c.name FROM unit_catalog c WHERE c.wing = 'umum' AND c.is_active = 1
    AND c.id NOT IN (SELECT unit_catalog_id FROM structure_units WHERE term_id = ? AND deleted_at IS NULL) ORDER BY c.id LIMIT 1");
$notInstalled->execute([$termId]);
$free = $notInstalled->fetch();
$muslimahUnit = (int) $pdo->query("SELECT id FROM unit_catalog WHERE wing = 'muslimah' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$installedCatalogId || !$free || !$muslimahUnit) { echo "LEWAT: data unit lokal tidak memenuhi syarat uji (unit terpasang di DPP, unit belum terpasang, unit Muslimah).\n"; exit(0); }

$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
$pdo->prepare("INSERT INTO unit_catalog (uuid, name, kind, wing, sort_order, is_active) VALUES (?, 'Unit Uji Nonaktif', 'departemen', 'umum', 0, 0)")->execute([Uuid::v4()]);
$inactiveUnit = (int) $pdo->lastInsertId();

// ---------- 1. Formulir ----------
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = [];
$c = new TD; $c->index();
$byWing = $c->rendered['public/daftar']['unitsByWing'] ?? [];
ok(isset($byWing['umum'], $byWing['muslimah']), '1: daftar unit tersedia untuk dua sayap');
foreach (['umum', 'muslimah'] as $wing) {
    $ids = [];
    foreach ($byWing[$wing] ?? [] as $g) foreach ($g['items'] as $it) $ids[] = (int) $it['id'];
    $expected = array_map('intval', $pdo->query("SELECT id FROM unit_catalog WHERE wing = '$wing' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
    sort($ids); sort($expected);
    ok($ids === $expected && count($ids) > 0, "1: sayap $wing memuat tepat unit katalog yang aktif dan sayapnya sama", count($ids) . ' vs ' . count($expected));
}
$allIds = [];
foreach ($byWing as $groups) foreach ($groups as $g) foreach ($g['items'] as $it) $allIds[] = $it['id'];
ok(!in_array($inactiveUnit, $allIds, true), '1: unit nonaktif tidak ditawarkan');
$labels = array_map(function ($g) { return $g['label']; }, $byWing['umum']);
ok(in_array('Departemen', $labels, true) && array_search('Departemen', $labels) < array_search('Lembaga', $labels), '1: dikelompokkan per jenis (Departemen sebelum Lembaga)', implode(',', $labels));
ok(strpos($c->html, 'name="unit_catalog_id"') !== false && strpos($c->html, 'Unit Organisasi') !== false, '1: formulir memuat kolom Unit Organisasi');
ok(preg_match('/"wing":"(umum|muslimah)"/', $c->html) === 1, '1: tiap struktur membawa sayapnya ke formulir (untuk memilih daftar unit)');
ok(preg_match('/<select id="unit_catalog_id" name="unit_catalog_id" disabled>/', $c->html) === 1, '1: kolom terkunci sampai struktur dipilih');

// ---------- 2. Kirim pengajuan ----------
function kirim($structureId, $phone, $unit, $name = 'Uji Unit Organisasi') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['name' => $name, 'gender' => 'L', 'phone' => $phone, 'target_structure_id' => (string) $structureId];
    if ($unit !== null) $_POST['unit_catalog_id'] = (string) $unit;
    unset($_SESSION['_flash']);
    $c = new TD; $to = null;
    try { $c->daftarKirim(); } catch (Redirected $r) { $to = $r->getMessage(); }
    return [$to, $c];
}
function row($phone) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM registration_requests WHERE phone = ?"); $s->execute([Text::normalizePhone($phone)]);
    return $s->fetch();
}
function unitOf($row) { $x = json_decode((string) $row['extra_data'], true); return $x['unit_catalog_id'] ?? null; }

[$to, $c] = kirim($dpp, '08111000501', $installedCatalogId);
$r1 = row('08111000501');
ok($to === null && isset($c->rendered['public/daftar_sukses']) && $r1, '2: pengajuan dengan unit diterima', (string) $to . ' ' . flashText());
ok($r1 && (int) unitOf($r1) === $installedCatalogId, '2: unit tersimpan di data pengajuan', $r1 ? (string) $r1['extra_data'] : '-');
ok($r1 && $r1['status'] === 'submitted' && $r1['target_unit_id'] === null, '2: status tetap Diajukan; tidak ada structure_units yang diisi diam-diam');

[$to, $c] = kirim($dpp, '08111000502', '');
$r2 = row('08111000502');
ok($to === null && $r2 && unitOf($r2) === null, '2: tanpa unit tetap sah dan tidak menyimpan kunci unit', $r2 ? (string) $r2['extra_data'] : flashText());
[$to] = kirim($dpp, '08111000503', null);
ok($to === null && ($r = row('08111000503')) && unitOf($r) === null, '2: kolom unit tak terkirim (dikunci) tetap sah');

foreach ([
    ['08111000504', 999999, 'id tak dikenal'],
    ['08111000505', $inactiveUnit, 'unit nonaktif'],
    ['08111000506', $muslimahUnit, 'unit katalog Muslimah untuk struktur DPP'],
] as [$ph, $unit, $why]) {
    [$to] = kirim($dpp, $ph, $unit);
    ok($to === 'daftar' && !row($ph) && stripos(flashText(), 'Unit Organisasi') !== false, "2: ditolak, tidak ada pengajuan tersimpan ($why)", (string) $to . ' ' . flashText());
}
[$to, $c] = kirim($mwp, '08111000507', $muslimahUnit);
ok($to === null && ($r = row('08111000507')) && (int) unitOf($r) === $muslimahUnit, '2: unit Muslimah diterima untuk struktur Muslimah (MWP)', (string) $to . ' ' . flashText());
[$to] = kirim($mwp, '08111000508', $installedCatalogId);
ok($to === 'daftar' && !row('08111000508'), '2: unit katalog Umum ditolak untuk struktur Muslimah', (string) $to);

// ---------- 3. Sisi admin ----------
$reg = new RegistrationModel();
$full = $reg->findById((int) $r1['id']);
ok(($full['requested_unit_name'] ?? null) === $pdo->query("SELECT name FROM unit_catalog WHERE id = $installedCatalogId")->fetchColumn(), '3: findById membawa nama unit yang dipilih');
$none = $reg->findById((int) $r2['id']);
ok(array_key_exists('requested_unit_name', $none) && $none['requested_unit_name'] === null, '3: tanpa unit -> kosong');

$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['status' => 'submitted']; $_POST = [];
$list = $reg->listInScope('submitted');
$byPhone = [];
foreach ($list as $l) $byPhone[$l['phone']] = $l;
ok(($byPhone[Text::normalizePhone('08111000501')]['requested_unit_name'] ?? null) === $full['requested_unit_name'], '3: daftar pengajuan membawa nama unit');
ok(array_key_exists('requested_unit_name', $byPhone[Text::normalizePhone('08111000502')] ?? []) && $byPhone[Text::normalizePhone('08111000502')]['requested_unit_name'] === null, '3: daftar: pengajuan tanpa unit kosong');
$c = new TR; ob_start(); try { $c->index(); } catch (Throwable $e) { ob_end_clean(); throw $e; } ob_end_clean();
ok(strpos($c->html, e($full['requested_unit_name'])) !== false, '3: halaman daftar pengajuan menampilkan nama unitnya');

// Tinjau: unit yang sudah terpasang di DPP periode itu -> terpilih otomatis di langkah aktivasi.
$pdo->prepare("UPDATE registration_requests SET status = 'verified' WHERE id IN (?, ?)")->execute([$r1['id'], $r2['id']]);
$c = new TR; $c->review((int) $r1['id']);
$d = $c->rendered['admin/registrations/review'] ?? [];
ok(($d['suggestedUnitId'] ?? null) === $installedSuId, '3: unit terpasang di struktur -> disarankan untuk aktivasi', json_encode($d['suggestedUnitId'] ?? null));
ok(preg_match('/<option value="' . (int) $d['suggestedUnitId'] . '" selected>/', $c->html) === 1, '3: unit itu sudah terpilih di kolom Unit saat aktivasi');
ok(strpos($c->html, '<th>Unit Organisasi</th>') !== false && strpos($c->html, e($full['requested_unit_name'])) !== false, '3: halaman tinjau menampilkan Unit Organisasi pendaftar');
ok(strpos($c->html, 'belum terpasang') === false, '3: unit yang terpasang tidak diberi catatan "belum terpasang"');

// Unit yang belum terpasang di struktur itu: tampil, diberi catatan, dan tidak dipilihkan.
[$to] = kirim($dpp, '08111000509', (int) $free['id']);
$r3 = row('08111000509');
$pdo->prepare("UPDATE registration_requests SET status = 'verified' WHERE id = ?")->execute([$r3['id']]);
$c = new TR; $c->review((int) $r3['id']);
$d = $c->rendered['admin/registrations/review'];
ok($d['suggestedUnitId'] === null && strpos($c->html, e($free['name'])) !== false && strpos($c->html, 'belum terpasang') !== false, '3: unit belum terpasang -> tampil dengan catatan, tidak dipilihkan');

// Tanpa unit: baris tampil sebagai tidak dipilih.
$c = new TR; $c->review((int) $r2['id']);
ok(strpos($c->html, 'Tidak dipilih') !== false && array_key_exists('suggestedUnitId', $c->rendered['admin/registrations/review']) && $c->rendered['admin/registrations/review']['suggestedUnitId'] === null, '3: tanpa unit -> "Tidak dipilih", tidak ada saran');

$pdo->rollBack();
ok(!row('08111000501') && !$pdo->query("SELECT COUNT(*) FROM unit_catalog WHERE name = 'Unit Uji Nonaktif'")->fetchColumn(), 'data uji tidak tersisa setelah rollback');

echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
