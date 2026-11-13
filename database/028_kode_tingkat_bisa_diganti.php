<?php
// PATH LOKASI FILE: database/028_kode_tingkat_bisa_diganti.php
// Migrasi 028: singkatan (kode) sebuah tingkat struktur bisa diganti dari halaman Master Data > Tingkat Struktur.
//
// Kode tingkat (structure_levels.code) adalah yang tersimpan di structures.level. Migrasi 027 memasang foreign key-nya dengan ON UPDATE RESTRICT
// (kode tak bisa berubah); di sini diganti ON UPDATE CASCADE, sehingga StructureLevelModel::save cukup mengganti kodenya di structure_levels dan
// setiap struktur yang memakainya ikut. Penghapusan tetap ditahan (ON DELETE RESTRICT). Tak ada baris yang berubah oleh migrasi ini.
//
// Idempoten: bila aturan UPDATE-nya sudah CASCADE, dilewati. Berjalan SETELAH 027.

function migrate_028_kode_tingkat_bisa_diganti(PDO $pdo, callable $say) {
    $say('Migrasi 028: kode tingkat struktur bisa diganti...');

    $rule = $pdo->query("SELECT UPDATE_RULE FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'structures' AND constraint_name = 'fk_structures_level'")->fetchColumn();
    if ($rule === false) {
        $say('  PERINGATAN: foreign key fk_structures_level tidak ada (migrasi 027 belum dijalankan?), dilewati.');
    } elseif ($rule === 'CASCADE') {
        $say('  fk_structures_level sudah ON UPDATE CASCADE, dilewati.');
    } else {
        $pdo->exec('ALTER TABLE structures DROP FOREIGN KEY fk_structures_level');
        $pdo->exec('ALTER TABLE structures ADD CONSTRAINT fk_structures_level
            FOREIGN KEY (level) REFERENCES structure_levels (code) ON DELETE RESTRICT ON UPDATE CASCADE');
        $say('  fk_structures_level: ON UPDATE RESTRICT menjadi CASCADE.');
    }
    $say('Migrasi 028 selesai.');
}
