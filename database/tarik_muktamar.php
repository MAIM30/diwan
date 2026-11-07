<?php
// database/tarik_muktamar.php
// Tarik semua data pengurus dari database Muktamar ke DIWAN.
//
// Dua tahap:
//   1. IMPOR  – orang dari muktamar_pengurus + muktamar_peserta yang belum ada di DIWAN
//   2. LENGKAPI – isi field kosong di orang DIWAN yang sudah ada dengan data Muktamar
//              (NIK, tanggal lahir, tempat lahir, email)
//
// Pencocokan: nomor HP (E.164) → NIK (16 digit) → nama (name_normalized).
//
// CLI:
//   php database/tarik_muktamar.php --muktamar=zz_muktamar_cek [--commit] [--verbose]
//
// Tanpa --commit: dry-run, hanya laporan.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';

$opts = getopt('', ['muktamar::', 'diwan::', 'host::', 'user::', 'pass::', 'commit', 'verbose']);
$host    = $opts['host'] ?? 'localhost';
$user    = $opts['user'] ?? 'root';
$pass    = $opts['pass'] ?? '';
$dbMkt   = $opts['muktamar'] ?? 'zz_muktamar_cek';
$dbDiwan = $opts['diwan'] ?? 'db_pengurus';
$commit  = isset($opts['commit']);
$verbose = isset($opts['verbose']);

$pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

$stats = [
    'imported' => 0,
    'enriched_nik' => 0,
    'enriched_birth_date' => 0,
    'enriched_birth_place' => 0,
    'enriched_email' => 0,
    'enriched_title_prefix' => 0,
    'enriched_title_suffix' => 0,
    'skipped_duplicate' => 0,
    'skipped_no_name' => 0,
];

$pdo->beginTransaction();

try {
    // ── Tahap 1: IMPOR orang baru ──────────────────────────────────────
    echo "=== TAHAP 1: Impor orang baru ===\n\n";

    // 1a. Dari muktamar_pengurus (deduplicated by uuid)
    $sql = "
        SELECT mp.uuid as mkt_uuid,
               MIN(mp.nama) as nama, MIN(mp.hp) as hp, MIN(mp.nik) as nik,
               MIN(mp.gelar_depan) as gelar_depan, MIN(mp.gelar_belakang) as gelar_belakang,
               MIN(mp.jk) as jk, MIN(mp.tanggal_lahir) as tanggal_lahir
        FROM `$dbMkt`.muktamar_pengurus mp
        WHERE mp.uuid IS NOT NULL
        GROUP BY mp.uuid
        HAVING NOT EXISTS (
            SELECT 1 FROM `$dbDiwan`.people dp
            WHERE dp.deleted_at IS NULL
            AND (
                (dp.phone IS NOT NULL AND dp.phone != '' AND MIN(mp.hp) IS NOT NULL AND MIN(mp.hp) != '' AND dp.phone = MIN(mp.hp))
                OR (dp.nik IS NOT NULL AND LENGTH(dp.nik) = 16 AND MIN(mp.nik) IS NOT NULL AND LENGTH(MIN(mp.nik)) = 16 AND dp.nik = MIN(mp.nik))
                OR (dp.name_normalized = UPPER(TRIM(MIN(mp.nama))))
            )
        )
    ";
    $pengurus = $pdo->query($sql)->fetchAll();
    echo "  Ditemukan " . count($pengurus) . " orang dari muktamar_pengurus yang belum ada di DIWAN\n";

    // 1b. From muktamar_peserta (peserta-only, not in pengurus, not in DIWAN)
    $sql2 = "
        SELECT ps.nama, ps.gelar_depan, ps.gelar_belakang,
               ps.tanggal_lahir, ps.tempat_lahir, ps.nik as nik_encrypted,
               u.phone as hp, u.email, u.jenis_kelamin
        FROM `$dbMkt`.muktamar_peserta ps
        LEFT JOIN `$dbMkt`.muktamar_pengurus mp ON ps.uuid_sdm = mp.uuid
        JOIN `$dbMkt`.muktamar_users u ON ps.user_id = u.id
        WHERE mp.uuid IS NULL
          AND ps.kategori IN ('PESERTA_PENUH', 'PESERTA_PENINJAU')
        AND NOT EXISTS (
            SELECT 1 FROM `$dbDiwan`.people dp
            WHERE dp.deleted_at IS NULL
            AND (
                (dp.phone IS NOT NULL AND dp.phone != '' AND u.phone IS NOT NULL AND u.phone != '' AND dp.phone = u.phone)
                OR (dp.name_normalized = UPPER(TRIM(ps.nama)))
            )
        )
    ";
    $pesertaOnly = $pdo->query($sql2)->fetchAll();
    echo "  Ditemukan " . count($pesertaOnly) . " peserta penuh/peninjau yang belum ada\n\n";

    $insertStmt = $pdo->prepare("
        INSERT INTO `$dbDiwan`.people
            (uuid, title_prefix, name, title_suffix, name_normalized, nik, gender, phone, birth_date, birth_place, email, notes, created_at)
        VALUES
            (:uuid, :prefix, :name, :suffix, :normalized, :nik, :gender, :phone, :birth_date, :birth_place, :email, :notes, NOW(3))
    ");

    foreach ($pengurus as $row) {
        $nama = trim($row['nama'] ?? '');
        if ($nama === '') { $stats['skipped_no_name']++; continue; }

        $parts = Text::splitName(
            trim(($row['gelar_depan'] ?? '') . ' ' . $nama . ($row['gelar_belakang'] ? ', ' . $row['gelar_belakang'] : ''))
        );

        $phone = Text::normalizePhone($row['hp']);
        $nik   = Text::normalizeNik($row['nik']);
        $gender = strtoupper(trim($row['jk'] ?? ''));
        if (!in_array($gender, ['L', 'P'])) $gender = 'L';

        $normalized = strtoupper(Text::normalizeBase($parts['name']));

        $insertStmt->execute([
            'uuid'        => Uuid::v4(),
            'prefix'      => $parts['title_prefix'],
            'name'        => $parts['name'],
            'suffix'      => $parts['title_suffix'],
            'normalized'  => $normalized,
            'nik'         => $nik,
            'gender'      => $gender,
            'phone'       => $phone,
            'birth_date'  => $row['tanggal_lahir'],
            'birth_place' => null,
            'email'       => null,
            'notes'       => 'Impor dari database Muktamar (muktamar_pengurus)',
        ]);
        $stats['imported']++;
        if ($verbose) echo "    + $nama" . ($phone ? " ($phone)" : "") . "\n";
    }

    foreach ($pesertaOnly as $row) {
        $nama = trim($row['nama'] ?? '');
        if ($nama === '') { $stats['skipped_no_name']++; continue; }

        $parts = Text::splitName(
            trim(($row['gelar_depan'] ?? '') . ' ' . $nama . ($row['gelar_belakang'] ? ', ' . $row['gelar_belakang'] : ''))
        );

        $phone = Text::normalizePhone($row['hp']);
        $gender = ($row['jenis_kelamin'] ?? '') === 'AKHWAT' ? 'P' : 'L';
        $email  = ($row['email'] ?? '');
        if (str_contains($email, '@muktamar.local')) $email = null;

        $normalized = strtoupper(Text::normalizeBase($parts['name']));

        $insertStmt->execute([
            'uuid'        => Uuid::v4(),
            'prefix'      => $parts['title_prefix'],
            'name'        => $parts['name'],
            'suffix'      => $parts['title_suffix'],
            'normalized'  => $normalized,
            'nik'         => null,
            'gender'      => $gender,
            'phone'       => $phone,
            'birth_date'  => $row['tanggal_lahir'],
            'birth_place' => $row['tempat_lahir'],
            'email'       => $email ?: null,
            'notes'       => 'Impor dari database Muktamar (muktamar_peserta)',
        ]);
        $stats['imported']++;
        if ($verbose) echo "    + $nama [peserta]\n";
    }

    echo "  Diimpor: {$stats['imported']} orang\n\n";

    // ── Tahap 2: LENGKAPI data yang sudah ada ──────────────────────────
    echo "=== TAHAP 2: Lengkapi data orang yang sudah ada ===\n\n";

    // 2a. NIK (dari muktamar_pengurus, cocokkan lewat HP, skip NIK yang sudah dipakai orang lain)
    $enrichNik = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        LEFT JOIN `$dbDiwan`.people dp2
            ON dp2.nik = mp.nik AND dp2.deleted_at IS NULL AND dp2.id != dp.id
        SET dp.nik = mp.nik
        WHERE dp.deleted_at IS NULL
          AND (dp.nik IS NULL OR dp.nik = '')
          AND mp.nik IS NOT NULL AND LENGTH(mp.nik) = 16
          AND dp2.id IS NULL
    ");
    $enrichNik->execute();
    $stats['enriched_nik'] = $enrichNik->rowCount();
    echo "  NIK dilengkapi: {$stats['enriched_nik']}\n";

    // 2b. Tanggal lahir (dari muktamar_pengurus, cocokkan lewat HP)
    $enrichBirth = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        SET dp.birth_date = mp.tanggal_lahir
        WHERE dp.deleted_at IS NULL
          AND dp.birth_date IS NULL
          AND mp.tanggal_lahir IS NOT NULL
    ");
    $enrichBirth->execute();
    $stats['enriched_birth_date'] = $enrichBirth->rowCount();
    echo "  Tanggal lahir dilengkapi: {$stats['enriched_birth_date']}\n";

    // 2c. Tempat lahir (dari muktamar_peserta via muktamar_pengurus UUID, cocokkan lewat HP)
    $enrichPlace = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        JOIN `$dbMkt`.muktamar_peserta ps ON ps.uuid_sdm = mp.uuid
        SET dp.birth_place = ps.tempat_lahir
        WHERE dp.deleted_at IS NULL
          AND (dp.birth_place IS NULL OR dp.birth_place = '')
          AND ps.tempat_lahir IS NOT NULL AND ps.tempat_lahir != ''
    ");
    $enrichPlace->execute();
    $stats['enriched_birth_place'] = $enrichPlace->rowCount();
    echo "  Tempat lahir dilengkapi: {$stats['enriched_birth_place']}\n";

    // 2d. Email (dari muktamar_users, hanya email asli, cocokkan lewat HP via muktamar_pengurus)
    $enrichEmail = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        JOIN `$dbMkt`.muktamar_users u ON u.uuid_sdm = mp.uuid
        SET dp.email = u.email
        WHERE dp.deleted_at IS NULL
          AND (dp.email IS NULL OR dp.email = '')
          AND u.email IS NOT NULL AND u.email != ''
          AND u.email NOT LIKE '%@muktamar.local'
    ");
    $enrichEmail->execute();
    $stats['enriched_email'] = $enrichEmail->rowCount();
    echo "  Email dilengkapi: {$stats['enriched_email']}\n";

    // 2e. Gelar depan (dari muktamar_pengurus, hanya isi yang kosong)
    $enrichPrefix = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        SET dp.title_prefix = mp.gelar_depan
        WHERE dp.deleted_at IS NULL
          AND (dp.title_prefix IS NULL OR dp.title_prefix = '')
          AND mp.gelar_depan IS NOT NULL AND mp.gelar_depan != ''
    ");
    $enrichPrefix->execute();
    $stats['enriched_title_prefix'] = $enrichPrefix->rowCount();
    echo "  Gelar depan dilengkapi: {$stats['enriched_title_prefix']}\n";

    // 2f. Gelar belakang (dari muktamar_pengurus, hanya isi yang kosong)
    $enrichSuffix = $pdo->prepare("
        UPDATE `$dbDiwan`.people dp
        JOIN `$dbMkt`.muktamar_pengurus mp ON dp.phone = mp.hp
        SET dp.title_suffix = mp.gelar_belakang
        WHERE dp.deleted_at IS NULL
          AND (dp.title_suffix IS NULL OR dp.title_suffix = '')
          AND mp.gelar_belakang IS NOT NULL AND mp.gelar_belakang != ''
    ");
    $enrichSuffix->execute();
    $stats['enriched_title_suffix'] = $enrichSuffix->rowCount();
    echo "  Gelar belakang dilengkapi: {$stats['enriched_title_suffix']}\n";

    echo "\n=== RINGKASAN ===\n";
    echo "  Orang baru diimpor     : {$stats['imported']}\n";
    echo "  NIK dilengkapi         : {$stats['enriched_nik']}\n";
    echo "  Tanggal lahir          : {$stats['enriched_birth_date']}\n";
    echo "  Tempat lahir           : {$stats['enriched_birth_place']}\n";
    echo "  Email                  : {$stats['enriched_email']}\n";
    echo "  Gelar depan            : {$stats['enriched_title_prefix']}\n";
    echo "  Gelar belakang         : {$stats['enriched_title_suffix']}\n";
    echo "  Dilewati (tanpa nama)  : {$stats['skipped_no_name']}\n";

    if ($commit) {
        $pdo->commit();
        echo "\n>>> COMMIT: semua perubahan tersimpan.\n";
    } else {
        $pdo->rollBack();
        echo "\n>>> DRY-RUN: semua di-rollback. Tambahkan --commit untuk menyimpan.\n";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n!!! ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
