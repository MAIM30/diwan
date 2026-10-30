<?php
// PATH LOKASI FILE: app/Core/Otp.php
// Kode OTP masuk Portal Pengurus. Kode TIDAK dikirim ke mana pun: halaman verifikasi menampilkannya
// di atas kolom isian, karena pengiriman OTP lewat WhatsApp membuat nomor gateway sering diblokir.

class Otp {
    const TTL = 300; // detik

    // Kode lama milik orang yang sama dibatalkan; hanya kode terbaru yang berlaku.
    public static function issue($personId, $phone) {
        $code   = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $expiry = date('Y-m-d H:i:s', time() + self::TTL);

        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE otp_codes SET verified = -1 WHERE person_id = :pid AND verified = 0 AND expires_at > NOW()")
           ->execute([':pid' => (int) $personId]);

        $db->prepare("INSERT INTO otp_codes (person_id, phone, code, expires_at, ip) VALUES (:pid, :ph, :code, :exp, :ip)")
           ->execute([':pid' => (int) $personId, ':ph' => (string) $phone, ':code' => $code, ':exp' => $expiry, ':ip' => Text::clientIp()]);

        return $code;
    }

    // true bila kode itu kode terbaru milik orang tersebut dan belum kedaluwarsa; sekali pakai.
    public static function verify($personId, $code) {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            SELECT id FROM otp_codes
            WHERE person_id = :pid AND code = :code AND verified = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':pid' => (int) $personId, ':code' => $code]);
        $id = $stmt->fetchColumn();

        if (!$id) return false;

        $db->prepare("UPDATE otp_codes SET verified = 1 WHERE id = :id")->execute([':id' => $id]);
        return true;
    }
}
