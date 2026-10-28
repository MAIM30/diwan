<?php
// PATH LOKASI FILE: database/016_periode_bersama.php
// Membuat katalog periode bersama dan menautkan setiap periode struktur ke acuannya.
// Data lama dikelompokkan berdasarkan nama + tanggal mulai + tanggal akhir rencana yang sama.

function migrate_016_periode_bersama(PDO $pdo, callable $say) {
    $say('Migrasi 016: membuat acuan Periode Kepengurusan bersama...');

    $pdo->exec("CREATE TABLE IF NOT EXISTS governance_periods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        name VARCHAR(50) NOT NULL,
        start_date DATE NOT NULL,
        end_date_planned DATE NULL,
        identity_key CHAR(64) GENERATED ALWAYS AS
          (SHA2(CONCAT(name, '#', DATE_FORMAT(start_date, '%Y-%m-%d'), '#', COALESCE(DATE_FORMAT(end_date_planned, '%Y-%m-%d'), '')), 256)) STORED,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (id),
        UNIQUE KEY uq_governance_periods_uuid (uuid),
        UNIQUE KEY uq_governance_periods_identity (identity_key),
        KEY idx_governance_periods_dates (start_date, end_date_planned)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $hasColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'terms' AND column_name = 'governance_period_id'")->fetchColumn();
    if (!$hasColumn) $pdo->exec('ALTER TABLE terms ADD COLUMN governance_period_id INT UNSIGNED NULL AFTER uuid');

    $pdo->exec("INSERT INTO governance_periods (uuid, name, start_date, end_date_planned)
        SELECT UUID(), t.name, t.start_date, t.end_date_planned
        FROM terms t
        WHERE NOT EXISTS (
          SELECT 1 FROM governance_periods gp
          WHERE gp.name = t.name AND gp.start_date = t.start_date
            AND gp.end_date_planned <=> t.end_date_planned
        )
        GROUP BY t.name, t.start_date, t.end_date_planned");

    $pdo->exec("UPDATE terms t
        JOIN governance_periods gp
          ON gp.name = t.name AND gp.start_date = t.start_date
         AND gp.end_date_planned <=> t.end_date_planned
        SET t.governance_period_id = gp.id
        WHERE t.governance_period_id IS NULL");

    $missing = (int) $pdo->query('SELECT COUNT(*) FROM terms WHERE governance_period_id IS NULL')->fetchColumn();
    if ($missing) throw new RuntimeException("Migrasi 016 dihentikan: $missing periode lama belum tertaut ke acuan.");

    $hasFk = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'terms'
          AND constraint_name = 'fk_terms_governance_period' AND constraint_type = 'FOREIGN KEY'")->fetchColumn();
    if (!$hasFk) {
        $pdo->exec('ALTER TABLE terms MODIFY governance_period_id INT UNSIGNED NOT NULL');
        $pdo->exec('ALTER TABLE terms ADD CONSTRAINT fk_terms_governance_period
            FOREIGN KEY (governance_period_id) REFERENCES governance_periods (id) ON DELETE RESTRICT');
    }

    $hasUnique = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'terms'
          AND index_name = 'uq_terms_structure_period'")->fetchColumn();
    if (!$hasUnique) $pdo->exec('ALTER TABLE terms ADD UNIQUE KEY uq_terms_structure_period (structure_id, governance_period_id)');

    $say('  Periode lama dipetakan ke acuan bersama berdasarkan nama dan tanggalnya.');
    $say('Migrasi 016 selesai.');
}
