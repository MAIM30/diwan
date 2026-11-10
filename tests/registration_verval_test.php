<?php
// PATH LOKASI FILE: tests/registration_verval_test.php
// Uji saklar verval pengajuan pengurus:
//   1. Hak akses: hanya Super Admin dan admin_struktur berlingkup DPP/MWP; diperiksa di server (bukan sekadar tombol disembunyikan).
//   2. Verval aktif (bawaan, juga bila pengaturannya belum ada): pengajuan menunggu verifikator, tak ada orang dibuat.
//   3. Verval nonaktif: pengajuan baru yang jelas langsung diterima (orang tercatat, bisa masuk portal, tanpa penempatan); yang mirip data lama,
//      atau jenis kelaminnya tak sesuai sayap struktur, tetap menunggu; pendaftar yang menautkan nama lama ditautkan, bukan digandakan.
//   4. Pengajuan yang sudah menunggu tidak disentuh saklar; aktivasi manual tetap bekerja (ia memakai logika pembuatan orang yang sama).
// Menjalankan DaftarController dan RegistrationsController SUNGGUHAN terhadap database lokal di dalam SATU transaksi yang selalu di-rollback.
//
//   php tests/registration_verval_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'WaNotify'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/DaftarController.php';
require_once ROOT_DIR . '/app/Controllers/RegistrationsController.php';
foreach (['UnitModel', 'TermModel', 'RegistrationModel', 'SettingModel', 'PositionModel', 'PlacementModel', 'MemberModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

$_SERVER['REMOTE_ADDR'] = '10.255.255.11';

class Redirected extends Exception {}
class Denied extends Exception {}
trait Capture {
    public $rendered = []; public $html = '';
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function requireLogin($roles = null) {}
    protected function denied($message = '') { throw new Denied($message); }
    protected function view($v, $d = []) {
        $this->rendered[$v] = $d;
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); ob_start(); require ROOT_DIR . "/app/Views/$v.php"; $this->html .= ob_get_clean();
    }
}
class TD extends DaftarController { use Capture; public function kirim() { $this->submit(); } }
class TR extends RegistrationsController { use Capture; }

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function flashText() { return implode(' | ', array_map(function ($x) { return $x['message']; }, $_SESSION['_flash'] ?? [])); }

$pdo = Database::getInstance()->getConnection();
$one = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
$dpp = (int) $one("SELECT id FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1");
$mwp = (int) $one("SELECT id FROM structures WHERE level = 'MWP' AND deleted_at IS NULL LIMIT 1");
$dpw = (int) $one("SELECT id FROM structures WHERE level = 'DPW' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$mww = (int) $one("SELECT id FROM structures WHERE level = 'MWW' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$term = (new TermModel())->currentOfStructure($dpp);
$pos = (int) $one("SELECT id FROM positions WHERE is_active = 1 AND applies_to IN ('structure','both') AND is_core = 0 ORDER BY id LIMIT 1");
$exist = $pdo->query("SELECT id, name, full_name, gender FROM people WHERE deleted_at IS NULL AND gender = 'L' AND phone IS NOT NULL AND phone <> ''
                       AND name LIKE '% % %' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$dpp || !$mwp || !$dpw || !$mww || !$term || !$pos || !$exist) { echo "LEWAT: data lokal tak memenuhi syarat uji (DPP, MWP, DPW, MWW, periode DPP, jabatan, orang bernama 3 kata).\n"; exit(0); }

$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

// ---------- akun uji untuk uji hak akses ----------
function akun($role, $scope) {
    global $pdo;
    $pdo->prepare("INSERT INTO accounts (uuid, phone, email, display_name, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([Uuid::v4(), '6200' . random_int(10000000, 99999999), null, 'Uji ' . $role]);
    $id = (int) $pdo->lastInsertId();
    if ($role !== null) $pdo->prepare("INSERT INTO account_roles (account_id, role, scope_structure_id) VALUES (?, ?, ?)")->execute([$id, $role, $scope]);
    return $id;
}
function sesi($accountId, array $roles, $all = true) {
    $_SESSION = ['auth' => ['account_id' => $accountId, 'roles' => $roles],
                 'scope' => ['all' => $all, 'root_ids' => [], 'structure_ids' => [], 'is_national' => $all, 'label' => 'Uji']];
}
function can($accountId, array $roles) { sesi($accountId, $roles); return Auth::canManageVerval(); }

$aSuper = akun('super_admin', null);
$aDpp   = akun('admin_struktur', $dpp);
$aMwp   = akun('admin_struktur', $mwp);
$aDpw   = akun('admin_struktur', $dpw);
$aMww   = akun('admin_struktur', $mww);
$aVerif = akun('verifikator', $dpp);
$aView  = akun('viewer', $dpp);
$aNone  = akun(null, null);

// ---------- 1. Hak akses ----------
ok(can($aSuper, ['super_admin']) === true, '1: Super Admin boleh');
ok(can($aDpp, ['admin_struktur']) === true, '1: admin DPP boleh');
ok(can($aMwp, ['admin_struktur']) === true, '1: admin MWP boleh');
ok(can($aDpw, ['admin_struktur']) === false, '1: admin DPW tidak boleh');
ok(can($aMww, ['admin_struktur']) === false, '1: admin MWW tidak boleh');
ok(can($aVerif, ['verifikator']) === false, '1: verifikator (walau di DPP) tidak boleh');
ok(can($aView, ['viewer']) === false, '1: viewer tidak boleh');
ok(can($aNone, []) === false, '1: akun tanpa peran tidak boleh');
// Sesi yang MENGAKU admin_struktur tetapi akunnya hanya admin DPW (gabungan peran tak membedakan lingkup): dicek dari account_roles.
ok(can($aDpw, ['admin_struktur', 'verifikator']) === false, '1: admin DPW + verifikator: tetap tidak boleh');
$_SESSION = []; ok(Auth::canManageVerval() === false, '1: tanpa sesi tidak boleh');

// ---------- 2. Verval aktif (bawaan) ----------
$reg = new RegistrationModel();
$pdo->exec("DELETE FROM settings WHERE `key` = 'verval_pengajuan'");
ok($reg->vervalActive() === true, '2: pengaturan belum ada -> verval AKTIF (perilaku sekarang)');
(new SettingModel())->set('verval_pengajuan', 'abc');
ok($reg->vervalActive() === true, '2: isi pengaturan tak dikenal -> tetap AKTIF (gagal aman)');
$pdo->exec("DELETE FROM settings WHERE `key` = 'verval_pengajuan'");

function kirim($structureId, $phone, $name, $gender = 'L', $existingId = null) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['name' => $name, 'gender' => $gender, 'phone' => $phone, 'target_structure_id' => (string) $structureId];
    if ($existingId) $_POST['existing_person_id'] = (string) $existingId;
    unset($_SESSION['_flash']);
    $c = new TD; $to = null;
    try { $c->kirim(); } catch (Redirected $r) { $to = $r->getMessage(); }
    return [$to, $c];
}
function req($phone) { global $pdo; $s = $pdo->prepare("SELECT * FROM registration_requests WHERE phone = ?"); $s->execute([Text::normalizePhone($phone)]); return $s->fetch(PDO::FETCH_ASSOC); }
function orangDgn($phone) { global $pdo; $s = $pdo->prepare("SELECT * FROM people WHERE phone = ? AND deleted_at IS NULL"); $s->execute([Text::normalizePhone($phone)]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function outcome($c) { return $c->rendered['public/daftar_sukses']['outcome'] ?? null; }

sesi($aSuper, ['super_admin']);
$uniq = 'Zulkifli Uji Verval' . getmypid();
$orangSebelum = (int) $one("SELECT COUNT(*) FROM people WHERE deleted_at IS NULL");
[$to, $c] = kirim($dpp, '08111000611', $uniq);
$r = req('08111000611');
ok($to === null && outcome($c) === 'review' && $r && $r['status'] === 'submitted', '2: verval aktif -> pengajuan menunggu (Diajukan), halaman sukses versi biasa', (string) outcome($c));
ok(count(orangDgn('08111000611')) === 0 && (int) $one("SELECT COUNT(*) FROM people WHERE deleted_at IS NULL") === $orangSebelum, '2: tak ada orang yang dibuat');
ok(strpos($c->html, 'menunggu verifikasi pengurus struktur') !== false && strpos($c->html, 'sudah tercatat sebagai pengurus') === false, '2: teks halaman sukses: menunggu verifikasi');
$c = new TD; $_SERVER['REQUEST_METHOD'] = 'GET'; $c->index();
ok(strpos($c->html, 'Formulir ini hanya mengirim') !== false && strpos($c->html, 'langsung tercatat') === false, '2: teks formulir: akan diperiksa dulu');

// ---------- 3. Menyalakan/mematikan lewat controller ----------
function verval($roleSet, $accountId, $aktif, $method = 'POST') {
    sesi($accountId, $roleSet);
    $_SERVER['REQUEST_METHOD'] = $method; $_POST = ['aktif' => $aktif]; unset($_SESSION['_flash']);
    $c = new TR;
    try { $c->verval(); return 'tanpa-redirect'; } catch (Redirected $r) { return $r->getMessage(); } catch (Denied $d) { return 'ditolak'; }
}
$auditBefore = (int) $one("SELECT COUNT(*) FROM audit_log WHERE entity = 'settings' AND after_data LIKE '%verval_pengajuan%'");
$to = verval(['admin_struktur'], $aDpw, '0');
ok($to === 'ditolak' && $reg->vervalActive() === true, '3: admin DPW mencoba mematikan -> ditolak di server, pengaturan tetap aktif', $to);
$to = verval(['verifikator'], $aVerif, '0');
ok($to === 'ditolak' && $reg->vervalActive() === true, '3: verifikator mencoba mematikan -> ditolak');
$to = verval(['admin_struktur'], $aMwp, '0', 'GET');
ok($to === 'registrations' && $reg->vervalActive() === true, '3: GET tidak mengubah apa pun');
$to = verval(['admin_struktur'], $aMwp, '0');
ok($to === 'registrations' && $reg->vervalActive() === false && stripos(flashText(), 'dinonaktifkan') !== false, '3: admin MWP mematikan -> nonaktif, pesan jelas', $to . ' ' . flashText());
ok((int) $one("SELECT updated_by FROM settings WHERE `key` = 'verval_pengajuan'") === $aMwp, '3: tercatat siapa yang mengubah (settings.updated_by)');
ok((int) $one("SELECT COUNT(*) FROM audit_log WHERE entity = 'settings' AND after_data LIKE '%verval_pengajuan%'") === $auditBefore + 1, '3: perubahan masuk jejak audit');
$to = verval(['admin_struktur'], $aMwp, '0');
ok((int) $one("SELECT COUNT(*) FROM audit_log WHERE entity = 'settings' AND after_data LIKE '%verval_pengajuan%'") === $auditBefore + 1, '3: menekan hal yang sama lagi tidak menambah jejak audit');

// Pengajuan yang sudah menunggu sebelum saklar dimatikan.
$menunggu = req('08111000611');
ok($menunggu && $menunggu['status'] === 'submitted', '3: pengajuan yang sudah menunggu tetap Diajukan setelah saklar dimatikan (tak ikut diterima)');

// ---------- 4. Verval nonaktif: yang jelas langsung diterima ----------
sesi($aSuper, ['super_admin']);
$uniq2 = 'Hamdan Uji Verval' . getmypid();
[$to, $c] = kirim($dpp, '08111000612', $uniq2);
$r = req('08111000612'); $orang = orangDgn('08111000612');
ok($to === null && outcome($c) === 'accepted' && $r && $r['status'] === 'activated', '4: pengajuan jelas -> langsung Aktif, halaman sukses versi "diterima"', (string) outcome($c) . ' ' . ($r['status'] ?? '-'));
ok(count($orang) === 1 && $orang[0]['name'] === $uniq2 && $orang[0]['gender'] === 'L' && $orang[0]['status'] === 'active' && (int) $orang[0]['portal_enabled'] === 1
   && (int) $orang[0]['home_structure_id'] === $dpp, '4: orang tercatat: nama, jenis kelamin, aktif, boleh ke portal, struktur asal = struktur tujuan');
ok($r && (int) $r['resulting_person_id'] === (int) $orang[0]['id'] && $r['resulting_placement_id'] === null, '4: pengajuan menunjuk orangnya; TANPA penempatan');
ok((int) $one("SELECT COUNT(*) FROM placements WHERE person_id = " . (int) $orang[0]['id']) === 0, '4: orang itu belum punya penempatan (diatur admin kemudian)');
ok($r && $r['reviewed_by'] === null && strpos((string) $r['review_note'], 'otomatis') !== false && $r['reviewed_at'] !== null, '4: catatan menyebut diterima otomatis; peninjaunya kosong (bukan atas nama admin mana pun)');
$login = (new MemberModel())->findEligibleByPhone('08111000612');
ok($login && (int) $login['id'] === (int) $orang[0]['id'], '4: orang itu langsung bisa masuk Portal Pengurus dengan nomor WA-nya');
ok(strpos($c->html, 'sudah tercatat sebagai pengurus') !== false && strpos($c->html, 'menunggu verifikasi') === false, '4: teks halaman sukses: sudah tercatat, bisa masuk portal');
ok((int) $one("SELECT COUNT(*) FROM audit_log WHERE entity = 'registration_requests' AND entity_id = " . (int) $r['id'] . " AND action = 'update' AND after_data LIKE '%activated%'") === 1, '4: penerimaan otomatis tercatat di jejak audit (status berubah menjadi activated)');
ok(strpos((string) $one("SELECT before_data FROM audit_log WHERE entity = 'registration_requests' AND entity_id = " . (int) $r['id'] . " AND action = 'update' LIMIT 1"), 'submitted_ip') === false, '4: IP biner tidak ikut disalin ke jejak audit (itulah yang dulu menggagalkan seluruh baris audit pengajuan)');
$c = new TD; $_SERVER['REQUEST_METHOD'] = 'GET'; $c->index();
ok(strpos($c->html, 'langsung tercatat') !== false && strpos($c->html, 'Formulir ini hanya mengirim') === false, '4: teks formulir berubah: langsung tercatat');

// Perempuan ke MWP (sayap cocok): diterima.
[$to, $c] = kirim($mwp, '08111000613', 'Zainab Uji Verval' . getmypid(), 'P');
ok(outcome($c) === 'accepted' && count(orangDgn('08111000613')) === 1, '4: perempuan ke MWP: diterima');

// ---------- 5. Yang tidak boleh diterima otomatis ----------
$namaLama = $exist['full_name'];
[$to, $c] = kirim($dpp, '08111000614', $namaLama);
$r = req('08111000614');
ok(outcome($c) === 'review_auto' && $r && $r['status'] === 'submitted' && count(orangDgn('08111000614')) === 0, '5: nama sama dengan orang yang sudah ada -> TETAP menunggu verifikator, tak ada orang ganda', (string) outcome($c));
$alasan = $reg->acceptWithoutReview((int) $r['id']);
ok($alasan['accepted'] === false && stripos($alasan['reason'], 'Nama mirip') !== false, '5: alasannya: nama mirip data lama', $alasan['reason'] ?? '');
ok(strpos($c->html, 'perlu diperiksa pengurus struktur') !== false, '5: halaman sukses menjelaskan perlu diperiksa (tanpa menyebut data orang lain)');
ok(stripos($c->html, $exist['name']) === false, '5: halaman sukses tidak membocorkan nama orang yang mirip');

[$to, $c] = kirim($dpp, '08111000615', 'Fitriyani Uji Verval' . getmypid(), 'P');
$r = req('08111000615');
ok(outcome($c) === 'review_auto' && $r && $r['status'] === 'submitted', '5: perempuan ke DPP (sayap Umum) -> menunggu', (string) outcome($c));
ok(stripos($reg->acceptWithoutReview((int) $r['id'])['reason'], 'sayap') !== false, '5: alasannya: jenis kelamin tak sesuai sayap');
[$to, $c] = kirim($mwp, '08111000616', 'Ridwan Uji Verval' . getmypid(), 'L');
ok(outcome($c) === 'review_auto' && req('08111000616')['status'] === 'submitted', '5: laki-laki ke MWP (sayap Muslimah) -> menunggu');

// Pengajuan yang menunggu dapat tetap ditinjau manual seperti biasa (Diverifikasi, lalu Aktifkan dengan penempatan).
$rid = (int) req('08111000614')['id'];
ok($reg->markVerified($rid, 'uji') === true && $reg->findById($rid)['status'] === 'verified', '5: pengajuan yang menunggu tetap bisa diverifikasi manual');
ok((int) $one("SELECT COUNT(*) FROM audit_log WHERE entity = 'registration_requests' AND entity_id = $rid AND action = 'update'") === 1, '5: verifikasi manual kini tercatat di jejak audit (dulu diam-diam gagal tertulis)');

// ---------- 6. Pendaftar menautkan nama lama: ditautkan, bukan digandakan ----------
$namaTaut = 'Zainab Tautan Uji' . getmypid();
$pdo->prepare("INSERT INTO people (uuid, name, name_normalized, gender, phone, status, portal_enabled, home_structure_id) VALUES (?, ?, ?, 'P', NULL, 'active', 1, ?)")
    ->execute([Uuid::v4(), $namaTaut, Text::normalizeBase($namaTaut), $mwp]);
$tautId = (int) $pdo->lastInsertId();
$orangDenganNama = (int) $one("SELECT COUNT(*) FROM people WHERE name = " . $pdo->quote($namaTaut));
[$to, $c] = kirim($mwp, '08111000617', $namaTaut, 'P', $tautId);
$r = req('08111000617');
ok(outcome($c) === 'accepted' && $r && $r['status'] === 'activated' && (int) $r['resulting_person_id'] === $tautId, '6: menautkan nama lama -> diterima dan menunjuk orang yang SAMA', (string) outcome($c) . ' ' . flashText());
ok((int) $one("SELECT COUNT(*) FROM people WHERE name = " . $pdo->quote($namaTaut)) === $orangDenganNama, '6: tak ada orang baru (tak ganda)');
ok($one("SELECT phone FROM people WHERE id = $tautId") === Text::normalizePhone('08111000617'), '6: nomor WA melengkapi kolom kosong milik orang lama');

// ---------- 7. Menyalakan kembali ----------
$to = verval(['admin_struktur'], $aDpp, '1');
ok($to === 'registrations' && $reg->vervalActive() === true && stripos(flashText(), 'diaktifkan') !== false, '7: admin DPP menyalakan lagi -> aktif', $to . ' ' . flashText());
sesi($aSuper, ['super_admin']);
[$to, $c] = kirim($dpp, '08111000618', 'Rahmat Uji Verval' . getmypid());
ok(outcome($c) === 'review' && req('08111000618')['status'] === 'submitted' && count(orangDgn('08111000618')) === 0, '7: sesudah dinyalakan lagi pengajuan baru kembali menunggu verifikator');

// ---------- 8. Halaman admin ----------
function halamanAdmin($accountId, array $roles) {
    sesi($accountId, $roles); $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['status' => 'submitted'];
    $c = new TR; $c->index(); return $c->html;
}
function formVerval($h) { return preg_match('/<form method="post" action="[^"]*\/registrations\/verval"[^>]*>/', $h, $m) ? $m[0] : ''; }
$h = halamanAdmin($aMwp, ['admin_struktur']);
ok(strpos($h, 'Verval pengajuan') !== false && formVerval($h) !== '' && strpos($h, 'Nonaktifkan verval') !== false && strpos(formVerval($h), 'data-confirm=') !== false,
   '8: admin MWP melihat tombol "Nonaktifkan verval" (dengan konfirmasi) saat verval aktif');
$h = halamanAdmin($aVerif, ['verifikator']);
ok(strpos($h, 'Verval pengajuan') !== false && strpos($h, '/registrations/verval') === false && strpos($h, 'Aktif') !== false, '8: verifikator melihat statusnya tetapi TIDAK ada tombolnya');
$reg->setVerval(false, $aSuper);
$h = halamanAdmin($aDpp, ['admin_struktur']);
ok(strpos($h, 'Aktifkan verval') !== false && strpos($h, 'Nonaktif') !== false && strpos($h, 'langsung diterima') !== false && formVerval($h) !== '' && strpos(formVerval($h), 'data-confirm=') === false,
   '8: saat nonaktif: tombol "Aktifkan verval" (tanpa konfirmasi) dan penjelasan apa yang terjadi');
$h = halamanAdmin($aDpw, ['admin_struktur']);
ok(strpos($h, '/registrations/verval') === false && strpos($h, 'Nonaktif') !== false, '8: admin DPW melihat statusnya, bukan tombolnya');
$reg->setVerval(true, $aSuper);

// ---------- 9. Aktivasi manual tetap bekerja (logika pembuatan orang dipakai bersama) ----------
sesi($aSuper, ['super_admin']);
[$to, $c] = kirim($dpp, '08111000619', 'Ibrahim Uji Verval' . getmypid());
$rid = (int) req('08111000619')['id'];
$reg->markVerified($rid, 'uji');
$res = $reg->activate($rid, null, ['term_id' => (int) $term['id'], 'structure_id' => $dpp, 'position_id' => $pos, 'is_primary' => 1]);
$o = orangDgn('08111000619');
$st = $reg->findById($rid);
ok(count($o) === 1 && (int) $res['person_id'] === (int) $o[0]['id'] && $res['placement_id'] > 0 && $st['status'] === 'activated' && (int) $st['resulting_placement_id'] === (int) $res['placement_id'],
   '9: aktivasi manual: orang baru + penempatan dibuat, status Aktif', json_encode($res));
ok((int) $one("SELECT COUNT(*) FROM placements WHERE person_id = " . (int) $o[0]['id'] . " AND source = 'registrasi'") === 1, '9: tepat satu penempatan bersumber registrasi');
// Nomor yang direbut orang lain sesudah pengajuan: aktivasi manual tetap menolak (pemeriksaan nomor ikut ke logika bersama).
[$to, $c] = kirim($dpp, '08111000620', 'Yusuf Uji Verval' . getmypid());
$rid2 = (int) req('08111000620')['id']; $reg->markVerified($rid2, 'uji');
$pdo->prepare("INSERT INTO people (uuid, name, name_normalized, gender, phone, status, portal_enabled) VALUES (?, 'Perebut Nomor Uji', 'perebut nomor uji', 'L', ?, 'active', 1)")->execute([Uuid::v4(), Text::normalizePhone('08111000620')]);
$ditolak = false; try { $reg->activate($rid2, null, ['term_id' => (int) $term['id'], 'structure_id' => $dpp, 'position_id' => $pos]); } catch (RuntimeException $e) { $ditolak = stripos($e->getMessage(), 'sudah dipakai') !== false; }
ok($ditolak && $reg->findById($rid2)['status'] === 'verified', '9: nomor yang sudah dipakai orang lain tetap menggagalkan aktivasi, pengajuan tetap Diverifikasi');

$pdo->rollBack();
ok(!req('08111000611') && (int) $one("SELECT COUNT(*) FROM people WHERE deleted_at IS NULL") === $orangSebelum && !$one("SELECT COUNT(*) FROM settings WHERE `key` = 'verval_pengajuan'"),
   'data uji tidak tersisa setelah rollback (pengajuan, orang, pengaturan)');

echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
