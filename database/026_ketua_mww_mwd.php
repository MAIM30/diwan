<?php
// PATH LOKASI FILE: database/026_ketua_mww_mwd.php
// Migrasi 026: jabatan "Ketua MWW" dan "Ketua MWD" (pucuk pimpinan Muslimah Wahdah Wilayah dan Daerah).
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN: jabatan yang sudah ada (menurut `code`) tidak disentuh,
// jadi pengaturan yang kelak diubah di Master Data > Jabatan tidak tertimpa.
//
// Sama seperti Ketua DPW / Ketua DPD (migrasi 018): memisahkan pucuk pimpinan MWW dan MWD dari label polos "Ketua",
// yang tetap dipakai MWP, DSO, DSY, DPK dan kepala unit Divisi/Komisi. Keduanya Pengurus Inti (is_core = 1) dan hanya
// dipegang di tingkat struktur ('structure'). Wakil Ketua MWW / MWD belum dipisah (belum diminta).
// Memindahkan pengurus ke jabatan ini bukan tugas migrasi: lihat database/sinkron_ketua_mww_mwd.php.

function migrate_026_ketua_mww_mwd(PDO $pdo, callable $say) {
    $say("Migrasi 026: jabatan Ketua MWW dan Ketua MWD...");

    $exists = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE code = :c");
    $ins = $pdo->prepare("INSERT INTO positions (code, name, applies_to, is_core, sort_order, is_active)
                          VALUES (:c, :n, 'structure', 1, 11, 1)");

    foreach (['ketua_mww' => 'Ketua MWW', 'ketua_mwd' => 'Ketua MWD'] as $code => $name) {
        $exists->execute([':c' => $code]);
        if ((int) $exists->fetchColumn()) {
            $say("  $name sudah ada, dilewati.");
            continue;
        }
        $ins->execute([':c' => $code, ':n' => $name]);
        $say("  $name ditambahkan.");
    }

    $say("Migrasi 026 selesai.");
}
