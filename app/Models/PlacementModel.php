<?php
// PATH LOKASI FILE: app/Models/PlacementModel.php
// Penempatan jabatan: orang + struktur + unit (opsional) + jabatan + PERIODE.
//
// Tabel ini satu-satunya tempat riwayat kepengurusan hidup. Prinsip yang dijaga di seluruh model
// ini: penempatan periode lama TIDAK PERNAH diubah oleh pengisian periode baru. Memulai periode
// baru berarti MENAMBAH baris, bukan memperbarui baris lama.

require_once ROOT_DIR . '/app/Models/PositionModel.php'; // PositionModel::CORE_SQL: batas Pengurus Inti

class PlacementModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT pl.*, p.full_name, p.title_prefix, p.name, p.title_suffix, p.photo_path, p.uuid AS person_uuid, p.gender,
                   po.name AS position_name, " . PositionModel::CORE_SQL . " AS is_core, po.sort_order AS position_sort,
                   s.level, s.name AS structure_name,
                   t.name AS term_name, t.start_date AS term_start, t.status AS term_status,
                   c.name AS unit_name, g.name AS group_name
            FROM placements pl
            JOIN people p ON p.id = pl.person_id
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            JOIN terms t ON t.id = pl.term_id
            LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE pl.id = :id AND pl.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Seluruh riwayat jabatan satu orang, lintas periode dan lintas struktur. Inilah tampilan yang
    // tidak mungkin dibuat di skema syuro, karena di sana periode cuma dua kolom di baris user.
    public function historyOfPerson($personId) {
        $stmt = $this->db->prepare("
            SELECT pl.*, po.name AS position_name, " . PositionModel::CORE_SQL . " AS is_core,
                   s.level, s.name AS structure_name, s.wing,
                   t.name AS term_name, t.start_date AS term_start, t.end_date_planned, t.end_date_actual,
                   t.status AS term_status, t.is_current,
                   c.name AS unit_name, g.name AS group_name
            FROM placements pl
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            JOIN terms t ON t.id = pl.term_id
            LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE pl.person_id = :pid AND pl.deleted_at IS NULL
            ORDER BY t.start_date DESC, po.sort_order
        ");
        $stmt->execute([':pid' => (int) $personId]);
        return $stmt->fetchAll();
    }

    // Penempatan satu periode, terurut protokoler: pucuk pimpinan dulu (unit NULL), lalu
    // per Bidang, lalu per unit, lalu per urutan jabatan di dalam unit. Pengelompokan per kategori ada di Susunan::arrange.
    // "Pengurus Inti" = jabatan tingkat struktur (structure_unit_id IS NULL) yang di katalog jabatan inti bagi struktur ini:
    // is_core = 1, atau core_from_tier <= tier struktur (PositionModel::CORE_SQL); tak ada daftar nama jabatan yang dipatri di kode.
    public function byTerm($termId, $unitId = null) {
        $sql = "
            SELECT pl.*, p.full_name, p.title_prefix, p.name, p.title_suffix, p.photo_path, p.uuid AS person_uuid, p.gender, p.phone,
                   po.name AS position_name, " . PositionModel::CORE_SQL . " AS is_core, po.sort_order AS position_sort,
                   c.name AS unit_name, c.abbreviation AS unit_abbr,
                   g.name AS group_name, g.sort_order AS group_sort
            FROM placements pl
            JOIN people p ON p.id = pl.person_id
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE pl.term_id = :tid AND pl.deleted_at IS NULL
        ";
        $params = [':tid' => (int) $termId];

        if ($unitId === 'none') {
            $sql .= " AND pl.structure_unit_id IS NULL";
        } elseif ($unitId !== null) {
            $sql .= " AND pl.structure_unit_id = :uid";
            $params[':uid'] = (int) $unitId;
        }

        // ISNULL(...) DESC menaruh penempatan tanpa unit (pucuk pimpinan) di urutan paling atas.
        $sql .= " ORDER BY ISNULL(pl.structure_unit_id) DESC, COALESCE(g.sort_order, 9999), g.name,
                           su.sort_order, c.name, po.sort_order, p.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $data) {
        $structureId = (int) $data['structure_id'];
        $termId = (int) $data['term_id'];

        try {
            $stmt = $this->db->prepare("
                INSERT INTO placements (uuid, person_id, term_id, structure_id, structure_unit_id,
                                        position_id, is_primary, start_date, end_date, end_reason,
                                        source, sk_reference, notes)
                VALUES (:uuid, :person, :term, :structure, :unit, :position, :primary,
                        :start, :end, :reason, :source, :sk_ref, :notes)
            ");
            $stmt->execute([
                ':uuid'      => Uuid::v4(),
                ':person'    => (int) $data['person_id'],
                ':term'      => $termId,
                ':structure' => $structureId,
                ':unit'      => !empty($data['structure_unit_id']) ? (int) $data['structure_unit_id'] : null,
                ':position'  => (int) $data['position_id'],
                ':primary'   => !empty($data['is_primary']) ? 1 : 0,
                ':start'     => !empty($data['start_date']) ? $data['start_date'] : null,
                ':end'       => !empty($data['end_date']) ? $data['end_date'] : null,
                ':reason'    => !empty($data['end_reason']) ? $data['end_reason'] : null,
                ':source'    => $data['source'] ?? 'manual',
                ':sk_ref'    => !empty($data['sk_reference']) ? $data['sk_reference'] : null,
                ':notes'     => !empty($data['notes']) ? $data['notes'] : null,
            ]);
            $id = (int) $this->db->lastInsertId();
            Audit::log('placements', $id, 'create', null, $this->findById($id));
            return $id;
        } catch (PDOException $e) {
            // Menerjemahkan penolakan database jadi pesan yang bisa ditindaklanjuti pengguna.
            // Penolakan ini BUKAN bug: justru itu penjaga yang kita pasang di skema.
            throw new RuntimeException($this->explainDbError($e), 0, $e);
        }
    }

    public function update($id, array $data) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        try {
            $stmt = $this->db->prepare("
                UPDATE placements SET structure_unit_id = :unit, position_id = :position,
                       is_primary = :primary, start_date = :start, end_date = :end,
                       end_reason = :reason, sk_reference = :sk_ref, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':unit'     => !empty($data['structure_unit_id']) ? (int) $data['structure_unit_id'] : null,
                ':position' => (int) $data['position_id'],
                ':primary'  => !empty($data['is_primary']) ? 1 : 0,
                ':start'    => !empty($data['start_date']) ? $data['start_date'] : null,
                ':end'      => !empty($data['end_date']) ? $data['end_date'] : null,
                ':reason'   => !empty($data['end_reason']) ? $data['end_reason'] : null,
                ':sk_ref'   => !empty($data['sk_reference']) ? $data['sk_reference'] : null,
                ':notes'    => !empty($data['notes']) ? $data['notes'] : null,
                ':id'       => $id,
            ]);
            Audit::log('placements', $id, 'update', $before, $this->findById($id));
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException($this->explainDbError($e), 0, $e);
        }
    }

    // Mengakhiri penempatan di tengah periode (mundur/mutasi/wafat/diberhentikan) TANPA menghapus
    // barisnya -- yang bersangkutan memang pernah menjabat, dan itu bagian dari riwayat.
    public function end($id, $endDate, $reason) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $stmt = $this->db->prepare("UPDATE placements SET end_date = :d, end_reason = :r WHERE id = :id");
        $stmt->execute([':d' => $endDate, ':r' => $reason, ':id' => $id]);
        Audit::log('placements', $id, 'update', $before, $this->findById($id));
        return true;
    }

    // Soft delete, untuk kekeliruan entri (bukan untuk pengurus yang selesai menjabat -- itu end()).
    public function softDelete($id) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $this->db->prepare("UPDATE placements SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => $id]);
        Audit::log('placements', $id, 'delete', $before, null);
        return true;
    }

    private function explainDbError(PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'uq_placements_live') !== false) {
            return 'Orang ini sudah tercatat pada jabatan yang sama di unit dan periode yang sama.';
        }
        if (strpos($msg, 'fk_placements_unit') !== false) {
            return 'Unit yang dipilih bukan milik periode ini. Pilih unit dari periode yang sedang dikerjakan.';
        }
        if (strpos($msg, 'fk_placements_term') !== false) {
            return 'Periode yang dipilih bukan milik struktur ini.';
        }
        error_log('Penempatan gagal: ' . $msg);
        return 'Data penempatan tidak bisa disimpan. Periksa kembali pilihan periode, unit, dan jabatan.';
    }
}
