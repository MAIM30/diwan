<?php
// PATH LOKASI FILE: database/029_portal_remember_tokens.php
// Migrasi 029: token pemulihan login Portal Pengurus yang bertahan sampai logout atau akses dicabut.
// Token mentah berada hanya di cookie HttpOnly; basis data menyimpan hash untuk mencabutnya.
// Dipanggil dari install.php dan migrate.php. Idempoten.

function migrate_029_portal_remember_tokens(PDO $pdo, callable $say) {
    $say('Migrasi 029: sesi persisten Portal Pengurus...');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS portal_remember_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id     INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_used_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_portal_remember_token_hash (token_hash),
  KEY idx_portal_remember_person (person_id),
  CONSTRAINT fk_portal_remember_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $say('  portal_remember_tokens siap.');
    $say('Migrasi 029 selesai.');
}
