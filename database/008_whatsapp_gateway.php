<?php
// PATH LOKASI FILE: database/008_whatsapp_gateway.php
// Migrasi 008: tabel pendukung integrasi WhatsApp (WA Gateway Baileys, lihat wa-gateway/).
// (Nama berkasnya dulu "whatsapp_kirimdev", sebelum integrasi itu diganti gateway sendiri. Skemanya tidak berubah.)
//
// Dipanggil dari install.php dan migrate.php. IDEMPOTEN.
//
//   settings           — pengaturan runtime (URL + kunci WA Gateway, jeda broadcast, kunci Google Drive, dll.)
//   otp_codes          — kode OTP untuk login Portal Pengurus via WhatsApp
//   whatsapp_log       — catatan setiap pesan WA yang dikirim lewat gateway

function migrate_008_whatsapp_gateway(PDO $pdo, callable $say) {
    $say("Migrasi 008: tabel integrasi WhatsApp (WA Gateway)...");

    // --- settings ---
    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetchColumn();
    if ($has) {
        $say("  settings sudah ada, dilewati.");
    } else {
        $pdo->exec("CREATE TABLE settings (
            `key`       VARCHAR(50) NOT NULL PRIMARY KEY,
            `value`     TEXT,
            updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by  SMALLINT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say("  settings dibuat.");
    }

    // --- otp_codes ---
    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'otp_codes'")->fetchColumn();
    if ($has) {
        $say("  otp_codes sudah ada, dilewati.");
    } else {
        $pdo->exec("CREATE TABLE otp_codes (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            person_id   INT UNSIGNED NOT NULL,
            phone       VARCHAR(20) NOT NULL,
            code        CHAR(6) NOT NULL,
            expires_at  DATETIME NOT NULL,
            verified    TINYINT(1) NOT NULL DEFAULT 0,
            ip          VARBINARY(16),
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_otp_phone_code (phone, code),
            INDEX idx_otp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say("  otp_codes dibuat.");
    }

    // --- whatsapp_log ---
    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'whatsapp_log'")->fetchColumn();
    if ($has) {
        $say("  whatsapp_log sudah ada, dilewati.");
    } else {
        $pdo->exec("CREATE TABLE whatsapp_log (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id      VARCHAR(60),
            phone           VARCHAR(20) NOT NULL,
            type            ENUM('otp','notification','broadcast') NOT NULL,
            template_name   VARCHAR(100),
            status          VARCHAR(20) NOT NULL DEFAULT 'pending',
            payload         JSON,
            response        JSON,
            error_message   TEXT,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wa_phone (phone),
            INDEX idx_wa_type (type),
            INDEX idx_wa_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $say("  whatsapp_log dibuat.");
    }

    $say("Migrasi 008 selesai.");
}
