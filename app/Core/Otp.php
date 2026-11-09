<?php
// PATH LOKASI FILE: app/Core/Otp.php
// Kode OTP masuk Portal Pengurus. Kode dikirim ke nomor WA pengurus lewat WA Gateway (Whatsapp::sendOtp) dan TIDAK ditampilkan di layar:
// hanya orang yang memegang nomor itu yang menerimanya.
// Bila gateway TIDAK aktif, kode tak bisa dikirim: pengurus diminta mengirim chat ke admin (adminChatUrl) dan admin memberinya
// kode umum PORTAL_FALLBACK_CODE (isGeneralCode), yang hanya diterima selama gateway tidak aktif (PengurusController::otp_verify).
//
// Karena setiap permintaan kode = satu pesan WhatsApp, pengirimannya dibatasi (RESEND_AFTER per orang, IP_MAX per IP): tanpa batas,
// formulir masuk bisa dipakai membanjiri nomor orang lain dan membuat nomor gateway diblokir WhatsApp.

class Otp {
    const TTL = 300;           // detik: masa berlaku kode
    const ADMIN_TTL = 900;     // detik: masa halaman OTP "minta kode ke admin" -- admin manusia, membalasnya butuh waktu lebih lama
    const RESEND_AFTER = 60;   // detik: satu orang tidak dikirimi kode baru lebih cepat dari ini
    const IP_MAX = 10;         // kode per alamat IP dalam IP_WINDOW menit
    const IP_WINDOW = 15;      // menit

    // Kode lama milik orang yang sama dibatalkan; hanya kode terbaru yang berlaku.
    public static function issue($personId, $phone) {
        $code   = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $expiry = date('Y-m-d H:i:s', time() + self::TTL);

        $db = Database::getInstance()->getConnection();
        self::revoke($personId);

        $db->prepare("INSERT INTO otp_codes (person_id, phone, code, expires_at, ip) VALUES (:pid, :ph, :code, :exp, :ip)")
           ->execute([':pid' => (int) $personId, ':ph' => (string) $phone, ':code' => $code, ':exp' => $expiry, ':ip' => Text::clientIp()]);

        return $code;
    }

    // Membatalkan kode yang masih berlaku milik orang ini (mis. karena pesannya gagal terkirim, jadi tak ada yang memegangnya).
    public static function revoke($personId) {
        Database::getInstance()->getConnection()
            ->prepare("UPDATE otp_codes SET verified = -1 WHERE person_id = :pid AND verified = 0 AND expires_at > NOW()")
            ->execute([':pid' => (int) $personId]);
    }

    // Berapa detik yang lalu kode terbaru milik orang ini diterbitkan, selama kode itu masih berlaku dan belum dipakai; null bila tak ada.
    public static function lastIssuedAgo($personId) {
        $stmt = Database::getInstance()->getConnection()->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM otp_codes
            WHERE person_id = :pid AND verified = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':pid' => (int) $personId]);
        $ago = $stmt->fetchColumn();
        return $ago === false || $ago === null ? null : max(0, (int) $ago);
    }

    // Sudah terlalu banyak kode diminta dari alamat IP ini? (<=> : IP tak dikenal berbagi satu ember, seperti di MemberThrottle.)
    public static function ipLimited() {
        $stmt = Database::getInstance()->getConnection()->prepare("
            SELECT COUNT(*) FROM otp_codes WHERE ip <=> :ip AND created_at > (NOW() - INTERVAL " . (int) self::IP_WINDOW . " MINUTE)
        ");
        $stmt->execute([':ip' => Text::clientIp()]);
        return (int) $stmt->fetchColumn() >= self::IP_MAX;
    }

    // Kode umum yang diberikan admin saat gateway tidak aktif. Perbandingan waktu-tetap; SYARAT gateway tidak aktif diperiksa pemanggil.
    public static function isGeneralCode($code) {
        return hash_equals((string) PORTAL_FALLBACK_CODE, (string) $code);
    }

    // Tautan chat ke admin untuk meminta kode umum. Isi pesan SENGAJA tak memuat nama atau nomor: halaman ini terbuka bagi siapa pun
    // yang mengetik sebuah nomor, jadi apa pun yang tertera di sini bisa bocor ke orang yang bukan pemilik nomornya. Admin mengenali
    // pemintanya dari nomor pengirim chat, yang memang bukti bahwa orang itu memegang nomor tersebut.
    public static function adminChatUrl() {
        $text = "Assalamu'alaikum admin DIWAN, mohon kode OTP untuk masuk Portal Pengurus.";
        return 'https://wa.me/' . preg_replace('/\D+/', '', PORTAL_FALLBACK_WA) . '?text=' . rawurlencode($text);
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
