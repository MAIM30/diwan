<?php
// PATH LOKASI FILE: database/027_tingkat_struktur.php
// Migrasi 027: tingkat struktur (DPP, DPW, DPLN, ..., MWP, MWW, ..., DSO, DSY, DPK) menjadi daftar yang dikelola, bukan ENUM yang dipatri di kolom.
//   1. Tabel structure_levels: kode (kunci yang tersimpan di structures.level; bisa diganti sejak migrasi 028), nama lengkap, kelompok (umum / musl / dewan), tier (setingkat Pusat..Ranting), induk yang lazim
//      atau wajib, sebutan untuk nama admin, urutan, aktif, dan penanda bawaan sistem (DPP dan MWP, dikenal langsung oleh kode akses).
//   2. structures.level: ENUM -> VARCHAR(10) dengan foreign key ke structure_levels.code. Nilai lama tetap utuh sebagai teks; tingkat yang
//      masih dipakai struktur tak bisa dihapus dari daftar.
//   3. structures.wing dan structures.tier: kolom TURUNAN (generated, dirumuskan dari daftar tingkat yang dipatri di rumus) menjadi kolom
//      biasa berisi salinan kelompok dan tier tingkatnya. Rumus generated tak bisa membaca tabel lain, jadi tingkat baru mustahil dijangkau
//      selama keduanya generated. Nilai yang ada tidak berubah; yang menuliskannya kini StructureModel (create/update) dan
//      StructureLevelModel (bila kelompok atau tier sebuah tingkat diubah).
//
// Idempoten: aman dijalankan berulang. Daftar awal hanya diisi bila tabelnya kosong, jadi tingkat yang sengaja dihapus tidak dihidupkan
// lagi. Berjalan SETELAH 015, 017, dan 023, yang kini melewati bagian ENUM/generated-nya begitu structures.level bukan ENUM lagi.

function migrate_027_tingkat_struktur(PDO $pdo, callable $say) {
    $say('Migrasi 027: tingkat struktur sebagai daftar yang dikelola...');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS structure_levels (
  code          VARCHAR(10)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  grp           ENUM('umum','musl','dewan') NOT NULL DEFAULT 'umum',
  tier          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  parent_code   VARCHAR(10)  NULL,
  parent_strict TINYINT(1)   NOT NULL DEFAULT 0,
  short_label   VARCHAR(30)  NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (code),
  UNIQUE KEY uq_structure_levels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // [kode, nama, kelompok, tier, induk lazim, induk wajib, urutan, sebutan nama admin, bawaan sistem]. Induknya meniru yang dulu dipatri di
    // StructureModel::levelsInScope; yang wajib meniru StructuresController::save (DPLN, MWLN, MWRa). Induk selalu tertulis sebelum anaknya.
    $seed = [
        ['DPP', 'Dewan Pimpinan Pusat', 'umum', 1, null, 0, 10, null, 1],
        ['DPW', 'Dewan Pimpinan Wilayah', 'umum', 2, 'DPP', 0, 20, null, 0],
        ['DPLN', 'Dewan Pimpinan Luar Negeri', 'umum', 2, 'DPP', 1, 30, 'Luar Negeri', 0],
        ['DPD', 'Dewan Pimpinan Daerah', 'umum', 3, 'DPW', 0, 40, null, 0],
        ['DPC', 'Dewan Pimpinan Cabang', 'umum', 4, 'DPD', 0, 50, null, 0],
        ['DPRa', 'Dewan Pimpinan Ranting', 'umum', 5, 'DPC', 0, 60, null, 0],
        ['MWP', 'Muslimah Wahdah Islamiyah Pusat', 'musl', 1, 'DPP', 0, 110, null, 1],
        ['MWW', 'Muslimah Wilayah', 'musl', 2, 'MWP', 0, 120, null, 0],
        ['MWLN', 'Muslimah Luar Negeri', 'musl', 2, 'MWP', 1, 130, 'Luar Negeri', 0],
        ['MWD', 'Muslimah Daerah', 'musl', 3, 'MWW', 0, 140, null, 0],
        ['MWC', 'Muslimah Cabang', 'musl', 4, 'MWD', 0, 150, null, 0],
        ['MWRa', 'Muslimah Ranting', 'musl', 5, 'MWC', 1, 160, null, 0],
        ['DSO', 'Dewan Syura', 'dewan', 1, null, 0, 210, null, 0],
        ['DSY', 'Dewan Syariah', 'dewan', 1, null, 0, 220, null, 0],
        ['DPK', 'Dewan Pengawas Keuangan', 'dewan', 1, null, 0, 230, null, 0],
    ];
    if (!(int) $pdo->query('SELECT COUNT(*) FROM structure_levels')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO structure_levels (code, name, grp, tier, parent_code, parent_strict, sort_order, short_label, is_system, is_active)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach ($seed as [$code, $name, $grp, $tier, $parent, $strict, $sort, $short, $system]) {
            $ins->execute([$code, $name, $grp, $tier, $parent, $strict, $sort, $short, $system]);
        }
        $say('  tingkat bawaan: ' . count($seed) . ' ditambahkan.');
    } else {
        $say('  structure_levels sudah berisi, daftar bawaan tidak ditambahkan lagi.');
    }

    $col = $pdo->prepare("SELECT DATA_TYPE, GENERATION_EXPRESSION FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'structures' AND column_name = :c");
    $read = function ($c) use ($col) { $col->execute([':c' => $c]); return $col->fetch(PDO::FETCH_ASSOC) ?: ['DATA_TYPE' => '', 'GENERATION_EXPRESSION' => '']; };

    // Tingkat yang dipakai struktur tetapi belum ada di daftar (tak terjadi pada data yang dibuat aplikasi ini; jaga-jaga bagi data impor):
    // ditambahkan dulu, sebab foreign key di bawah menolak tingkat yang tak dikenal. Kelompok ditebak dari awalan MW, tier dari struktur itu.
    $orphans = $pdo->query("SELECT s.level, MIN(s.tier) AS tier FROM structures s LEFT JOIN structure_levels l ON l.code = s.level
        WHERE l.code IS NULL GROUP BY s.level")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orphans as $o) {
        $pdo->prepare('INSERT INTO structure_levels (code, name, grp, tier, sort_order) VALUES (?, ?, ?, ?, 500)')
            ->execute([$o['level'], $o['level'], strpos($o['level'], 'MW') === 0 ? 'musl' : 'umum', max(1, min(5, (int) $o['tier']))]);
        $say('  tingkat ' . $o['level'] . ' dipakai struktur tetapi belum terdaftar: ditambahkan ke daftar.');
    }

    // --- structures.level, wing, tier ---
    $isEnum = $read('level')['DATA_TYPE'] === 'enum';
    $generated = $read('wing')['GENERATION_EXPRESSION'] !== '' || $read('tier')['GENERATION_EXPRESSION'] !== '';
    if ($isEnum || $generated) {
        // idx_structures_region memakai kolom wing dan tier: dilepas selama kolomnya diubah (seperti di migrasi 017 dan 023), lalu dipasang lagi.
        $idx = "SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'structures' AND index_name = 'idx_structures_region'";
        $hadIdx = (int) $pdo->query($idx)->fetchColumn() > 0;
        if ($hadIdx) $pdo->exec('ALTER TABLE structures DROP INDEX idx_structures_region');
        try {
            // Satu pernyataan: kolom turunan bergantung pada level, jadi ketiganya berubah bersama. Nilai wing/tier yang ada dipertahankan
            // (MySQL menyalin nilai kolom turunan tersimpan ke kolom biasa), tanpa menyentuh updated_at sehingga API tak mengirim ulang struktur.
            $pdo->exec("ALTER TABLE structures
                MODIFY level VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                MODIFY wing ENUM('umum','muslimah') NOT NULL,
                MODIFY tier TINYINT UNSIGNED NOT NULL");
        } finally {
            if ($hadIdx && !(int) $pdo->query($idx)->fetchColumn()) {
                $pdo->exec('ALTER TABLE structures ADD KEY idx_structures_region (region_code, tier, wing)');
            }
        }
        $say('  structures.level menjadi VARCHAR(10); wing dan tier menjadi kolom biasa (nilainya tetap).');
    } else {
        $say('  structures.level, wing, dan tier sudah dalam bentuk baru, dilewati.');
    }

    $fk = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'structures' AND constraint_name = 'fk_structures_level'
          AND constraint_type = 'FOREIGN KEY'")->fetchColumn();
    if (!$fk) {
        $pdo->exec('ALTER TABLE structures ADD CONSTRAINT fk_structures_level
            FOREIGN KEY (level) REFERENCES structure_levels (code) ON DELETE RESTRICT ON UPDATE RESTRICT');
        $say('  foreign key structures.level -> structure_levels.code dipasang.');
    }

    $say('Migrasi 027 selesai.');
}
