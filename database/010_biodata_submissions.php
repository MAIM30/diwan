<?php
// PATH LOKASI FILE: database/010_biodata_submissions.php
// Migrasi 010: tabel biodata_submissions -- formulir "Biodata / Profil Singkat Pengurus" yang diisi
// pengurus sendiri lewat Portal Pengurus.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
// PDF-nya TIDAK PERNAH disimpan di server: dibangkitkan ulang di memori dari baris ini setiap kali
// dibutuhkan (unduh, atau kirim ke Google Drive). Lihat app/Core/BiodataPdf.php.

function migrate_010_biodata_submissions(PDO $pdo, callable $say) {
    $say("Migrasi 010: tabel biodata_submissions...");

    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'biodata_submissions'")->fetchColumn();
    if ($has) {
        $say("  biodata_submissions sudah ada, dilewati.");
        return;
    }

    $pdo->exec("CREATE TABLE biodata_submissions (
        id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid                   CHAR(36)     NOT NULL,
        person_id              INT UNSIGNED NOT NULL,
        jabatan_text           VARCHAR(150) NULL,
        bidang_text            VARCHAR(150) NULL,
        pengalaman_organisasi  TEXT NULL,
        kompetensi             TEXT NULL,
        karya_kontribusi       TEXT NULL,
        commitments            JSON NOT NULL,
        signed_place           VARCHAR(100) NULL,
        signed_date            DATE NULL,
        gdrive_file_id         VARCHAR(100) NULL,
        gdrive_file_url        VARCHAR(255) NULL,
        gdrive_uploaded_at     DATETIME(3) NULL,
        created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_biodata_uuid (uuid),
        UNIQUE KEY uq_biodata_person (person_id),
        CONSTRAINT fk_biodata_person FOREIGN KEY (person_id) REFERENCES people (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $say("  biodata_submissions dibuat.");

    $say("Migrasi 010 selesai.");
}
