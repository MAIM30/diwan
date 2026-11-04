<?php
// PATH LOKASI FILE: app/Models/ForumParticipantModel.php
// Peserta forum permusyawaratan.
//
// Satu orang = satu baris AKTIF per forum, dijaga oleh kolom generated active_guard + UNIQUE di DB.
// Pengganti = baris lama status 'diganti' + baris baru 'ditetapkan', dalam satu transaksi.

class ForumParticipantModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function byForum($forumId, $statusFilter = null) {
        $where = ['fp.forum_id = :fid', 'fp.deleted_at IS NULL'];
        $params = [':fid' => (int) $forumId];

        if ($statusFilter !== null) {
            $where[] = 'fp.status = :st';
            $params[':st'] = $statusFilter;
        }

        $stmt = $this->db->prepare("
            SELECT fp.*, p.full_name, p.title_prefix, p.name AS person_name, p.title_suffix,
                   p.phone, p.gender, p.uuid AS person_uuid,
                   s.level AS repr_level, s.name AS repr_structure_name, s.uuid AS repr_structure_uuid,
                   po.name AS position_name, pl_s.level AS basis_level, pl_s.name AS basis_structure_name,
                   rb.uuid AS replaced_by_uuid, rb_p.full_name AS replaced_by_name
            FROM forum_participants fp
            JOIN people p ON p.id = fp.person_id
            LEFT JOIN structures s ON s.id = fp.representing_structure_id
            LEFT JOIN placements pl ON pl.id = fp.basis_placement_id
            LEFT JOIN positions po ON po.id = pl.position_id
            LEFT JOIN structures pl_s ON pl_s.id = pl.structure_id
            LEFT JOIN forum_participants rb ON rb.id = fp.replaced_by_id
            LEFT JOIN people rb_p ON rb_p.id = rb.person_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY fp.status = 'ditetapkan' DESC, p.full_name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT fp.*, p.full_name, p.uuid AS person_uuid, p.phone,
                   s.level AS repr_level, s.name AS repr_structure_name
            FROM forum_participants fp
            JOIN people p ON p.id = fp.person_id
            LEFT JOIN structures s ON s.id = fp.representing_structure_id
            WHERE fp.id = :id AND fp.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function countActive($forumId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM forum_participants
            WHERE forum_id = :fid AND status = 'ditetapkan' AND deleted_at IS NULL
        ");
        $stmt->execute([':fid' => (int) $forumId]);
        return (int) $stmt->fetchColumn();
    }

    public function activePersonIds($forumId) {
        $stmt = $this->db->prepare("
            SELECT person_id FROM forum_participants
            WHERE forum_id = :fid AND status = 'ditetapkan' AND deleted_at IS NULL
        ");
        $stmt->execute([':fid' => (int) $forumId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function assign($forumId, array $data, $forum) {
        $uuid = Uuid::v4();
        $changedAfterLock = (new ForumModel())->isLocked($forum) ? 1 : 0;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO forum_participants
                    (uuid, forum_id, person_id, category, representing_structure_id, basis_placement_id,
                     status, decision_ref, decided_by, notes, changed_after_lock)
                VALUES (:uuid, :fid, :pid, :cat, :repr, :basis, 'ditetapkan', :ref, :decided, :notes, :cal)
            ");
            $stmt->execute([
                ':uuid'    => $uuid,
                ':fid'     => (int) $forumId,
                ':pid'     => (int) $data['person_id'],
                ':cat'     => $data['category'] ?? 'peserta_penuh',
                ':repr'    => !empty($data['representing_structure_id']) ? (int) $data['representing_structure_id'] : null,
                ':basis'   => !empty($data['basis_placement_id']) ? (int) $data['basis_placement_id'] : null,
                ':ref'     => trim($data['decision_ref'] ?? '') ?: null,
                ':decided' => Auth::id(),
                ':notes'   => trim($data['notes'] ?? '') ?: null,
                ':cal'     => $changedAfterLock,
            ]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'uq_fp_active') !== false) {
                throw new \RuntimeException('Orang ini sudah terdaftar sebagai peserta aktif di forum ini.');
            }
            throw $e;
        }

        $id = (int) $this->db->lastInsertId();
        Audit::log('forum_participants', $id, 'create', null, ['forum_id' => $forumId, 'person_id' => $data['person_id']]);
        (new ForumModel())->logHistory($forumId, $id, 'peserta_ditetapkan', [
            'category' => $data['category'] ?? 'peserta_penuh',
        ], $data['reason'] ?? null);
        return $id;
    }

    public function bulkAssign($forumId, array $personIds, array $defaults, $forum) {
        $added = 0;
        $skipped = 0;
        $existing = array_map('intval', $this->activePersonIds($forumId));

        foreach ($personIds as $pid) {
            $pid = (int) $pid;
            if (in_array($pid, $existing, true)) {
                $skipped++;
                continue;
            }
            try {
                $this->assign($forumId, array_merge($defaults, ['person_id' => $pid]), $forum);
                $added++;
            } catch (\RuntimeException $e) {
                $skipped++;
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    public function updateParticipant($id, array $data, $forum) {
        $before = $this->findById($id);
        if (!$before) return false;

        $changedAfterLock = (new ForumModel())->isLocked($forum) ? 1 : $before['changed_after_lock'];

        $this->db->prepare("
            UPDATE forum_participants SET
                category = :cat, representing_structure_id = :repr, basis_placement_id = :basis,
                decision_ref = :ref, notes = :notes, changed_after_lock = :cal
            WHERE id = :id AND deleted_at IS NULL
        ")->execute([
            ':cat'   => $data['category'] ?? $before['category'],
            ':repr'  => !empty($data['representing_structure_id']) ? (int) $data['representing_structure_id'] : null,
            ':basis' => !empty($data['basis_placement_id']) ? (int) $data['basis_placement_id'] : null,
            ':ref'   => trim($data['decision_ref'] ?? '') ?: null,
            ':notes' => trim($data['notes'] ?? '') ?: null,
            ':cal'   => $changedAfterLock,
            ':id'    => (int) $id,
        ]);

        Audit::log('forum_participants', $id, 'update', $before, $this->findById($id));
        (new ForumModel())->logHistory((int) $before['forum_id'], $id, 'peserta_diubah',
            ['before' => $before['category'], 'after' => $data['category'] ?? $before['category']],
            $data['reason'] ?? null);
        return true;
    }

    public function revoke($id, $reason, $forum) {
        $row = $this->findById($id);
        if (!$row || $row['status'] !== 'ditetapkan') return false;

        $changedAfterLock = (new ForumModel())->isLocked($forum) ? 1 : 0;

        $this->db->prepare("
            UPDATE forum_participants SET status = 'dicabut', changed_after_lock = :cal WHERE id = :id
        ")->execute([':cal' => $changedAfterLock, ':id' => (int) $id]);

        Audit::log('forum_participants', $id, 'update', $row, $this->findById($id));
        (new ForumModel())->logHistory((int) $row['forum_id'], $id, 'peserta_dicabut', null, $reason);
        return true;
    }

    public function replace($oldId, array $newData, $forum) {
        $old = $this->findById($oldId);
        if (!$old || $old['status'] !== 'ditetapkan') return false;

        $this->db->beginTransaction();
        try {
            $changedAfterLock = (new ForumModel())->isLocked($forum) ? 1 : 0;

            $newId = $this->assign((int) $old['forum_id'], $newData, $forum);

            $this->db->prepare("
                UPDATE forum_participants SET status = 'diganti', replaced_by_id = :new_id, changed_after_lock = :cal
                WHERE id = :id
            ")->execute([':new_id' => $newId, ':cal' => $changedAfterLock, ':id' => (int) $oldId]);

            Audit::log('forum_participants', $oldId, 'update', $old, $this->findById($oldId));
            (new ForumModel())->logHistory((int) $old['forum_id'], $oldId, 'peserta_diganti', [
                'pengganti_id' => $newId,
                'pengganti_person_id' => $newData['person_id'],
            ], $newData['reason'] ?? null);

            $this->db->commit();
            return $newId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function remove($id, $forum) {
        $row = $this->findById($id);
        if (!$row) return false;
        if ((new ForumModel())->isLocked($forum)) return false;

        $this->db->prepare("UPDATE forum_participants SET deleted_at = NOW(3) WHERE id = :id")
            ->execute([':id' => (int) $id]);

        Audit::log('forum_participants', $id, 'delete', $row, null);
        (new ForumModel())->logHistory((int) $row['forum_id'], $id, 'peserta_dihapus', null, null);
        return true;
    }

    public function categoryCounts($forumId) {
        $stmt = $this->db->prepare("
            SELECT category, COUNT(*) AS cnt
            FROM forum_participants
            WHERE forum_id = :fid AND status = 'ditetapkan' AND deleted_at IS NULL
            GROUP BY category
        ");
        $stmt->execute([':fid' => (int) $forumId]);
        $result = ['peserta_penuh' => 0, 'undangan' => 0, 'peninjau' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $result[$r['category']] = (int) $r['cnt'];
        }
        return $result;
    }
}
