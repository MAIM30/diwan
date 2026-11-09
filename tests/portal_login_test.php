<?php
// PATH LOKASI FILE: tests/portal_login_test.php
// Uji langkah OTP masuk Portal Pengurus. Gateway WhatsApp aktif: kode DIKIRIM lewat WhatsApp (bukan ditampilkan), dengan jeda kirim ulang dan
// batas per IP. Gateway TIDAK aktif: pengurus diarahkan meminta kode ke admin, dan hanya kode umum (PORTAL_FALLBACK_CODE) yang berlaku --
// selama gateway memang tidak aktif. Tak ada jalan masuk tanpa kode.
//   1. Whatsapp::isActive() terhadap gateway tiruan (php -S di 127.0.0.1): aktif hanya bila terisi, menjawab, kunci benar, dan status "open".
//   2. PengurusController::login_process / otp_verify terhadap pengurus nyata di database lokal, dengan gateway tiruan yang menampung
//      pesan yang dikirim. Semua tulisan (audit, kode OTP, log WA, percobaan gagal) ada di SATU transaksi yang selalu di-rollback.
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
    public $html = '';
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0 || strpos($v, 'auth/') === 0) return;
        extract($d); ob_start(); require ROOT_DIR . "/app/Views/$v.php"; $this->html .= ob_get_clean();
    }
    public function masuk() { $this->login_process(); }
    public function verifikasi() { $this->otp_verify(); }
    public function halamanOtp() { $this->otp(); }
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
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/send') {   // pengiriman pesan: catat, lalu balas sesuai send_mode.txt
    file_put_contents(__DIR__ . '/sent.txt', json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo trim((string) @file_get_contents(__DIR__ . '/send_mode.txt')) === 'fail' ? json_encode(['ok' => false, 'error' => 'gagal kirim']) : json_encode(['ok' => true, 'id' => 'uji']);
    return;
}
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
Whatsapp::forget(); Whatsapp::$settings = $dead;   // tak ada pesan uji yang boleh sampai ke gateway sungguhan
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
function verifikasi($code, $active) {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['code' => $code]; TL::$active = $active;
    try { (new TL)->verifikasi(); return '(tanpa redirect)'; } catch (Redirected $r) { return $r->getMessage(); }
}
function halamanOtp() { $_SERVER['REQUEST_METHOD'] = 'GET'; $t = new TL; $t->halamanOtp(); return $t->html; }
function lastAudit($pid) { return (string) count_("SELECT after_data FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login' ORDER BY id DESC LIMIT 1", true); }
function count_($sql, $raw = false) { global $pdo; $v = $pdo->query($sql)->fetchColumn(); return $raw ? $v : (int) $v; }
function sentMessages() {
    global $tmp;
    $f = $tmp . DIRECTORY_SEPARATOR . 'sent.txt';
    return is_file($f) ? array_values(array_filter(array_map('json_decode', file($f, FILE_IGNORE_NEW_LINES)))) : [];
}
function setSendMode($mode) { global $tmp; file_put_contents($tmp . DIRECTORY_SEPARATOR . 'send_mode.txt', $mode); }
function latestCode($pid) { return (string) count_("SELECT code FROM otp_codes WHERE person_id = $pid ORDER BY id DESC LIMIT 1", true); }
function lastFlash() { $f = $_SESSION['_flash'] ?? []; return $f ? end($f)['message'] : ''; }
function loggedIn() { return Member::check() && !empty($_SESSION['member']['person_id']) ? (int) $_SESSION['member']['person_id'] : 0; }

if ($pe) {
    $pid = (int) $pe['id'];
    $otpBefore = count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid");
    $audBefore = count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'");

    // A. Gateway tidak aktif: tak ada jalan masuk tanpa kode. Pengurus diarahkan meminta kode ke admin; tak ada kode yang dikirim atau diterbitkan.
    $to = login($pe['phone'], false);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'A: gateway tak aktif -> halaman OTP, TIDAK langsung masuk', $to);
    ok(!empty($_SESSION['otp_pending']['via_admin']) && (int) $_SESSION['otp_pending']['person_id'] === $pid, 'A: sesi menandai "minta kode ke admin" untuk orang itu');
    ok(count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid") === $otpBefore, 'A: tak ada kode OTP yang diterbitkan');
    ok(count(sentMessages()) === 0, 'A: tak ada pesan WhatsApp yang dikirim');
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore, 'A: belum ada riwayat masuk');
    $html = halamanOtp();
    ok(strpos($html, 'https://wa.me/' . PORTAL_FALLBACK_WA . '?text=') !== false && strpos($html, 'Minta kode ke admin') !== false, 'A: halaman memuat tombol chat ke admin (wa.me ke nomor admin)');
    ok(PORTAL_FALLBACK_WA === '6285242327296', 'A: nomor admin bawaan 6285242327296');
    ok(strpos($html, 'name="code"') !== false && strpos($html, $pe['phone']) === false && strpos($html, PORTAL_FALLBACK_CODE) === false, 'A: ada kolom kode; halaman TIDAK memuat nomor orang itu maupun kode umumnya');
    preg_match('/wa\.me\/\d+\?text=([^"]*)"/', $html, $m);
    $isiChat = isset($m[1]) ? urldecode(html_entity_decode($m[1])) : '';
    ok(stripos($isiChat, 'kode OTP') !== false && strpos($isiChat, $pe['phone']) === false, 'A: isi chat meminta kode OTP dan tak membawa nomor/nama (admin membaca nomor pengirim)', $isiChat);

    // A2. Kode umum diterima SELAMA gateway tidak aktif; masuknya tercatat khusus.
    ok(PORTAL_FALLBACK_CODE === '1111', 'A2: kode umum bawaan 1111');
    $to = verifikasi('0000', false);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'A2: kode lain ditolak', $to);
    ok(stripos(lastFlash(), 'admin') !== false, 'A2: pesan salah mengarahkan ke admin', lastFlash());
    $to = verifikasi('1111', false);
    ok($to === 'pengurus' && loggedIn() === $pid && empty($_SESSION['otp_pending']), 'A2: kode umum 1111 + gateway tak aktif -> masuk', $to);
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore + 1, 'A2: riwayat masuk tercatat');
    ok(strpos(lastAudit($pid), 'kode_umum') !== false, 'A2: jejak audit menandai masuk lewat kode umum', lastAudit($pid));

    // A3. Gateway aktif kembali di tengah proses: kode umum tak berlaku lagi, dan tak ada kode yang dikirim ke orang ini.
    login($pe['phone'], false);
    $to = verifikasi('1111', true);
    ok($to === 'pengurus/login' && loggedIn() === 0 && empty($_SESSION['otp_pending']), 'A3: halaman minta-ke-admin + gateway kini aktif + 1111 -> ditolak, suruh masuk ulang', $to);
    ok(stripos(lastFlash(), 'aktif kembali') !== false, 'A3: pesan menjelaskan WhatsApp sudah aktif kembali', lastFlash());

    // A4. Masa halaman minta-ke-admin lebih panjang (admin manusia) daripada kode yang dikirim.
    login($pe['phone'], false);
    $_SESSION['otp_pending']['issued_at'] = time() - (Otp::TTL + 60);
    $to = verifikasi('1111', false);
    ok($to === 'pengurus' && loggedIn() === $pid, 'A4: minta-ke-admin masih berlaku lewat masa kode biasa (5 menit)', $to);
    login($pe['phone'], false);
    $_SESSION['otp_pending']['issued_at'] = time() - (Otp::ADMIN_TTL + 60);
    $to = verifikasi('1111', false);
    ok($to === 'pengurus/login' && loggedIn() === 0 && stripos(lastFlash(), 'kedaluwarsa') !== false, 'A4: lewat 15 menit -> kedaluwarsa', $to);
    // B. Gateway aktif: kode DIKIRIM lewat WhatsApp ke nomor orang itu; halaman OTP tidak menampilkannya.
    $audBefore = count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'");   // A2-A4 sudah menambah riwayat
    Whatsapp::$settings = $good;
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/otp', 'B: gateway aktif -> halaman OTP', $to);
    ok(loggedIn() === 0, 'B: belum masuk sebelum kode diverifikasi');
    $code = latestCode($pid);
    ok(preg_match('/^\d{4}$/', $code) === 1, 'B: satu kode 4 digit diterbitkan', $code);
    ok(!isset($_SESSION['otp_pending']['code']) && (int) $_SESSION['otp_pending']['person_id'] === $pid, 'B: sesi menyimpan orangnya, BUKAN kodenya');
    ok(count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid") === $otpBefore + 1, 'B: tepat satu kode di database');
    $sent = sentMessages();
    ok(count($sent) === 1 && Whatsapp::formatPhone($pe['phone']) === (string) ($sent[0]->target ?? ''), 'B: tepat satu pesan terkirim, ke nomor orang itu', json_encode($sent));
    ok(strpos((string) ($sent[0]->message ?? ''), "*$code*") !== false, 'B: isi pesan memuat kode yang sama dengan di database');
    $log = $pdo->query("SELECT type, status, payload FROM whatsapp_log WHERE phone = '" . Whatsapp::formatPhone($pe['phone']) . "' ORDER BY id DESC LIMIT 1")->fetch();
    ok($log && $log['type'] === 'otp' && $log['status'] === 'sent', 'B: tercatat di log WhatsApp sebagai jenis otp');
    ok($log && strpos($log['payload'], $code) === false, 'B: log WhatsApp TIDAK memuat kode');
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore, 'B: belum ada riwayat masuk baru');

    // B2. Meminta lagi dalam 60 detik tidak mengirim pesan kedua; kode yang sama tetap berlaku.
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'B2: minta lagi -> kembali ke halaman OTP', $to);
    ok(count(sentMessages()) === 1 && count_("SELECT COUNT(*) FROM otp_codes WHERE person_id = $pid") === $otpBefore + 1 && latestCode($pid) === $code,
       'B2: tak ada pesan atau kode baru dalam masa jeda');
    ok(stripos(lastFlash(), 'sudah dikirim') !== false, 'B2: pesan menjelaskan kode sudah dikirim', lastFlash());

    // B3. Kode umum TIDAK berlaku selama gateway aktif: yang diterima hanya kode yang dikirim ke WhatsApp orang itu.
    $pdo->exec("UPDATE otp_codes SET code = '4821' WHERE person_id = $pid AND verified = 0");   // pasti bukan 1111
    $code = '4821';
    $to = verifikasi(PORTAL_FALLBACK_CODE, true);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'B3: gateway aktif -> kode umum 1111 ditolak', $to);
    $to = verifikasi($code, true);
    ok($to === 'pengurus' && loggedIn() === $pid && strpos(lastAudit($pid), 'kode_umum') === false, 'B3: kode yang dikirim tetap diterima, dan tidak ditandai kode umum', $to);

    // B4. Gateway mati SESUDAH kode dikirim: kode yang sudah dikirim tetap berlaku, dan kode umum kini juga berlaku.
    $pdo->exec("DELETE FROM otp_codes WHERE person_id = $pid");
    login($pe['phone'], true);
    $pdo->exec("UPDATE otp_codes SET code = '4821' WHERE person_id = $pid AND verified = 0");
    $to = verifikasi('4821', false);
    ok($to === 'pengurus' && loggedIn() === $pid, 'B4: kode yang sudah dikirim tetap berlaku walau gateway kemudian mati', $to);
    $pdo->exec("DELETE FROM otp_codes WHERE person_id = $pid");
    login($pe['phone'], true);
    $to = verifikasi('1111', false);
    ok($to === 'pengurus' && loggedIn() === $pid && strpos(lastAudit($pid), 'kode_umum') !== false, 'B4: gateway mati sesudah kode dikirim -> kode umum juga berlaku', $to);
    $pdo->exec("DELETE FROM otp_codes WHERE person_id = $pid");
    login($pe['phone'], true);
    $code = latestCode($pid);
    $audBefore = count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'");   // B3-B4 sudah menambah riwayat
    // C. Kode yang salah ditolak; yang benar masuk dan sekali pakai.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['code' => $code === '0000' ? '0001' : '0000'];
    try { (new TL)->verifikasi(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'C: kode salah ditolak', $to);
    $_POST = ['code' => $code];
    try { (new TL)->verifikasi(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
    ok($to === 'pengurus' && loggedIn() === $pid && empty($_SESSION['otp_pending']), 'C: kode benar masuk dan OTP tertunda dibersihkan', $to);
    ok(count_("SELECT COUNT(*) FROM audit_log WHERE entity = 'people' AND entity_id = $pid AND event = 'portal_login'") === $audBefore + 1, 'C: riwayat masuk tercatat');
    $_SESSION = ['otp_pending' => ['person_id' => $pid, 'phone' => $pe['phone'], 'issued_at' => time()]]; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['code' => $code];
    try { (new TL)->verifikasi(); $to = '(tanpa redirect)'; } catch (Redirected $r) { $to = $r->getMessage(); }
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'C: kode yang sudah dipakai tak bisa dipakai lagi', $to);
    // D. OTP tertunda milik orang lain yang tertinggal di sesi tidak terbawa: digantikan oleh milik orang yang masuk sekarang.
    $_SESSION = ['otp_pending' => ['person_id' => 999999, 'phone' => '0', 'issued_at' => time()]];
    $to = attempt($pe['phone'], false);
    ok($to === 'pengurus/otp' && (int) $_SESSION['otp_pending']['person_id'] === $pid && !empty($_SESSION['otp_pending']['via_admin']), 'D: OTP tertunda lama digantikan, bukan dipakai ulang', $to);
    $to = verifikasi('1111', false);
    ok($to === 'pengurus' && loggedIn() === $pid, 'D: kode umum masuk sebagai orang yang benar (bukan milik sesi lama)', $to);
}

// E. NIK 16 digit: perilaku sama.
if ($pn) {
    $to = login($pn['nik'], false);
    ok($to === 'pengurus/otp' && loggedIn() === 0 && !empty($_SESSION['otp_pending']['via_admin']), 'E: NIK 16 digit, gateway tak aktif -> minta kode ke admin', $to);
    $html = halamanOtp();
    ok(strpos($html, $pn['nik']) === false && (empty($pn['phone']) || strpos($html, (string) $pn['phone']) === false), 'E: halaman tak membocorkan NIK maupun nomor milik orang itu');
    $to = verifikasi('1111', false);
    ok($to === 'pengurus' && loggedIn() === (int) $pn['id'], 'E: kode umum masuk sebagai pemilik NIK', $to);
    $to = login($pn['nik'], true);
    ok($to === 'pengurus/otp' && loggedIn() === 0, 'E: NIK 16 digit, gateway aktif -> OTP', $to);
}

// H. Pesan gagal terkirim: tak ada jalan masuk lewat layar; kode dibatalkan supaya tidak menahan permintaan berikutnya.
if ($pe) {
    $pdo->exec("DELETE FROM otp_codes WHERE person_id = $pid");
    $n0 = count(sentMessages());
    setSendMode('fail');
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/login' && loggedIn() === 0 && empty($_SESSION['otp_pending']), 'H: kirim gagal -> kembali ke login, tanpa halaman OTP', $to);
    ok(stripos(lastFlash(), 'gagal dikirim') !== false, 'H: pesan menjelaskan kode gagal dikirim', lastFlash());
    ok(count(sentMessages()) === $n0 + 1 && Otp::lastIssuedAgo($pid) === null, 'H: kode dibatalkan (tak ada kode berlaku yang menggantung)');
    ok(count_("SELECT COUNT(*) FROM whatsapp_log WHERE phone = '" . Whatsapp::formatPhone($pe['phone']) . "' AND type = 'otp' AND status = 'failed'") >= 1, 'H: kegagalan tercatat di log WhatsApp');
    setSendMode('ok');
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/otp', 'H: percobaan berikutnya langsung berhasil (tidak tertahan jeda)', $to);

    // J. Batas per IP: IP_MAX kode dalam jendela -> permintaan berikutnya ditolak tanpa mengirim apa pun.
    $pdo->exec("DELETE FROM otp_codes WHERE person_id = $pid");
    $ins = $pdo->prepare("INSERT INTO otp_codes (person_id, phone, code, expires_at, verified, ip) VALUES (?, '0', '0000', NOW(), 1, ?)");
    for ($i = 0; $i < Otp::IP_MAX; $i++) $ins->execute([999000 + $i, Text::clientIp()]);
    $n0 = count(sentMessages());
    $to = login($pe['phone'], true);
    ok($to === 'pengurus/login' && loggedIn() === 0 && count(sentMessages()) === $n0, 'J: IP kebanyakan meminta kode -> ditolak, tak ada pesan terkirim', $to);
    ok(stripos(lastFlash(), 'terlalu banyak') !== false, 'J: pesan menjelaskan batasnya', lastFlash());
    $pdo->exec("DELETE FROM otp_codes WHERE person_id >= 999000");
}

// F. Yang tak berhak tetap ditolak sebelum langkah kode, apa pun keadaan gateway.
$to = login('08999999999', false);
ok($to === 'daftar' && loggedIn() === 0 && !count_("SELECT COUNT(*) FROM people WHERE phone IN ('08999999999','628999999999')"), 'F: nomor tak terdaftar -> daftar, tidak masuk', $to);
if ($pi) {
    $to = login($pi['phone'], false);
    ok($to === 'daftar' && loggedIn() === 0, 'F: akses portal dicabut -> ditolak (tak sampai ke halaman kode)', $to);
}
if ($ps) {
    $to = login($ps, false);
    ok($to === 'daftar' && loggedIn() === 0, 'F: nomor dipakai dua orang -> tak bisa masuk (tak sampai ke halaman kode)', $to);
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

// G. Gateway yang terisi tetapi tak menjawab sama dengan tidak aktif: pengurus diarahkan meminta kode ke admin.
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
