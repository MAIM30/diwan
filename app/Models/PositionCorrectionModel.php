<?php
// PATH LOKASI FILE: app/Models/PositionCorrectionModel.php
// Laporan pengurus tentang penempatan/jabatan yang perlu diperiksa admin.

class PositionCorrectionModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function categoryLabel($category) {
        return $category === 'position' ? 'Jabatan' : 'Penempatan';
    }

    public function submitForPerson($personId, $placementId, $category, $details) {
        $personId = (int) $personId;
        $placementId = (int) $placementId;
        $category = is_string($category) ? $category : '';
        $details = is_string($details) ? trim($details) : '';

        if (!in_array($category, ['placement', 'position'], true)) {
            throw new RuntimeException('Pilih jenis kekeliruan yang ingin dilaporkan.');
        }
        if (mb_strlen($details) < 10 || mb_strlen($details) > 2000) {
            throw new RuntimeException('Jelaskan kekeliruannya dalam 10 sampai 2.000 karakter.');
        }

        // Penempatan harus benar-benar milik pengurus yang sedang masuk dan masih berjalan.
        // Nilai snapshot berasal dari database, bukan dari data yang dikirim oleh peramban.
        $stmt = $this->db->prepare("
            SELECT pl.id, pl.person_id, pl.structure_id, po.name AS position_name,
                   s.level, s.name AS structure_name, t.name AS term_name,
                   COALESCE(NULLIF(su.display_name, ''), uc.name) AS unit_name, g.name AS group_name
            FROM placements pl
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            JOIN terms t ON t.id = pl.term_id
            LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
            LEFT JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE pl.id = :placement AND pl.person_id = :person
              AND pl.deleted_at IS NULL AND t.deleted_at IS NULL AND t.is_current = 1
              AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
            LIMIT 1
        ");
        $stmt->execute([':placement' => $placementId, ':person' => $personId]);
        $placement = $stmt->fetch();
        if (!$placement) {
            throw new RuntimeException('Penempatan yang dipilih sudah tidak berjalan. Muat ulang halaman lalu pilih penempatan yang masih aktif.');
        }

        $duplicate = $this->db->prepare("SELECT id FROM position_correction_requests
            WHERE person_id = :person AND placement_id = :placement AND status = 'submitted' LIMIT 1");
        $duplicate->execute([':person' => $personId, ':placement' => $placementId]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('Laporan untuk penempatan ini masih menunggu admin. Anda dapat melihat statusnya di halaman ini.');
        }

        $unit = trim(implode(' › ', array_filter([$placement['group_name'], $placement['unit_name']], function ($v) {
            return $v !== null && $v !== '';
        })));
        $insert = $this->db->prepare("
            INSERT INTO position_correction_requests
                (person_id, placement_id, structure_id, category, position_snapshot,
                 structure_snapshot, term_snapshot, unit_snapshot, details)
            VALUES (:person, :placement, :structure, :category, :position, :structure_name,
                    :term, :unit, :details)
        ");
        $insert->execute([
            ':person' => $personId,
            ':placement' => $placementId,
            ':structure' => (int) $placement['structure_id'],
            ':category' => $category,
            ':position' => $placement['position_name'],
            ':structure_name' => $placement['level'] . ' ' . $placement['structure_name'],
            ':term' => $placement['term_name'],
            ':unit' => $unit !== '' ? $unit : null,
            ':details' => $details,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listForPerson($personId) {
        $stmt = $this->db->prepare("
            SELECT id, placement_id, category, position_snapshot, structure_snapshot,
                   term_snapshot, unit_snapshot, details, status, admin_note, created_at,
                   reviewed_at
            FROM position_correction_requests
            WHERE person_id = :person
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $stmt->execute([':person' => (int) $personId]);
        return $stmt->fetchAll();
    }

    public function listInScope($status = null) {
        $where = [AdminScope::sqlFilter('r.structure_id')];
        $params = [];
        if ($status !== null && in_array($status, ['submitted', 'resolved', 'rejected'], true)) {
            $where[] = 'r.status = :status';
            $params[':status'] = $status;
        }
        $stmt = $this->db->prepare("
            SELECT r.*, p.full_name, p.phone, pl.term_id, pl.deleted_at AS placement_deleted_at,
                   a.display_name AS reviewer_name
            FROM position_correction_requests r
            JOIN people p ON p.id = r.person_id
            LEFT JOIN placements pl ON pl.id = r.placement_id
            LEFT JOIN accounts a ON a.id = r.reviewed_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(r.status, 'submitted', 'resolved', 'rejected'), r.created_at DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findInScope($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, p.full_name, p.phone, pl.term_id, pl.deleted_at AS placement_deleted_at
            FROM position_correction_requests r
            JOIN people p ON p.id = r.person_id
            LEFT JOIN placements pl ON pl.id = r.placement_id
            WHERE r.id = :id AND " . AdminScope::sqlFilter('r.structure_id') . "
            LIMIT 1
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function countPendingInScope() {
        return (int) $this->db->query("
            SELECT COUNT(*) FROM position_correction_requests r
            WHERE r.status = 'submitted' AND " . AdminScope::sqlFilter('r.structure_id')
        )->fetchColumn();
    }

    public function decide($id, $status, $adminNote, $accountId) {
        if (!in_array($status, ['resolved', 'rejected'], true)) {
            throw new RuntimeException('Keputusan laporan tidak valid.');
        }
        $adminNote = is_string($adminNote) ? trim($adminNote) : '';
        if (mb_strlen($adminNote) > 2000) {
            throw new RuntimeException('Catatan admin maksimal 2.000 karakter.');
        }
        $stmt = $this->db->prepare("
            UPDATE position_correction_requests
            SET status = :status, admin_note = :note, reviewed_by = :reviewer, reviewed_at = NOW(3)
            WHERE id = :id AND status = 'submitted' AND " . AdminScope::sqlFilter('structure_id')
        );
        $stmt->execute([
            ':status' => $status,
            ':note' => $adminNote !== '' ? $adminNote : null,
            ':reviewer' => (int) $accountId,
            ':id' => (int) $id,
        ]);
        return $stmt->rowCount() === 1;
    }
}
