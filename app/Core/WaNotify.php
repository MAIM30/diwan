<?php
// PATH LOKASI FILE: app/Core/WaNotify.php
// Pengirim notifikasi WhatsApp otomatis: dipanggil dari controller saat ada peristiwa penting.

class WaNotify {

    // Pendaftar diberi kabar setelah verifikator mengubah statusnya. Hasil pengiriman
    // dikembalikan supaya halaman admin bisa menandai bila gateway belum siap/gagal.
    public static function registrationDecision(array $registration, $status, $note = '') {
        if (!Whatsapp::isConfigured()) return ['ok' => false, 'skipped' => true];

        $phone = Text::normalizePhone($registration['phone'] ?? '');
        if (!$phone) return ['ok' => false, 'skipped' => true];

        $name = trim($registration['name'] ?? '') ?: 'Pengurus';
        $honorific = ($registration['gender'] ?? 'L') === 'P' ? 'Ustazah' : 'Ustaz';
        if ($status === 'verified') {
            $text = "Assalamu 'alaykum {$honorific} {$name},\n\n"
                  . "Pengajuan Anda telah diverifikasi. Tahap berikutnya adalah penetapan penempatan dan aktivasi oleh admin.\n\n"
                  . "Barokallohu Fikum.";
        } elseif ($status === 'rejected') {
            $reason = trim((string) $note);
            $text = "Assalamu 'alaykum {$honorific} {$name},\n\n"
                  . "Mohon maaf, pengajuan Anda belum dapat diterima.\n"
                  . "Alasan: " . ($reason !== '' ? $reason : 'Silakan hubungi admin untuk informasi lebih lanjut.') . "\n\n"
                  . "Barokallohu Fikum.";
        } else {
            return ['ok' => false, 'skipped' => true];
        }

        return Whatsapp::sendText($phone, $text);
    }

    public static function placementCreated($personId, $placementId) {
        if (!Whatsapp::isConfigured()) return;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT p.name, p.phone, pos.name AS position_name,
                   s.name AS structure_name, s.level
            FROM placements pl
            JOIN people p ON p.id = pl.person_id
            JOIN positions pos ON pos.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            WHERE pl.id = :id
        ");
        $stmt->execute([':id' => (int) $placementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['phone'])) return;

        $phone = Text::normalizePhone($row['phone']);
        if (!$phone) return;

        $text = "Assalamu'alaikum " . $row['name'] . ",\n\n"
              . "Anda telah ditempatkan sebagai *" . $row['position_name'] . "* "
              . "di " . $row['level'] . " " . $row['structure_name'] . ".\n\n"
              . "Lihat detail di Portal Pengurus:\n"
              . BASEURL . "/pengurus\n\n"
              . "Barakallahu fiikum.";

        Whatsapp::sendText($phone, $text);
    }

    public static function skRegistered($structureId, $skReference) {
        if (!Whatsapp::isConfigured()) return;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT DISTINCT p.name, p.phone
            FROM placements pl
            JOIN people p ON p.id = pl.person_id
            JOIN positions pos ON pos.id = pl.position_id
            WHERE pl.structure_id = :sid AND pl.deleted_at IS NULL
              AND (pos.is_core = 1 OR pos.core_from_tier IS NOT NULL)
              AND p.phone IS NOT NULL AND p.phone != ''
        ");
        $stmt->execute([':sid' => (int) $structureId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $structure = $db->prepare("SELECT name, level FROM structures WHERE id = :id");
        $structure->execute([':id' => (int) $structureId]);
        $s = $structure->fetch(PDO::FETCH_ASSOC);
        if (!$s) return;

        foreach ($recipients as $r) {
            $phone = Text::normalizePhone($r['phone']);
            if (!$phone) continue;

            $text = "Assalamu'alaikum " . $r['name'] . ",\n\n"
                  . "SK baru telah terdaftar untuk " . $s['level'] . " " . $s['name'] . ":\n"
                  . "*" . $skReference . "*\n\n"
                  . "Lihat di Portal Pengurus:\n"
                  . BASEURL . "/pengurus";
            Whatsapp::sendText($phone, $text);

            usleep(100000);
        }
    }
}
