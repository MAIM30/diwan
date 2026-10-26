<?php
// PATH LOKASI FILE: app/Core/LoginThrottle.php
// Pembatas percobaan login. Dihitung per (nomor telepon + IP) supaya satu penyerang tidak bisa
// mengunci akun orang lain hanya dengan menebak dari IP berbeda, dan sebaliknya satu IP tidak bisa
// menyapu banyak akun sekaligus.

class LoginThrottle {
    const MAX_ATTEMPTS = 5;
    const WINDOW_MINUTES = 15;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Sisa kesempatan sebelum terkunci; 0 = sedang terkunci.
    public function remaining($phone) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE phone = :phone AND ip = :ip AND succeeded = 0
              AND attempted_at > (NOW() - INTERVAL :mins MINUTE)
        ");
        $stmt->execute([':phone' => $phone, ':ip' => Text::clientIp(), ':mins' => self::WINDOW_MINUTES]);
        return max(0, self::MAX_ATTEMPTS - (int) $stmt->fetchColumn());
    }

    public function isLocked($phone) {
        return $this->remaining($phone) === 0;
    }

    public function record($phone, $succeeded) {
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (phone, ip, succeeded) VALUES (:phone, :ip, :ok)
        ");
        $stmt->execute([':phone' => $phone, ':ip' => Text::clientIp(), ':ok' => $succeeded ? 1 : 0]);

        // Login berhasil membersihkan hitungan gagal, supaya percobaan salah ketik yang wajar
        // tidak menumpuk sampai mengunci pengguna yang sah.
        if ($succeeded) {
            $clear = $this->db->prepare("DELETE FROM login_attempts WHERE phone = :phone AND succeeded = 0");
            $clear->execute([':phone' => $phone]);
        }
    }
}
