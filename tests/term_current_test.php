<?php
// PATH LOKASI FILE: tests/term_current_test.php
// Uji "periode yang sudah dibuat tetapi belum ditandai berjalan": halaman Struktur dan Data Pengurus menulis keadaan yang sebenarnya (bukan "belum
// ada periode" + Buat periode, yang lalu gagal "sudah menggunakan periode tersebut"), tombol Tandai berjalan (TermsController::mark_current),
// Buat periode untuk struktur yang sudah memakai acuan itu (diarahkan ke periode yang ada), centang bawaan, dan peringatan sesudah menyimpan.
//
// Pembuatan/ubah periode memulai transaksinya sendiri (TermModel), jadi uji ini MENYIMPAN baris uji sungguhan (struktur "UJI-PERIODE-*" beserta
// periodenya) dan menghapusnya lagi di akhir (juga lewat shutdown, bila uji terhenti).
//
//   php tests/term_current_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
ob_start();

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/TermsController.php';
require_once ROOT_DIR . '/app/Controllers/StructuresController.php';
require_once ROOT_DIR . '/app/Controllers/PeopleController.php';
foreach (['Susunan', 'AdminNaming', 'Photo', 'Lokasi'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['TermModel', 'StructureModel', 'AccountModel', 'PersonModel', 'PlacementModel', 'PositionModel', 'UnitModel', 'DashboardModel', 'MemberModel', 'BiodataModel'] as $m) {
    if (is_file(ROOT_DIR . "/app/Models/$m.php")) require_once ROOT_DIR . "/app/Models/$m.php";
}

class Redirected extends Exception {}   // bukan RuntimeException: controller menangkap RuntimeException di sekitar redirect
class Refused extends Exception {}
class TTc extends TermsController {
    protected function requireLogin($roles = null) {}
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function denied($message = '') { throw new Refused('ditolak'); }
    protected function notFound($message = '') { throw new Refused('tidak ada'); }
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
    public function call($m, ...$a) { ob_start(); try { $this->$m(...$a); } finally { $this->out = ob_get_clean(); } return $this->out; }
    public $out = '';
}
class TPe extends PeopleController {
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
    public function html($id) { ob_start(); $this->detail($id); return ob_get_clean(); }
}
class TSt extends StructuresController {
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
    public function html() { ob_start(); $this->index(); return ob_get_clean(); }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function has($h, $n) { return strpos($h, $n) !== false; }
function national($role = 'super_admin') {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => [$role]], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function sql($pdo, $q) { return $pdo->query($q)->fetchColumn(); }

$pdo = Database::getInstance()->getConnection();
national();
$tm = new TermModel();

// ---------- Bahan uji ----------
$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM structures WHERE name LIKE 'UJI-PERIODE-%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    $pids = $pdo->query("SELECT id FROM placements WHERE structure_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    if ($pids) {
        $pin = implode(',', array_map('intval', $pids));
        $pdo->exec("DELETE FROM audit_log WHERE entity = 'placements' AND entity_id IN ($pin)");
        $pdo->exec("DELETE FROM placements WHERE id IN ($pin)");
    }
    $tids = $pdo->query("SELECT id FROM terms WHERE structure_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    if ($tids) {
        $tin = implode(',', array_map('intval', $tids));
        $pdo->exec("DELETE FROM audit_log WHERE entity = 'terms' AND entity_id IN ($tin)");
        $pdo->exec("DELETE FROM terms WHERE id IN ($tin)");
    }
    $pdo->exec("DELETE FROM audit_log WHERE entity = 'structures' AND entity_id IN ($in)");
    $pdo->exec("DELETE FROM structures WHERE id IN ($in)");
};
$cleanup();
register_shutdown_function($cleanup);

$dpp = $pdo->query("SELECT id, path, depth FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1")->fetch();
$gps = $pdo->query("SELECT id, name FROM governance_periods ORDER BY start_date")->fetchAll();
if (!$dpp || count($gps) < 2) { echo "LEWAT: butuh DPP dan dua acuan periode di database lokal.\n"; exit(0); }
[$gpA, $gpB] = [(int) $gps[0]['id'], (int) $gps[1]['id']];
$mkStructure = function ($suffix) use ($pdo, $dpp) {
    $pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id, is_active) VALUES (?, 'DPLN', ?, ?, 1)")->execute([Uuid::v4(), 'UJI-PERIODE-' . $suffix, $dpp['id']]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE structures SET path = ?, depth = ? WHERE id = ?")->execute([$dpp['path'] . $id . '/', $dpp['depth'] + 1, $id]);
    return $id;
};
$mkTerm = function ($sid, $gp, $status, $current) use ($pdo) {
    $g = $pdo->query("SELECT name, start_date, end_date_planned FROM governance_periods WHERE id = " . (int) $gp)->fetch();
    $pdo->prepare("INSERT INTO terms (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, status, is_current) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([Uuid::v4(), $gp, $sid, $g['name'], $g['start_date'], $g['end_date_planned'], $status, $current]);
    return (int) $pdo->lastInsertId();
};
$sA = $mkStructure('A-kosong');                                  // tanpa periode
$sB = $mkStructure('B-draf'); $tB = $mkTerm($sB, $gpB, 'draft', 0);        // periode ada, belum berjalan (kasus DPLN yang dilaporkan)
$sC = $mkStructure('C-selesai'); $tC = $mkTerm($sC, $gpA, 'ended', 0);     // hanya yang sudah selesai
$sD = $mkStructure('D-berjalan'); $tDcur = $mkTerm($sD, $gpA, 'active', 1); $tDnext = $mkTerm($sD, $gpB, 'draft', 0);   // berjalan + periode berikutnya
$sE = $mkStructure('E-draf2'); $tE = $mkTerm($sE, $gpB, 'active', 0);      // aktif tetapi belum ditandai berjalan

// ---------- 1. Model ----------
$st = new StructureModel();
$pend = $st->pendingTermsInScope();
ok(isset($pend[$sB]) && $pend[$sB]['id'] === $tB, 'pendingTermsInScope: struktur dengan periode draf');
ok(isset($pend[$sE]) && $pend[$sE]['id'] === $tE, 'pendingTermsInScope: periode aktif yang belum ditandai juga');
ok(!isset($pend[$sA]) && !isset($pend[$sC]) && !isset($pend[$sD]), 'pendingTermsInScope: bukan yang tanpa periode, hanya selesai, atau sudah berjalan');
ok($tm->hasCurrent($sD) && !$tm->hasCurrent($sB) && !$tm->hasCurrent($sA), 'hasCurrent');

// ---------- 2. Halaman Struktur ----------
function structPage(array $get) { $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET'; return (new TSt)->html(); }
$h = structPage(['q' => 'UJI-PERIODE', 'per_page' => '100']);
function rowOf($h, $name) { return preg_match('#<tr>\s*<td>\s*<div class="st-name"[^>]*>' . preg_quote($name, '#') . '.*?</tr>#s', $h, $m) ? $m[0] : ''; }
$rA = rowOf($h, 'UJI-PERIODE-A-kosong'); $rB = rowOf($h, 'UJI-PERIODE-B-draf'); $rC = rowOf($h, 'UJI-PERIODE-C-selesai'); $rD = rowOf($h, 'UJI-PERIODE-D-berjalan');
ok($rA !== '' && $rB !== '' && $rC !== '' && $rD !== '', 'kelima struktur uji tampil di Struktur');
ok(has($rA, 'Belum punya periode berjalan') && has($rA, '/terms/form/0/' . $sA) && !has($rA, 'Tandai berjalan'), 'tanpa periode: "Buat periode"');
ok(has($rB, 'belum ditandai berjalan') && has($rB, '/terms/detail/' . $tB) && has($rB, 'Tandai berjalan') && !has($rB, 'Buat periode') && !has($rB, 'Belum punya periode'), 'periode draf: tertulis apa adanya + Tandai berjalan, tanpa Buat periode');
ok(has($rB, 'action="' . BASEURL . '/terms/mark_current"') && has($rB, 'name="id" value="' . $tB . '"') && has($rB, 'name="back" value="structures?'), 'tombol mengirim id periode dan halaman asal');
ok(has($rC, 'Buat periode') && !has($rC, 'Tandai berjalan'), 'hanya periode yang selesai: Buat periode, bukan Tandai berjalan');
ok(!has($rD, 'Tandai berjalan') && !has($rD, 'Buat periode') && has($rD, '/terms?structure=' . $sD), 'sudah berjalan: nama periodenya, tanpa tindakan');
$hp = structPage(['q' => 'UJI-PERIODE', 'f' => 'belum_berjalan']);
ok(has($hp, 'UJI-PERIODE-B-draf') && has($hp, 'UJI-PERIODE-E-draf2') && !has($hp, 'UJI-PERIODE-A-kosong') && !has($hp, 'UJI-PERIODE-C-selesai') && !has($hp, 'UJI-PERIODE-D-berjalan'), 'saringan "Periode belum ditandai berjalan"');
$hp = structPage(['q' => 'UJI-PERIODE', 'f' => 'tanpa_periode']);
ok(has($hp, 'UJI-PERIODE-A-kosong') && has($hp, 'UJI-PERIODE-C-selesai') && !has($hp, 'UJI-PERIODE-B-draf'), 'saringan "Belum punya periode": hanya yang benar-benar tanpa periode berjalan atau menunggu');
$hpo = structPage(['q' => 'UJI-PERIODE-B', 'tampil' => 'pohon']);
ok(has($hpo, 'belum ditandai berjalan') && has($hpo, 'Tandai berjalan'), 'tampilan Pohon: keterangan dan tombol yang sama');

// ---------- 3. Tombol Tandai berjalan ----------
function post(array $p) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $p; $_GET = []; Flash::take(); }
function run($m, $args = []) { try { (new TTc)->call($m, ...$args); return '(tanpa redirect)'; } catch (Redirected $r) { return $r->getMessage(); } catch (Refused $r) { return $r->getMessage(); } }
post(['id' => (string) $tB, 'back' => 'structures?lv=DPLN&page=2']);
$to = run('mark_current');
$row = $pdo->query("SELECT is_current, status FROM terms WHERE id = $tB")->fetch();
ok($to === 'structures?lv=DPLN&page=2' && (int) $row['is_current'] === 1 && $row['status'] === 'active', 'Tandai berjalan: periode berjalan dan Draf menjadi Aktif; kembali ke halaman asal', $to . ' ' . json_encode($row));
$msgs = Flash::take();
ok($msgs && $msgs[0]['type'] === 'success' && has($msgs[0]['message'], 'ditandai berjalan'), 'pesan sukses menyebut struktur dan periodenya', json_encode($msgs, JSON_UNESCAPED_UNICODE));
ok(!isset($st->pendingTermsInScope()[$sB]) && $tm->hasCurrent($sB), 'sesudahnya struktur itu tak lagi "belum ditandai"');
$hB = rowOf(structPage(['q' => 'UJI-PERIODE-B', 'per_page' => '100']), 'UJI-PERIODE-B-draf');
ok(has($hB, '/terms?structure=' . $sB) && !has($hB, 'Tandai berjalan'), 'halaman Struktur sekarang menampilkan periodenya sebagai berjalan');

// Menandai periode berikutnya memindahkan tanda: tepat satu yang berjalan.
post(['id' => (string) $tDnext, 'back' => 'people?lv=DPD']);
$to = run('mark_current');
$cur = $pdo->query("SELECT id FROM terms WHERE structure_id = $sD AND is_current = 1")->fetchAll(PDO::FETCH_COLUMN);
ok($to === 'people?lv=DPD' && count($cur) === 1 && (int) $cur[0] === $tDnext, 'tanda berjalan berpindah: tepat satu periode berjalan di struktur itu', implode(',', $cur));
ok((int) sql($pdo, "SELECT COUNT(*) FROM terms WHERE id = $tDcur AND deleted_at IS NULL") === 1 && (int) sql($pdo, "SELECT is_current FROM terms WHERE id = $tDcur") === 0, 'periode sebelumnya utuh (hanya tandanya dilepas)');
post(['id' => (string) $tDnext, 'back' => '']);
ok(run('mark_current') === 'terms', 'sudah berjalan, back kosong: ke daftar periode tanpa galat');
// back harus halaman daftar yang dikenal.
foreach (['people/detail/12/../../x', 'people/detail/abc', 'people/detail/', 'http://evil.example/x', '//evil.example', 'structures?x=<script>', "structures\r\nLocation: x", 'admin/accounts', '../structures'] as $bad) {
    post(['id' => (string) $tE, 'back' => $bad]);
    $to = run('mark_current');
    ok($to === 'terms', 'back tak sah ditolak: ' . substr(json_encode($bad), 0, 40), $to);
    $pdo->exec("UPDATE terms SET is_current = 0 WHERE id = $tE");
}
// Profil satu orang adalah tujuan yang sah (tombol di kartu "Jabatan Saat Ini").
post(['id' => (string) $tE, 'back' => 'people/detail/123']);
ok(run('mark_current') === 'people/detail/123', 'back = profil orang (people/detail/ID) diterima');
$pdo->exec("UPDATE terms SET is_current = 0 WHERE id = $tE");
// Periode yang sudah selesai tidak ditandai lewat tombol ini.
post(['id' => (string) $tC, 'back' => 'structures']);
$to = run('mark_current');
$m = Flash::take();
ok($to === 'structures' && (int) sql($pdo, "SELECT is_current FROM terms WHERE id = $tC") === 0 && $m && $m[0]['type'] === 'error', 'periode selesai: ditolak dengan pesan, tidak berubah');
post(['id' => '99999999', 'back' => 'terms']);
ok(run('mark_current') === 'tidak ada', 'periode tak ada: 404');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
ok(run('mark_current') === 'terms', 'GET bukan aksi tandai');
post(['id' => (string) $tE, 'back' => 'terms']);
national('viewer');
ok(run('mark_current') === 'ditolak' && (int) sql($pdo, "SELECT is_current FROM terms WHERE id = $tE") === 0, 'peninjau (tak boleh mengubah) ditolak');
national();
// Lingkup: admin yang hanya memegang struktur lain tidak bisa menandai periode struktur ini.
$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => false, 'root_ids' => [$sA], 'structure_ids' => [$sA], 'is_national' => false, 'label' => 'Uji']];
post(['id' => (string) $tE, 'back' => 'terms']);
ok(run('mark_current') !== 'terms' && (int) sql($pdo, "SELECT is_current FROM terms WHERE id = $tE") === 0, 'di luar lingkup admin: ditolak, tidak berubah');
national();

// ---------- 4. Buat periode untuk struktur yang sudah memakai acuan itu ----------
$count = function () use ($pdo) { return (int) sql($pdo, "SELECT COUNT(*) FROM terms WHERE structure_id IN (SELECT id FROM structures WHERE name LIKE 'UJI-PERIODE-%')"); };
$pdo->exec("UPDATE terms SET is_current = 0 WHERE id = $tE");   // kembalikan: E aktif tetapi belum ditandai
$before = $count();
post(['structure_id' => (string) $sE, 'governance_period_id' => (string) $gpB, 'status' => 'draft']);
$to = run('save');
$m = Flash::take();
ok($to === 'terms/form/' . $tE && $count() === $before, 'acuan yang sudah dipakai: diarahkan ke periode yang ada, tanpa baris baru', $to);
ok($m && $m[0]['type'] === 'warning' && has($m[0]['message'], 'sudah memakai periode') && has($m[0]['message'], 'belum ditandai berjalan'), 'pesannya menjelaskan dan menunjuk centang "Tandai ..."', json_encode($m, JSON_UNESCAPED_UNICODE));
post(['structure_id' => (string) $sD, 'governance_period_id' => (string) $gpB, 'status' => 'draft']);   // periode berikutnya D sudah ditandai berjalan di atas
$to = run('save'); $m = Flash::take();
ok($to === 'terms/form/' . $tDnext && has($m[0]['message'], 'Ubah periode itu di sini'), 'acuan yang sudah dipakai dan berjalan: pesan "Ubah periode itu di sini"', $to . ' ' . json_encode($m, JSON_UNESCAPED_UNICODE));

// ---------- 5. Membuat periode: centang bawaan, status, dan peringatan ----------
function formHtml($structureId) { $_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET'; return (new TTc)->call('form', 0, $structureId); }
$hf = formHtml($sA);
ok(preg_match('/name="is_current" value="1" checked/', $hf) === 1 && has($hf, 'Dicentang karena struktur ini belum punya periode berjalan'), 'form: struktur tanpa periode berjalan: centang berjalan sejak awal');
$hf = formHtml($sD);
ok(preg_match('/name="is_current" value="1" checked/', $hf) === 0 && !has($hf, 'Dicentang karena'), 'form: struktur yang sudah punya periode berjalan: tidak dicentang');
$_GET = []; $hf = (new TTc)->call('form', 0);
ok(preg_match('/name="is_current" value="1" checked/', $hf) === 0, 'form tanpa struktur terpilih: tidak dicentang');

// Dibuat dengan centang: Aktif, tanpa peringatan.
post(['structure_id' => (string) $sA, 'governance_period_id' => (string) $gpA, 'status' => 'draft', 'is_current' => '1']);
$to = run('save'); $m = Flash::take();
$new = $pdo->query("SELECT id, is_current, status FROM terms WHERE structure_id = $sA")->fetch();
ok(has($to, 'terms/detail/') && $new && (int) $new['is_current'] === 1 && $new['status'] === 'active', 'dibuat dengan centang berjalan: berjalan dan Aktif (bukan Draf)', json_encode($new));
ok(count($m) === 1 && $m[0]['type'] === 'success', 'tanpa peringatan bila hasilnya berjalan', json_encode($m, JSON_UNESCAPED_UNICODE));
// Dibuat tanpa centang pada struktur tanpa periode berjalan: berhasil tetapi diperingatkan.
$sF = $mkStructure('F-baru');
post(['structure_id' => (string) $sF, 'governance_period_id' => (string) $gpB, 'status' => 'draft']);
$to = run('save'); $m = Flash::take();
$types = array_column($m, 'type');
ok(has($to, 'terms/detail/') && (int) sql($pdo, "SELECT is_current FROM terms WHERE structure_id = $sF") === 0 && in_array('success', $types, true) && in_array('warning', $types, true)
    && has(json_encode($m, JSON_UNESCAPED_UNICODE), 'belum ditandai berjalan'), 'dibuat tanpa centang pada struktur tanpa periode berjalan: berhasil + peringatan', json_encode($m, JSON_UNESCAPED_UNICODE));
// Dibuat tanpa centang pada struktur yang sudah punya periode berjalan: tak perlu peringatan.
$sG = $mkStructure('G-ada'); $mkTerm($sG, $gpA, 'active', 1);
post(['structure_id' => (string) $sG, 'governance_period_id' => (string) $gpB, 'status' => 'draft']);
run('save'); $m = Flash::take();
ok(!in_array('warning', array_column($m, 'type'), true), 'struktur yang sudah punya periode berjalan: periode berikutnya tanpa peringatan');
// Mengubah: melepas centang pada satu-satunya periode berjalan -> peringatan.
$tG = (int) sql($pdo, "SELECT id FROM terms WHERE structure_id = $sG AND is_current = 1");
post(['id' => (string) $tG, 'structure_id' => (string) $sG, 'status' => 'active']);   // tanpa is_current
run('save'); $m = Flash::take();
ok(in_array('warning', array_column($m, 'type'), true) && has(json_encode($m, JSON_UNESCAPED_UNICODE), 'belum punya periode berjalan'), 'melepas tanda pada satu-satunya periode berjalan: peringatan');

// ---------- 5b. Profil orang: penempatan di periode yang belum ditandai berjalan ----------
$pdo->exec("UPDATE terms SET is_current = 0, status = 'active' WHERE id = $tE");
$personId = (int) sql($pdo, "SELECT id FROM people WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
$posId = (int) sql($pdo, "SELECT id FROM positions ORDER BY id LIMIT 1");
$pdo->prepare("INSERT INTO placements (uuid, person_id, term_id, structure_id, position_id) VALUES (?, ?, ?, ?, ?)")->execute([Uuid::v4(), $personId, $tE, $sE, $posId]);
$plE = (int) $pdo->lastInsertId();
$_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$hd = (new TPe)->html($personId);
ok(has($hd, 'Belum terhitung menjabat') && has($hd, '/terms/detail/' . $tE) && has($hd, 'Tandai berjalan') && has($hd, 'value="people/detail/' . $personId . '"'), 'profil: penempatan di periode yang belum ditandai dijelaskan, dengan tombol dan halaman asal');
ok(has($hd, 'belum ditandai berjalan') && has($hd, 'UJI-PERIODE-E-draf2'), 'profil: lencana di Riwayat dan nama struktur tampil');
$onlyThis = (int) sql($pdo, "SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id WHERE pl.person_id = $personId AND pl.deleted_at IS NULL AND t.is_current = 1 AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())");
if ($onlyThis === 0) ok(!has($hd, 'Tidak sedang menjabat di periode berjalan.'), 'profil: orang tanpa jabatan lain tidak lagi diberi pesan "Tidak sedang menjabat" yang menyesatkan');
// Penempatan yang sudah berakhir tidak dihitung menunggu.
$pdo->exec("UPDATE placements SET end_date = '2000-01-01' WHERE id = $plE");
$hd2 = (new TPe)->html($personId);
ok(!has($hd2, 'Belum terhitung menjabat'), 'penempatan yang sudah berakhir tidak dianggap menunggu');
$pdo->exec("UPDATE placements SET end_date = NULL WHERE id = $plE");
// Struktur yang sudah punya periode berjalan lain: periode berikutnya yang berisi orang ini bukan "menunggu".
$pdo->exec("UPDATE terms SET is_current = 1 WHERE id = $tDnext");
$pdo->exec("UPDATE placements SET structure_id = $sD, term_id = " . (int) $pdo->query("SELECT id FROM terms WHERE structure_id = $sD AND id <> $tDnext LIMIT 1")->fetchColumn() . " WHERE id = $plE");
$hd3 = (new TPe)->html($personId);
ok(!has($hd3, 'Belum terhitung menjabat'), 'periode lain milik struktur yang sudah punya periode berjalan: bukan "menunggu"');
// Tandai berjalan dari profil: jabatan pindah ke "Jabatan Saat Ini".
$pdo->exec("UPDATE terms SET is_current = 0 WHERE id = $tDnext");
$pdo->exec("UPDATE terms SET is_current = 0, status = 'active' WHERE id = $tE");
$pdo->exec("UPDATE placements SET structure_id = $sE, term_id = $tE WHERE id = $plE");
post(['id' => (string) $tE, 'back' => 'people/detail/' . $personId]);
$to = run('mark_current');
$hd4 = (new TPe)->html($personId);
ok($to === 'people/detail/' . $personId && !has($hd4, 'Belum terhitung menjabat') && has($hd4, 'UJI-PERIODE-E-draf2') && has($hd4, 'class="tile"'), 'setelah Tandai berjalan: jabatannya masuk "Jabatan Saat Ini" dan peringatan hilang');

$pdo->exec("UPDATE terms SET is_current = 1 WHERE id = $tDnext");   // D kembali berjalan untuk bagian berikut

// ---------- 6. Data Pengurus (Per Struktur) ----------
require_once ROOT_DIR . '/app/Models/PersonModel.php';
$pm = new PersonModel();
$ov = $pm->overviewByStructure('', [], true);
$byId = []; foreach ($ov['structures'] as $s) $byId[$s['id']] = $s;
$pdo->exec("UPDATE terms SET is_current = 0 WHERE id = $tE");
$ov = $pm->overviewByStructure('', [], true);
$byId = []; foreach ($ov['structures'] as $s) $byId[$s['id']] = $s;
ok(isset($byId[$sE]) && $byId[$sE]['term_id'] === null && $byId[$sE]['pending'] === ['id' => $tE, 'name' => $gps[1]['name']], 'Data Pengurus: struktur dengan periode yang belum ditandai membawa periodenya', json_encode($byId[$sE] ?? null, JSON_UNESCAPED_UNICODE));
ok(isset($byId[$sA]) && $byId[$sA]['pending'] === null && $byId[$sD]['pending'] === null && $byId[$sD]['term_id'] !== null, 'tanpa periode atau sudah berjalan: tanpa pending');
$ovBlocks = PeopleOverview::build($ov, []);
$lvl = null; foreach ($ovBlocks as $b) if ($b['level'] === 'DPLN') $lvl = $b;
$rowE = null; foreach ($lvl['rows'] as $r) if ($r['id'] === $sE) $rowE = $r;
ok($rowE && $rowE['pending']['id'] === $tE && $rowE['term_id'] === null, 'PeopleOverview::build meneruskan pending ke barisnya');

// Tampilan: Ui::termGap, dipakai Struktur dan Data Pengurus.
$g1 = Ui::termGap($sA, null, 'people?lv=DPLN');
$g2 = Ui::termGap($sE, ['id' => $tE, 'name' => '2026-2031'], 'people?lv=DPLN');
ok(has($g1, 'Buat periode') && !has($g1, 'Tandai'), 'termGap tanpa periode');
ok(has($g2, 'belum ditandai berjalan') && has($g2, 'Tandai berjalan') && has($g2, 'value="people?lv=DPLN"') && !has($g2, 'Buat periode'), 'termGap dengan periode menunggu');
national('viewer');
ok(!has(Ui::termGap($sA, null), 'Buat periode') && !has(Ui::termGap($sE, ['id' => $tE, 'name' => 'x']), '<form') && has(Ui::termGap($sE, ['id' => $tE, 'name' => 'x']), 'belum ditandai berjalan'), 'peninjau: keterangan tanpa tindakan');
national();
$_SERVER['REQUEST_METHOD'] = 'GET';
$js = file_get_contents(ROOT_DIR . '/public/assets/css/app.css');
ok(has($js, '.linkbtn') && has($js, '.alert.warn, .alert.warning'), 'CSS: .linkbtn dan .alert.warning ada');

$cleanup();
ok((int) sql($pdo, "SELECT COUNT(*) FROM structures WHERE name LIKE 'UJI-PERIODE-%'") === 0, 'baris uji dihapus lagi');

$out = ob_get_clean();
echo $out;
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
