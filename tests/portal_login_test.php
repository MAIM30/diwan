<?php
// PATH LOKASI FILE: tests/portal_login_test.php
// Uji langkah OTP masuk Portal Pengurus: dilewati bila gateway WhatsApp tidak aktif, tetap ada bila aktif.
//   1. Whatsapp::isActive() terhadap gateway tiruan (php -S di 127.0.0.1): aktif hanya bila terisi, menjawab, kunci benar, dan status "open".
//   2. PengurusController::login_process / otp_verify terhadap pengurus nyata di database lokal. Semua tulisan (audit, kode OTP,
//      percobaan gagal) ada di SATU transaksi yang selalu di-rollback.
//
//   php tests/portal_login_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
ob_start();   // sesi dan header() di CLI butuh keluaran yang belum terkirim

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'Member', 'MemberThrottle', 'Otp', 'Whatsapp'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['PositionModel', 'PlacementModel', 'MemberModel', 'SettingModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";
require_once ROOT_DIR . '/app/Controllers/PengurusController.php';

class Redirected extends RuntimeException {}
class TL extends PengurusController {
    public static $active = false;
    protected function gatewayActive() { return self::$active; }
    protected function redirect($path = '') { throw new Redirected($path); }
    public function masuk() { $this->login_process(); }
    public function verifikasi() { $this->otp_verify(); }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}

// ---------- 1. Whatsapp::isActive() terhadap gateway tiruan ----------
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'diwan_wa_' . getmypid();
@mkdir($tmp);
$modeFile = $tmp . DIRECTORY_SEPARATOR . 'mode.txt';
$router = $tmp . DIRECTORY_SEPARATOR . 'router.php';
file_put_contents($router, <<<'PHP'
<?php
$mode = trim((string) @file_get_contents(__DIR__ . '/mode.txt'));
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'kunci-uji') { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'kunci salah']); return; }
if ($mode === 'garbage') { echo 'bukan json'; return; }
if ($mode === 'slow') { sleep(6); }
if ($mode === 'notok') { echo json_encode(['ok' => false, 'status' => 'open']); return; }
echo json_encode(['ok' => true, 'status' => $mode === 'slow' ? 'open' : $mode, 'hasQr' => false]);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/out.txt', 'w'], 2 => ['file', $tmp . '/err.txt', 'w']], $pipes);
register_shutdown_function(function () use (&$proc, $tmp) {
    if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); }
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    try { $c = fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); } catch (ErrorException $e) { $c = false; }   // belum siap: handler galat uji mengubah peringatan jadi pengecualian
    if ($c) { fclose($c); $up = true; } else usleep(100000);
}
ok($up, 'gateway tiruan berjalan', "port $port");

function active($settings, $mode = null) {
    global $modeFile;
    if ($mode !== null) file_put_contents($modeFile, $mode);
    Whatsapp::forget();
    Whatsapp::$settings = $settings;
    return Whatsapp::isActive();
}
$good = ['wa_gateway_url' => "http://127.0.0.1:$port", 'wa_gateway_key' => 'kunci-uji'];
if ($up) {
    ok(active($good, 'open') === true, 'aktif: terisi, menjawab, kunci benar, status open');
    ok(active($good, 'connecting') === false, 'tidak aktif: status connecting');
    ok(active($good, 'close') === false, 'tidak aktif: status close');
    ok(active($good, 'notok') === false, 'tidak aktif: ok=false walau status open');
    ok(active($good, 'garbage') === false, 'tidak aktif: balasan bukan JSON');
    ok(active(['wa_gateway_url' => "http://127.0.0.1:$port", 'wa_gateway_key' => 'salah'], 'open') === false, 'tidak aktif: kunci salah (401)');
    ok(active(['wa_gateway_url' => '', 'wa_gateway_key' => 'kunci-uji'], 'open') === false, 'tidak aktif: URL kosong');
    ok(active(['wa_gateway_url' => "http://127.0.0.1:$port", 'wa_gateway_key' => ''], 'open') === false, 'tidak aktif: kunci kosong');
    ok(active(['wa_gateway_url' => null, 'wa_gateway_key' => null], 'open') === false, 'tidak aktif: belum dikonfigurasi');

    // Diingat selama satu permintaan; forget() melupakannya.
    ok(active($good, 'open') === true, 'memo: aktif');
    file_put_contents($modeFile, 'close');
    ok(Whatsapp::isActive() === true, 'memo: jawaban diingat selama permintaan yang sama');
    Whatsapp::forget(); Whatsapp::$settings = $good;
    ok(Whatsapp::isActive() === false, 'forget(): dihitung ulang');
}
// Port yang mati: cepat dan tidak aktif.
$t = microtime(true);
$dead = ['wa_gateway_url' => 'http://127.0.0.1:1', 'wa_gateway_key' => 'kunci-uji'];
ok(active($dead) === false && microtime(true) - $t < 4.5, 'tidak aktif: tak terhubung, selesai di bawah batas waktu', round(microtime(true) - $t, 2) . ' dtk');

// ---------- 2. Alur masuk ----------
if (!PORTAL_ENABLED) { echo "LEWAT: PORTAL_ENABLED = false\n"; goto selesai; }
$pdo = Database::getInstance()->getConnection();
$pdo->beginTransaction();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SERVER['REMOTE_ADDR'] = '10.255.255.7';   // IP uji: tak bersinggungan dengan pembatas milik peramban sungguhan

$elig = "p.deleted_at IS NULL AND p.status = 'active' AND p.portal_enabled = 1";
$uniq = "(SELECT COUNT(*) FROM people q WHERE q.phone = p.phone AND q.deleted_at IS NULL) = 1";
$one = function ($where) use ($pdo) { return $pdo->query("SELECT p.id, p.phone, p.nik FROM people p WHERE $where ORDER BY p.id LIMIT 1")->fetch(); };
$pe = $one("$elig AND p.phone IS NOT NULL AND p.phone <> '' AND $uniq");
$pn = $one("$elig AND p.nik IS NOT NULL AND LENGTH(p.nik) = 16 AND p.nik_guard IS NOT NULL");
$pi = $one("p.deleted_at IS NULL AND p.portal_enabled = 0 AND p.phone IS NOT NULL AND p.phone <> '' AND $uniq");
$ps = $pdo->query("SELECT phone FROM people WHERE deleted_at IS NULL AND phone IS NOT NULL AND phone <> '' GROUP BY phone HAVING COUNT(*) >= 2 LIMIT 1")->fetchColumn();
ok((bool) $pe, 'ada pengurus layak bernomor unik di database lokal');

function login($wa, $active) {
    $_SESSION = [];
    return attempt($wa, $active, true);
}
function attempt($wa, $active, $fresh = false) {
    if ($fresh) $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['wa' => $wa];
    TL::$active = $active;
    try { (new TL)->masuk(); } catch (Redirected $r) { return $r->getMessage(); }
    return '(tanpa redirect)';
}
function count_($sql) { global $pdo; return (int) $pdo->query($sql)->fetchColumn(); }
function loggedIn() { return Member::check() && !empty($_SESSION['member']['person_id']) ? (int) $_SESSION['member']['person_id'] : 0; }

if ($pe) {
    $pid = (int) $pe['id'];
    $otpBefore = count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid");
    $audBefore = count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'");

    // A. Gateway tidak aktif: langsung masuk, tanpa kode.
    $to = login($pe['phone'], false);
    ok($to === 'pengurus', 'A: gateway tak aktif -> langsung ke beranda', $to);
    ok(loggedIn() === $pid, 'A: sesi portal terbuka atas nama orang itu');
    ok(empty($_SESSION['otp_pending']), 'A: tidak ada OTP tertunda');
    ok(count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid") === $otpBefore, 'A: tidak ada kode OTP yang diterbitkan');
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore + 1, 'A: riwayat masuk tercatat');

    // B. Gateway aktif: alur OTP seperti biasa.
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/otp', 'B: gateway aktif -> halaman OTP', $to);
    ok(loggedIn() === 0, 'B: belum masuk sebelum kode diverifikasi');
    $code = $_SESSION['otp_pending']['code'] ?? '';
    ok(preg_match('/^\d{4}$/', $code) === 1 && (int) $_SESSION['otp_pending']['person_id'] === $pid, 'B: kode 4 digit tertunda untuk orang itu');
    ok(count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid") === $otpBefore + 1, 'B: satu kode OTP diterbitkan');
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore + 1, 'B: belum ada riwayat masuk baru');

    // C. Kode yang salah ditolak; yang benar masuk dan sekali pakai (alur OTP tidak berubah).
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['code' => $code === '0000' ? '0001' : '0000'];
    try { (new TL)->verifikasi(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'C: kode salah ditolak', $to);
    $_POST = ['code' => $code];
    try { (new TL)->verifikasi(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
    ok($to === 'pengurus' && loggedIn() === $pid && empty($_SESSION['otp_pending']), 'C: kode benar masuk dan OTP tertunda dibersihkan', $to);
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore + 2, 'C: riwayat masuk tercatat');

    // D. OTP tertunda yang tertinggal tidak ikut terbawa saat masuk langsung.
    $_SESSION = ['otp_pending' => ['person_id' => 999999, 'code' => '1234', 'issued_at' => time()]];
    $to = attempt($pe['phone'], false);
    ok($to === 'pengurus' && empty($_SESSION['otp_pending']), 'D: OTP tertunda lama dihapus saat masuk langsung', $to);
}

// E. NIK 16 digit: perilaku sama.
if ($pn) {
    $to = login($pn['nik'], false);
    ok($to === 'pengurus' && loggedIn() === (int) $pn['id'], 'E: NIK 16 digit, gateway tak aktif -> langsung masuk', $to);
    $to = login($pn['nik'], true);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'E: NIK 16 digit, gateway aktif -> OTP', $to);
}

// F. Tanpa OTP bukan berarti tanpa pemeriksaan: yang tak berhak tetap ditolak.
$to = login('08999999999', false);
ok($to === 'daftar' && loggedIn() === 0 && !count_("SELECT COUNT(*) FROM people WHERE phone IN ('08999999999','628999999999')"), 'F: nomor tak terdaftar -> daftar, tidak masuk', $to);
if ($pi) {
    $to = login($pi['phone'], false);
    ok($to === 'daftar' && loggedIn() === 0, 'F: akses portal dicabut -> ditolak walau gateway tak aktif', $to);
}
if ($ps) {
    $to = login($ps, false);
    ok($to === 'daftar' && loggedIn() === 0, 'F: nomor dipakai dua orang -> tak bisa masuk walau gateway tak aktif', $to);
}
$to = login('abc', false);
ok($to === 'pengurus/login' && loggedIn() === 0, 'F: format nomor salah -> kembali ke login', $to);
$_SESSION = []; $_SERVER['REQUEST_METHOD'] = 'GET';
try { TL::$active = false; (new TL)->masuk(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
ok($to === 'pengurus/login' && loggedIn() === 0, 'F: GET bukan masuk', $to);
// Pembatas per IP tetap berlaku sebelum apa pun: banyak gagal -> terkunci, termasuk untuk nomor yang benar.
if ($pe) {
    $_SESSION = [];
    for ($i = 0; $i < 9; $i++) login('08999999999', false);   // sembilan gagal dari IP uji (batas: 8 per 15 menit)
    $to = login($pe['phone'], false);
    ok($to === 'pengurus/login' && loggedIn() === 0, 'F: IP terkunci setelah banyak gagal -> nomor benar pun tak masuk', $to);
}
$pdo->rollBack();

// G. Gateway yang terisi tetapi tak menjawab sama dengan tidak aktif: pengurus tidak terhalang OTP.
if ($pe) {
    Whatsapp::forget(); Whatsapp::$settings = $dead;
    ok(Whatsapp::isActive() === false, 'G: gateway terisi tapi mati -> tidak aktif');
}

// Gateway lambat: batas waktu menahan pemeriksaan (diletakkan terakhir karena php -S melayani satu permintaan sekali).
selesai:
if (!empty($up)) {
    $t = microtime(true);
    $r = active($good, 'slow');
    $dt = microtime(true) - $t;
    ok($r === false && $dt < 4.5, 'tidak aktif: gateway lambat dipotong batas 3 detik', round($dt, 2) . ' dtk');
}

$out = ob_get_clean();
echo $out;
echo "\n$pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
