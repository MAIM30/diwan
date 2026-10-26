<?php
// PATH LOKASI FILE: database/011_biodata_foto_drive.php
// Migrasi 011: biodata_submissions.photo_gdrive_file_id -- ID berkas foto biodata di Google Drive.
//
// Dipanggil dari migrate.php dan install.php. IDEMPOTEN.
//
// Foto biodata TIDAK disimpan di server (sama seperti PDF-nya): hanya ID berkas di Drive yang dicatat,
// dan BiodataPdf mengambil fotonya dari Drive saat PDF dibangkitkan. NULL = belum ada foto biodata
// (PDF memakai foto profil bila ada).

function migrate_011_biodata_foto_drive(PDO $pdo, callable $say) {
    $say("Migrasi 011: kolom foto Google Drive di biodata_submissions...");

    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = 'biodata_submissions'
                                AND column_name = 'photo_gdrive_file_id'")->fetchColumn();
    if ($has) {
        $say("  kolom photo_gdrive_file_id sudah ada, dilewati.");
        return;
    }

    $pdo->exec("ALTER TABLE biodata_submissions ADD COLUMN photo_gdrive_file_id VARCHAR(100) NULL AFTER gdrive_uploaded_at");
    $say("  kolom photo_gdrive_file_id ditambahkan.");
}
