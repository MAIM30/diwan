<?php
// PATH LOKASI FILE: database/018_ketua_dpw_dpd.php
// Migrasi 018: jabatan "Ketua DPW" dan "Ketua DPD" (pucuk pimpinan Wilayah dan Daerah).
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN: jabatan yang sudah ada (menurut `code`) tidak disentuh,
// jadi pengaturan yang kelak diubah di Master Data > Jabatan tidak tertimpa.
//
// Label polos "Ketua" tetap ada untuk MWW/MWD/... dan kepala unit. Kedua jabatan baru ini hanya memisahkan pucuk
// pimpinan DPW/DPD dari label polos itu; Ketua DPC dan Ketua DPRa menyusul dengan cara yang sama bila diperlukan.
// Keduanya Pengurus Inti (is_core = 1) seperti "Ketua", dan hanya dipegang di tingkat struktur ('structure').
// Memindahkan pengurus ke jabatan ini bukan tugas migrasi: lihat database/sinkron_ketua_dpw_dpd.php.

function migrate_018_ketua_dpw_dpd(PDO $pdo, callable $say) {
    $say("Migrasi 018: jabatan Ketua DPW dan Ketua DPD...");

    $exists = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE code = :c");
    $ins = $pdo->prepare("INSERT INTO positions (code, name, applies_to, is_core, sort_order, is_active)
                          VALUES (:c, :n, 'structure', 1, 11, 1)");

    foreach (['ketua_dpw' => 'Ketua DPW', 'ketua_dpd' => 'Ketua DPD'] as $code => $name) {
        $exists->execute([':c' => $code]);
        if ((int) $exists->fetchColumn()) {
            $say("  $name sudah ada, dilewati.");
            continue;
        }
        $ins->execute([':c' => $code, ':n' => $name]);
        $say("  $name ditambahkan.");
    }

    $say("Migrasi 018 selesai.");
}
