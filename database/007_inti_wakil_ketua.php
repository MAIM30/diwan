<?php
// PATH LOKASI FILE: database/007_inti_wakil_ketua.php
// Migrasi 007: Wakil Ketua termasuk Pengurus Inti di DPW/DPD/DPC/DPRa dan MWW/MWD/MWC.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
//   positions.core_from_tier  NULL = tidak ada tambahan. Angka = jabatan ini JUGA termasuk Pengurus Inti (bila dipegang di
//                             tingkat struktur) pada struktur yang tier-nya >= angka itu (tier: 1 Pusat, 2 Wilayah, 3 Daerah,
//                             4 Cabang, 5 Ranting; DPP dan MWP sama-sama tier 1, jadi 2 berarti "Wilayah ke bawah").
//                             Dipakai untuk jabatan yang inti di tingkat bawah tetapi bukan di Pusat: Wakil Ketua.
//
// Nilai awalnya hanya dipasang saat kolom ini BARU dibuat, supaya menjalankan ulang migrasi tidak menimpa pengaturan
// yang kelak diubah dengan sengaja.

function migrate_007_inti_wakil_ketua(PDO $pdo, callable $say) {
    $say("Migrasi 007: Wakil Ketua sebagai Pengurus Inti mulai tingkat Wilayah...");

    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = 'positions' AND column_name = 'core_from_tier'")->fetchColumn();
    if ($has) {
        $say("  positions.core_from_tier sudah ada, dilewati.");
    } else {
        $pdo->exec("ALTER TABLE positions ADD COLUMN core_from_tier TINYINT UNSIGNED NULL AFTER is_core");
        $n = $pdo->exec("UPDATE positions SET core_from_tier = 2 WHERE code = 'wakil_ketua' AND is_core = 0");
        $say("  positions: core_from_tier ditambahkan; Wakil Ketua ditandai inti mulai tier 2 ($n jabatan).");
    }

    $say("Migrasi 007 selesai.");
}
