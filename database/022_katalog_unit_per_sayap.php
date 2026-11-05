<?php
// PATH LOKASI FILE: database/022_katalog_unit_per_sayap.php
// Migrasi 022: katalog Unit Organisasi dipisah per SAYAP (Umum / Muslimah).
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
// Susunan unit sayap Muslimah (MWP, kelak MWW, MWD, ...) memang berbeda dengan DPP (mis. "Departemen Pembinaan Pemudi", Pusat dan
// Badan sendiri), jadi katalognya sendiri-sendiri. Sebelumnya satu katalog untuk semua dan nama dibuat unik di seluruh katalog,
// sehingga "Departemen Kesehatan" DPP dan MWP adalah satu baris yang sama.
//
//   unit_catalog.wing     'umum' (DPP, DPW, DPD, ..., DSO, DSY, DPK) atau 'muslimah' (MWP, MWW, MWD, MWC, MWRa); mengikuti
//                         structures.wing. Sebuah unit hanya boleh dipasang pada struktur yang sayapnya sama dengan katalognya.
//   UNIQUE (name, wing)   menggantikan UNIQUE (name): nama yang sama boleh ada di kedua sayap, tak boleh dobel dalam satu sayap.
//
// Data lama dipilah menurut siapa pemakainya (semua baris structure_units, termasuk yang sudah dihapus):
//   * dipakai HANYA struktur Muslimah  -> wing = 'muslimah' (baris yang sama, tak ada yang berpindah);
//   * dipakai KEDUA sayap (mis. "Departemen Kesehatan") -> yang lama tetap 'umum'; dibuatkan SALINAN 'muslimah' (nama, singkatan,
//     jenis, urutan SKUO, kepala bawaan sama) dan unit struktur Muslimah dipindah menunjuk salinan itu;
//   * lainnya tetap 'umum'.
// Penempatan, unit per periode (structure_units.id/uuid), dan API tak berubah: yang berganti hanya entri katalog yang ditunjuk unit
// Muslimah, dan namanya sama.
//
// Pemilahan data dipasang SEKALI (penanda di tabel settings); kolom dan kunci unik selalu dipastikan ada.

function migrate_022_katalog_unit_per_sayap(PDO $pdo, callable $say) {
    $say("Migrasi 022: katalog Unit Organisasi per sayap (Umum / Muslimah)...");

    $hasWing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                                  WHERE table_schema = DATABASE() AND table_name = 'unit_catalog' AND column_name = 'wing'")->fetchColumn();
    if ($hasWing) {
        $say("  unit_catalog.wing sudah ada, dilewati.");
    } else {
        $pdo->exec("ALTER TABLE unit_catalog ADD COLUMN wing ENUM('umum','muslimah') NOT NULL DEFAULT 'umum' AFTER kind");
        $say("  unit_catalog.wing ditambahkan (semua entri awalnya 'umum').");
    }

    $idx = $pdo->query("SELECT index_name FROM information_schema.statistics
                        WHERE table_schema = DATABASE() AND table_name = 'unit_catalog' AND index_name IN ('uq_unit_catalog_name', 'uq_unit_catalog_name_wing')")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('uq_unit_catalog_name_wing', $idx, true)) {
        $say("  kunci unik (name, wing) sudah ada, dilewati.");
    } else {
        $drop = in_array('uq_unit_catalog_name', $idx, true) ? 'DROP INDEX uq_unit_catalog_name, ' : '';
        $pdo->exec("ALTER TABLE unit_catalog {$drop}ADD UNIQUE KEY uq_unit_catalog_name_wing (name, wing)");
        $say("  kunci unik nama diganti menjadi (name, wing).");
    }

    $marker = 'migrasi_022_katalog_unit_per_sayap';
    $done = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = :k");
    $done->execute([':k' => $marker]);
    if ((int) $done->fetchColumn()) {
        $say("  katalog sudah dipilah per sayap, dilewati.");
        $say("Migrasi 022 selesai.");
        return;
    }

    $pdo->beginTransaction();
    try {
        $use = $pdo->query("
            SELECT uc.id, SUM(s.wing = 'umum') AS n_umum, SUM(s.wing = 'muslimah') AS n_muslimah
            FROM unit_catalog uc
            LEFT JOIN structure_units su ON su.unit_catalog_id = uc.id
            LEFT JOIN structures s ON s.id = su.structure_id
            GROUP BY uc.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $toMus = $pdo->prepare("UPDATE unit_catalog SET wing = 'muslimah' WHERE id = :id");
        $copy  = $pdo->prepare("
            INSERT INTO unit_catalog (uuid, name, abbreviation, kind, wing, sort_order, default_head_position_id, is_active)
            SELECT :uuid, name, abbreviation, kind, 'muslimah', sort_order, default_head_position_id, is_active
            FROM unit_catalog WHERE id = :id
        ");
        $repoint = $pdo->prepare("
            UPDATE structure_units su JOIN structures s ON s.id = su.structure_id
            SET su.unit_catalog_id = :new
            WHERE su.unit_catalog_id = :old AND s.wing = 'muslimah'
        ");

        $moved = 0; $copied = 0; $repointed = 0;
        foreach ($use as $u) {
            $umum = (int) $u['n_umum']; $mus = (int) $u['n_muslimah'];
            if ($mus > 0 && $umum === 0) {
                $toMus->execute([':id' => $u['id']]); $moved++;
            } elseif ($mus > 0 && $umum > 0) {
                $copy->execute([':uuid' => migrate_022_uuid(), ':id' => $u['id']]);
                $new = (int) $pdo->lastInsertId();
                $repoint->execute([':new' => $new, ':old' => $u['id']]);
                $repointed += $repoint->rowCount(); $copied++;
            }
        }
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, '1')")->execute([':k' => $marker]);
        $pdo->commit();
        $say("  $moved entri khusus Muslimah ditandai 'muslimah'; $copied entri yang dipakai kedua sayap disalin untuk Muslimah ($repointed unit struktur Muslimah dipindah ke salinannya).");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $say("Migrasi 022 selesai.");
}

function migrate_022_uuid() {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
