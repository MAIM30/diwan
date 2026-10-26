<?php
// PATH LOKASI FILE: database/provision_admins.php
// Menyediakan SATU akun admin untuk tiap struktur (Pusat, Wilayah, Daerah, Cabang, Ranting) yang belum
// punya, CLI:
//
//   php database/provision_admins.php --db=db_pengurus [--levels=DPP,DPW,DPD] [--out=kredensial.csv] [--commit]
//
// Tanpa --commit hanya menampilkan apa yang AKAN dibuat (tidak ada yang ditulis, tidak ada kata sandi
// yang diterbitkan). Idempoten: struktur yang sudah punya admin_struktur berlingkup TEPAT dirinya dilewati,
// jadi dijalankan lagi saat ada struktur baru (mis. DPC/DPRa) hanya mengisi yang kosong.
//
// Yang dibuat per struktur: akun + SATU peran admin_struktur berlingkup struktur itu. Lingkup sebuah peran
// otomatis mencakup seluruh keturunannya (AdminScope), dan tidak pernah menyeberang cabang: admin DPW tidak
// menyentuh MWW. Tidak ada peran tanpa batas lingkup dan tidak ada super_admin.
//
// Kredensial: nomor pengganti (lihat AdminNaming) + kata sandi acak 16 karakter. Kata sandi HANYA dicetak
// sekali di sini (di basis data hanya hash-nya); simpan --out bila perlu, lalu hapus berkasnya setelah
// dibagikan. Ganti nomor pengganti dengan nomor WA admin sebenarnya lewat menu Akun > Ubah.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/AdminNaming.php';

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'levels::', 'out::', 'commit']);
$host = $opts['host'] ?? 'localhost'; $user = $opts['user'] ?? 'root'; $pass = $opts['pass'] ?? '';
$dbName = $opts['db'] ?? 'db_pengurus';
$commit = isset($opts['commit']);
$levels = !empty($opts['levels']) ? array_filter(array_map('trim', explode(',', $opts['levels']))) : null;
$out = $opts['out'] ?? null;

function fail($m) { fwrite(STDERR, "BATAL: $m\n"); exit(1); }
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) fail('Nama database hanya boleh huruf, angka, dan garis bawah.');
// Kata sandi yang sudah terbit TIDAK bisa dipulihkan (di basis data hanya hash). Berkas keluaran dari jalan
// sebelumnya karena itu tidak pernah ditimpa -- diperiksa SEBELUM ada akun yang dibuat.
if ($out !== null && file_exists($out)) fail("Berkas $out sudah ada dan tidak akan ditimpa (isinya mungkin satu-satunya salinan kata sandi). Pakai nama lain atau hapus dulu bila sudah dibagikan.");
$all = ['DPP', 'DPW', 'DPD', 'DPC', 'DPRa', 'MWP', 'MWW', 'MWD', 'MWC'];
if ($levels !== null) foreach ($levels as $l) if (!in_array($l, $all, true)) fail("Tingkat tidak dikenal: $l (pilihan: " . implode(', ', $all) . ').');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) { fail('Tidak bisa terhubung ke basis data: ' . $e->getMessage()); }

$structures = $pdo->query("
    SELECT s.id, s.level, s.name, s.tier, s.path,
           (SELECT COUNT(*) FROM account_roles ar WHERE ar.role = 'admin_struktur' AND ar.scope_structure_id = s.id) AS admins
    FROM structures s WHERE s.deleted_at IS NULL AND s.is_active = 1 ORDER BY s.path
")->fetchAll();

$todo = []; $skipped = 0;
foreach ($structures as $s) {
    if ($levels !== null && !in_array($s['level'], $levels, true)) continue;
    if ((int) $s['admins'] > 0) { $skipped++; continue; }
    $todo[] = $s;
}

$tier = [1 => 'Pusat', 2 => 'Wilayah', 3 => 'Daerah', 4 => 'Cabang', 5 => 'Ranting'];
echo 'Struktur aktif: ' . count($structures) . ' | sudah punya admin (dilewati): ' . $skipped . ' | akan dibuat: ' . count($todo) . "\n";
if (!$todo) { echo "Tidak ada yang perlu dibuat.\n"; exit(0); }

$pdo->beginTransaction();
$rows = [];
try {
    $insAcc = $pdo->prepare("INSERT INTO accounts (uuid, phone, password_hash, display_name, status) VALUES (?,?,?,?, 'active')");
    $insRole = $pdo->prepare("INSERT INTO account_roles (account_id, role, scope_structure_id) VALUES (?, 'admin_struktur', ?)");
    $insAud = $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, after_data, structure_id) VALUES ('accounts', ?, 'create', ?, ?)");
    $chk = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE phone = ?");
    foreach ($todo as $s) {
        $phone = AdminNaming::placeholderPhone($s['id']);
        $chk->execute([$phone]);
        if ((int) $chk->fetchColumn()) { echo "  ! dilewati {$s['level']} {$s['name']}: nomor pengganti $phone sudah dipakai akun lain\n"; continue; }
        $pw = AdminNaming::randomPassword();
        $name = AdminNaming::displayName($s['level'], $s['name']);
        $insAcc->execute([Uuid::v4(), $phone, password_hash($pw, PASSWORD_DEFAULT), $name]);
        $aid = (int) $pdo->lastInsertId();
        $insRole->execute([$aid, $s['id']]);
        $insAud->execute([$aid, json_encode(['display_name' => $name, 'role' => 'admin_struktur', 'scope_structure_id' => (int) $s['id'], 'event' => 'provision_admin'], JSON_UNESCAPED_UNICODE), $s['id']]);
        $rows[] = [$tier[(int) $s['tier']] ?? '-', $s['level'], $s['name'], AdminNaming::typedUsername($s['id']), $pw];
    }
    if ($commit) $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Gagal, semua perubahan dibatalkan: ' . $e->getMessage());
}

if (!$commit) {
    echo "\nDRY-RUN: tidak ada yang disimpan. Yang akan dibuat:\n";
    foreach ($todo as $s) echo sprintf("  %-8s %-5s %-50s username %s\n", $tier[(int) $s['tier']] ?? '-', $s['level'], mb_substr($s['name'], 0, 48), AdminNaming::typedUsername($s['id']));
    echo "\nUlangi dengan --commit untuk membuat akunnya dan menerbitkan kata sandi.\n";
    exit(0);
}

echo "\nDIBUAT " . count($rows) . " akun. Kata sandi hanya tampil SEKALI ini:\n\n";
echo sprintf("%-8s %-5s %-46s %-12s %s\n", 'Tingkat', 'Kode', 'Struktur', 'Username*', 'Kata sandi');
foreach ($rows as $r) echo sprintf("%-8s %-5s %-46s %-12s %s\n", $r[0], $r[1], mb_substr($r[2], 0, 44), $r[3], $r[4]);
echo "\n* ketik di kolom \"Nomor WhatsApp\" halaman Masuk Admin. Ganti dengan nomor WA admin sebenarnya lewat menu Akun > Ubah.\n";
if ($out) {
    $fh = fopen($out, 'w');
    fputcsv($fh, ['tingkat', 'kode', 'struktur', 'username', 'kata_sandi']);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
    echo "Kredensial disimpan di: $out  (hapus setelah dibagikan)\n";
}
