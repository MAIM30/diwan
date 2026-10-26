<?php
// PATH LOKASI FILE: database/005_data_diri.php
// Migrasi 005: dua isian "Identitas Diri" formulir Biodata Pengurus yang belum punya kolom.
//
// Dipanggil dari install.php. IDEMPOTEN.
//
//   people.occupation     Pekerjaan/profesi (teks bebas: "Guru dan Mahasiswa Doktoral").
//   people.family_phone   Nomor HP keluarga, kontak darurat. Disimpan ternormalisasi E.164 seperti `phone`.
//
// family_phone adalah data pribadi pihak KETIGA (anggota keluarga) yang tidak pernah diminta
// konsumen mana pun, jadi aturannya lebih ketat dari `phone`: TIDAK ikut di API, tersamar di
// Portal Pengurus, dan hanya terlihat utuh oleh admin yang berhak mengubah data orang itu.

function migrate_005_data_diri(PDO $pdo, callable $say) {
    $say("Migrasi 005: pekerjaan & nomor HP keluarga...");

    // Alias eksplisit: MySQL 8 mengembalikan nama kolom information_schema dalam HURUF KAPITAL
    // (COLUMN_NAME), sehingga array_column(..., 'column_name') tanpa alias selalu kosong dan migrasi
    // mencoba ALTER lagi pada jalan kedua.
    $has = $pdo->prepare("SELECT column_name AS col FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = 'people'
                            AND column_name IN ('occupation', 'family_phone')");
    $has->execute();
    $present = array_column($has->fetchAll(PDO::FETCH_ASSOC), 'col');

    if (!in_array('occupation', $present, true)) {
        $pdo->exec("ALTER TABLE people ADD COLUMN occupation VARCHAR(150) NULL AFTER province");
        $say("  people: occupation ditambahkan.");
    }
    if (!in_array('family_phone', $present, true)) {
        $pdo->exec("ALTER TABLE people ADD COLUMN family_phone VARCHAR(20) NULL AFTER phone");
        $say("  people: family_phone ditambahkan.");
    }

    $say("Migrasi 005 selesai.");
}
