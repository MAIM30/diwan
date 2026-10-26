<?php
// PATH LOKASI FILE: database/006_alamat_dan_sandi.php
// Migrasi 006: alamat bertingkat + kata sandi sementara.
//
// Dipanggil dari install.php. IDEMPOTEN.
//
//   people.village                 Kelurahan / Desa
//   people.district                Kecamatan
//     (Jalan/BTN/Lorong tetap di people.address_text; Kota/Kabupaten di people.city; Provinsi di people.province.)
//   accounts.must_change_password  1 = akun ini memakai kata sandi sementara (mis. yang ditetapkan admin atau
//                                  mengikuti pola nama pengguna) dan WAJIB menggantinya sebelum memakai
//                                  halaman apa pun selain halaman ganti kata sandi.

function migrate_006_alamat_dan_sandi(PDO $pdo, callable $say) {
    $say("Migrasi 006: alamat bertingkat + kata sandi sementara...");

    $cols = function ($table, array $names) use ($pdo) {
        $in = "'" . implode("','", $names) . "'";
        // Alias eksplisit: MySQL 8 mengembalikan nama kolom information_schema dalam HURUF KAPITAL.
        $s = $pdo->query("SELECT column_name AS col FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name IN ($in)");
        return array_column($s->fetchAll(PDO::FETCH_ASSOC), 'col');
    };

    $have = $cols('people', ['village', 'district']);
    if (!in_array('village', $have, true)) {
        $pdo->exec("ALTER TABLE people ADD COLUMN village VARCHAR(100) NULL AFTER address_text");
        $say("  people: village (kelurahan/desa) ditambahkan.");
    }
    if (!in_array('district', $have, true)) {
        $pdo->exec("ALTER TABLE people ADD COLUMN district VARCHAR(100) NULL AFTER village");
        $say("  people: district (kecamatan) ditambahkan.");
    }

    if (!in_array('must_change_password', $cols('accounts', ['must_change_password']), true)) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        $say("  accounts: must_change_password ditambahkan.");
    }

    $say("Migrasi 006 selesai.");
}
