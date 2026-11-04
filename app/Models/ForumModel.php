<?php
// PATH LOKASI FILE: app/Models/ForumModel.php
// Forum permusyawaratan (Mukernas, Muktamar, Musyawarah Wilayah/Daerah/Cabang).

class ForumModel {
    const TYPES = [
        'mukernas'            => 'Mukernas',
        'muktamar'            => 'Muktamar',
        'musyawarah_wilayah'  => 'Musyawarah Wilayah',
        'musyawarah_daerah'   => 'Musyawarah Daerah',
        'musyawarah_cabang'   => 'Musyawarah Cabang',
        'lainnya'             => 'Lainnya',
    ];

    const STATUSES = ['draft', 'terbuka', 'dikunci', 'selesai'];

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all() {
        $where = [AdminScope::sqlFilter('f.organizer_structure_id'), 'f.deleted_at IS NULL'];
        return $this->db->query("
            SELECT f.*, s.level, s.name AS structure_name,
                   (SELECT COUNT(*) FROM forum_participants fp
                     WHERE fp.forum_id = f.id AND fp.status = 'ditetapkan' AND fp.deleted_at IS NULL) AS active_count
            FROM forums f
            JOIN structures s ON s.id = f.organizer_structure_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.start_date DESC, f.created_at DESC
        ")->fetchAll();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT f.*, s.level, s.name AS structure_name, s.uuid AS structure_uuid
            FROM forums f
            JOIN structures s ON s.id = f.organizer_structure_id
            WHERE f.id = :id AND f.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("
            SELECT f.*, s.level, s.name AS structure_name, s.uuid AS structure_uuid
            FROM forums f
            JOIN structures s ON s.id = f.organizer_structure_id
            WHERE f.uuid = :u AND f.deleted_at IS NULL
        ");
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data) {
        $stmt = $this->db->prepare("
            INSERT INTO forums (uuid, name, type, organizer_structure_id, start_date, end_date, status, decision_ref)
            VALUES (:uuid, :name, :type, :org, :start, :end, 'draft', :ref)
        ");
        $stmt->execute([
            ':uuid'  => Uuid::v4(),
            ':name'  => trim($data['name']),
            ':type'  => $data['type'],
            ':org'   => (int) $data['organizer_structure_id'],
            ':start' => $data['start_date'] ?: null,
            ':end'   => $data['end_date'] ?: null,
            ':ref'   => trim($data['decision_ref'] ?? '') ?: null,
        ]);
        $id = (int) $this->db->lastInsertId();
        Audit::log('forums', $id, 'create', null, $this->findById($id));
        $this->logHistory($id, null, 'forum_dibuat', null, null);
        return $id;
    }

    public function update($id, array $data) {
        $before = $this->findById($id);
        $stmt = $this->db->prepare("
            UPDATE forums SET name = :name, type = :type, start_date = :start, end_date = :end,
                   decision_ref = :ref
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':name'  => trim($data['name']),
            ':type'  => $data['type'],
            ':start' => $data['start_date'] ?: null,
            ':end'   => $data['end_date'] ?: null,
            ':ref'   => trim($data['decision_ref'] ?? '') ?: null,
            ':id'    => (int) $id,
        ]);
        Audit::log('forums', $id, 'update', $before, $this->findById($id));
        $this->logHistory($id, null, 'forum_diubah', ['before' => $before, 'after' => $data], null);
    }

    public function advanceStatus($id, $newStatus, $reason = null) {
        $forum = $this->findById($id);
        if (!$forum) return false;

        $allowed = [
            'draft'   => 'terbuka',
            'terbuka' => 'dikunci',
            'dikunci' => 'selesai',
        ];
        if (($allowed[$forum['status']] ?? null) !== $newStatus) return false;

        $sets = ['status = :status'];
        $params = [':status' => $newStatus, ':id' => (int) $id];

        if ($newStatus === 'dikunci') {
            $sets[] = 'locked_at = NOW(3)';
        }

        $this->db->prepare("UPDATE forums SET " . implode(', ', $sets) . " WHERE id = :id")
            ->execute($params);

        Audit::log('forums', $id, 'update', $forum, $this->findById($id));
        $this->logHistory($id, null, 'status_' . $newStatus, ['dari' => $forum['status']], $reason);
        return true;
    }

    public function softDelete($id) {
        $forum = $this->findById($id);
        if (!$forum || $forum['status'] !== 'draft') return false;

        $this->db->prepare("UPDATE forums SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => (int) $id]);
        $this->db->prepare("UPDATE forum_participants SET deleted_at = NOW(3) WHERE forum_id = :fid AND deleted_at IS NULL")
            ->execute([':fid' => (int) $id]);

        Audit::log('forums', $id, 'delete', $forum, null);
        $this->logHistory($id, null, 'forum_dihapus', null, null);
        return true;
    }

    public function isLocked($forum) {
        return in_array($forum['status'], ['dikunci', 'selesai'], true);
    }

    public function isFinished($forum) {
        return $forum['status'] === 'selesai';
    }

    // ---------- Peringatan sebelum kunci ----------

    public function warningsBeforeLock($forumId) {
        $warnings = [];

        $noPhone = $this->db->prepare("
            SELECT p.full_name FROM forum_participants fp
            JOIN people p ON p.id = fp.person_id
            WHERE fp.forum_id = :fid AND fp.status = 'ditetapkan' AND fp.deleted_at IS NULL
              AND (p.phone IS NULL OR p.phone = '')
        ");
        $noPhone->execute([':fid' => (int) $forumId]);
        $rows = $noPhone->fetchAll();
        if ($rows) $warnings['tanpa_hp'] = $rows;

        $sharedPhone = $this->db->prepare("
            SELECT p.full_name, p.phone FROM forum_participants fp
            JOIN people p ON p.id = fp.person_id
            WHERE fp.forum_id = :fid AND fp.status = 'ditetapkan' AND fp.deleted_at IS NULL
              AND p.phone IS NOT NULL AND p.phone != ''
              AND (SELECT COUNT(*) FROM people p2 WHERE p2.phone = p.phone AND p2.deleted_at IS NULL) > 1
        ");
        $sharedPhone->execute([':fid' => (int) $forumId]);
        $rows = $sharedPhone->fetchAll();
        if ($rows) $warnings['hp_rangkap'] = $rows;

        $noPlacement = $this->db->prepare("
            SELECT p.full_name FROM forum_participants fp
            JOIN people p ON p.id = fp.person_id
            WHERE fp.forum_id = :fid AND fp.status = 'ditetapkan' AND fp.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM placements pl
                  JOIN terms t ON t.id = pl.term_id
                  WHERE pl.person_id = fp.person_id AND pl.deleted_at IS NULL AND t.is_current = 1
              )
        ");
        $noPlacement->execute([':fid' => (int) $forumId]);
        $rows = $noPlacement->fetchAll();
        if ($rows) $warnings['tanpa_penempatan'] = $rows;

        return $warnings;
    }

    // ---------- Riwayat ----------

    public function logHistory($forumId, $participantId, $action, $changes = null, $reason = null) {
        $this->db->prepare("
            INSERT INTO forum_history (forum_id, participant_id, action, changes, reason, after_lock, actor_id, actor_name)
            VALUES (:fid, :pid, :action, :changes, :reason, :after_lock, :aid, :aname)
        ")->execute([
            ':fid'        => (int) $forumId,
            ':pid'        => $participantId !== null ? (int) $participantId : null,
            ':action'     => $action,
            ':changes'    => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            ':reason'     => $reason,
            ':after_lock' => $this->isForumLockedById($forumId) ? 1 : 0,
            ':aid'        => class_exists('Auth') ? Auth::id() : null,
            ':aname'      => class_exists('Auth') ? Auth::name() : null,
        ]);
    }

    private function isForumLockedById($forumId) {
        $stmt = $this->db->prepare("SELECT status FROM forums WHERE id = :id");
        $stmt->execute([':id' => (int) $forumId]);
        $status = $stmt->fetchColumn();
        return in_array($status, ['dikunci', 'selesai'], true);
    }

    public function history($forumId) {
        $stmt = $this->db->prepare("
            SELECT h.*, p.full_name AS participant_name
            FROM forum_history h
            LEFT JOIN forum_participants fp ON fp.id = h.participant_id
            LEFT JOIN people p ON p.id = fp.person_id
            WHERE h.forum_id = :fid
            ORDER BY h.created_at DESC
        ");
        $stmt->execute([':fid' => (int) $forumId]);
        return $stmt->fetchAll();
    }
}
