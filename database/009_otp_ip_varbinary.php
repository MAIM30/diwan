<?php
// PATH LOKASI FILE: database/009_otp_ip_varbinary.php
// Migrasi 009: perbaiki tipe kolom otp_codes.ip dari VARCHAR(45) ke VARBINARY(16).
//
// Dipanggil dari migrate.php. IDEMPOTEN.
//
// Bug: Whatsapp::sendOtp() menulis Text::clientIp() (hasil inet_pton(), BINER) ke kolom ini,
// tapi migrasi 008 membuatnya sebagai VARCHAR(45) -- cocok untuk teks IP yang terbaca manusia,
// bukan biner. Di MySQL strict mode ini gagal dengan "Incorrect string value" setiap kali OTP
// dikirim (baru kelihatan saat WA Gateway benar-benar dipakai di server produksi). Kolom lain yang
// menyimpan hal sama (login_attempts.ip, audit_log.ip) sudah benar VARBINARY(16) sejak awal --
// migrasi ini menyamakan otp_codes dengan keduanya.

function migrate_009_otp_ip_varbinary(PDO $pdo, callable $say) {
    $say("Migrasi 009: perbaiki tipe otp_codes.ip ke VARBINARY(16)...");

    $hasTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                   WHERE table_schema = DATABASE() AND table_name = 'otp_codes'")->fetchColumn();
    if (!$hasTable) {
        $say("  otp_codes belum ada (akan dibuat benar oleh migrasi 008), dilewati.");
        return;
    }

    $type = $pdo->query("SELECT DATA_TYPE FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'otp_codes' AND column_name = 'ip'")->fetchColumn();

    if ($type === 'varbinary') {
        $say("  otp_codes.ip sudah VARBINARY, dilewati.");
        return;
    }

    // Isi lama (string IP yang gagal tersimpan sejak bug ini ada) tidak pernah berhasil ter-INSERT
    // sama sekali (query-nya melempar galat), jadi tidak ada data lama yang perlu dikonversi.
    $pdo->exec("ALTER TABLE otp_codes MODIFY ip VARBINARY(16) NULL");
    $say("  otp_codes.ip diubah ke VARBINARY(16).");
}
