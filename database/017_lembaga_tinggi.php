<?php
// PATH LOKASI FILE: database/017_lembaga_tinggi.php
// Migrasi 017: Tabel lembaga_tinggi + struktur DSO/DSY/DPK.
//
// Wahdah Islamiyah memiliki 4 Lembaga Tinggi setara (bukan hierarki satu-sama-lain):
//   DPP (Dewan Pimpinan Pusat)  — sudah ada; membawahi DPW/DPLN dst.
//   DSO (Dewan Syura)           — baru; unit berupa Komisi
//   DSY (Dewan Syariah)         — baru; unit berupa Komisi
//   DPK (Dewan Pengawas Keuangan) — baru; struktur datar tanpa unit
//
// Perubahan skema:
//   1. CREATE TABLE lembaga_tinggi (DPP, DSO, DSY, DPK)
//   2. structures.lembaga_tinggi_id FK → lembaga_tinggi; backfill DPP + keturunannya = 1
//   3. structures.level ENUM + tambah DSO, DSY, DPK
//   4. structures.tier  generated: DSO/DSY/DPK → 1
//   5. unit_catalog.kind ENUM + tambah 'komisi'
//   6. INSERT strukturs DSO, DSY, DPK (root, parent_id NULL)
//   7. INSERT terms 2026-2031 untuk DSO, DSY, DPK (ikut governance_period DPP)

function migrate_017_lembaga_tinggi(PDO $pdo, callable $say) {
    $say('Migrasi 017: Lembaga Tinggi (DSO, DSY, DPK)...');

    // --- 1. Tabel lembaga_tinggi ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS lembaga_tinggi (
        id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        abbreviation VARCHAR(10)  NOT NULL,
        name         VARCHAR(100) NOT NULL,
        sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 10,
        PRIMARY KEY (id),
        UNIQUE KEY uq_lt_abbreviation (abbreviation)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO lembaga_tinggi (id, abbreviation, name, sort_order) VALUES
        (1, 'DPP', 'Dewan Pimpinan Pusat',       1),
        (2, 'DSO', 'Dewan Syura',                 2),
        (3, 'DSY', 'Dewan Syariah',               3),
        (4, 'DPK', 'Dewan Pengawas Keuangan',     4)");
    $say('  lembaga_tinggi: DPP, DSO, DSY, DPK.');

    // --- 2. Kolom structures.lembaga_tinggi_id ---
    $hasCol = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'structures'
          AND column_name = 'lembaga_tinggi_id'")->fetchColumn();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN lembaga_tinggi_id TINYINT UNSIGNED NULL AFTER parent_id");
        $say('  structures.lembaga_tinggi_id ditambahkan.');
    }

    // --- 3 & 4. Alter ENUM level + regenerated tier + wing ---
    // Drop index dulu karena pakai kolom generated tier & wing
    $hasIdx = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'structures'
          AND index_name = 'idx_structures_region'")->fetchColumn();
    if ($hasIdx) {
        $pdo->exec("ALTER TABLE structures DROP INDEX idx_structures_region");
    }

    $enumLevel = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'structures'
          AND column_name = 'level'")->fetchColumn();
    if (strpos($enumLevel, 'DSO') === false) {
        $pdo->exec("ALTER TABLE structures MODIFY COLUMN level
            ENUM('DPP','DPW','DPD','DPC','DPRa','DPLN','MWP','MWW','MWD','MWC','MWRa','DSO','DSY','DPK')
            NOT NULL");
        $say('  structures.level: tambah DSO, DSY, DPK ke ENUM.');
    }

    // wing: DSO/DSY/DPK tetap 'umum' (tidak termasuk muslimah)
    $pdo->exec("ALTER TABLE structures MODIFY COLUMN wing ENUM('umum','muslimah')
        GENERATED ALWAYS AS (IF(level IN ('MWP','MWW','MWD','MWC','MWRa'),'muslimah','umum')) STORED");

    // tier: DSO/DSY/DPK = 1 (setara pusat)
    $pdo->exec("ALTER TABLE structures MODIFY COLUMN tier TINYINT UNSIGNED
        GENERATED ALWAYS AS (CASE level
            WHEN 'DPP' THEN 1 WHEN 'MWP' THEN 1
            WHEN 'DSO' THEN 1 WHEN 'DSY' THEN 1 WHEN 'DPK' THEN 1
            WHEN 'DPW' THEN 2 WHEN 'DPLN' THEN 2 WHEN 'MWW' THEN 2
            WHEN 'DPD' THEN 3 WHEN 'MWD' THEN 3
            WHEN 'DPC' THEN 4 WHEN 'MWC' THEN 4
            WHEN 'DPRa' THEN 5 WHEN 'MWRa' THEN 5 END) STORED");
    $say('  structures.tier: DSO/DSY/DPK ditambahkan (tier=1).');

    // Buat ulang index
    $hasIdx = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'structures'
          AND index_name = 'idx_structures_region'")->fetchColumn();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE structures ADD KEY idx_structures_region (region_code, tier, wing)");
    }

    // --- FK lembaga_tinggi_id ---
    $hasFk = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'structures'
          AND constraint_name = 'fk_structures_lembaga_tinggi'
          AND constraint_type = 'FOREIGN KEY'")->fetchColumn();
    if (!$hasFk) {
        $pdo->exec("ALTER TABLE structures ADD CONSTRAINT fk_structures_lembaga_tinggi
            FOREIGN KEY (lembaga_tinggi_id) REFERENCES lembaga_tinggi (id) ON DELETE RESTRICT");
    }

    // --- 5. Backfill DPP + seluruh keturunannya → lembaga_tinggi_id = 1 ---
    $affected = $pdo->exec("UPDATE structures SET lembaga_tinggi_id = 1
        WHERE lembaga_tinggi_id IS NULL AND (id = 1 OR path LIKE '/1/%')");
    $say("  $affected struktur (DPP + keturunan) ditandai lembaga_tinggi_id=1.");

    // --- 6. unit_catalog.kind: tambah 'komisi' ---
    $enumKind = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'unit_catalog'
          AND column_name = 'kind'")->fetchColumn();
    if (strpos($enumKind, 'komisi') === false) {
        $pdo->exec("ALTER TABLE unit_catalog MODIFY COLUMN kind
            ENUM('departemen','lembaga','biro','badan','pusat','divisi','komisi','lainnya')
            NOT NULL DEFAULT 'lainnya'");
        $say('  unit_catalog.kind: tambah komisi.');
    }

    // --- 7. Buat struktur root DSO, DSY, DPK ---
    $ltMap   = ['DSO' => 2, 'DSY' => 3, 'DPK' => 4];
    $nameMap = ['DSO' => 'Dewan Syura', 'DSY' => 'Dewan Syariah', 'DPK' => 'Dewan Pengawas Keuangan'];

    $existing = $pdo->query("SELECT level FROM structures WHERE level IN ('DSO','DSY','DPK')")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_diff(['DSO', 'DSY', 'DPK'], $existing) as $level) {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        $pdo->prepare("INSERT INTO structures
            (uuid, level, name, parent_id, lembaga_tinggi_id, path, depth, is_active)
            VALUES (:uuid, :level, :name, NULL, :lt, '', 0, 1)")
            ->execute([':uuid' => $uuid, ':level' => $level,
                       ':name' => $nameMap[$level], ':lt' => $ltMap[$level]]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE structures SET path = :p WHERE id = :id")
            ->execute([':p' => "/$newId/", ':id' => $newId]);
        $say("  Struktur $level dibuat (id=$newId).");
    }

    // --- 8. Terms 2026-2031 untuk DSO, DSY, DPK ---
    $gpId = (int) $pdo->query("SELECT id FROM governance_periods WHERE name = '2026-2031'")->fetchColumn();
    if (!$gpId) {
        $say('  PERINGATAN: governance_period 2026-2031 tidak ditemukan; terms dilewati.');
    } else {
        foreach (['DSO', 'DSY', 'DPK'] as $level) {
            $sid = (int) $pdo->query("SELECT id FROM structures WHERE level = '$level' LIMIT 1")->fetchColumn();
            if (!$sid) continue;
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM terms
                WHERE structure_id = $sid AND governance_period_id = $gpId")->fetchColumn();
            if ($exists) { $say("  Term 2026-2031 $level sudah ada, dilewati."); continue; }
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            $pdo->prepare("INSERT INTO terms
                (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, status, is_current)
                VALUES (:uuid, :gp, :sid, '2026-2031', '2026-09-12', '2031-09-12', 'active', 1)")
                ->execute([':uuid' => $uuid, ':gp' => $gpId, ':sid' => $sid]);
            $say("  Term 2026-2031 dibuat untuk $level (structure_id=$sid).");
        }
    }

    $say('Migrasi 017 selesai.');
}
