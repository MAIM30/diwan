<?php
// PATH LOKASI FILE: tests/api_sync_jabatan_test.php
// Uji: mengubah sebuah jabatan di Master Data > Jabatan harus sampai ke konsumen API yang menyinkron lewat /sync/changes.
//   position_code, position_name, order, dan is_core di /placements dibaca dari tabel jabatan, tetapi sinkronisasi inkremental hanya melihat
//   placements.updated_at. Tanpa penyentuhan itu, kode yang diganti admin (mis. wakil_ketua_departemen_lembaga -> wakil_ketua_departemen) tak
//   pernah dikirim ulang, dan salinan lokal konsumen menyimpan kode lama selamanya.
//   1. Kode, nama, urutan, atau tanda inti berubah -> penempatan hidup jabatan itu ikut terkirim dengan nilai baru.
//   2. Perubahan yang tak terlihat di API (aktif/nonaktif) atau simpan tanpa perubahan -> tidak menyentuh apa pun.
//   3. Penempatan jabatan lain dan penempatan yang sudah terhapus tidak tersentuh.
// Menjalankan model SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback.
//
//   php tests/api_sync_jabatan_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Audit', 'Text', 'Uuid', 'Auth', 'AdminScope', 'UnitKinds'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Models/PositionModel.php';
require_once ROOT_DIR . '/app/Models/SyncModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
$_SERVER['REMOTE_ADDR'] = '10.255.255.12';

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}

$pdo = Database::getInstance()->getConnection();
$live = $pdo->query("SELECT pl.id, p.uuid AS person_uuid FROM placements pl JOIN people p ON p.id = pl.person_id
                     WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL ORDER BY pl.id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
$dead = $pdo->query("SELECT id FROM placements WHERE deleted_at IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
if (count($live) < 2) { echo "LEWAT: butuh dua penempatan hidup tingkat struktur di database lokal.\n"; exit(0); }

$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

$pm = new PositionModel();
$sync = new SyncModel();
$suffix = substr(md5((string) microtime(true)), 0, 6);
$base = function ($code, $name, $order = 500, $active = 1) {
    return ['name' => $name, 'code' => $code, 'applies_to' => 'both', 'unit_kinds' => [], 'sort_order' => $order, 'is_active' => $active];
};
$p1 = $pm->save(null, $base("zz_uji_sync_a_$suffix", 'Jabatan Uji Sync A'));
$p2 = $pm->save(null, $base("zz_uji_sync_b_$suffix", 'Jabatan Uji Sync B'));

[$a, $b] = $live;
$OLD = '2026-01-01 00:00:00.000';
$retag = function () use ($pdo, $a, $b, $dead, $p1, $p2, $OLD) {
    $pdo->prepare("UPDATE placements SET position_id = ? WHERE id IN (?, ?)")->execute([$p1, $a['id'], $dead ?: 0]);
    $pdo->prepare("UPDATE placements SET position_id = ? WHERE id = ?")->execute([$p2, $b['id']]);
    $pdo->prepare("UPDATE placements SET updated_at = ? WHERE id IN (?, ?, ?)")->execute([$OLD, $a['id'], $b['id'], $dead ?: 0]);
};
$stamp = function ($id) use ($pdo) { $s = $pdo->prepare("SELECT updated_at FROM placements WHERE id = ?"); $s->execute([$id]); return (string) $s->fetchColumn(); };
$touched = function ($id) use ($stamp) { return strtotime($stamp($id)) > strtotime('2026-11-01'); };
$apiRow = function ($placement) use ($sync, $pdo) {
    $uuid = $pdo->prepare("SELECT uuid FROM placements WHERE id = ?"); $uuid->execute([$placement['id']]); $uuid = $uuid->fetchColumn();
    foreach ($sync->placements(['person' => $placement['person_uuid']], null, null, 1000, false)['rows'] as $r) if ($r['uuid'] === $uuid) return $r;
    return null;
};
$save = function ($id, array $over) use ($pm, $base) {
    $cur = (new PositionModel())->findById($id);
    $data = $base($cur['code'], $cur['name'], (int) $cur['sort_order'], (int) $cur['is_active']);
    return $pm->save($id, $over + $data);
};
$retag();
ok(!$touched($a['id']) && !$touched($b['id']), '0: prasyarat: penempatan uji bertanda waktu lama');

// ---------- 2. Perubahan yang tak terlihat di API ----------
$save($p1, []);
ok(!$touched($a['id']), '2: simpan tanpa perubahan tidak menyentuh penempatan');
$save($p1, ['is_active' => 0]);
ok(!$touched($a['id']), '2: aktif/nonaktif tak terlihat di API -> tidak menyentuh penempatan');
$save($p1, ['is_active' => 1]);

// ---------- 1. Kode berubah ----------
$kodeBaru = "zz_uji_sync_a2_$suffix";
$save($p1, ['code' => $kodeBaru]);
ok($touched($a['id']), '1: ganti kode -> penempatan jabatan itu disentuh', $stamp($a['id']));
$row = $apiRow($a);
ok($row && $row['position_code'] === $kodeBaru, '1: API mengirim kode baru untuk penempatan itu', $row['position_code'] ?? '-');
ok(strtotime($row['updated_at'] ?? '2000-01-01') > strtotime('2026-11-01'), '1: updated_at di API sudah maju (akan ikut /sync/changes?since=...)');

// ---------- 3. Yang tak boleh tersentuh ----------
ok(!$touched($b['id']), '3: penempatan jabatan LAIN tidak tersentuh', $stamp($b['id']));
if ($dead) ok(!$touched($dead), '3: penempatan yang sudah terhapus tidak tersentuh', $stamp($dead));

// ---------- 1. Nama, urutan, tanda inti ----------
$retag(); $save($p1, ['name' => 'Jabatan Uji Sync A Baru']);
ok($touched($a['id']) && ($apiRow($a)['position_name'] ?? '') === 'Jabatan Uji Sync A Baru', '1: ganti nama -> disentuh dan API membawa nama baru');
$retag(); $save($p1, ['sort_order' => 777]);
ok($touched($a['id']) && ($apiRow($a)['order'] ?? 0) === 777, '1: ganti urutan -> disentuh dan API membawa urutan baru');
$retag(); $save($p1, ['is_core' => 1]);
ok($touched($a['id']) && ($apiRow($a)['is_core'] ?? false) === true, '1: tanda inti dinyalakan -> disentuh dan API membawa is_core baru');
$retag(); $save($p1, ['is_core' => 1]);
ok(!$touched($a['id']), '2: tanda inti dikirim tetapi nilainya sama -> tidak menyentuh');

$pdo->rollBack();
ok((int) $pdo->query("SELECT COUNT(*) FROM positions WHERE code LIKE 'zz_uji_sync_%'")->fetchColumn() === 0, 'data uji tidak tersisa setelah rollback');

echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
