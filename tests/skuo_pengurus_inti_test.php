<?php
// PATH LOKASI FILE: tests/skuo_pengurus_inti_test.php
// Uji "Pengurus Inti sebagai entri pertama SKUO" -- hanya TAMPILAN; penempatan inti tetap tanpa unit (tak ada migrasi):
//   - Ui::slotLabel: nama unit, atau "Pengurus Inti" (jabatan inti) / "Pimpinan Lainnya" (bukan inti) bila tanpa unit
//   - Pengelompokan Unit: tiap struktur membuka dengan baris "Pengurus Inti" beserta jumlah orangnya (sama dengan hitungan SQL mandiri)
//   - Daftar Data Pengurus: baris yang jabatan utamanya inti diberi "Pengurus Inti ·" di depan struktur
//   - halaman anggota satu jabatan: kolom unit menyebut "Pimpinan Lainnya" untuk jabatan non-inti (dulu semuanya "Pengurus Inti")
//   - profil orang: tempat tiap jabatan berjalan disebut (bukan lagi "Pucuk pimpinan")
// Menjalankan pengendali SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback.
//
//   php tests/skuo_pengurus_inti_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['PeopleController', 'UnitsController', 'PositionsController'] as $c) require_once ROOT_DIR . "/app/Controllers/$c.php";
require_once ROOT_DIR . '/app/Models/PersonModel.php';
require_once ROOT_DIR . '/app/Models/PositionModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];

trait Render {
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); require ROOT_DIR . "/app/Views/$v.php";
    }
}
class TPeople extends PeopleController { use Render; }
class TUnits extends UnitsController { use Render; }
class TPositions extends PositionsController { use Render; }

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function out($controller, $method, array $args = [], array $get = []) {
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); $controller->$method(...$args); return ob_get_clean();
}

$pdo = Database::getInstance()->getConnection();
$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

$active = "(pl.end_date IS NULL OR pl.end_date >= CURDATE())";
$curTerm = "JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 AND t.deleted_at IS NULL";

// ---------- Ui::slotLabel ----------
ok(Ui::slotLabel('Departemen Kesehatan', 1) === 'Departemen Kesehatan' && Ui::slotLabel('Departemen Kesehatan', 0) === 'Departemen Kesehatan', 'slotLabel: bila ada unit, nama unitnya');
ok(Ui::slotLabel(null, 1) === 'Pengurus Inti' && Ui::slotLabel('', '1') === 'Pengurus Inti', 'slotLabel: tanpa unit + jabatan inti = Pengurus Inti');
ok(Ui::slotLabel(null, 0) === 'Pimpinan Lainnya' && Ui::slotLabel('', null) === 'Pimpinan Lainnya', 'slotLabel: tanpa unit + bukan inti = Pimpinan Lainnya');

// ---------- Pengelompokan Unit ----------
$dppTerm = (int) $pdo->query("SELECT t.id FROM terms t JOIN structures s ON s.id = t.structure_id AND s.level = 'DPP' WHERE t.is_current = 1 AND t.deleted_at IS NULL LIMIT 1")->fetchColumn();
if ($dppTerm) {
    $want = (int) $pdo->query("SELECT COUNT(DISTINCT pl.person_id) FROM placements pl JOIN positions po ON po.id = pl.position_id
        WHERE pl.term_id = $dppTerm AND pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND po.is_core = 1 AND $active")->fetchColumn();
    $h = out(new TUnits, 'grouping', [], ['lv' => 'DPP', 'st' => 'semua']);
    ok(preg_match('~Pengurus Inti</strong>.*?&middot; ([\d.]+) orang~s', $h, $m) === 1 && (int) str_replace('.', '', $m[1]) === $want && $want > 0,
       'Pengelompokan Unit (DPP): baris "Pengurus Inti" memuat jumlah orang yang sama dengan SQL (' . $want . ')', $m[1] ?? 'tak ada baris');
    ok(strpos($h, '>Lihat susunan</a>') !== false && strpos($h, '/terms/detail/' . $dppTerm . '"') !== false, 'baris Pengurus Inti menaut ke susunan periodenya');
    ok(strpos($h, 'Tambah unit') !== false, 'Pengelompokan Unit: pengeditan unit tetap ada (baris Pengurus Inti hanya-baca)');
}
$noInti = $pdo->query("SELECT t.id, s.level, s.name FROM terms t JOIN structures s ON s.id = t.structure_id
    WHERE t.is_current = 1 AND t.deleted_at IS NULL AND s.level IN ('DPD', 'MWD', 'DPLN', 'MWW', 'DPW')
      AND NOT EXISTS (SELECT 1 FROM placements pl JOIN positions po ON po.id = pl.position_id WHERE pl.term_id = t.id AND pl.deleted_at IS NULL
                      AND pl.structure_unit_id IS NULL AND po.is_core = 1 AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()))
    ORDER BY t.id LIMIT 1")->fetch();
if ($noInti) {
    $h = out(new TUnits, 'grouping', [], ['lv' => $noInti['level'], 'st' => 'semua', 'q' => $noInti['name']]);
    $at = strpos($h, 'id="t' . $noInti['id'] . '"');
    ok($at !== false && strpos(substr($h, $at, 2500), 'belum ada pengurus inti') !== false, 'struktur tanpa pengurus inti (' . $noInti['level'] . ' ' . $noInti['name'] . '): "belum ada pengurus inti"');
}

// ---------- Daftar Data Pengurus ----------
$rows = (new PersonModel())->searchInScope('', 25, 0, []);
$inti = count(array_filter($rows, function ($r) { return !empty($r['position_inti']); }));
$h = out(new TPeople, 'index', [], ['susun' => 'daftar', 'per_page' => '25']);
ok($rows && substr_count($h, 'Pengurus Inti &middot;') === $inti, 'Daftar: "Pengurus Inti ·" tepat pada baris yang jabatan utamanya inti (' . $inti . ' dari ' . count($rows) . ')', (string) substr_count($h, 'Pengurus Inti &middot;'));
$h2 = out(new TPeople, 'index', [], ['susun' => 'daftar', 'per_page' => '25', 'q' => 'a']);
ok(strpos($h2, 'Jabatan / Penempatan Utama') !== false, 'Daftar tetap tampil dengan pencarian');
// Orang yang jabatan utamanya di dalam unit: unitnya tetap tampil, bukan "Pengurus Inti".
$unitRow = array_values(array_filter($rows, function ($r) { return !empty($r['unit_name']); }))[0] ?? null;
if ($unitRow) ok(empty($unitRow['position_inti']), 'jabatan utama di dalam unit: bukan "inti" (unit ' . $unitRow['unit_name'] . ')');

// ---------- Anggota satu jabatan ----------
$pos = function ($core) use ($pdo, $curTerm, $active) {
    return (int) $pdo->query("SELECT po.id FROM positions po JOIN placements pl ON pl.position_id = po.id AND pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND $active
        $curTerm WHERE po.is_core = $core GROUP BY po.id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
};
$nonCore = $pos(0); $core = $pos(1);
if ($nonCore) {
    $h = out(new TPositions, 'members', [$nonCore], []);
    ok(strpos($h, 'Pimpinan Lainnya') !== false && strpos($h, 'Pengurus Inti') === false, 'jabatan non-inti tanpa unit: kolom unit = "Pimpinan Lainnya" (bukan "Pengurus Inti")');
}
if ($core) {
    $h = out(new TPositions, 'members', [$core], []);
    ok(strpos($h, 'Pengurus Inti') !== false && strpos($h, 'Pimpinan Lainnya') === false, 'jabatan inti tanpa unit: kolom unit = "Pengurus Inti"');
}

// ---------- Profil orang ----------
$one = function ($coreFlag) use ($pdo, $curTerm, $active) {
    return (int) $pdo->query("SELECT pl.person_id FROM placements pl JOIN positions po ON po.id = pl.position_id $curTerm
        JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
        WHERE pl.deleted_at IS NULL AND $active GROUP BY pl.person_id
        HAVING COUNT(*) = 1 AND SUM(pl.structure_unit_id IS NULL AND po.is_core = $coreFlag) = 1 LIMIT 1")->fetchColumn();
};
$pInti = $one(1); $pLain = $one(0);
if ($pInti) { $h = out(new TPeople, 'detail', [$pInti]); ok(strpos($h, 'Pengurus Inti · Periode') !== false && strpos($h, 'Pucuk pimpinan') === false, 'profil (jabatan inti): tempatnya "Pengurus Inti", bukan "Pucuk pimpinan"'); }
if ($pLain) { $h = out(new TPeople, 'detail', [$pLain]); ok(strpos($h, 'Pimpinan Lainnya · Periode') !== false && strpos($h, 'Pucuk pimpinan') === false, 'profil (non-inti): tempatnya "Pimpinan Lainnya"'); }

// ---------- Filter "Tanpa Unit Organisasi (SKUO)": Pengurus Inti dihitung sebagai SKUO ----------
$people = new PersonModel();
$wantTanpa = (int) $pdo->query("SELECT COUNT(*) FROM people p WHERE p.deleted_at IS NULL AND NOT EXISTS (
    SELECT 1 FROM placements x JOIN terms tx ON tx.id = x.term_id AND tx.is_current = 1 AND tx.deleted_at IS NULL
    JOIN positions px ON px.id = x.position_id
    WHERE x.person_id = p.id AND x.deleted_at IS NULL AND (x.end_date IS NULL OR x.end_date >= CURDATE())
      AND (x.structure_unit_id IS NOT NULL OR px.is_core = 1))")->fetchColumn();
$gotTanpa = $people->countInScope('', ['filter' => 'tanpa_unit']);
ok($gotTanpa === $wantTanpa && $wantTanpa > 0, 'filter tanpa_unit: orang yang tak di unit mana pun dan tak di Pengurus Inti (' . $wantTanpa . ')', (string) $gotTanpa);
$idsTanpa = array_flip($people->idsInScope('', ['gender' => '', 'filter' => 'tanpa_unit', 'serving' => false]));
if ($pInti) ok(!isset($idsTanpa[$pInti]), 'orang yang HANYA menjabat di Pengurus Inti tidak lagi masuk "tanpa unit" (#' . $pInti . ')');
if ($pLain) ok(isset($idsTanpa[$pLain]), 'orang yang hanya di Pimpinan Lainnya tetap masuk "tanpa unit" (#' . $pLain . ')');
$unitOnly = (int) $pdo->query("SELECT pl.person_id FROM placements pl $curTerm WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NOT NULL AND $active LIMIT 1")->fetchColumn();
if ($unitOnly) ok(!isset($idsTanpa[$unitOnly]), 'orang yang menjabat di sebuah unit tidak masuk "tanpa unit"');
$noPlacement = (int) $pdo->query("SELECT p.id FROM people p WHERE p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM placements x WHERE x.person_id = p.id AND x.deleted_at IS NULL) LIMIT 1")->fetchColumn();
if ($noPlacement) ok(isset($idsTanpa[$noPlacement]), 'orang yang belum punya penempatan tetap masuk "tanpa unit"');
$h = out(new TPeople, 'index', [], ['filter' => 'tanpa_unit', 'susun' => 'daftar']);
ok(strpos($h, 'Pengurus Inti dihitung sebagai SKUO') !== false && strpos($h, 'termasuk yang hanya menjabat sebagai Pengurus Inti') === false, 'catatan di bawah filter menjelaskan aturan barunya');

// ---------- Jabatan: tanpa "(inti)" di dropdown, tanpa kotak centang inti, tanda inti tetap terjaga ----------
$formSrc = file_get_contents(ROOT_DIR . '/app/Views/admin/placements/form.php');
ok(strpos($formSrc, '(inti)') === false, 'dropdown Jabatan di form penempatan: tak ada lagi "(inti)" (tampilan awal maupun JS)');
ok(strpos($formSrc, 'data-core="') !== false, 'penanda data-core untuk pratinjau "Akan tampil di" tetap ada');
$h = out(new TPositions, 'index', [], []);
ok(strpos($h, 'name="is_core"') === false && strpos($h, 'Termasuk Pengurus Inti') === false, 'halaman Jabatan: kotak centang "Termasuk Pengurus Inti" tidak ada (formulir tambah maupun ubah)');
ok(strpos($h, 'badge ok">Inti<') !== false, 'halaman Jabatan: kolom Inti (penanda katalog) tetap tampil');

$pm = new PositionModel();
$coreRow = $pdo->query("SELECT * FROM positions WHERE is_core = 1 AND is_active = 1 AND code = 'sekretaris'")->fetch()
        ?: $pdo->query("SELECT * FROM positions WHERE is_core = 1 AND is_active = 1 LIMIT 1")->fetch();
if ($coreRow) {
    // Simpan tanpa kunci is_core (seperti formulir sekarang): tanda inti tidak boleh hilang.
    $same = ['name' => $coreRow['name'], 'code' => $coreRow['code'], 'applies_to' => $coreRow['applies_to'], 'unit_kinds' => PositionModel::kindList($coreRow['unit_kinds']),
             'sort_order' => $coreRow['sort_order'], 'is_active' => $coreRow['is_active']];
    $pm->save((int) $coreRow['id'], $same);
    ok((int) $pdo->query("SELECT is_core FROM positions WHERE id = " . (int) $coreRow['id'])->fetchColumn() === 1, 'menyimpan jabatan inti tanpa kunci is_core: tetap inti (' . $coreRow['name'] . ')');
    $pm->save((int) $coreRow['id'], $same + ['name' => $coreRow['name'] . ' uji']);
    ok((int) $pdo->query("SELECT is_core FROM positions WHERE id = " . (int) $coreRow['id'])->fetchColumn() === 1, 'mengganti nama jabatan inti tanpa kunci is_core: tetap inti');
}
$newId = $pm->save(null, ['name' => 'Jabatan Uji Inti', 'code' => 'zz_uji_inti_' . substr(md5((string) microtime(true)), 0, 6), 'applies_to' => 'both', 'unit_kinds' => [], 'sort_order' => 500, 'is_active' => 1]);
ok((int) $pdo->query("SELECT is_core FROM positions WHERE id = $newId")->fetchColumn() === 0, 'jabatan baru dari formulir (tanpa is_core): tidak inti');
$pm->save($newId, ['name' => 'Jabatan Uji Inti', 'code' => $pdo->query("SELECT code FROM positions WHERE id = $newId")->fetchColumn(), 'applies_to' => 'both', 'unit_kinds' => [], 'sort_order' => 500, 'is_active' => 1, 'is_core' => true]);
ok((int) $pdo->query("SELECT is_core FROM positions WHERE id = $newId")->fetchColumn() === 1, 'kunci is_core yang diberikan eksplisit tetap dihormati (skrip/migrasi)');
$pm->save($newId, ['name' => 'Jabatan Uji Inti', 'code' => $pdo->query("SELECT code FROM positions WHERE id = $newId")->fetchColumn(), 'applies_to' => 'both', 'unit_kinds' => [], 'sort_order' => 500, 'is_active' => 1]);
ok((int) $pdo->query("SELECT is_core FROM positions WHERE id = $newId")->fetchColumn() === 1, 'menyimpan ulang tanpa kunci is_core: nilai yang ada tidak ditimpa');

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
