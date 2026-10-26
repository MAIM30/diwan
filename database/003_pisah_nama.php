<?php
// PATH LOKASI FILE: database/003_pisah_nama.php
// Migrasi 003: nama dipisah jadi gelar depan | nama | gelar belakang, plus kolom pendukung UI baru.
//
// Dipanggil dari install.php. IDEMPOTEN: setiap langkah memeriksa keadaan sebelum mengubah,
// jadi aman dijalankan ulang di basis data baru maupun yang sudah berisi data.
//
// Perubahan:
//   people                 + title_prefix, name, title_suffix; full_name jadi kolom TURUNAN (generated)
//                          + home_structure_id (struktur tempat orang ini pertama didaftarkan)
//   registration_requests  + title_prefix, name, title_suffix; full_name jadi kolom turunan
//   audit_log              + structure_id (supaya umpan aktivitas bisa dibatasi lingkup admin)
//
// Mengapa full_name dijadikan kolom turunan, bukan dibiarkan kolom biasa: tiga bagian nama itu kini
// sumber kebenarannya, dan nama lengkap hanya gabungannya. Kolom biasa yang diisi aplikasi bisa
// berselisih dengan bagian-bagiannya begitu ada satu jalur tulis yang lupa memperbaruinya; kolom
// turunan dihitung MySQL sendiri sehingga tidak mungkin berselisih. Semua kode yang membaca
// full_name (termasuk API) tetap berjalan apa adanya.

require_once dirname(__DIR__) . '/app/Core/Text.php';

// Ekspresi gabungan nama. WAJIB sama dengan Text::joinName() -- keduanya diuji bersama.
const NAME_JOIN_SQL = "CONCAT(TRIM(CONCAT_WS(' ', NULLIF(title_prefix, ''), name)), IF(NULLIF(title_suffix, '') IS NULL, '', CONCAT(', ', title_suffix)))";

function m003_col_exists(PDO $pdo, $table, $col) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $s->execute([':t' => $table, ':c' => $col]);
    return (int) $s->fetchColumn() > 0;
}

function m003_col_generated(PDO $pdo, $table, $col) {
    $s = $pdo->prepare("SELECT EXTRA FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $s->execute([':t' => $table, ':c' => $col]);
    return stripos((string) $s->fetchColumn(), 'GENERATED') !== false;
}

function m003_index_exists(PDO $pdo, $table, $index) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                        WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i");
    $s->execute([':t' => $table, ':i' => $index]);
    return (int) $s->fetchColumn() > 0;
}

// Memecah full_name lama menjadi tiga bagian untuk satu tabel. Mengembalikan [diproses, bergelar].
function m003_backfill_names(PDO $pdo, $table) {
    $rows = $pdo->query("SELECT id, full_name FROM `$table` WHERE name IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE `$table` SET title_prefix = :p, name = :n, title_suffix = :s WHERE id = :id");
    $withTitle = 0;
    foreach ($rows as $r) {
        $x = Text::splitName($r['full_name']);
        if ($x['title_prefix'] !== null || $x['title_suffix'] !== null) $withTitle++;
        $upd->execute([':p' => $x['title_prefix'], ':n' => $x['name'], ':s' => $x['title_suffix'], ':id' => $r['id']]);
    }
    return [count($rows), $withTitle];
}

// Mengubah satu tabel: tiga kolom nama + full_name turunan.
function m003_split_name_table(PDO $pdo, $table, $afterCol, callable $say, array $dropIndexes = []) {
    if (!m003_col_exists($pdo, $table, 'title_prefix')) {
        $pdo->exec("ALTER TABLE `$table`
            ADD COLUMN title_prefix VARCHAR(60)  NULL AFTER `$afterCol`,
            ADD COLUMN name         VARCHAR(200) NULL AFTER title_prefix,
            ADD COLUMN title_suffix VARCHAR(120) NULL AFTER name");
        $say("  $table: kolom title_prefix, name, title_suffix ditambahkan.");
    }

    // Pengisian awal dari full_name lama (hanya bila full_name masih kolom biasa).
    if (m003_col_exists($pdo, $table, 'full_name') && !m003_col_generated($pdo, $table, 'full_name')) {
        [$n, $withTitle] = m003_backfill_names($pdo, $table);
        if ($n > 0) $say("  $table: $n nama dipecah ($withTitle di antaranya bergelar).");
    }

    $pdo->exec("ALTER TABLE `$table` MODIFY name VARCHAR(200) NOT NULL");

    if (m003_col_exists($pdo, $table, 'full_name') && !m003_col_generated($pdo, $table, 'full_name')) {
        foreach ($dropIndexes as $idx) {
            if (m003_index_exists($pdo, $table, $idx)) {
                $pdo->exec("ALTER TABLE `$table` DROP INDEX `$idx`");
            }
        }
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN full_name");
    }
    if (!m003_col_exists($pdo, $table, 'full_name')) {
        $expr = NAME_JOIN_SQL;
        $pdo->exec("ALTER TABLE `$table`
            ADD COLUMN full_name VARCHAR(400) GENERATED ALWAYS AS ($expr) STORED AFTER title_suffix");
        $say("  $table: full_name kini kolom turunan (gabungan otomatis).");
    }
}

function migrate_003_pisah_nama(PDO $pdo, callable $say) {
    $say("Migrasi 003: nama terpisah + dukungan UI...");

    // ---------- people ----------
    m003_split_name_table($pdo, 'people', 'uuid', $say, ['ft_people_name']);

    if (!m003_index_exists($pdo, 'people', 'idx_people_name')) {
        $pdo->exec("ALTER TABLE people ADD KEY idx_people_name (name)");
    }

    // Struktur tempat orang ini pertama didaftarkan. Tanpa kolom ini, orang yang baru dibuat admin
    // wilayah belum punya penempatan sehingga TIDAK TERLIHAT oleh admin itu sendiri (visibilitas
    // dihitung lewat penempatan) dan tidak bisa dipilih saat menambah penempatan pertamanya.
    if (!m003_col_exists($pdo, 'people', 'home_structure_id')) {
        $pdo->exec("ALTER TABLE people
            ADD COLUMN home_structure_id INT UNSIGNED NULL AFTER photo_path,
            ADD KEY idx_people_home (home_structure_id),
            ADD CONSTRAINT fk_people_home FOREIGN KEY (home_structure_id) REFERENCES structures (id) ON DELETE SET NULL");
        $say("  people: home_structure_id ditambahkan.");
    }

    // ---------- registration_requests ----------
    m003_split_name_table($pdo, 'registration_requests', 'uuid', $say);

    // ---------- audit_log ----------
    if (!m003_col_exists($pdo, 'audit_log', 'structure_id')) {
        $pdo->exec("ALTER TABLE audit_log
            ADD COLUMN structure_id INT UNSIGNED NULL AFTER account_id,
            ADD KEY idx_audit_structure (structure_id, created_at)");
        // Tanpa FK: jejak audit harus tetap ada walau struktur yang dirujuknya kelak dihapus.
        $n = $pdo->exec("
            UPDATE audit_log SET structure_id = CASE
                WHEN entity = 'structures' THEN entity_id
                ELSE COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(after_data,  '\$.structure_id'))        AS UNSIGNED),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(before_data, '\$.structure_id'))        AS UNSIGNED),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(after_data,  '\$.target_structure_id')) AS UNSIGNED),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(before_data, '\$.target_structure_id')) AS UNSIGNED))
            END
            WHERE structure_id IS NULL");
        $say("  audit_log: structure_id ditambahkan ($n baris lama ditelusuri).");
    }

    $say("Migrasi 003 selesai.");
}
