<?php
// PATH LOKASI FILE: database/015_struktur_dpln_mwra.php
// Menambahkan DPLN (sementara setingkat DPW di bawah DPP) dan MWRa (ranting di bawah MWC).
// Idempoten: definisi kolom selalu diselaraskan agar instalasi lama dan baru memiliki generated
// wing/tier yang sama.

function migrate_015_struktur_dpln_mwra(PDO $pdo, callable $say) {
    $say('Migrasi 015: menambahkan tingkat DPLN dan MWRa...');

    // Periksa apakah nilai dari migrasi 017 (DSO/DSY/DPK) sudah ada di ENUM.
    // Bila ya, sertakan agar MODIFY tidak memotong data yang sudah ada.
    $curEnum = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'structures'
          AND column_name = 'level'")->fetchColumn();
    $extra = (strpos($curEnum, 'DSO') !== false) ? ",'DSO','DSY','DPK'" : '';

    $pdo->exec("ALTER TABLE structures
        MODIFY level ENUM('DPP','DPW','DPLN','DPD','DPC','DPRa','MWP','MWW','MWD','MWC','MWRa'$extra) NOT NULL,
        MODIFY wing ENUM('umum','muslimah')
            GENERATED ALWAYS AS (IF(level IN ('MWP','MWW','MWD','MWC','MWRa'),'muslimah','umum')) STORED,
        MODIFY tier TINYINT UNSIGNED
            GENERATED ALWAYS AS (CASE level
                WHEN 'DPP' THEN 1 WHEN 'MWP' THEN 1
                WHEN 'DPW' THEN 2 WHEN 'DPLN' THEN 2 WHEN 'MWW' THEN 2
                WHEN 'DPD' THEN 3 WHEN 'MWD' THEN 3
                WHEN 'DPC' THEN 4 WHEN 'MWC' THEN 4
                WHEN 'DPRa' THEN 5 WHEN 'MWRa' THEN 5
            END) STORED");

    $say('  structures.level, wing, dan tier diselaraskan; data struktur yang sudah ada tetap tersimpan.');
    $say('Migrasi 015 selesai.');
}
