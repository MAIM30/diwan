<?php
// PATH LOKASI FILE: tests/structure_levels_test.php
// Uji pengelolaan Tingkat Struktur (migrasi 027): tingkat baru bisa dibuat, dipilih di formulir Struktur, menurunkan wing/tier ke struktur,
// menegakkan induk wajib, bisa dinonaktifkan, dan hanya bisa dihapus bila tak dipakai.
//
// Tingkat uji berkode "UJ<pid>" dan struktur uji bernama "UJI-TINGKAT-<pid>"; keduanya (beserta jejak auditnya) dihapus berdasarkan id/kode
// di akhir, juga bila uji berhenti di tengah. Tingkat bawaan (DPP, DPW, ...) tak pernah diubah: satu-satunya penulisan ke DPP adalah
// pengiriman nilai yang sama persis, untuk membuktikan bahwa tingkat bawaan sistem terkunci.
//
//   php tests/structure_levels_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview', 'AdminNaming', 'StructureLevels'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/StructuresController.php';
require_once ROOT_DIR . '/app/Controllers/LevelsController.php';
foreach (['StructureModel', 'StructureLevelModel', 'UnitModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class Redirected extends Exception {}
trait Harness {
    protected function requireLogin($roles = null) {}
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); require ROOT_DIR . "/app/Views/$v.php";
    }
}
class TS extends StructuresController { use Harness; }
class TL extends LevelsController { use Harness; }

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
function call($ctl, $method, array $post) {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; unset($_SESSION['_flash']);
    try { (new $ctl)->$method(); return null; } catch (Redirected $r) { return $r->getMessage(); }
}
function html($ctl, $method, $arg = null) {
    $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); (new $ctl)->$method($arg); return ob_get_clean();
}
// Apakah pemanggilan model melempar RuntimeException berpesan mengandung $needle?
function rejects(callable $fn, $needle) {
    try { $fn(); return false; } catch (RuntimeException $e) { return stripos($e->getMessage(), $needle) !== false ? true : $e->getMessage(); }
}

$pdo = Database::getInstance()->getConnection();
$one = function ($sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };
$dpp = $one("SELECT id FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1");
$dpw = $one("SELECT id FROM structures WHERE level = 'DPW' AND deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$dpp || !$dpw || !$one("SELECT COUNT(*) FROM structure_levels")) { echo "LEWAT: butuh DPP, DPW, dan tabel structure_levels (migrasi 027) di database lokal.\n"; exit(0); }

$pid = getmypid();
$codeA = 'UJ' . $pid;            // tingkat uji utama (Umum, setingkat Wilayah, wajib di bawah DPP)
$codeB = 'UJB' . ($pid % 10000); // tingkat uji kedua (anak tingkat pertama)
$prefix = 'UJI-TINGKAT-' . $pid;
$auditFrom = (int) $one("SELECT COALESCE(MAX(id), 0) FROM audit_log");   // jejak audit yang ditulis uji ini = yang id-nya di atas ini
$cleanup = function () use ($pdo, $prefix, $codeA, $codeB, $auditFrom) {
    foreach ($pdo->query("SELECT id FROM structures WHERE name LIKE '" . $prefix . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->exec("DELETE FROM audit_log WHERE entity = 'structures' AND entity_id = " . (int) $id);
        $pdo->exec("DELETE FROM structures WHERE id = " . (int) $id . " AND name LIKE '" . $prefix . "%'");
    }
    foreach ([$codeA, $codeB] as $c) $pdo->exec("DELETE FROM structure_levels WHERE code = " . $pdo->quote($c) . " AND is_system = 0");
    $pdo->exec("DELETE FROM audit_log WHERE entity = 'structure_levels' AND id > " . (int) $auditFrom);
};
register_shutdown_function($cleanup);
$cleanup();   // sisa dari proses dengan pid yang sama (tak mungkin, tapi murah)
$model = new StructureLevelModel();
sess('super_admin');

// ---------- 1. Daftar bawaan (hasil migrasi) ----------
$seed = ['DPP', 'DPW', 'DPLN', 'DPD', 'DPC', 'DPRa', 'MWP', 'MWW', 'MWLN', 'MWD', 'MWC', 'MWRa', 'DSO', 'DSY', 'DPK'];
ok(!array_diff($seed, StructureLevels::codes()), '1: lima belas tingkat bawaan ada di daftar', implode(',', array_diff($seed, StructureLevels::codes())));
ok(array_slice(StructureLevels::codes(), 0, 15) === $seed, '1: urutan bawaan: Umum, Muslimah, lalu Dewan', implode(',', array_slice(StructureLevels::codes(), 0, 15)));
ok(StructureLevels::requiredParent('DPLN') === 'DPP' && StructureLevels::requiredParent('MWLN') === 'MWP' && StructureLevels::requiredParent('MWRa') === 'MWC' && StructureLevels::requiredParent('DPW') === null, '1: induk wajib bawaan: DPLN, MWLN, MWRa saja');
ok(array_values(array_intersect(['DPW', 'DPLN', 'MWP'], StructureLevels::childrenOf('DPP'))) === ['DPW', 'DPLN', 'MWP'] && StructureLevels::childrenOf('DPW') === ['DPD'], '1: anak lazim DPP = DPW, DPLN, MWP; DPW = DPD');
ok(StructureLevels::group('DSO') === 'dewan' && StructureLevels::structureWing('DSO') === 'umum' && StructureLevels::structureWing('MWLN') === 'muslimah' && StructureLevels::tier('DPLN') === 2, '1: kelompok/sayap/tier bawaan: DSO dewan -> umum, MWLN muslimah, DPLN tier 2');
ok(StructureLevels::adminLabel('DPLN') === 'Luar Negeri' && StructureLevels::adminLabel('DPW') === 'Wilayah' && StructureLevels::adminLabel('XYZ') === 'XYZ', '1: sebutan admin: khusus (Luar Negeri) atau nama tier; tak dikenal apa adanya');
ok(StructureLevels::group('MWXX') === 'musl' && StructureLevels::group('ZZ') === 'umum', '1: kode tak dikenal: awalan MW = Muslimah, selain itu Umum (perilaku lama)');
$bad = $one("SELECT COUNT(*) FROM structures s JOIN structure_levels l ON l.code = s.level WHERE s.tier <> l.tier OR s.wing <> IF(l.grp = 'musl', 'muslimah', 'umum')");
ok($bad === 0, '1: wing/tier semua struktur sama dengan tingkatnya', (string) $bad);

// ---------- 2. Validasi pembuatan ----------
$base = ['code' => $codeA, 'name' => 'Uji Tingkat ' . $pid, 'grp' => 'umum', 'tier' => 2, 'parent_code' => 'DPP', 'parent_strict' => 1, 'short_label' => 'Uji', 'sort_order' => '', 'is_active' => 1];
foreach ([['code' => '1ab', 'butir' => 'diawali angka'], ['code' => 'a b', 'butir' => 'berspasi'], ['code' => 'A', 'butir' => 'satu huruf'], ['code' => 'ABCDEFGHIJK', 'butir' => 'sebelas huruf'], ['code' => '', 'butir' => 'kosong']] as $c) {
    $r = rejects(function () use ($model, $base, $c) { $model->save(null, array_merge($base, ['code' => $c['code']])); }, 'Singkatan');
    ok($r === true, '2: singkatan ' . $c['butir'] . ' ditolak', is_string($r) ? $r : 'tidak ditolak');
}
$r = rejects(function () use ($model, $base) { $model->save(null, array_merge($base, ['code' => 'dpw'])); }, 'sudah dipakai');
ok($r === true, '2: singkatan kembar (tanpa membedakan huruf besar-kecil) ditolak', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($model, $base) { $model->save(null, array_merge($base, ['name' => 'dewan pimpinan wilayah'])); }, 'sudah dipakai');
ok($r === true, '2: nama kembar ditolak', is_string($r) ? $r : 'tidak ditolak');
foreach ([['name' => '', 'k' => 'Nama'], ['grp' => 'lain', 'k' => 'kelompok'], ['tier' => 0, 'k' => 'setingkat'], ['tier' => 6, 'k' => 'setingkat'], ['parent_code' => 'NOPE', 'k' => 'induk']] as $c) {
    $k = $c['k']; unset($c['k']);
    $r = rejects(function () use ($model, $base, $c) { $model->save(null, array_merge($base, $c)); }, $k);
    ok($r === true, '2: isian tak sah ditolak: ' . json_encode($c), is_string($r) ? $r : 'tidak ditolak');
}
ok(!StructureLevels::exists($codeA), '2: tak satu pun penolakan meninggalkan tingkat setengah jadi');

// ---------- 3. Membuat tingkat ----------
$made = $model->save(null, $base);
ok($made === $codeA && StructureLevels::exists($codeA), '3: tingkat baru dibuat dan langsung ada di daftar');
$def = StructureLevels::find($codeA);
ok($def['grp'] === 'umum' && (int) $def['tier'] === 2 && $def['parent_code'] === 'DPP' && (int) $def['parent_strict'] === 1 && $def['short_label'] === 'Uji' && (int) $def['is_active'] === 1 && (int) $def['is_system'] === 0, '3: isian tersimpan', json_encode($def));
ok((int) $def['sort_order'] === 10 * (1 + (int) (max(array_column(array_filter(StructureLevels::all(), function ($r) use ($codeA) { return $r['grp'] === 'umum' && $r['code'] !== $codeA; }), 'sort_order')) / 10)), '3: urutan otomatis = 10 di atas urutan terbesar kelompoknya', (string) $def['sort_order']);
ok(in_array($codeA, StructureLevels::codes(true), true), '3: tingkat baru ikut di daftar aktif');
ok(in_array($codeA, StructureLevels::childrenOf('DPP'), true), '3: ia terhitung anak lazim DPP');
ok(StructureLevels::adminLabel($codeA) === 'Uji', '3: sebutan admin dari isian');
ok(AdminNaming::displayName($codeA, 'Contoh') === 'Admin Uji: ' . $codeA . ' Contoh', '3: nama akun admin memakai sebutannya', AdminNaming::displayName($codeA, 'Contoh'));
ok(in_array($codeA, array_keys(array_filter(PeopleOverview::chipGroups([$codeA => 0, 'DPW' => 1]), function ($l) use ($codeA) { return in_array($codeA, $l, true); })) ? ['x'] : [], true) || PeopleOverview::chipGroups([$codeA => 0, 'DPW' => 1])['umum'] === ['DPW', $codeA], '3: chip jenjang: tingkat baru ikut di kelompoknya, sesudah DPW', json_encode(PeopleOverview::chipGroups([$codeA => 0, 'DPW' => 1])));
ok(PeopleOverview::levelName($codeA) === 'Uji Tingkat ' . $pid, '3: nama lengkap tampil di chip');

// tingkat kedua sebagai anak tingkat pertama; lingkaran induk ditolak
$model->save(null, ['code' => $codeB, 'name' => 'Uji Anak ' . $pid, 'grp' => 'umum', 'tier' => 3, 'parent_code' => $codeA, 'parent_strict' => 0, 'short_label' => '', 'sort_order' => 5, 'is_active' => 1]);
ok((int) StructureLevels::find($codeB)['sort_order'] === 5, '3: urutan yang diketik dipakai');
$r = rejects(function () use ($model, $codeA, $codeB) {
    $model->save($codeA, ['name' => 'Uji Tingkat ' . getmypid(), 'grp' => 'umum', 'tier' => 2, 'parent_code' => $codeB, 'parent_strict' => 0, 'is_active' => 1]);
}, 'turunannya');
ok($r === true, '3: induk tak boleh turunan sendiri (lingkaran)', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($model, $codeA) {
    $model->save($codeA, ['name' => 'Uji Tingkat ' . getmypid(), 'grp' => 'umum', 'tier' => 2, 'parent_code' => $codeA, 'parent_strict' => 0, 'is_active' => 1]);
}, 'turunannya');
ok($r === true, '3: induk tak boleh dirinya sendiri', is_string($r) ? $r : 'tidak ditolak');

// ---------- 4. Pilihan di formulir Struktur dan penyimpanan struktur ----------
sess('super_admin');
$lv = (new StructureModel())->levelsInScope();
ok(in_array($codeA, $lv, true) && in_array($codeB, $lv, true), '4: Super Admin: tingkat baru tersedia di pilihan Tingkat');
$h = html('TS', 'form');
ok(strpos($h, '<option value="' . $codeA . '"') !== false, '4: formulir memuat tingkat baru');
ok(strpos($h, 'data-info="Uji Tingkat ' . $pid . ' · Umum · setingkat Wilayah · wajib di bawah DPP"') !== false, '4: keterangan tingkat baru di formulir', substr($h, strpos($h, '<option value="' . $codeA . '"'), 200));
ok(strpos($h, '"' . $codeA . '":"DPP"') !== false, '4: skrip form: induk wajib tingkat baru ikut disaring');
ok(strpos($h, 'href="' . BASEURL . '/levels"') !== false, '4: super admin diberi tautan ke Tingkat Struktur');

sess('admin_struktur', [$dpp]);
ok(in_array($codeA, (new StructureModel())->levelsInScope(), true) && !in_array($codeB, (new StructureModel())->levelsInScope(), true), '4: admin DPP: anak lazim DPP tersedia, cucunya belum');
sess('admin_struktur', [$dpw]);
ok(!in_array($codeA, (new StructureModel())->levelsInScope(), true), '4: admin DPW: bukan anak lazim DPW, tak tersedia');

sess('super_admin');
$r1 = call('TS', 'save', ['id' => '', 'name' => $prefix . '-SALAH', 'level' => $codeA, 'parent_id' => $dpw, 'region_code' => '', 'is_active' => '1']);
ok(strpos(flashText(), 'harus berada di bawah DPP') !== false && $one("SELECT COUNT(*) FROM structures WHERE name = '" . $prefix . "-SALAH'") === 0, '4: induk selain DPP ditolak untuk tingkat dengan induk wajib', flashText());
$r2 = call('TS', 'save', ['id' => '', 'name' => $prefix . '-TANPA', 'level' => $codeA, 'parent_id' => '', 'region_code' => '', 'is_active' => '1']);
ok(strpos(flashText(), 'wajib memiliki induk') !== false && $one("SELECT COUNT(*) FROM structures WHERE name = '" . $prefix . "-TANPA'") === 0, '4: tanpa induk ditolak untuk tingkat dengan induk wajib', flashText());
$r3 = call('TS', 'save', ['id' => '', 'name' => $prefix . '-A', 'level' => $codeA, 'parent_id' => $dpp, 'region_code' => '', 'is_active' => '1']);
$sid = $one("SELECT id FROM structures WHERE name = '" . $prefix . "-A'");
ok($sid > 0 && strpos((string) $r3, 'terms/form/0/' . $sid) === 0, '4: struktur bertingkat baru tersimpan lalu diarahkan membuat periode', (string) $r3 . ' | ' . flashText());
$row = $pdo->query("SELECT level, wing, tier, parent_id FROM structures WHERE id = " . (int) $sid)->fetch(PDO::FETCH_ASSOC);
ok($row && $row['level'] === $codeA && $row['wing'] === 'umum' && (int) $row['tier'] === 2 && (int) $row['parent_id'] === $dpp, '4: wing dan tier struktur disalin dari tingkatnya', json_encode($row));
$r4 = call('TS', 'save', ['id' => '', 'name' => 'x', 'level' => 'TAKADA', 'parent_id' => $dpp, 'region_code' => '', 'is_active' => '1']);
ok(strpos(flashText(), 'tingkat struktur wajib diisi dengan benar') !== false, '4: tingkat yang tak ada ditolak', flashText());

// ---------- 5. Mengubah tingkat yang sudah dipakai ----------
$model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'umum', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'short_label' => 'Uji', 'sort_order' => '', 'is_active' => 1]);
ok((int) $one("SELECT tier FROM structures WHERE id = " . (int) $sid) === 3, '5: mengubah Setingkat ikut mengubah tier struktur yang memakainya');
ok(StructureLevels::name($codeA) === 'Uji Tingkat ' . $pid . ' b', '5: nama baru langsung terbaca');
$r = rejects(function () use ($model, $codeA, $pid) {
    $model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'musl', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'is_active' => 1]);
}, 'tidak bisa diubah');
ok($r === true && $pdo->query("SELECT wing FROM structures WHERE id = " . (int) $sid)->fetchColumn() === 'umum' && StructureLevels::find($codeA)['grp'] === 'umum', '5: kelompok Umum/Muslimah terkunci selama ada struktur yang memakainya', is_string($r) ? $r : 'tidak ditolak');
$model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'dewan', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'is_active' => 1]);
ok(StructureLevels::group($codeA) === 'dewan' && $pdo->query("SELECT wing FROM structures WHERE id = " . (int) $sid)->fetchColumn() === 'umum', '5: Umum <-> Dewan boleh (sama-sama sayap Umum)');
$model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'umum', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'is_active' => 1]);

// ---------- 6. Nonaktif ----------
$model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'umum', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'is_active' => 0]);
ok(!in_array($codeA, (new StructureModel())->levelsInScope(), true) && in_array($codeA, StructureLevels::codes(), true), '6: tingkat nonaktif tak ditawarkan, tetapi tetap ada di daftar');
$h = html('TS', 'form', $sid);
ok(strpos($h, 'value="' . $codeA . '" data-info') !== false && strpos($h, '(nonaktif)') !== false, '6: formulir struktur yang bertingkat nonaktif tetap menampilkan tingkatnya');
$r = call('TS', 'save', ['id' => (string) $sid, 'name' => $prefix . '-A2', 'level' => $codeA, 'parent_id' => $dpp, 'region_code' => '', 'is_active' => '1']);
ok($pdo->query("SELECT name FROM structures WHERE id = " . (int) $sid)->fetchColumn() === $prefix . '-A2', '6: struktur bertingkat nonaktif tetap bisa diubah (namanya)', flashText());
$r = call('TS', 'save', ['id' => '', 'name' => $prefix . '-B', 'level' => $codeA, 'parent_id' => $dpp, 'region_code' => '', 'is_active' => '1']);
ok($one("SELECT COUNT(*) FROM structures WHERE name = '" . $prefix . "-B'") === 0, '6: struktur BARU bertingkat nonaktif ditolak', flashText());
$model->save($codeA, ['name' => 'Uji Tingkat ' . $pid . ' b', 'grp' => 'umum', 'tier' => 3, 'parent_code' => 'DPP', 'parent_strict' => 1, 'is_active' => 1]);
ok(in_array($codeA, (new StructureModel())->levelsInScope(), true), '6: diaktifkan lagi, tersedia lagi');

// ---------- 7. Menghapus ----------
$r = rejects(function () use ($model, $codeA) { $model->delete($codeA); }, 'masih dipakai');
ok($r === true && StructureLevels::exists($codeA), '7: tingkat yang dipakai struktur tak bisa dihapus', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($model, $codeA) { $model->delete($codeA); }, '1 struktur');
ok($r === true, '7: pesan menyebut jumlah struktur', is_string($r) ? $r : '');
$r = rejects(function () use ($model) { $model->delete('DPP'); }, 'bawaan sistem');
ok($r === true && StructureLevels::exists('DPP'), '7: DPP (bawaan sistem) tak bisa dihapus', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($model) { $model->delete('DPW'); }, 'masih dipakai');
ok($r === true && StructureLevels::exists('DPW'), '7: DPW (dipakai ratusan struktur) tak bisa dihapus', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($model) { $model->delete('TAKADA'); }, 'tidak ditemukan');
ok($r === true, '7: tingkat yang tak ada ditolak', is_string($r) ? $r : '');

// struktur dihapus (lunak): tingkat masih tertahan oleh foreign key, dan pesannya jujur
(new StructureModel())->softDelete($sid);
$r = rejects(function () use ($model, $codeA) { $model->delete($codeA); }, 'sudah dihapus');
ok($r === true && StructureLevels::exists($codeA), '7: struktur yang sudah dihapus masih menahan tingkatnya, dengan pesan yang menjelaskan', is_string($r) ? $r : 'tidak ditolak');

// induk bagi tingkat lain
$pdo->exec("DELETE FROM audit_log WHERE entity = 'structures' AND entity_id = " . (int) $sid);
$pdo->exec("DELETE FROM structures WHERE id = " . (int) $sid);
$r = rejects(function () use ($model, $codeA) { $model->delete($codeA); }, 'menjadi induk');
ok($r === true && StructureLevels::exists($codeA), '7: tingkat yang masih menjadi induk tingkat lain tak bisa dihapus', is_string($r) ? $r : 'tidak ditolak');

$model->delete($codeB);
ok(!StructureLevels::exists($codeB), '7: tingkat tak terpakai dihapus');
$model->delete($codeA);
ok(!StructureLevels::exists($codeA) && $one("SELECT COUNT(*) FROM structure_levels WHERE code = " . $pdo->quote($codeA)) === 0, '7: ... dan induknya menyusul begitu bebas');

// ---------- 8. Tingkat bawaan sistem terkunci ----------
$dppRow = StructureLevels::find('DPP');
$model->save('DPP', ['name' => $dppRow['name'], 'grp' => 'musl', 'tier' => 5, 'parent_code' => 'DPW', 'parent_strict' => 1, 'short_label' => (string) $dppRow['short_label'], 'sort_order' => '', 'is_active' => 0]);
$after = StructureLevels::find('DPP');
ok($after['grp'] === 'umum' && (int) $after['tier'] === 1 && (string) $after['parent_code'] === '' && (int) $after['is_active'] === 1 && (int) $after['sort_order'] === (int) $dppRow['sort_order'], '8: DPP: kelompok, tier, induk, aktif tak bisa diubah walau dikirim', json_encode($after));
// nilai terkirim kosong (formulir mengunci selectnya): tetap lolos
$model->save('MWP', ['name' => StructureLevels::name('MWP'), 'short_label' => '', 'sort_order' => '']);
ok(StructureLevels::group('MWP') === 'musl' && StructureLevels::tier('MWP') === 1, '8: MWP: formulir tanpa kelompok/tier (select terkunci) tetap diterima dan tak mengubahnya');

// ---------- 9. Halaman Tingkat Struktur ----------
sess('super_admin');
$h = html('TL', 'index');
ok(strpos($h, 'Tingkat Struktur') !== false && strpos($h, '>DPLN<') !== false && strpos($h, '>MWRa<') !== false && strpos($h, 'Tambah tingkat') !== false, '9: halaman memuat daftar tingkat dan tombol Tambah');
ok(substr_count($h, 'bawaan sistem') >= 2, '9: DPP dan MWP ditandai bawaan sistem');
ok(strpos($h, 'action="' . BASEURL . '/levels/delete"') !== false && preg_match('/<button type="submit" class="btn small secondary" disabled title="Masih dipakai: \d+ struktur/', $h), '9: tombol Hapus nonaktif untuk tingkat yang dipakai');
$r = call('TL', 'save', ['code' => $codeA, 'name' => 'Lewat Halaman ' . $pid, 'grp' => 'umum', 'tier' => '2', 'parent_code' => 'DPP', 'parent_strict' => '1', 'short_label' => '', 'sort_order' => '', 'is_active' => '1']);
ok($r === 'levels' && StructureLevels::exists($codeA) && strpos(flashText(), 'dibuat') !== false, '9: Tambah lewat controller membuat tingkat', flashText());
$r = call('TL', 'save', ['edit' => '1', 'code' => $codeA, 'name' => 'Lewat Halaman ' . $pid . ' x', 'grp' => 'umum', 'tier' => '4', 'parent_code' => '', 'short_label' => '', 'sort_order' => '77', 'is_active' => '']);
$d2 = StructureLevels::find($codeA);
ok($d2['name'] === 'Lewat Halaman ' . $pid . ' x' && (int) $d2['tier'] === 4 && (int) $d2['sort_order'] === 77 && (int) $d2['is_active'] === 0 && (int) $d2['parent_strict'] === 0, '9: Ubah lewat controller menyimpan (tanpa induk, parent_strict ikut 0, nonaktif)', json_encode($d2));
$r = call('TL', 'save', ['code' => $codeA, 'name' => 'Duplikat', 'grp' => 'umum', 'tier' => '2']);
ok(strpos(flashText(), 'sudah dipakai') !== false, '9: Tambah dengan singkatan yang sudah ada ditolak dengan pesan', flashText());
$r = call('TL', 'delete', ['code' => $codeA]);
ok($r === 'levels' && !StructureLevels::exists($codeA) && strpos(flashText(), 'dihapus') !== false, '9: Hapus lewat controller menghapus tingkat tak terpakai', flashText());

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
