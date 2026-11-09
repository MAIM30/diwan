<?php
// PATH LOKASI FILE: tests/structure_mwln_test.php
// Uji tingkat MWLN (Muslimah Luar Negeri, padanan DPLN): tampil di pilihan Tingkat, wajib di bawah MWP, sayap Muslimah dan tier 2 di database,
// ikut di urutan/label/chip jenjang, dan terbatas menurut lingkup admin.
//
// StructureModel::create/update memakai transaksi sendiri (commit), jadi jalur SIMPAN yang berhasil tak bisa dibungkus transaksi uji. Struktur
// yang dibuatnya bernama "UJI-MWLN-<pid>" dan dihapus berdasarkan id-nya (beserta jejak auditnya) di akhir, juga bila uji berhenti di tengah.
//
//   php tests/structure_mwln_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview', 'AdminNaming'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/StructuresController.php';
foreach (['StructureModel', 'UnitModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class Redirected extends Exception {}   // BUKAN RuntimeException: controller menangkap RuntimeException di sekitar create()
class TS extends StructuresController {
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
function sess($role, $roots = null) {
    $all = $roots === null;
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => [$role]],
                 'scope' => ['all' => $all, 'root_ids' => $all ? [] : $roots,
                             'structure_ids' => $all ? [] : (new StructureModel())->descendantIdsOfMany($roots), 'is_national' => $all, 'label' => 'Uji']];
}
function flashText() { return implode(' | ', array_map(function ($x) { return $x['message']; }, $_SESSION['_flash'] ?? [])); }
function simpan(array $post) {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; unset($_SESSION['_flash']);
    try { (new TS)->save(); return null; } catch (Redirected $r) { return $r->getMessage(); }
}
function formHtml() { $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); (new TS)->form(); return ob_get_clean(); }

$pdo = Database::getInstance()->getConnection();
$one = function ($sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };
$dpp = $one("SELECT id FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1");
$mwp = $one("SELECT id FROM structures WHERE level = 'MWP' AND deleted_at IS NULL LIMIT 1");
$dpw = $one("SELECT id FROM structures WHERE level = 'DPW' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$mww = $one("SELECT id FROM structures WHERE level = 'MWW' AND deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$dpp || !$mwp || !$dpw || !$mww) { echo "LEWAT: butuh DPP, MWP, DPW, dan MWW di database lokal.\n"; exit(0); }

$prefix = 'UJI-MWLN-' . getmypid();
$cleanup = function () use ($pdo, $prefix) {
    $ids = $pdo->query("SELECT id FROM structures WHERE name LIKE '" . $prefix . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->exec("DELETE FROM audit_log WHERE entity = 'structures' AND entity_id = " . (int) $id);
        $pdo->exec("DELETE FROM structures WHERE id = " . (int) $id . " AND name LIKE '" . $prefix . "%'");
    }
};
register_shutdown_function($cleanup);
$mwlnBefore = $one("SELECT COUNT(*) FROM structures WHERE level = 'MWLN'");

// ---------- 1. Konstanta, urutan, label ----------
ok(StructureModel::BY_WING['muslimah'] === ['MWP', 'MWW', 'MWLN', 'MWD', 'MWC', 'MWRa'], '1: sayap Muslimah berurut MWP, MWW, MWLN, MWD, MWC, MWRa (MWLN setingkat MWW)', implode(',', StructureModel::BY_WING['muslimah']));
ok(count(StructureModel::LEVELS) === count(array_unique(StructureModel::LEVELS)) && substr_count(implode(',', StructureModel::LEVELS), 'MWLN') === 1, '1: MWLN muncul tepat sekali di LEVELS');
$lvl = StructureModel::LEVELS;
ok(!array_diff($lvl, PeopleOverview::LEVEL_ORDER), '1: setiap tingkat StructureModel punya tempat di urutan PeopleOverview', implode(',', array_diff($lvl, PeopleOverview::LEVEL_ORDER)));
ok(!array_diff(PeopleOverview::LEVEL_ORDER, array_keys(PeopleOverview::LEVEL_NAMES)), '1: setiap tingkat di urutan punya nama lengkap', implode(',', array_diff(PeopleOverview::LEVEL_ORDER, array_keys(PeopleOverview::LEVEL_NAMES))));
$o = PeopleOverview::LEVEL_ORDER;
ok(array_search('MWW', $o) < array_search('MWLN', $o) && array_search('MWLN', $o) < array_search('MWD', $o), '1: di urutan jenjang MWLN tepat sesudah MWW dan sebelum MWD');
ok(PeopleOverview::LEVEL_NAMES['MWLN'] === 'Muslimah Luar Negeri', '1: nama lengkap "Muslimah Luar Negeri"');
ok(PeopleOverview::wingOf('MWLN') === 'musl', '1: MWLN masuk kelompok Muslimah');
ok(PeopleOverview::chipGroups(['MWW' => 1, 'MWLN' => 0, 'MWD' => 2, 'DPLN' => 0])['musl'] === ['MWW', 'MWLN', 'MWD'], '1: chip jenjang: MWLN ikut (walau 0 orang) di kelompok Muslimah, sesudah MWW');
ok(AdminNaming::displayName('MWLN', 'Arab Saudi') === 'Admin Luar Negeri: MWLN Arab Saudi', '1: nama akun admin MWLN', AdminNaming::displayName('MWLN', 'Arab Saudi'));

// ---------- 2. Database: ENUM, sayap, tier ----------
$enum = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'structures' AND column_name = 'level'")->fetchColumn();
preg_match_all("/'([^']+)'/", $enum, $mm);
ok(!array_diff($lvl, $mm[1]), '2: setiap tingkat yang dikenal kode ada di ENUM structures.level (migrasi 023 sudah dijalankan?)', implode(',', array_diff($lvl, $mm[1])));
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id, path) VALUES (?, 'MWLN', ?, ?, '')")->execute([Uuid::v4(), $prefix . '-SQL', $mwp]);
$row = $pdo->query("SELECT level, wing, tier FROM structures WHERE name = '" . $prefix . "-SQL'")->fetch(PDO::FETCH_ASSOC);
$pdo->rollBack();
ok($row && $row['wing'] === 'muslimah' && (int) $row['tier'] === 2, '2: baris MWLN otomatis wing = muslimah, tier = 2 (setingkat MWW/DPLN)', json_encode($row));
$tiers = $pdo->query("SELECT level, tier FROM structures GROUP BY level, tier")->fetchAll(PDO::FETCH_KEY_PAIR);
ok((int) ($tiers['MWW'] ?? 0) === 2 && (int) ($tiers['DPLN'] ?? 0) === 2 && (int) ($tiers['DPD'] ?? 0) === 3, '2: migrasi tak merusak tier tingkat lain (MWW=2, DPLN=2, DPD=3)', json_encode($tiers));
ok($one("SELECT COUNT(*) FROM structures WHERE wing = 'umum' AND level LIKE 'MW%'") === 0, '2: tak ada tingkat MW* yang tercatat sebagai sayap Umum');

// ---------- 3. Pilihan tingkat menurut lingkup ----------
sess('super_admin');
$sm = new StructureModel();
ok(in_array('MWLN', $sm->levelsInScope(), true), '3: Super Admin: MWLN tersedia');
sess('admin_struktur', [$mwp]);
$lv = (new StructureModel())->levelsInScope();
ok(in_array('MWLN', $lv, true) && in_array('MWW', $lv, true) && !in_array('DPP', $lv, true) && !in_array('DPW', $lv, true), '3: admin MWP: MWLN tersedia (anak MWP), tingkat sayap Umum tidak', implode(',', $lv));
sess('admin_struktur', [$dpp]);
ok(in_array('MWLN', (new StructureModel())->levelsInScope(), true), '3: admin DPP (MWP ada di bawahnya): MWLN tersedia');
sess('admin_struktur', [$dpw]);
ok(!in_array('MWLN', (new StructureModel())->levelsInScope(), true), '3: admin DPW: MWLN tidak tersedia (bukan sayapnya)');
sess('admin_struktur', [$mww]);
ok(!in_array('MWLN', (new StructureModel())->levelsInScope(), true), '3: admin MWW: MWLN tidak tersedia (MWLN setingkat MWW, bukan anaknya)');

// ---------- 4. Form ----------
sess('super_admin');
$h = formHtml();
preg_match('/<select id="level".*?<\/select>/s', $h, $sel);
$opts = preg_match_all('/<option value="([A-Za-z]*)"/', $sel[0] ?? '', $om) ? $om[1] : [];
$opts = array_values(array_filter($opts));
ok($opts === ['DPP', 'DPW', 'DPLN', 'DPD', 'DPC', 'DPRa', 'MWP', 'MWW', 'MWLN', 'MWD', 'MWC', 'MWRa'], '4: pilihan Tingkat berurut dan memuat MWLN antara MWW dan MWD', implode(',', $opts));
ok(strpos($h, "{DPLN: 'DPP', MWLN: 'MWP', MWRa: 'MWC'}") !== false, '4: skrip form: MWLN menyaring pilihan Induk ke MWP');
ok(strpos($h, 'MWLN (Luar Negeri) setingkat MWW di bawah MWP') !== false, '4: teks bantuan menjelaskan MWLN');
sess('admin_struktur', [$dpw]);
$h = formHtml();
preg_match('/<select id="level".*?<\/select>/s', $h, $sel);
ok(strpos($sel[0] ?? '', 'value="MWLN"') === false, '4: admin DPW tidak ditawari MWLN');

// ---------- 5. Simpan: aturan induk dan lingkup ----------
sess('super_admin');
$base = ['level' => 'MWLN', 'name' => $prefix . '-A', 'region_code' => '', 'is_active' => '1'];
$to = simpan($base + ['parent_id' => (string) $dpp]);
ok($to === 'structures/form' && stripos(flashText(), 'MWLN harus berada di bawah MWP') !== false, '5: MWLN di bawah DPP ditolak', (string) $to . ' ' . flashText());
$to = simpan($base + ['parent_id' => (string) $mww]);
ok($to === 'structures/form' && stripos(flashText(), 'MWLN harus berada di bawah MWP') !== false, '5: MWLN di bawah MWW ditolak', (string) $to . ' ' . flashText());
$to = simpan($base + ['parent_id' => '']);
ok($to === 'structures/form' && stripos(flashText(), 'wajib memiliki induk') !== false, '5: MWLN tanpa induk (Super Admin) ditolak', (string) $to . ' ' . flashText());
sess('admin_struktur', [$dpw]);
$to = simpan($base + ['parent_id' => (string) $dpw]);
ok($to === 'structures/form' && stripos(flashText(), 'tingkat struktur wajib diisi dengan benar') !== false, '5: admin DPW yang mengirim MWLN secara manual ditolak di server', (string) $to . ' ' . flashText());
ok($one("SELECT COUNT(*) FROM structures WHERE name LIKE '" . $prefix . "%'") === 0, '5: semua penolakan tidak menyimpan apa pun');

// ---------- 6. Simpan yang berhasil ----------
sess('super_admin');
$to = simpan($base + ['parent_id' => (string) $mwp]);
$new = $pdo->query("SELECT * FROM structures WHERE name = '" . $prefix . "-A'")->fetch(PDO::FETCH_ASSOC);
ok($new && $to === 'terms/form/0/' . $new['id'], '6: MWLN di bawah MWP diterima; lanjut ke pembuatan periode', (string) $to . ' ' . flashText());
$mwpRow = $pdo->query("SELECT path, depth FROM structures WHERE id = $mwp")->fetch(PDO::FETCH_ASSOC);
ok($new && $new['level'] === 'MWLN' && $new['wing'] === 'muslimah' && (int) $new['tier'] === 2 && (int) $new['parent_id'] === $mwp, '6: tersimpan: MWLN, wing muslimah, tier 2, induk MWP', json_encode($new));
ok($new && $new['path'] === $mwpRow['path'] . $new['id'] . '/' && (int) $new['depth'] === (int) $mwpRow['depth'] + 1, '6: path dan depth mengikuti induknya (dasar lingkup admin)', (string) ($new['path'] ?? ''));

// Admin MWP membuat sendiri; lingkupnya langsung mencakup struktur barunya.
sess('admin_struktur', [$mwp]);
$to = simpan(['level' => 'MWLN', 'name' => $prefix . '-B', 'parent_id' => (string) $mwp, 'region_code' => '', 'is_active' => '1']);
$newB = $pdo->query("SELECT id FROM structures WHERE name = '" . $prefix . "-B'")->fetchColumn();
ok($newB && $to === 'terms/form/0/' . $newB, '6: admin MWP boleh membuat MWLN di bawah MWP-nya', (string) $to . ' ' . flashText());
ok($newB && AdminScope::allows((int) $newB), '6: lingkup admin MWP langsung mencakup MWLN barunya (AdminScope::refresh)');
sess('admin_struktur', [$dpw]);
ok(!AdminScope::allows((int) $new['id']), '6: admin DPW tidak mencakup MWLN');

// ---------- 7. Setelah ada MWLN ----------
sess('super_admin');
$counts = (new StructureModel())->levelCountsInScope();
ok(($counts['MWLN'] ?? 0) === $mwlnBefore + 2, '7: jumlah struktur per jenjang memuat MWLN', json_encode($counts['MWLN'] ?? null));
ok(PeopleOverview::chipGroups($counts)['musl'] === array_values(array_intersect(PeopleOverview::LEVEL_ORDER, array_keys(array_filter($counts, function ($k) { return strpos($k, 'MW') === 0; }, ARRAY_FILTER_USE_KEY)))), '7: chip jenjang Muslimah memuat MWLN dalam urutan baku', implode(',', PeopleOverview::chipGroups($counts)['musl']));
$_GET = ['lv' => 'MWLN']; $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); (new TS)->index(); $h = ob_get_clean();
ok(strpos($h, $prefix . '-A') !== false && strpos($h, '<span class="badge lv">MWLN</span>') !== false && strpos($h, '>MWLN <span class="chip-n">') !== false, '7: halaman Struktur: chip dan baris MWLN tampil');

// Katalog unit: MWLN memakai sayap Muslimah.
$um = new UnitModel();
ok($um->wingOfStructure((int) $new['id']) === 'muslimah', '7: MWLN memakai katalog unit sayap Muslimah');
$cMus = $one("SELECT id FROM unit_catalog WHERE wing = 'muslimah' AND is_active = 1 ORDER BY id LIMIT 1");
$cUmum = $one("SELECT id FROM unit_catalog WHERE wing = 'umum' AND is_active = 1 ORDER BY id LIMIT 1");
$cocok = true; try { $um->assertCatalogFitsStructure($cMus, (int) $new['id']); } catch (RuntimeException $e) { $cocok = false; }
$tolak = false; try { $um->assertCatalogFitsStructure($cUmum, (int) $new['id']); } catch (RuntimeException $e) { $tolak = true; }
ok($cocok && $tolak, '7: unit Muslimah boleh dipasang di MWLN; unit sayap Umum ditolak');

// ---------- Bersih-bersih ----------
$cleanup();
ok($one("SELECT COUNT(*) FROM structures WHERE name LIKE '" . $prefix . "%'") === 0 && $one("SELECT COUNT(*) FROM structures WHERE level = 'MWLN'") === $mwlnBefore, 'sesudah pembersihan: tak ada struktur uji tersisa (jumlah MWLN seperti semula)');

echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
