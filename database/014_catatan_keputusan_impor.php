<?php
// PATH LOKASI FILE: database/014_catatan_keputusan_impor.php
// Menambahkan catatan terpisah untuk keputusan butir impor; `note` lama tetap menyimpan catatan
// proses/migrasi yang menjelaskan mengapa butir tersebut perlu ditinjau.

function migrate_014_catatan_keputusan_impor(PDO $pdo, callable $say) {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                                 WHERE table_schema = DATABASE()
                                   AND table_name = 'import_review_items'
                                   AND column_name = 'resolution_note'")->fetchColumn();
    if ($exists) {
        $say('Migrasi 014: import_review_items.resolution_note sudah ada, dilewati.');
        return;
    }
    $pdo->exec('ALTER TABLE import_review_items ADD COLUMN resolution_note TEXT NULL AFTER resolution');
    $say('Migrasi 014: import_review_items.resolution_note ditambahkan.');
}
