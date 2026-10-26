<?php
// PATH LOKASI FILE: app/Models/TermModel.php
// Periode kepengurusan per struktur. Inti dari aplikasi ini: selama setiap penempatan terikat ke
// salah satu baris di sini, periode lama tidak pernah tertimpa oleh periode baru.

class TermModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT t.*, s.level, s.tier, s.name AS structure_name, s.uuid AS structure_uuid
            FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.id = :id AND t.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("
            SELECT t.*, s.level, s.name AS structure_name, s.uuid AS structure_uuid
            FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.uuid = :u AND t.deleted_at IS NULL
        ");
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function byStructure($structureId) {
        $stmt = $this->db->prepare("
            SELECT * FROM terms
            WHERE structure_id = :sid AND deleted_at IS NULL
            ORDER BY start_date DESC
        ");
        $stmt->execute([':sid' => (int) $structureId]);
        return $stmt->fetchAll();
    }

    public function currentOfStructure($structureId) {
        $stmt = $this->db->prepare("
            SELECT * FROM terms
            WHERE structure_id = :sid AND is_current = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':sid' => (int) $structureId]);
        return $stmt->fetch() ?: null;
    }

    // Seluruh periode dalam lingkup admin, dengan jumlah penempatannya -- kolom itu yang membuat
    // halaman daftar langsung berguna ("periode mana yang sudah terisi, mana yang masih kosong").
    public function allInScope() {
        return $this->db->query("
            SELECT t.*, s.level, s.name AS structure_name,
                   s.path AS structure_path, s.tier AS structure_tier,
                   (SELECT COUNT(*) FROM placements pl
                     WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) AS placement_count
            FROM terms t
            JOIN structures s ON s.id = t.structure_id
            WHERE t.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id') . "
            ORDER BY s.path, t.start_date DESC
        ")->fetchAll();
    }

    public function create(array $data) {
        $structureId = (int) $data['structure_id'];
        $isCurrent = !empty($data['is_current']) ? 1 : 0;

        $this->db->beginTransaction();
        try {
            // Database sudah menjamin maksimal satu periode is_current per struktur lewat
            // uq_terms_one_current. Di sini kita MEMINDAHKAN penanda itu, bukan menabraknya:
            // periode lama diturunkan dulu, baru yang baru dinaikkan.
            if ($isCurrent) {
                $this->clearCurrent($structureId);
            }

            $stmt = $this->db->prepare("
                INSERT INTO terms (uuid, structure_id, name, start_date, end_date_planned, end_date_actual,
                                   status, is_current, sk_number, sk_date, notes)
                VALUES (:uuid, :sid, :name, :start, :planned, :actual, :status, :current, :sk_no, :sk_date, :notes)
            ");
            $stmt->execute([
                ':uuid'    => Uuid::v4(),
                ':sid'     => $structureId,
                ':name'    => trim($data['name']),
                ':start'   => $data['start_date'],
                ':planned' => $data['end_date_planned'] ?: null,
                ':actual'  => $data['end_date_actual'] ?: null,
                ':status'  => $data['status'],
                ':current' => $isCurrent,
                ':sk_no'   => $data['sk_number'] !== '' ? $data['sk_number'] : null,
                ':sk_date' => $data['sk_date'] ?: null,
                ':notes'   => $data['notes'] !== '' ? $data['notes'] : null,
            ]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
            Audit::log('terms', $id, 'create', null, $this->findById($id));
            return $id;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Memasang / mengganti / mencabut berkas SK sebuah periode. Berkas lama dihapus dari disk HANYA bila tak
    // dipakai periode lain: satu SK bisa mengesahkan dua periode sekaligus (mis. DPP dan MWP pada SK yang sama).
    public function setSkFile($id, $rel) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;
        $old = $before['sk_file_path'];
        $this->db->prepare("UPDATE terms SET sk_file_path = :p WHERE id = :id")->execute([':p' => $rel, ':id' => $id]);
        Audit::log('terms', $id, 'update', $before, $this->findById($id));
        if ($old && $old !== $rel) {
            $used = $this->db->prepare("SELECT COUNT(*) FROM terms WHERE sk_file_path = :p AND id <> :id");
            $used->execute([':p' => $old, ':id' => $id]);
            if (!(int) $used->fetchColumn()) SkFile::remove($old);
        }
        return true;
    }

    // Cakupan jejak SK sebuah periode: berapa penempatannya yang sudah tertaut ke SK (placements.sk_reference).
    public function skCoverage($termId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(sk_reference IS NOT NULL AND sk_reference <> ''), 0) AS linked
            FROM placements WHERE term_id = :t AND deleted_at IS NULL
        ");
        $stmt->execute([':t' => (int) $termId]);
        $r = $stmt->fetch();
        return ['total' => (int) $r['total'], 'linked' => (int) $r['linked']];
    }

    public function update($id, array $data) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $isCurrent = !empty($data['is_current']) ? 1 : 0;

        $this->db->beginTransaction();
        try {
            if ($isCurrent) {
                $this->clearCurrent((int) $before['structure_id'], $id);
            }

            $stmt = $this->db->prepare("
                UPDATE terms SET name = :name, start_date = :start, end_date_planned = :planned,
                       end_date_actual = :actual, status = :status, is_current = :current,
                       sk_number = :sk_no, sk_date = :sk_date, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'    => trim($data['name']),
                ':start'   => $data['start_date'],
                ':planned' => $data['end_date_planned'] ?: null,
                ':actual'  => $data['end_date_actual'] ?: null,
                ':status'  => $data['status'],
                ':current' => $isCurrent,
                ':sk_no'   => $data['sk_number'] !== '' ? $data['sk_number'] : null,
                ':sk_date' => $data['sk_date'] ?: null,
                ':notes'   => $data['notes'] !== '' ? $data['notes'] : null,
                ':id'      => $id,
            ]);

            $this->db->commit();
            Audit::log('terms', $id, 'update', $before, $this->findById($id));
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function clearCurrent($structureId, $exceptId = null) {
        $sql = "UPDATE terms SET is_current = 0 WHERE structure_id = :sid AND is_current = 1";
        $params = [':sid' => (int) $structureId];
        if ($exceptId !== null) {
            $sql .= " AND id <> :except";
            $params[':except'] = (int) $exceptId;
        }
        $this->db->prepare($sql)->execute($params);
    }

    public function softDelete($id) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        // Periode yang masih memegang penempatan tidak boleh dihapus: itu justru arsip yang
        // keberadaannya jadi alasan aplikasi ini dibuat.
        $used = $this->db->prepare("SELECT COUNT(*) FROM placements WHERE term_id = :id AND deleted_at IS NULL");
        $used->execute([':id' => $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Periode ini masih berisi penempatan pengurus dan tidak boleh dihapus.');
        }

        $this->db->prepare("UPDATE terms SET deleted_at = NOW(3), is_current = 0 WHERE id = :id")
                 ->execute([':id' => $id]);
        Audit::log('terms', $id, 'delete', $before, null);
        return true;
    }

    // Menyalin susunan Bidang + unit dari periode lain ke periode ini. Dibutuhkan karena
    // unit_groups/structure_units terikat periode: tanpa penyalinan ini, setiap periode baru harus
    // disusun ulang dari nol. Penempatan TIDAK ikut disalin -- orangnya memang berganti.
    public function cloneUnitsFrom($sourceTermId, $targetTermId) {
        $source = $this->findById($sourceTermId);
        $target = $this->findById($targetTermId);
        if (!$source || !$target) {
            throw new RuntimeException('Periode asal atau tujuan tidak ditemukan.');
        }
        if ((int) $source['structure_id'] !== (int) $target['structure_id']) {
            throw new RuntimeException('Penyalinan hanya bisa antar periode pada struktur yang sama.');
        }

        $this->db->beginTransaction();
        try {
            // 1. Salin Bidang, sambil mencatat pemetaan id lama -> id baru.
            $groups = $this->db->prepare("
                SELECT * FROM unit_groups WHERE term_id = :t AND deleted_at IS NULL ORDER BY sort_order, name
            ");
            $groups->execute([':t' => (int) $sourceTermId]);

            $map = [];
            $insG = $this->db->prepare("
                INSERT INTO unit_groups (uuid, structure_id, term_id, name, kind, sort_order)
                VALUES (:uuid, :sid, :tid, :name, :kind, :sort)
            ");
            foreach ($groups->fetchAll() as $g) {
                $insG->execute([
                    ':uuid' => Uuid::v4(),
                    ':sid'  => (int) $target['structure_id'],
                    ':tid'  => (int) $targetTermId,
                    ':name' => $g['name'],
                    ':kind' => $g['kind'],
                    ':sort' => $g['sort_order'],
                ]);
                $map[(int) $g['id']] = (int) $this->db->lastInsertId();
            }

            // 2. Salin unit, mengarahkan unit_group_id ke Bidang hasil salinan di langkah 1.
            $units = $this->db->prepare("
                SELECT * FROM structure_units WHERE term_id = :t AND deleted_at IS NULL ORDER BY sort_order
            ");
            $units->execute([':t' => (int) $sourceTermId]);

            $insU = $this->db->prepare("
                INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id, unit_group_id, display_name, sort_order)
                VALUES (:uuid, :sid, :tid, :cat, :grp, :disp, :sort)
            ");
            $count = 0;
            foreach ($units->fetchAll() as $u) {
                $insU->execute([
                    ':uuid' => Uuid::v4(),
                    ':sid'  => (int) $target['structure_id'],
                    ':tid'  => (int) $targetTermId,
                    ':cat'  => (int) $u['unit_catalog_id'],
                    ':grp'  => isset($map[(int) $u['unit_group_id']]) ? $map[(int) $u['unit_group_id']] : null,
                    ':disp' => $u['display_name'],
                    ':sort' => $u['sort_order'],
                ]);
                $count++;
            }

            $this->db->commit();
            Audit::log('terms', $targetTermId, 'update', null,
                ['cloned_from_term_id' => (int) $sourceTermId, 'groups' => count($map), 'units' => $count]);
            return ['groups' => count($map), 'units' => $count];
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
