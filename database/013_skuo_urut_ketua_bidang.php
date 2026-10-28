<?php
// PATH LOKASI FILE: database/013_skuo_urut_ketua_bidang.php
// Migrasi 013: urutan resmi SKUO, Ketua Bidang, dan Wakil Ketua sebagai Pengurus Inti di Pusat.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN; nilai awal hanya dipasang sekali supaya menjalankan
// ulang migrasi tidak menimpa perubahan yang kelak dibuat dengan sengaja.
//
//   unit_catalog.sort_order           urutan resmi SKUO (kelipatan 10, angka kecil tampil lebih dulu; 500 = belum diurutkan).
//                                     Diisi dari daftar SKUO tingkat pusat, lalu disalin ke unit-unit periode yang masih 500.
//   unit_groups.coordinator_placement_id  penempatan Ketua/Wakil Ketua (Pengurus Inti) yang memimpin Bidang itu.
//                                     Menunjuk PENEMPATAN, bukan orang: mengikuti periode, dan pergantian Ketua cukup mengganti penempatannya.
//   positions.core_from_tier          Wakil Ketua jadi inti mulai tier 1 (DPP; sebelumnya mulai tier 2 / Wilayah).

const SKUO_URUT_013 = [
    'Departemen Dakwah dan Pembinaan Masjid', 'Departemen Kaderisasi dan Pembinaan Murabbi', 'Lembaga Pendidikan Dai dan Murabbi',
    'Departemen Pengembangan Sumber Daya Manusia', 'Lembaga Pengembangan Pendidikan Dasar Al-Qur’an',
    'Lembaga Pengembangan Pengajaran Tahsinul Qira’ah', 'Lembaga Pengembangan Pengajaran Fahmul Qur’an',
    'Lembaga Pengembangan Tahfizh Al-Qur\'an', 'Lembaga Pembinaan dan Pengembangan Anak dan Remaja',
    'Lembaga Pernikahan dan Pembinaan Keluarga Sakinah', 'Lembaga Pembinaan Lanjut Usia',
    'Departemen Pendidikan Anak Usia Dini, Nonformal dan Informal', 'Departemen Pendidikan Dasar dan Menengah',
    'Departemen Pendidikan Tinggi', 'Lembaga Pembinaan Yayasan Pendidikan', 'Departemen Urusan Wilayah dan Daerah',
    'Departemen Urusan Luar Negeri', 'Lembaga Pembinaan dan Pengembangan Organisasi Otonom', 'Departemen Kesehatan',
    'Departemen Olahraga dan Seni', 'Departemen Lingkungan Hidup', 'Departemen Sosial', 'Departemen Pengembangan Usaha',
    'Departemen Pembinaan Koperasi, Usaha Mikro, Kecil dan Menengah', 'Lembaga Amil Zakat Wahdah Inspirasi Zakat',
    'Lembaga Perencanaan dan Pengawasan Pembangunan', 'Departemen Pertanian', 'Lembaga Wakaf',
    'Lembaga Pendamping Proses Produk Halal', 'Departemen Pembinaan Pemuda', 'Departemen Pembinaan Pelajar',
    'Departemen Pembinaan Mahasiswa', 'Departemen Data dan Teknologi Informasi', 'Departemen Media Informasi dan Komunikasi',
    'Pusat Penelitian dan Pengembangan', 'Pusat Penjaminan Mutu', 'Satuan Pengawas Internal', 'Badan Anggaran',
    'Badan Pencegahan dan Penanggulangan Stunting', 'Biro Administrasi Umum dan Kesekretariatan',
    'Biro Kearsipan Digital dan Pustaka', 'Biro Hukum dan Advokasi', 'Biro Hubungan Masyarakat dan Kerja Sama',
    'Biro Protokoler Pimpinan', 'Biro Kepegawaian', 'Biro Keuangan dan Aset', 'Biro Kantor Pusat', 'Biro Kantor DPP Jakarta',
];

function migrate_013_skuo_urut_ketua_bidang(PDO $pdo, callable $say) {
    $say("Migrasi 013: urutan SKUO, Ketua Bidang, Wakil Ketua inti di Pusat...");

    $hasCol = function ($table, $col) use ($pdo) {
        return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                                  WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = '$col'")->fetchColumn();
    };

    // --- unit_catalog.sort_order ---
    if ($hasCol('unit_catalog', 'sort_order')) {
        $say("  unit_catalog.sort_order sudah ada, dilewati.");
    } else {
        $pdo->exec("ALTER TABLE unit_catalog ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 500 AFTER kind,
                    ADD KEY idx_unit_catalog_sort (sort_order)");
        $nk = function ($s) { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); };
        $byKey = [];
        foreach ($pdo->query("SELECT id, name FROM unit_catalog") as $r) $byKey[$nk($r['name'])] = (int) $r['id'];

        $upd = $pdo->prepare("UPDATE unit_catalog SET sort_order = :o WHERE id = :id");
        $n = 0; $missing = [];
        foreach (SKUO_URUT_013 as $i => $name) {
            $id = $byKey[$nk($name)] ?? null;
            if ($id === null) { $missing[] = $name; continue; }
            $upd->execute([':o' => ($i + 1) * 10, ':id' => $id]); $n++;
        }
        $say("  unit_catalog.sort_order ditambahkan; $n unit diurutkan menurut daftar SKUO" . ($missing ? ' (tidak ada di katalog: ' . implode('; ', $missing) . ')' : '') . '.');

        // Unit periode yang belum pernah diurutkan (500) mengikuti urutan katalognya; yang sudah diatur tangan tidak disentuh.
        $m = $pdo->exec("UPDATE structure_units su JOIN unit_catalog c ON c.id = su.unit_catalog_id
                         SET su.sort_order = c.sort_order WHERE su.sort_order = 500 AND c.sort_order < 500");
        $say("  structure_units: $m unit periode mengikuti urutan katalog.");
    }

    // --- unit_groups.coordinator_placement_id ---
    if ($hasCol('unit_groups', 'coordinator_placement_id')) {
        $say("  unit_groups.coordinator_placement_id sudah ada, dilewati.");
    } else {
        $pdo->exec("ALTER TABLE unit_groups
                    ADD COLUMN coordinator_placement_id INT UNSIGNED NULL AFTER sort_order,
                    ADD KEY idx_unit_groups_coord (coordinator_placement_id),
                    ADD CONSTRAINT fk_unit_groups_coord FOREIGN KEY (coordinator_placement_id) REFERENCES placements (id) ON DELETE SET NULL");
        $say("  unit_groups.coordinator_placement_id ditambahkan.");
    }

    // --- Wakil Ketua inti di Pusat (sekali saja) ---
    $marker = 'migrasi_013_wakil_ketua_pusat';
    $done = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = :k");
    $done->execute([':k' => $marker]);
    if ((int) $done->fetchColumn()) {
        $say("  aturan Wakil Ketua inti di Pusat sudah dipasang, dilewati.");
    } else {
        $n = $pdo->exec("UPDATE positions SET core_from_tier = 1 WHERE code = 'wakil_ketua' AND is_core = 0 AND core_from_tier = 2");
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, '1')")->execute([':k' => $marker]);
        $say("  positions: Wakil Ketua inti mulai tier 1 / Pusat ($n jabatan). Berlaku juga untuk MWP (sama-sama tier 1).");
    }

    $say("Migrasi 013 selesai.");
}
