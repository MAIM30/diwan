<?php
// Migrasi 012: kolom event + indeks di audit_log.
//
// Query Portal Pengurus (loginHistory, selfEditCountSince) dan dasbor admin (activity) menyaring
// audit_log berdasarkan JSON_EXTRACT(after_data, '$.event'). Tanpa indeks, query itu memindai
// seluruh tabel -- makin lambat seiring tabel tumbuh. Kolom biasa + indeks menyelesaikannya.
//
// IDEMPOTEN.

function migrate_012_audit_event_column(PDO $pdo, callable $say) {
    $say("Migrasi 012: kolom event di audit_log...");

    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = 'audit_log'
                                AND column_name = 'event'")->fetchColumn();
    if ($has) {
        $say("  kolom event sudah ada, dilewati.");
        return;
    }

    $pdo->exec("ALTER TABLE audit_log ADD COLUMN event VARCHAR(50) NULL AFTER action");
    $say("  kolom event ditambahkan.");

    // Isi dari JSON yang sudah ada (baris tanpa event tetap NULL).
    $pdo->exec("UPDATE audit_log SET event = JSON_UNQUOTE(JSON_EXTRACT(after_data, '\$.event'))
                WHERE after_data IS NOT NULL AND JSON_EXTRACT(after_data, '\$.event') IS NOT NULL");
    $say("  event diisi dari after_data yang ada.");

    $pdo->exec("CREATE INDEX idx_audit_event ON audit_log (event)");
    $say("  indeks idx_audit_event dibuat.");

    // Indeks gabungan untuk loginHistory: (entity, entity_id, event) → cukup cepat tanpa full scan.
    $hasIdx = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
                                  WHERE table_schema = DATABASE() AND table_name = 'audit_log'
                                    AND index_name = 'idx_audit_entity_event'")->fetchColumn();
    if (!$hasIdx) {
        $pdo->exec("CREATE INDEX idx_audit_entity_event ON audit_log (entity, entity_id, event)");
        $say("  indeks idx_audit_entity_event dibuat.");
    }

    $say("Migrasi 012 selesai.");
}
