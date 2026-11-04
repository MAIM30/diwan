<?php
// PATH LOKASI FILE: database/018_forum_peserta.php
// Migrasi 018: Tabel forum permusyawaratan (Mukernas, Muktamar, Musyawarah Wilayah, dsb.)
//
// Tiga tabel baru (tidak menyentuh tabel yang sudah ada):
//   1. forums              — forum permusyawaratan beserta penyelenggaranya
//   2. forum_participants   — peserta forum (satu orang = satu baris AKTIF per forum)
//   3. forum_history        — catatan perubahan (append-only)
//
// Keunikan peserta aktif dijaga lewat kolom generated `active_guard`: bernilai non-NULL hanya bila
// status = 'ditetapkan' DAN deleted_at IS NULL, lalu di-UNIQUE-kan. MySQL membolehkan banyak NULL
// di UNIQUE index, jadi baris yang dicabut/diganti/dihapus tidak bentrok.

function migrate_018_forum_peserta(PDO $pdo, callable $say) {
    $say('Migrasi 018: Forum permusyawaratan...');

    // ---------- 1. forums ----------
    $hasForums = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'forums'")->fetchColumn();

    if (!$hasForums) {
        $pdo->exec("CREATE TABLE forums (
            id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid                   CHAR(36)     NOT NULL,
            name                   VARCHAR(200) NOT NULL,
            type                   ENUM('mukernas','muktamar','musyawarah_wilayah','musyawarah_daerah','musyawarah_cabang','lainnya')
                                       NOT NULL DEFAULT 'lainnya',
            organizer_structure_id INT UNSIGNED NOT NULL,
            start_date             DATE         NULL,
            end_date               DATE         NULL,
            status                 ENUM('draft','terbuka','dikunci','selesai') NOT NULL DEFAULT 'draft',
            locked_at              DATETIME(3)  NULL,
            decision_ref           VARCHAR(200) NULL,
            created_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            deleted_at             DATETIME(3)  NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forums_uuid (uuid),
            KEY idx_forums_sync (updated_at, id),
            KEY idx_forums_organizer (organizer_structure_id),
            CONSTRAINT fk_forums_organizer FOREIGN KEY (organizer_structure_id)
                REFERENCES structures (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say('  Tabel forums dibuat.');
    } else {
        $say('  Tabel forums sudah ada, dilewati.');
    }

    // ---------- 2. forum_participants ----------
    $hasFp = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'forum_participants'")->fetchColumn();

    if (!$hasFp) {
        $pdo->exec("CREATE TABLE forum_participants (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid                     CHAR(36)     NOT NULL,
            forum_id                 INT UNSIGNED NOT NULL,
            person_id                INT UNSIGNED NOT NULL,
            category                 ENUM('peserta_penuh','undangan','peninjau') NOT NULL DEFAULT 'peserta_penuh',
            representing_structure_id INT UNSIGNED NULL,
            basis_placement_id       INT UNSIGNED NULL,
            status                   ENUM('ditetapkan','dicabut','diganti') NOT NULL DEFAULT 'ditetapkan',
            replaced_by_id           INT UNSIGNED NULL,
            decision_ref             VARCHAR(200) NULL,
            decided_by               INT UNSIGNED NULL,
            notes                    TEXT         NULL,
            changed_after_lock       TINYINT(1)   NOT NULL DEFAULT 0,
            active_guard             VARCHAR(50)  GENERATED ALWAYS AS (
                                         IF(status = 'ditetapkan' AND deleted_at IS NULL,
                                            CONCAT(forum_id, '-', person_id), NULL)
                                     ) STORED,
            created_at               DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at               DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            deleted_at               DATETIME(3)  NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fp_uuid (uuid),
            UNIQUE KEY uq_fp_active (active_guard),
            KEY idx_fp_sync (updated_at, id),
            KEY idx_fp_forum (forum_id),
            KEY idx_fp_person (person_id),
            CONSTRAINT fk_fp_forum FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE RESTRICT,
            CONSTRAINT fk_fp_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT,
            CONSTRAINT fk_fp_representing FOREIGN KEY (representing_structure_id) REFERENCES structures (id) ON DELETE SET NULL,
            CONSTRAINT fk_fp_basis FOREIGN KEY (basis_placement_id) REFERENCES placements (id) ON DELETE SET NULL,
            CONSTRAINT fk_fp_replaced_by FOREIGN KEY (replaced_by_id) REFERENCES forum_participants (id) ON DELETE SET NULL,
            CONSTRAINT fk_fp_decided_by FOREIGN KEY (decided_by) REFERENCES accounts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say('  Tabel forum_participants dibuat.');
    } else {
        $say('  Tabel forum_participants sudah ada, dilewati.');
    }

    // ---------- 3. forum_history ----------
    $hasFh = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'forum_history'")->fetchColumn();

    if (!$hasFh) {
        $pdo->exec("CREATE TABLE forum_history (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            forum_id        INT UNSIGNED NOT NULL,
            participant_id  INT UNSIGNED NULL,
            action          VARCHAR(50)  NOT NULL,
            changes         JSON         NULL,
            reason          TEXT         NULL,
            after_lock      TINYINT(1)   NOT NULL DEFAULT 0,
            actor_id        INT UNSIGNED NULL,
            actor_name      VARCHAR(150) NULL,
            created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_fh_forum (forum_id),
            KEY idx_fh_participant (participant_id),
            CONSTRAINT fk_fh_forum FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say('  Tabel forum_history dibuat.');
    } else {
        $say('  Tabel forum_history sudah ada, dilewati.');
    }

    $say('Migrasi 018 selesai.');
}

function migrate_018_forum_peserta_down(PDO $pdo, callable $say, $force = false) {
    $say('Membatalkan migrasi 018: Forum permusyawaratan...');

    foreach (['forum_history', 'forum_participants', 'forums'] as $table) {
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = '$table'")->fetchColumn();
        if (!$exists) {
            $say("  Tabel $table tidak ada, dilewati.");
            continue;
        }

        if (!$force) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($count > 0) {
                $say("  DITOLAK: Tabel $table berisi $count baris. Tambahkan --force untuk tetap menghapus.");
                return;
            }
        }

        $pdo->exec("DROP TABLE `$table`");
        $say("  Tabel $table dihapus.");
    }

    $say('Pembatalan migrasi 018 selesai.');
}
