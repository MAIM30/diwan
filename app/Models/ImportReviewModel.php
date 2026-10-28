<?php
// PATH LOKASI FILE: app/Models/ImportReviewModel.php
// Daftar kerja untuk kasus impor yang perlu keputusan manusia. Resolusi hanya mencatat keputusan;
// tidak mengubah orang atau penempatan secara otomatis.

class ImportReviewModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function counts() {
        $rows = $this->db->query("SELECT resolution, COUNT(*) AS jumlah FROM import_review_items GROUP BY resolution")
                         ->fetchAll(PDO::FETCH_KEY_PAIR);
        $counts = array_fill_keys(['pending','confirmed','split','corrected','ignored'], 0);
        foreach ($rows as $key => $value) $counts[$key] = (int) $value;
        return $counts;
    }

    public function listItems($resolution = 'pending', $limit = 500) {
        $where = '';
        $params = [];
        if ($resolution !== 'all') {
            $where = 'WHERE i.resolution = :resolution';
            $params[':resolution'] = $resolution;
        }
        $stmt = $this->db->prepare("SELECT i.*, b.label AS batch_label, b.source AS batch_source,
                                           b.status AS batch_status, p.full_name AS candidate_name
                                    FROM import_review_items i
                                    JOIN import_batches b ON b.id = i.batch_id
                                    LEFT JOIN people p ON p.id = i.candidate_person_id
                                    $where
                                    ORDER BY (i.resolution = 'pending') DESC, b.created_at DESC, i.id DESC
                                    LIMIT :lim");
        foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, min(1000, (int) $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateResolution($id, $resolution, $resolutionNote) {
        $allowed = ['pending','confirmed','split','corrected','ignored'];
        if (!in_array($resolution, $allowed, true)) throw new RuntimeException('Pilihan keputusan tidak valid.');

        $stmt = $this->db->prepare('SELECT * FROM import_review_items WHERE id = :id');
        $stmt->execute([':id' => (int) $id]);
        $before = $stmt->fetch();
        if (!$before) return false;

        $note = trim((string) $resolutionNote);
        if (mb_strlen($note) > 10000) throw new RuntimeException('Catatan keputusan terlalu panjang (maksimal 10.000 karakter).');
        $resolved = $resolution === 'pending' ? null : Auth::id();
        $resolvedAt = $resolution === 'pending' ? null : date('Y-m-d H:i:s');

        $this->db->prepare("UPDATE import_review_items
                            SET resolution = :resolution, resolution_note = :note,
                                resolved_by = :by, resolved_at = :at
                            WHERE id = :id")
                 ->execute([
                     ':resolution' => $resolution,
                     ':note' => $note !== '' ? $note : null,
                     ':by' => $resolved,
                     ':at' => $resolvedAt,
                     ':id' => (int) $id,
                 ]);
        $after = $before;
        $after['resolution'] = $resolution;
        $after['resolution_note'] = $note !== '' ? $note : null;
        Audit::log('import_review_items', (int) $id, 'update', $before, $after);
        return true;
    }
}
