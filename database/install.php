<?php
// PATH LOKASI FILE: database/install.php
// Pemasangan awal, dijalankan dari CLI:
//
//   php database/install.php --db=db_pengurus --admin-phone=08123456789 --admin-password=... [--fresh]
//
// Yang dilakukan:
//   1. Membuat database (bila belum ada) dan menerapkan 001_schema.sql + 002_seed_positions.sql
//   2. Membuat struktur akar DPP + MWP sebagai cabang Muslimah di bawahnya
//   3. Membuat satu akun Super Admin pertama
//
// --fresh MENGHAPUS database lalu membuatnya ulang. Hanya untuk pengembangan lokal.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'admin-phone::', 'admin-password::', 'admin-name::', 'fresh']);

$host = $opts['host'] ?? 'localhost';
$user = $opts['user'] ?? 'root';
$pass = $opts['pass'] ?? '';
$dbName = $opts['db'] ?? 'db_pengurus';
$fresh = isset($opts['fresh']);

$adminPhone = $opts['admin-phone'] ?? null;
$adminPassword = $opts['admin-password'] ?? null;
$adminName = $opts['admin-name'] ?? 'Super Admin';

// Nama database masuk ke SQL sebagai identifier (tidak bisa di-bind sebagai parameter), jadi
// bentuknya dibatasi ketat di sini alih-alih dipercaya apa adanya.
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "Nama database hanya boleh huruf, angka, dan garis bawah.\n");
    exit(1);
}

function say($msg) { echo $msg . "\n"; }

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Tidak bisa terhubung ke MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

if ($fresh) {
    say("Menghapus database `$dbName` (--fresh)...");
    $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbName`");
say("Database `$dbName` siap.");

// Sudah terpasang? Jangan menerapkan skema dua kali.
$already = $pdo->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'placements'
")->fetchColumn();

if (!$already) {
    foreach (['001_schema.sql', '002_seed_positions.sql'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (!is_file($path)) {
            fwrite(STDERR, "Berkas $file tidak ditemukan.\n");
            exit(1);
        }
        say("Menerapkan $file...");
        // Skema ini tidak memakai trigger/prosedur, jadi tidak ada ';' di dalam blok kode --
        // menjalankan isinya sebagai satu batch sudah cukup dan menghindari pemecahan statement
        // yang rapuh.
        $pdo->exec(file_get_contents($path));
    }
    say("Skema dan katalog jabatan terpasang.");
} else {
    say("Skema sudah ada, dilewati.");
}

// Migrasi bernomor berikutnya. Idempoten, jadi dijalankan di setiap pemasangan: basis data baru
// (001 lalu 003) dan yang sudah berisi data (hanya yang belum diterapkan) berujung pada skema sama.
require_once __DIR__ . '/003_pisah_nama.php';
migrate_003_pisah_nama($pdo, 'say');
require_once __DIR__ . '/004_portal_pengurus.php';
migrate_004_portal_pengurus($pdo, 'say');
require_once __DIR__ . '/005_data_diri.php';
migrate_005_data_diri($pdo, 'say');
require_once __DIR__ . '/006_alamat_dan_sandi.php';
migrate_006_alamat_dan_sandi($pdo, 'say');
require_once __DIR__ . '/007_inti_wakil_ketua.php';
migrate_007_inti_wakil_ketua($pdo, 'say');
require_once __DIR__ . '/008_whatsapp_kirimdev.php';
migrate_008_whatsapp_kirimdev($pdo, 'say');
require_once __DIR__ . '/010_biodata_submissions.php';
migrate_010_biodata_submissions($pdo, 'say');
require_once __DIR__ . '/011_biodata_foto_drive.php';
migrate_011_biodata_foto_drive($pdo, 'say');

// ---------- Struktur akar ----------
// DPP dibuat sebagai akar, MWP sebagai anaknya. Inilah yang membuat lingkup "Nasional" (akar)
// otomatis mencakup sayap Muslimah, sementara lingkup DPW tetap tidak menyeberang ke MWW.

function uuidV4() {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function ensureStructure(PDO $pdo, $level, $name, $parentId) {
    $stmt = $pdo->prepare("SELECT id, path FROM structures WHERE level = :lv AND name = :nm LIMIT 1");
    $stmt->execute([':lv' => $level, ':nm' => $name]);
    if ($row = $stmt->fetch()) {
        return (int) $row['id'];
    }

    $ins = $pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id) VALUES (:u, :lv, :nm, :pid)");
    $ins->execute([':u' => uuidV4(), ':lv' => $level, ':nm' => $name, ':pid' => $parentId]);
    $id = (int) $pdo->lastInsertId();

    // path hanya bisa dihitung setelah id terbit (lihat StructureModel::writePath()).
    $parentPath = '/';
    $depth = 0;
    if ($parentId !== null) {
        $p = $pdo->prepare("SELECT path, depth FROM structures WHERE id = :id");
        $p->execute([':id' => $parentId]);
        if ($prow = $p->fetch()) {
            $parentPath = $prow['path'] !== '' ? $prow['path'] : '/';
            $depth = (int) $prow['depth'] + 1;
        }
    }
    $pdo->prepare("UPDATE structures SET path = :p, depth = :d WHERE id = :id")
        ->execute([':p' => $parentPath . $id . '/', ':d' => $depth, ':id' => $id]);

    return $id;
}

$dppId = ensureStructure($pdo, 'DPP', 'Wahdah Islamiyah', null);
$mwpId = ensureStructure($pdo, 'MWP', 'Muslimah Wahdah Islamiyah Pusat', $dppId);
say("Struktur akar siap: DPP #$dppId, MWP #$mwpId (anak DPP).");

// ---------- Akun Super Admin ----------

if ($adminPhone === null || $adminPassword === null) {
    say("");
    say("Akun Super Admin BELUM dibuat. Jalankan ulang dengan:");
    say("  --admin-phone=08xxxxxxxxxx --admin-password='kata sandi minimal 10 karakter'");
    exit(0);
}

$phone = preg_replace('/\D+/', '', $adminPhone);
if (strpos($phone, '0') === 0)       $phone = '62' . substr($phone, 1);
elseif (strpos($phone, '62') !== 0)  $phone = '62' . $phone;

if (strlen($adminPassword) < 10) {
    fwrite(STDERR, "Kata sandi Super Admin minimal 10 karakter.\n");
    exit(1);
}

$exists = $pdo->prepare("SELECT id FROM accounts WHERE phone = :p LIMIT 1");
$exists->execute([':p' => $phone]);

if ($accountId = $exists->fetchColumn()) {
    say("Akun dengan nomor $phone sudah ada (#$accountId), kata sandinya tidak diubah.");
} else {
    $ins = $pdo->prepare("
        INSERT INTO accounts (uuid, phone, password_hash, display_name, status)
        VALUES (:u, :p, :pw, :n, 'active')
    ");
    $ins->execute([
        ':u'  => uuidV4(),
        ':p'  => $phone,
        ':pw' => password_hash($adminPassword, PASSWORD_DEFAULT),
        ':n'  => $adminName,
    ]);
    $accountId = (int) $pdo->lastInsertId();
    say("Akun Super Admin dibuat: #$accountId ($phone).");
}

// Lingkup NULL = seluruh sistem. Hanya super_admin yang boleh begitu.
$role = $pdo->prepare("
    INSERT IGNORE INTO account_roles (account_id, role, scope_structure_id)
    VALUES (:acc, 'super_admin', NULL)
");
$role->execute([':acc' => $accountId]);

say("");
say("Selesai. Masuk di " . ($opts['baseurl'] ?? 'http://localhost/pengurus') . "/auth/login");
say("  Nomor    : $phone");
say("  Kata sandi: (yang Anda masukkan di argumen --admin-password)");
