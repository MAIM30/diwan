<?php
// PATH LOKASI FILE: database/021_ketua_menurut_jenis_unit.php
// Migrasi 021: kepala unit dibedakan menurut jenis unitnya: Ketua Departemen, Ketua Lembaga, Ketua Biro, Ketua Badan.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
//   positions.unit_kinds  VARCHAR(100) NULL, daftar jenis unit (unit_catalog.kind) dipisah koma tempat jabatan ini boleh dipegang
//                         bila ia dipegang DI DALAM UNIT. NULL = semua jenis unit. Tak berpengaruh untuk jabatan tingkat struktur.
//                         Contoh: "Ketua Biro" = 'biro', jadi tak bisa ditempatkan di Departemen, Lembaga, atau Badan.
//
//   Ketua Departemen / Ketua Lembaga / Ketua Biro / Ketua Badan   baru; applies_to 'unit', bukan inti, unit_kinds = jenisnya sendiri.
//   Ketua                                                         tetap; kini hanya untuk jenis unit yang belum punya jabatan
//                                                                 sendiri (pusat, divisi, komisi, lainnya) dan tingkat struktur.
//
// Penempatan "Ketua" di dalam unit dipindah menurut jenis unitnya, dan kepala bawaan katalog unit (unit_catalog.default_head_position_id)
// mengikutinya. Pengajuan (registration_requests) yang menunjuk Ketua di unit ikut dipindah.
// "Wakil Ketua Departemen/Lembaga" (migrasi 019) tidak diubah.
//
// Penempatan dan batasan dipasang SEKALI (penanda di tabel settings), supaya menjalankan ulang migrasi tidak menimpa pengaturan
// yang kelak diubah di Master Data > Jabatan. Kolomnya sendiri selalu dipastikan ada.

function migrate_021_ketua_menurut_jenis_unit(PDO $pdo, callable $say) {
    $say("Migrasi 021: Ketua Departemen / Lembaga / Biro / Badan dan batas jenis unit...");

    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = 'positions' AND column_name = 'unit_kinds'")->fetchColumn();
    if ($has) {
        $say("  positions.unit_kinds sudah ada, dilewati.");
    } else {
        $pdo->exec("ALTER TABLE positions ADD COLUMN unit_kinds VARCHAR(100) NULL AFTER applies_to");
        $say("  positions.unit_kinds ditambahkan.");
    }

    $marker = 'migrasi_021_ketua_menurut_jenis_unit';
    $done = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = :k");
    $done->execute([':k' => $marker]);
    if ((int) $done->fetchColumn()) {
        $say("  jabatan Ketua menurut jenis unit sudah dipasang, dilewati.");
        $say("Migrasi 021 selesai.");
        return;
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare("SELECT id FROM positions WHERE code = :c");
        $id = function ($code) use ($find) {
            $find->execute([':c' => $code]);
            $v = $find->fetchColumn();
            return $v ? (int) $v : null;
        };

        // 1. Jabatan baru, masing-masing dibatasi pada jenis unitnya.
        $ins = $pdo->prepare("INSERT INTO positions (code, name, applies_to, unit_kinds, is_core, sort_order, is_active)
                              VALUES (:c, :n, 'unit', :k, 0, 11, 1)");
        $new = [];
        foreach (['departemen' => 'Departemen', 'lembaga' => 'Lembaga', 'biro' => 'Biro', 'badan' => 'Badan'] as $kind => $label) {
            $code = 'ketua_' . $kind;
            if ($id($code) === null) {
                $ins->execute([':c' => $code, ':n' => "Ketua $label", ':k' => $kind]);
                $say("  Ketua $label ditambahkan.");
            } else {
                $pdo->prepare("UPDATE positions SET applies_to = 'unit', unit_kinds = :k WHERE code = :c")->execute([':k' => $kind, ':c' => $code]);
            }
            $new[$kind] = $id($code);
        }

        $ketua = $id('ketua');
        if ($ketua) {
            // 2. Penempatan Ketua di dalam unit -> Ketua <jenis unit>. Semua baris, termasuk yang sudah diarsipkan.
            $moved = [];
            foreach ($new as $kind => $to) {
                $moved[$kind] = $pdo->exec("UPDATE placements pl
                                            JOIN structure_units su ON su.id = pl.structure_unit_id
                                            JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                                            SET pl.position_id = $to
                                            WHERE pl.position_id = $ketua AND uc.kind = '$kind'");
                // Pengajuan yang menunjuk Ketua pada unit berjenis itu.
                $pdo->exec("UPDATE registration_requests r
                            JOIN structure_units su ON su.id = r.target_unit_id
                            JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                            SET r.target_position_id = $to
                            WHERE r.target_position_id = $ketua AND uc.kind = '$kind'");
                // Kepala bawaan katalog unit mengikuti jenisnya.
                $pdo->exec("UPDATE unit_catalog SET default_head_position_id = $to
                            WHERE kind = '$kind' AND default_head_position_id = $ketua");
            }
            $say("  penempatan dipindah: " . implode(', ', array_map(fn($k, $n) => "$n -> Ketua " . ucfirst($k), array_keys($moved), $moved)) . ".");

            // 3. "Ketua" polos tak lagi berlaku di jenis unit yang sudah punya jabatan sendiri.
            $pdo->prepare("UPDATE positions SET unit_kinds = 'pusat,divisi,komisi,lainnya' WHERE id = :id")->execute([':id' => $ketua]);
            $say("  Ketua: di dalam unit kini hanya untuk Pusat, Divisi, Komisi, dan Lainnya.");
        }

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, '1')")->execute([':k' => $marker]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $say("Migrasi 021 selesai.");
}
