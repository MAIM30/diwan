<?php
// PATH LOKASI FILE: database/migrate.php
// Menerapkan migrasi bernomor (003 dst.) pada basis data yang SUDAH terpasang, CLI:
//
//   php database/migrate.php --db=db_pengurus
//
// Semua migrasi IDEMPOTEN (memeriksa dulu apakah kolomnya sudah ada), jadi aman dijalankan kapan saja dan berulang --
// jalankan setiap kali kode aplikasi diperbarui. Tidak menyentuh data, hanya menambah kolom bila belum ada.
// (install.php tetap untuk pemasangan BARU: ia juga membuat struktur akar dan akun Super Admin pertama.)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::']);
$host = $opts['host'] ?? 'localhost'; $user = $opts['user'] ?? 'root'; $pass = $opts['pass'] ?? '';
$dbName = $opts['db'] ?? 'db_pengurus';
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) { fwrite(STDERR, "Nama database hanya boleh huruf, angka, dan garis bawah.\n"); exit(1); }

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { fwrite(STDERR, 'Tidak bisa terhubung ke basis data: ' . $e->getMessage() . "\n"); exit(1); }

if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'people'")->fetchColumn()) {
    fwrite(STDERR, "Skema dasar belum terpasang di `$dbName`. Jalankan database/install.php dulu.\n"); exit(1);
}

$say = function ($m) { echo $m . "\n"; };
foreach ([['003_pisah_nama.php', 'migrate_003_pisah_nama'], ['004_portal_pengurus.php', 'migrate_004_portal_pengurus'],
          ['005_data_diri.php', 'migrate_005_data_diri'], ['006_alamat_dan_sandi.php', 'migrate_006_alamat_dan_sandi'],
          ['007_inti_wakil_ketua.php', 'migrate_007_inti_wakil_ketua'],
          ['008_whatsapp_kirimdev.php', 'migrate_008_whatsapp_kirimdev']] as [$file, $fn]) {
    require_once __DIR__ . '/' . $file;
    $fn($pdo, $say);
}
echo "Selesai.\n";
