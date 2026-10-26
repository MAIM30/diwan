<?php
// PATH LOKASI FILE: app/Core/MemberThrottle.php
// Pembatas percobaan masuk GAGAL ke Portal Pengurus -- dihitung per ALAMAT IP.
//
// Mengapa per IP dan bukan per nomor (seperti LoginThrottle untuk admin, yang per nomor + IP):
// di portal ini nomor WA (atau NIK) adalah kredensialnya. Nomor yang salah berarti tidak ada orangnya,
// sehingga "percobaan gagal per nomor" selalu 1 -- penyerang yang menyapu nomor tidak pernah tertahan.
// Satu-satunya hal yang menyatukan sapuan itu adalah asal permintaannya.
//
// Dua jendela: 15 menit (menahan penyapuan cepat) dan 24 jam (menahan penyapuan lambat yang
// menyelinap di bawah batas pendek). Percobaan yang salah FORMAT (bukan nomor WA maupun NIK 16 digit) tidak dihitung:
// tidak ada pencarian yang terjadi, jadi tidak ada yang bisa ditebak.
//
// PERINGATAN: bila server berada di belakang proxy/CDN dan TRUSTED_IP_HEADER belum diisi, semua
// pengunjung tampak berasal dari satu IP -- satu orang yang salah ketik berkali-kali mengunci
// semua orang. Isi TRUSTED_IP_HEADER di config.local.php sebelum membuka portal ke publik.

class MemberThrottle {
    const SCOPE = 'portal';   // disimpan di kolom login_attempts.phone

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function failures($minutes) {
        // <=> : sama-dengan yang aman terhadap NULL, supaya IP tak dikenal berbagi satu ember
        // alih-alih lolos tanpa batas (NULL = NULL bernilai tidak-benar di MySQL).
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE phone = :scope AND ip <=> :ip AND succeeded = 0
              AND attempted_at > (NOW(3) - INTERVAL :mins MINUTE)
        ");
        $stmt->execute([':scope' => self::SCOPE, ':ip' => Text::clientIp(), ':mins' => (int) $minutes]);
        return (int) $stmt->fetchColumn();
    }

    public function isLocked() {
        return $this->failures(15) >= PORTAL_FAIL_MAX_SHORT || $this->failures(1440) >= PORTAL_FAIL_MAX_DAY;
    }

    public function recordFailure() {
        $stmt = $this->db->prepare("INSERT INTO login_attempts (phone, ip, succeeded) VALUES (:scope, :ip, 0)");
        $stmt->execute([':scope' => self::SCOPE, ':ip' => Text::clientIp()]);

        // Memangkas jejak lama sesekali supaya tabel tidak tumbuh tanpa batas.
        if (random_int(1, 50) === 1) {
            $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW(3) - INTERVAL 2 DAY)");
        }
    }
}
