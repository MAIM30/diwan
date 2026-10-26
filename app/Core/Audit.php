<?php
// PATH LOKASI FILE: app/Core/Audit.php
// Pencatat perubahan data. Aplikasi ini jadi rujukan resmi susunan pengurus, jadi setiap perubahan
// pada data yang punya konsekuensi di luar sistem (penempatan, periode, orang, verifikasi, token
// API, dan setiap pembukaan NIK) harus bisa dilacak ke akun + waktu.

class Audit {
    public static function log($entity, $entityId, $action, $before = null, $after = null, $accountId = null) {
        try {
            $db = Database::getInstance()->getConnection();
            $event = is_array($after) ? ($after['event'] ?? null) : null;
            $stmt = $db->prepare("
                INSERT INTO audit_log (entity, entity_id, action, event, before_data, after_data, account_id, structure_id, ip)
                VALUES (:entity, :entity_id, :action, :event, :before_data, :after_data, :account_id, :structure_id, :ip)
            ");
            $stmt->execute([
                ':entity'       => $entity,
                ':entity_id'    => (int) $entityId,
                ':action'       => $action,
                ':event'        => $event,
                ':before_data'  => $before === null ? null : json_encode(self::redact($before), JSON_UNESCAPED_UNICODE),
                ':after_data'   => $after === null ? null : json_encode(self::redact($after), JSON_UNESCAPED_UNICODE),
                ':account_id'   => $accountId !== null ? (int) $accountId : (class_exists('Auth') ? Auth::id() : null),
                ':structure_id' => self::structureOf($entity, $entityId, $before, $after),
                ':ip'           => Text::clientIp(),
            ]);
        } catch (PDOException $e) {
            // Audit yang gagal tidak boleh menggagalkan aksi penggunanya; cukup dicatat ke log server.
            error_log('Audit gagal: ' . $e->getMessage());
        }
    }

    // Struktur yang terkait dengan satu baris audit. Dipakai umpan aktivitas dasbor supaya admin
    // wilayah hanya melihat aktivitas di lingkupnya. NULL berarti tidak diketahui, dan baris seperti
    // itu hanya terlihat oleh admin tanpa batas lingkup -- gagal tertutup, bukan terbuka.
    private static function structureOf($entity, $entityId, $before, $after) {
        if ($entity === 'structures') {
            return (int) $entityId;
        }
        foreach ([$after, $before] as $row) {
            if (!is_array($row)) continue;
            foreach (['structure_id', 'target_structure_id', 'home_structure_id'] as $key) {
                if (!empty($row[$key])) return (int) $row[$key];
            }
        }
        return null;
    }

    // NIK tidak ikut disimpan utuh di jejak audit. Jejak audit dibaca jauh lebih luas daripada
    // tabel people itu sendiri, jadi menyalin nomor KTP ke sini hanya melipatgandakan tempat data
    // sensitif itu berada tanpa menambah nilai: yang perlu diketahui auditor adalah BAHWA NIK
    // berubah, bukan nomornya.
    private static function redact(array $row) {
        if (array_key_exists('nik', $row) && $row['nik'] !== null && $row['nik'] !== '') {
            $row['nik'] = Text::maskNik($row['nik']);
        }
        unset($row['nik_guard'], $row['password_hash'], $row['token_hash']);
        return $row;
    }
}
