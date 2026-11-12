<?php
// PATH LOKASI FILE: tests/api_photo_test.php
// Uji: foto pengurus bagi konsumen API (GET /people/{uuid}/photo + photo_url / photo_updated_at di data orang).
//   1. Orang yang fotonya ada di disk -> photo_url (alamat endpoint, bukan jalur disk) dan photo_updated_at terisi; photo_path TIDAK ikut keluar.
//   2. Tanpa foto, atau photo_path terisi tetapi berkasnya hilang, atau jalurnya keluar dari uploads/people/ -> keduanya null, dan endpoint berkasnya 404.
//   3. Orang terhapus tidak diberi foto.
//   4. Token berlingkup subtree: foto orang di luar lingkup tidak bisa diambil, yang di dalam lingkup bisa.
//   5. Mengganti foto (jalur sama, nilai photo_path tak berubah) tetap menyentuh updated_at, sehingga orangnya muncul di sinkronisasi inkremental.
//   6. Foto perempuan tidak diberikan ke konsumen API; mengoreksi jenis kelamin membuka/menutupnya lagi.
// Menjalankan model SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback. Tidak menulis atau menghapus berkas foto.
//
//   php tests/api_photo_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Audit', 'Text', 'Uuid', 'Auth', 'AdminScope', 'UnitKinds'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Models/PositionModel.php';
require_once ROOT_DIR . '/app/Models/PersonModel.php';
require_once ROOT_DIR . '/app/Models/SyncModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
$_SERVER['REMOTE_ADDR'] = '10.255.255.14';

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}

$pdo = Database::getInstance()->getConnection();
$withPhoto = $pdo->query("SELECT p.id, p.uuid, p.photo_path FROM people p
                          WHERE p.deleted_at IS NULL AND p.gender = 'L' AND p.photo_path LIKE 'uploads/people/%'
                            AND EXISTS (SELECT 1 FROM placements pl WHERE pl.person_id = p.id AND pl.deleted_at IS NULL)
                          ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$bare = $pdo->query("SELECT id, uuid FROM people WHERE deleted_at IS NULL AND (photo_path IS NULL OR photo_path = '') ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (!$withPhoto || !is_file(ROOT_DIR . '/public/' . $withPhoto['photo_path']) || count($bare) < 2) {
    echo "LEWAT: butuh satu orang berfoto (berkasnya ada, punya penempatan) dan dua orang tanpa foto di database lokal.\n";
    exit(0);
}

$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

// Orang "tanpa foto" dipakai bagian 2 dan 3: dipaksa laki-laki supaya null/null di sana pasti karena foto/berkas/terhapus, bukan karena aturan jenis kelamin.
$pdo->exec("UPDATE people SET gender = 'L' WHERE id IN (" . (int) $bare[0]['id'] . ',' . (int) $bare[1]['id'] . ')');

$sync = new SyncModel();

// 1. Foto ada.
$p = $sync->personByUuid($withPhoto['uuid']);
ok($p['photo_url'] === BASEURL . '/api/v1/people/' . $withPhoto['uuid'] . '/photo', '1: photo_url = alamat endpoint', (string) $p['photo_url']);
ok(!empty($p['photo_updated_at']) && strtotime($p['photo_updated_at']) === filemtime(ROOT_DIR . '/public/' . $withPhoto['photo_path']), '1: photo_updated_at = waktu ubah berkas');
ok(!array_key_exists('photo_path', $p) && strpos(json_encode($p), 'uploads/people') === false, '1: jalur disk tidak keluar di data orang');
$f = $sync->photoFileByUuid($withPhoto['uuid']);
ok($f && $f['file'] === ROOT_DIR . '/public/' . $withPhoto['photo_path'], '1: photoFileByUuid menunjuk berkas yang benar');
$listed = null;
foreach ($sync->people(null, null, 50000)['rows'] as $row) if ($row['uuid'] === $withPhoto['uuid']) $listed = $row;
ok($listed && $listed['photo_url'] === $p['photo_url'], '1: daftar /people memuat photo_url yang sama');

// 2. Tanpa foto / berkas hilang / jalur menyimpang.
$q = $sync->personByUuid($bare[0]['uuid']);
ok($q['photo_url'] === null && $q['photo_updated_at'] === null, '2: tanpa foto -> null/null');
ok($sync->photoFileByUuid($bare[0]['uuid']) === null, '2: tanpa foto -> photoFileByUuid null');
foreach ([
    'berkas hilang'       => 'uploads/people/zz-uji-tidak-ada.jpg',
    'keluar dari folder'  => 'uploads/people/../../config/config.php',
    'folder lain'         => 'config/config.php',
] as $label => $path) {
    $pdo->prepare("UPDATE people SET photo_path = :p WHERE id = :id")->execute([':p' => $path, ':id' => $bare[0]['id']]);
    $r = $sync->personByUuid($bare[0]['uuid']);
    ok($r['photo_url'] === null && $r['photo_updated_at'] === null, "2: $label -> null/null", (string) $r['photo_url']);
    ok($sync->photoFileByUuid($bare[0]['uuid']) === null, "2: $label -> photoFileByUuid null");
}
ok($sync->photoFileByUuid('bukan-uuid') === null, '2: uuid tidak sah -> null');

// 3. Orang terhapus (tetap tampil di include_deleted, tanpa foto).
$pdo->prepare("UPDATE people SET photo_path = :p WHERE id = :id")->execute([':p' => $withPhoto['photo_path'], ':id' => $bare[1]['id']]);
$pdo->prepare("UPDATE people SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => $bare[1]['id']]);
$deleted = null;
foreach ($sync->people(null, null, 50000, true)['rows'] as $row) if ($row['uuid'] === $bare[1]['uuid']) $deleted = $row;
ok($deleted && $deleted['deleted'] === true && $deleted['photo_url'] === null, '3: orang terhapus tidak diberi photo_url');
ok($sync->photoFileByUuid($bare[1]['uuid']) === null, '3: orang terhapus -> photoFileByUuid null');

// 4. Lingkup subtree.
$inScope = (int) $pdo->query("SELECT structure_id FROM placements WHERE person_id = {$withPhoto['id']} AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$outScope = (int) $pdo->query("SELECT s.id FROM structures s WHERE s.deleted_at IS NULL AND s.id NOT IN (
                                 SELECT structure_id FROM placements WHERE person_id = {$withPhoto['id']}) ORDER BY s.id DESC LIMIT 1")->fetchColumn();
if ($inScope && $outScope) {
    ok((new SyncModel($inScope))->photoFileByUuid($withPhoto['uuid']) !== null, '4: di dalam lingkup -> foto bisa diambil');
    ok((new SyncModel($outScope))->photoFileByUuid($withPhoto['uuid']) === null, '4: di luar lingkup -> null (404 di endpoint)');
} else {
    echo "LEWAT bagian 4: tidak ada struktur dalam/luar lingkup yang cocok.\n";
}

// 6. Foto perempuan tidak diberikan; berkas dan photo_path tetap utuh. (gender ENUM('L','P') NOT NULL: tidak ada nilai ketiga untuk diuji.)
//    Dikembalikan ke 'L' sesudahnya karena bagian 5 memakai orang yang sama.
$expectedUrl = $p['photo_url'];
foreach ([['P', 'perempuan']] as [$g, $label]) {
    $pdo->prepare("UPDATE people SET gender = :g WHERE id = :id")->execute([':g' => $g, ':id' => $withPhoto['id']]);
    $r = $sync->personByUuid($withPhoto['uuid']);
    ok($r['photo_url'] === null && $r['photo_updated_at'] === null, "6: $label -> photo_url/photo_updated_at null");
    ok($sync->photoFileByUuid($withPhoto['uuid']) === null, "6: $label -> photoFileByUuid null (404 di endpoint)");
    $inList = null;
    foreach ($sync->people(null, null, 50000)['rows'] as $row) if ($row['uuid'] === $withPhoto['uuid']) $inList = $row;
    ok($inList && $inList['photo_url'] === null, "6: $label -> daftar /people juga tanpa photo_url");
    ok((string) $pdo->query("SELECT photo_path FROM people WHERE id = {$withPhoto['id']}")->fetchColumn() === $withPhoto['photo_path'], "6: $label -> photo_path di database tidak diubah");
}
$pdo->prepare("UPDATE people SET gender = 'L' WHERE id = :id")->execute([':id' => $withPhoto['id']]);
$r = $sync->personByUuid($withPhoto['uuid']);
ok($r['photo_url'] === $expectedUrl, '6: dikoreksi kembali ke laki-laki -> photo_url muncul lagi');

// 5. Mengganti foto di jalur yang sama menyentuh updated_at. Dipaksa mundur dulu supaya selisih waktunya pasti terlihat.
$pdo->prepare("UPDATE people SET updated_at = '2020-01-01 00:00:00.000' WHERE id = :id")->execute([':id' => $withPhoto['id']]);
$before = $pdo->query("SELECT updated_at FROM people WHERE id = {$withPhoto['id']}")->fetchColumn();
(new PersonModel())->setPhoto($withPhoto['id'], $withPhoto['photo_path']);   // nilai photo_path persis sama
$after = $pdo->query("SELECT updated_at FROM people WHERE id = {$withPhoto['id']}")->fetchColumn();
ok($after > $before, '5: setPhoto dengan jalur yang sama menyentuh updated_at', "$before -> $after");
$since = date('Y-m-d\TH:i:sP', time() - 60);
$changed = array_column($sync->people($since, null, 50000)['rows'], 'uuid');
ok(in_array($withPhoto['uuid'], $changed, true), '5: orangnya muncul di sinkronisasi inkremental sesudah foto diganti');

echo "\n" . ($fail === 0 ? "OK" : "ADA YANG GAGAL") . ": $pass lolos, $fail gagal\n";
exit($fail === 0 ? 0 : 1);
