<?php
// PATH LOKASI FILE: database/024_pengajuan_koreksi_penempatan.php
// Migrasi 024: kotak masuk laporan pengurus tentang kekeliruan jabatan atau penempatan.

function migrate_024_pengajuan_koreksi_penempatan(PDO $pdo, callable $say) {
    $say('Migrasi 024: menyiapkan pengajuan koreksi penempatan...');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS position_correction_requests (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id          INT UNSIGNED NOT NULL,
  placement_id       INT UNSIGNED NOT NULL,
  structure_id       INT UNSIGNED NOT NULL,
  category           ENUM('placement','position') NOT NULL,
  position_snapshot  VARCHAR(150) NOT NULL,
  structure_snapshot VARCHAR(220) NOT NULL,
  term_snapshot      VARCHAR(150) NOT NULL,
  unit_snapshot      VARCHAR(500) NULL,
  details            TEXT NOT NULL,
  status             ENUM('submitted','resolved','rejected') NOT NULL DEFAULT 'submitted',
  admin_note         TEXT NULL,
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME(3) NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_position_corrections_scope (structure_id, status, created_at),
  KEY idx_position_corrections_person (person_id, created_at),
  KEY idx_position_corrections_placement (placement_id, status),
  CONSTRAINT fk_position_corrections_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT,
  CONSTRAINT fk_position_corrections_placement FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE RESTRICT,
  CONSTRAINT fk_position_corrections_structure FOREIGN KEY (structure_id) REFERENCES structures (id) ON DELETE RESTRICT,
  CONSTRAINT fk_position_corrections_reviewer FOREIGN KEY (reviewed_by) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $say('  Tabel laporan koreksi siap.');
    $say('Migrasi 024 selesai.');
}
