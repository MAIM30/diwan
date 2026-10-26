<?php
// PATH LOKASI FILE: database/reset_password.php
// Mengganti kata sandi sebuah akun admin dari CLI -- untuk memutar kata sandi (mis. setelah kredensial
// terlanjur tercetak di tempat yang tak aman) atau memulihkan akses bila kata sandi lupa.
//
//   php database/reset_password.php --db=db_pengurus --phone=08123456789 [--password='...']
//
// Tanpa --password dibuatkan kata sandi acak 16 karakter dan dicetak SEKALI. Sekaligus membuka kuncian
// percobaan masuk akun itu (login_attempts). Hanya hash yang disimpan di basis data.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/AdminNaming.php';

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'phone:', 'password::']);
$host = $opts['host'] ?? 'localhost'; $user = $opts['user'] ?? 'root'; $pass = $opts['pass'] ?? '';
$dbName = $opts['db'] ?? 'db_pengurus';
function fail($m) { fwrite(STDERR, "BATAL: $m\n"); exit(1); }
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) fail('Nama database hanya boleh huruf, angka, dan garis bawah.');
$phone = isset($opts['phone']) ? Text::normalizePhone($opts['phone']) : null;
if ($phone === null) fail('--phone wajib diisi dengan nomor WA akun yang valid (mis. 08123456789).');

$generated = empty($opts['password']);
$password = $generated ? AdminNaming::randomPassword() : (string) $opts['password'];
if (strlen($password) < 10) fail('Kata sandi minimal 10 karakter.');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { fail('Tidak bisa terhubung ke basis data: ' . $e->getMessage()); }

$acc = $pdo->prepare("SELECT id, display_name FROM accounts WHERE phone = ?");
$acc->execute([$phone]);
$row = $acc->fetch();
if (!$row) fail("Tidak ada akun dengan nomor $phone.");

$pdo->prepare("UPDATE accounts SET password_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
$pdo->prepare("DELETE FROM login_attempts WHERE phone = ?")->execute([$phone]);
$pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, after_data) VALUES ('accounts', ?, 'update', ?)")
    ->execute([$row['id'], json_encode(['event' => 'reset_password'], JSON_UNESCAPED_UNICODE)]);

echo "Kata sandi akun #{$row['id']} ({$row['display_name']}) diganti.\n";
echo "  Username (Nomor WA): $phone\n";
echo $generated ? "  Kata sandi baru    : $password   (tampil SEKALI ini)\n" : "  Kata sandi baru    : sesuai --password\n";
