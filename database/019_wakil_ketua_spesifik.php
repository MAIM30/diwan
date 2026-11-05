<?php
// PATH LOKASI FILE: database/019_wakil_ketua_spesifik.php
// Migrasi 019: "Wakil Ketua" dibedakan menurut tempatnya, dan "Inti mulai jenjang" (positions.core_from_tier) dihapus.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
// Wakil Ketua dulu satu jabatan untuk dua hal yang berbeda (wakil pimpinan struktur, dan wakil ketua di dalam
// Departemen/Lembaga), dan "inti"-nya diatur lewat core_from_tier. Kini:
//
//   Wakil Ketua DPW / Wakil Ketua DPD   baru; wakil pimpinan tingkat Wilayah / Daerah (kembaran Ketua DPW / Ketua DPD, migrasi 018).
//   Wakil Ketua                         tetap, tetapi khusus tingkat struktur (DPP, MWP, MWW, MWD, DSO, DSY, DPK) dan selalu inti.
//   Wakil Ketua Departemen/Lembaga      baru; wakil ketua di dalam unit (Departemen, Lembaga, Badan, Biro). Tidak pernah inti.
//
// Penempatan yang sudah ada dipindah ke jabatan yang tepat: di dalam unit -> Wakil Ketua Departemen/Lembaga, tingkat
// struktur di DPW/DPD -> Wakil Ketua DPW/DPD. Pengajuan (registration_requests) ikut dipindah agar tak menunjuk jabatan lama.
//
// core_from_tier hanya dipakai Wakil Ketua (nilai 1 = inti di semua tingkat = setara is_core), jadi kolomnya dihapus setelah
// aturannya dilebur ke is_core. Bila ada jabatan lain bernilai > 1 migrasi BERHENTI sebelum mengubah apa pun, sebab aturan
// "inti mulai tingkat X" tak bisa dipertahankan tanpa kolom itu.
//
// Perubahan data dipasang SEKALI (penanda di tabel settings), supaya menjalankan ulang migrasi tidak menimpa pengaturan jabatan
// yang kelak diubah di Master Data > Jabatan.

function migrate_019_wakil_ketua_spesifik(PDO $pdo, callable $say) {
    $say("Migrasi 019: Wakil Ketua spesifik dan penghapusan positions.core_from_tier...");

    $hasTier = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                                  WHERE table_schema = DATABASE() AND table_name = 'positions' AND column_name = 'core_from_tier'")->fetchColumn();

    // Penjaga: sebelum mengubah apa pun.
    if ($hasTier) {
        $stuck = $pdo->query("SELECT code, core_from_tier FROM positions
                              WHERE core_from_tier IS NOT NULL AND core_from_tier > 1 AND code <> 'wakil_ketua'")->fetchAll(PDO::FETCH_ASSOC);
        if ($stuck) {
            $list = implode(', ', array_map(fn($p) => $p['code'] . ' (mulai tier ' . $p['core_from_tier'] . ')', $stuck));
            throw new RuntimeException("Migrasi 019 dibatalkan: jabatan $list memakai aturan \"inti mulai jenjang\" yang tak setara dengan \"selalu inti\". "
                . "Putuskan dulu jabatan itu inti atau tidak (atur is_core), kosongkan core_from_tier-nya, lalu jalankan ulang.");
        }
    }

    $marker = 'migrasi_019_wakil_ketua_spesifik';
    $done = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = :k");
    $done->execute([':k' => $marker]);

    if ((int) $done->fetchColumn()) {
        $say("  jabatan Wakil Ketua sudah dipisah, dilewati.");
    } else {
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare("SELECT id FROM positions WHERE code = :c");
            $ins  = $pdo->prepare("INSERT INTO positions (code, name, applies_to, is_core, sort_order, is_active)
                                   VALUES (:c, :n, :at, :core, 21, 1)");
            $id = [];
            foreach ([['wakil_ketua_dpw', 'Wakil Ketua DPW', 'structure', 1],
                      ['wakil_ketua_dpd', 'Wakil Ketua DPD', 'structure', 1],
                      ['wakil_ketua_departemen_lembaga', 'Wakil Ketua Departemen/Lembaga', 'unit', 0]] as [$code, $name, $at, $core]) {
                $find->execute([':c' => $code]);
                $existing = $find->fetchColumn();
                if (!$existing) {
                    $ins->execute([':c' => $code, ':n' => $name, ':at' => $at, ':core' => $core]);
                    $existing = $pdo->lastInsertId();
                    $say("  $name ditambahkan.");
                }
                $id[$code] = (int) $existing;
            }
            $find->execute([':c' => 'wakil_ketua']);
            $wk = (int) $find->fetchColumn();

            if ($wk) {
                $moved = [];
                // Di dalam unit -> Wakil Ketua Departemen/Lembaga. Semua baris, termasuk yang sudah diarsipkan, supaya konsisten.
                $moved['unit'] = $pdo->exec("UPDATE placements SET position_id = {$id['wakil_ketua_departemen_lembaga']}
                                             WHERE position_id = $wk AND structure_unit_id IS NOT NULL");
                // Tingkat struktur di DPW / DPD.
                foreach (['DPW' => 'wakil_ketua_dpw', 'DPD' => 'wakil_ketua_dpd'] as $level => $code) {
                    $moved[$level] = $pdo->exec("UPDATE placements pl JOIN structures s ON s.id = pl.structure_id
                                                 SET pl.position_id = {$id[$code]}
                                                 WHERE pl.position_id = $wk AND pl.structure_unit_id IS NULL AND s.level = '$level'");
                }
                // Pengajuan yang menunjuk Wakil Ketua: ikut dipindah.
                $pdo->exec("UPDATE registration_requests SET target_position_id = {$id['wakil_ketua_departemen_lembaga']}
                            WHERE target_position_id = $wk AND target_unit_id IS NOT NULL");
                foreach (['DPW' => 'wakil_ketua_dpw', 'DPD' => 'wakil_ketua_dpd'] as $level => $code) {
                    $pdo->exec("UPDATE registration_requests r JOIN structures s ON s.id = r.target_structure_id
                                SET r.target_position_id = {$id[$code]}
                                WHERE r.target_position_id = $wk AND r.target_unit_id IS NULL AND s.level = '$level'");
                }
                $say("  penempatan dipindah: {$moved['unit']} di dalam unit -> Wakil Ketua Departemen/Lembaga, "
                   . "{$moved['DPW']} -> Wakil Ketua DPW, {$moved['DPD']} -> Wakil Ketua DPD.");

                // Wakil Ketua polos: khusus tingkat struktur, selalu inti (hasil leburan core_from_tier).
                $pdo->exec("UPDATE positions SET applies_to = 'structure', is_core = 1 WHERE id = $wk");
                $say("  Wakil Ketua: khusus tingkat struktur dan selalu inti.");
            }

            $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, '1')")->execute([':k' => $marker]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($hasTier) {
        // Jabatan lain bernilai 1 (inti di semua tingkat) = selalu inti. (Yang > 1 sudah ditolak di atas.)
        $n = $pdo->exec("UPDATE positions SET is_core = 1 WHERE core_from_tier = 1 AND is_core = 0");
        if ($n) $say("  $n jabatan lain bernilai jenjang Pusat dijadikan selalu inti.");
        $pdo->exec("ALTER TABLE positions DROP COLUMN core_from_tier");
        $say("  positions.core_from_tier dihapus.");
    } else {
        $say("  positions.core_from_tier sudah tidak ada, dilewati.");
    }

    $say("Migrasi 019 selesai.");
}
