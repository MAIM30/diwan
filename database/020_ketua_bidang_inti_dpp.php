<?php
// PATH LOKASI FILE: database/020_ketua_bidang_inti_dpp.php
// Migrasi 020: Ketua dan Wakil Ketua di Pengurus Inti DPP menjadi "Ketua Bidang" dan "Wakil Ketua Bidang", dan urutan
// Pengurus Inti DPP disamakan dengan urutan resmi SKUO.
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
// Di SKUO Pengurus Inti DPP, Ketua adalah Ketua Bidang (9 Ketua untuk 9 Bidang) dan Wakil Ketua adalah Wakil Ketua Bidang.
// Hanya DPP yang punya Bidang, jadi jabatan "Ketua" dan "Wakil Ketua" polos TIDAK diganti namanya (MWP, MWW, MWD, DSO, DSY,
// DPK dan kepala unit masih memakainya); jabatan baru ini hanya dipakai di tingkat struktur DPP, seperti Ketua DPW / Ketua DPD
// untuk Wilayah dan Daerah (migrasi 018).
//
//   Ketua Bidang          baru; tingkat struktur, inti. Penempatan "Ketua" tanpa unit di DPP dipindah ke sini.
//   Wakil Ketua Bidang    baru; tingkat struktur, inti. Penempatan "Wakil Ketua" tanpa unit di DPP dipindah ke sini.
//
// Urutan Tampil (positions.sort_order) Pengurus Inti DPP:
//   Ketua Umum 10, Ketua Harian 20, Sekretaris Jenderal 30, Ketua Bidang 40, Wakil Ketua Bidang 50,
//   Wakil Sekretaris Jenderal 60, Bendahara Umum 70, Wakil Bendahara Umum 80.
// Jabatan-jabatan itu hanya dipakai di DPP, jadi mengubah urutannya tak mengganggu tingkat lain. Penempatan yang jabatannya
// berganti urutan ikut diberi updated_at baru, sebab API menyajikan "order" dan konsumen hanya menyinkronkan baris yang berubah.
//
// Siapa saja yang memegang jabatan itu (mis. hanya satu Wakil Ketua Bidang) adalah data, bukan urusan migrasi ini.
// Perubahan dipasang SEKALI (penanda di tabel settings), supaya menjalankan ulang tidak menimpa pengaturan yang kelak diubah
// di Master Data > Jabatan.

function migrate_020_ketua_bidang_inti_dpp(PDO $pdo, callable $say) {
    $say("Migrasi 020: Ketua Bidang / Wakil Ketua Bidang di Pengurus Inti DPP dan urutannya...");

    $marker = 'migrasi_020_ketua_bidang_inti_dpp';
    $done = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = :k");
    $done->execute([':k' => $marker]);
    if ((int) $done->fetchColumn()) {
        $say("  sudah dipasang, dilewati.");
        $say("Migrasi 020 selesai.");
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

        // 1. Jabatan baru.
        $ins = $pdo->prepare("INSERT INTO positions (code, name, applies_to, is_core, sort_order, is_active)
                              VALUES (:c, :n, 'structure', 1, :s, 1)");
        foreach ([['ketua_bidang', 'Ketua Bidang', 40], ['wakil_ketua_bidang', 'Wakil Ketua Bidang', 50]] as [$code, $name, $sort]) {
            if ($id($code) === null) {
                $ins->execute([':c' => $code, ':n' => $name, ':s' => $sort]);
                $say("  $name ditambahkan.");
            }
        }

        // 2. Urutan Tampil Pengurus Inti DPP.
        $order = ['ketua_umum' => 10, 'ketua_harian' => 20, 'sekretaris_jenderal' => 30, 'ketua_bidang' => 40, 'wakil_ketua_bidang' => 50,
                  'wakil_sekretaris_jenderal' => 60, 'bendahara_umum' => 70, 'wakil_bendahara_umum' => 80];
        $cur  = $pdo->prepare("SELECT sort_order FROM positions WHERE id = :id");
        $set  = $pdo->prepare("UPDATE positions SET sort_order = :s WHERE id = :id");
        $touch = $pdo->prepare("UPDATE placements SET updated_at = CURRENT_TIMESTAMP(3) WHERE position_id = :id");
        $reordered = 0;
        foreach ($order as $code => $sort) {
            $pid = $id($code);
            if ($pid === null) continue;
            $cur->execute([':id' => $pid]);
            if ((int) $cur->fetchColumn() === $sort) continue;
            $set->execute([':s' => $sort, ':id' => $pid]);
            $touch->execute([':id' => $pid]);
            $reordered++;
        }
        $say("  urutan Pengurus Inti DPP dipasang ($reordered jabatan berganti urutan).");

        // 3. Penempatan tingkat struktur DPP: Ketua -> Ketua Bidang, Wakil Ketua -> Wakil Ketua Bidang.
        $moves = [['ketua', 'ketua_bidang', 'Ketua Bidang'], ['wakil_ketua', 'wakil_ketua_bidang', 'Wakil Ketua Bidang']];
        foreach ($moves as [$from, $to, $label]) {
            $f = $id($from); $t = $id($to);
            if ($f === null || $t === null) continue;
            $n = $pdo->exec("UPDATE placements pl JOIN structures s ON s.id = pl.structure_id
                             SET pl.position_id = $t
                             WHERE pl.position_id = $f AND pl.structure_unit_id IS NULL AND s.level = 'DPP'");
            // Pengajuan yang menunjuk jabatan itu di tingkat struktur DPP ikut dipindah.
            $pdo->exec("UPDATE registration_requests r JOIN structures s ON s.id = r.target_structure_id
                        SET r.target_position_id = $t
                        WHERE r.target_position_id = $f AND r.target_unit_id IS NULL AND s.level = 'DPP'");
            $say("  $n penempatan di Pengurus Inti DPP dipindah ke $label.");
        }

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, '1')")->execute([':k' => $marker]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $say("Migrasi 020 selesai.");
}
