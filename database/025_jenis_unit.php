<?php
// PATH LOKASI FILE: database/025_jenis_unit.php
// Migrasi 025: jenis unit (Departemen, Lembaga, Biro, ...) menjadi daftar yang dikelola, bukan ENUM yang dipatri di kolom.
//   1. Tabel unit_kinds: kode (tetap), label, urutan, kelompok bawaan (Biro-Biro, ...), aktif, dan penanda bawaan sistem.
//   2. unit_catalog.kind: ENUM -> VARCHAR(30), berisi kode jenis. Daftar jenis tak lagi dibatasi skema, jadi jenis baru bisa dibuat dan
//      yang tak terpakai dihapus dari halaman Unit Organisasi.
//   3. "Lainnya" diganti "Belum ditentukan" (kode 'belum'): unit yang jenisnya belum ditetapkan. Berlaku di unit_catalog.kind dan di
//      batas jenis unit pada jabatan (positions.unit_kinds, mis. "Ketua": pusat,divisi,komisi,lainnya).
// Idempoten: aman dijalankan berulang. Kelompok bawaan Biro / Badan / Pusat meniru yang dulu dipatri di UnitModel::KIND_GROUP.

function migrate_025_jenis_unit(PDO $pdo, callable $say) {
    $say('Migrasi 025: jenis unit sebagai daftar yang dikelola...');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS unit_kinds (
  code        VARCHAR(30)  NOT NULL,
  label       VARCHAR(60)  NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  group_name  VARCHAR(100) NULL,
  group_sort  SMALLINT UNSIGNED NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (code),
  UNIQUE KEY uq_unit_kinds_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // [kode, label, urutan, kelompok bawaan, urutan kelompok, bawaan sistem]
    $seed = [
        ['departemen', 'Departemen', 10, null, null, 0],
        ['lembaga', 'Lembaga', 20, null, null, 0],
        ['biro', 'Biro', 30, 'Biro-Biro', 910, 0],
        ['badan', 'Badan', 40, 'Badan-Badan', 900, 0],
        ['pusat', 'Pusat', 50, 'Pusat-Pusat', 890, 0],
        ['divisi', 'Divisi', 60, null, null, 0],
        ['komisi', 'Komisi', 70, null, null, 0],
        ['belum', 'Belum ditentukan', 900, null, null, 1],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO unit_kinds (code, label, sort_order, group_name, group_sort, is_system) VALUES (?, ?, ?, ?, ?, ?)');
    $added = 0;
    foreach ($seed as $row) { $ins->execute($row); $added += $ins->rowCount(); }
    $say("  jenis unit bawaan: $added ditambahkan.");

    // Kolom jenis pada katalog: ENUM -> VARCHAR (nilai lama tetap utuh sebagai teks).
    $type = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'unit_catalog' AND column_name = 'kind'")->fetchColumn();
    if (stripos($type, 'enum(') === 0) {
        $pdo->exec("ALTER TABLE unit_catalog MODIFY COLUMN kind VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'belum'");
        $say('  unit_catalog.kind: ENUM menjadi VARCHAR(30).');
    }

    $n = $pdo->exec("UPDATE unit_catalog SET kind = 'belum' WHERE kind = 'lainnya'");
    $say("  unit_catalog: $n unit berjenis \"lainnya\" menjadi \"belum ditentukan\".");

    // Batas jenis unit pada jabatan: daftar dipisah koma; ganti tepat satu kata, bukan sepotong kata lain.
    $sel = $pdo->query("SELECT id, unit_kinds FROM positions WHERE FIND_IN_SET('lainnya', unit_kinds) > 0")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare('UPDATE positions SET unit_kinds = :k WHERE id = :id');
    foreach ($sel as $r) {
        $kinds = array_map(function ($k) { return trim($k) === 'lainnya' ? 'belum' : trim($k); }, explode(',', $r['unit_kinds']));
        $upd->execute([':k' => implode(',', array_values(array_unique($kinds))), ':id' => $r['id']]);
    }
    $say('  positions.unit_kinds: ' . count($sel) . ' jabatan diperbarui.');
    $say('Migrasi 025 selesai.');
}
