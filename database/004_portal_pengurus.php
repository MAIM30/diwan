<?php
// PATH LOKASI FILE: database/004_portal_pengurus.php
// Migrasi 004: Portal Pengurus (login pengurus dengan NIK).
//
// Dipanggil dari install.php. IDEMPOTEN.
//
//   people.portal_enabled   saklar per orang: boleh/tidak masuk ke Portal Pengurus. Bawaan 1 (boleh).
//
// Dibuat sebagai saklar PER ORANG karena NIK adalah satu-satunya kredensial portal. Admin harus bisa
// mencabut akses seseorang seketika -- mis. nomor NIK-nya diketahui orang lain -- tanpa menghapus
// datanya atau mengubah NIK-nya (yang juga dipakai konsumen API lewat lookup).

function migrate_004_portal_pengurus(PDO $pdo, callable $say) {
    $say("Migrasi 004: Portal Pengurus...");

    $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = 'people' AND column_name = 'portal_enabled'");
    $has->execute();
    if (!(int) $has->fetchColumn()) {
        $pdo->exec("ALTER TABLE people ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
        $say("  people: portal_enabled ditambahkan (bawaan: boleh masuk).");
    }

    $say("Migrasi 004 selesai.");
}
