<?php
// PATH LOKASI FILE: database/023_struktur_mwln.php
// Migrasi 023: tingkat MWLN (Muslimah Wahdah Islamiyah Luar Negeri), padanan Muslimah dari DPLN: setingkat MWW, di bawah MWP.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN. Hanya DEFINISI KOLOM structures yang berubah, tidak ada baris:
//   level  ENUM mendapat 'MWLN' di ujung (nilai lama dipertahankan apa adanya)
//   wing   turunan: MWLN = 'muslimah' (jadi memakai katalog unit Muslimah dan tak bisa dipasangi unit sayap Umum)
//   tier   turunan: MWLN = 2 (setingkat MWW dan DPLN)
//
// Berjalan SETELAH 015 dan 017, yang pada setiap migrate.php menyelaraskan ulang wing/tier tanpa MWLN; migrasi ini memulihkannya di
// akhir, dan karena itu selalu memeriksa dulu apakah ketiganya memang sudah benar.

function migrate_023_struktur_mwln(PDO $pdo, callable $say) {
    $say('Migrasi 023: menambahkan tingkat MWLN...');

    $col = $pdo->prepare("SELECT COLUMN_TYPE, GENERATION_EXPRESSION FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'structures' AND column_name = :c");
    $read = function ($c) use ($col) { $col->execute([':c' => $c]); return $col->fetch(PDO::FETCH_ASSOC) ?: ['COLUMN_TYPE' => '', 'GENERATION_EXPRESSION' => '']; };

    $enum = (string) $read('level')['COLUMN_TYPE'];
    // Sejak migrasi 027 structures.level bukan ENUM lagi dan MWLN hanyalah satu baris di structure_levels: tak ada yang perlu diselaraskan.
    if (stripos($enum, 'enum(') !== 0) {
        $say('  structures.level sudah bukan ENUM (migrasi 027), dilewati.');
        $say('Migrasi 023 selesai.');
        return;
    }
    $okEnum = strpos($enum, "'MWLN'") !== false;
    $okWing = strpos((string) $read('wing')['GENERATION_EXPRESSION'], 'MWLN') !== false;
    $okTier = strpos((string) $read('tier')['GENERATION_EXPRESSION'], 'MWLN') !== false;
    if ($okEnum && $okWing && $okTier) {
        $say('  structures.level, wing, dan tier sudah mengenal MWLN, dilewati.');
        $say('Migrasi 023 selesai.');
        return;
    }

    // DSO/DSY/DPK (migrasi 017) hanya ikut di rumus tier bila memang ada di ENUM.
    $dewan = strpos($enum, "'DSO'") !== false ? " WHEN 'DSO' THEN 1 WHEN 'DSY' THEN 1 WHEN 'DPK' THEN 1" : '';
    $newEnum = $okEnum ? $enum : preg_replace('/\)$/', ",'MWLN')", $enum);

    // idx_structures_region memakai kolom turunan wing dan tier: dilepas selama kolomnya diubah (seperti di migrasi 017), lalu dipasang lagi.
    $idx = "SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'structures' AND index_name = 'idx_structures_region'";
    $hadIdx = (int) $pdo->query($idx)->fetchColumn() > 0;
    if ($hadIdx) $pdo->exec('ALTER TABLE structures DROP INDEX idx_structures_region');

    try {
        $pdo->exec("ALTER TABLE structures
            MODIFY level $newEnum NOT NULL,
            MODIFY wing ENUM('umum','muslimah')
                GENERATED ALWAYS AS (IF(level IN ('MWP','MWW','MWLN','MWD','MWC','MWRa'),'muslimah','umum')) STORED,
            MODIFY tier TINYINT UNSIGNED
                GENERATED ALWAYS AS (CASE level
                    WHEN 'DPP' THEN 1 WHEN 'MWP' THEN 1$dewan
                    WHEN 'DPW' THEN 2 WHEN 'DPLN' THEN 2 WHEN 'MWW' THEN 2 WHEN 'MWLN' THEN 2
                    WHEN 'DPD' THEN 3 WHEN 'MWD' THEN 3
                    WHEN 'DPC' THEN 4 WHEN 'MWC' THEN 4
                    WHEN 'DPRa' THEN 5 WHEN 'MWRa' THEN 5
                END) STORED");
    } finally {
        if ($hadIdx && !(int) $pdo->query($idx)->fetchColumn()) {
            $pdo->exec('ALTER TABLE structures ADD KEY idx_structures_region (region_code, tier, wing)');
        }
    }

    $say($okEnum
        ? '  structures.level sudah memuat MWLN; wing (muslimah) dan tier (2) diselaraskan ulang, karena 015/017 menyetelnya tanpa MWLN.'
        : '  structures.level ditambah MWLN; wing (muslimah) dan tier (2) diselaraskan. Baris yang sudah ada tidak berubah.');
    $say('Migrasi 023 selesai.');
}
