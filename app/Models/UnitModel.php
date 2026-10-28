<?php
// PATH LOKASI FILE: app/Models/UnitModel.php
// SKUO: katalog unit bersama (unit_catalog), Bidang per periode (unit_groups), dan unit yang
// benar-benar ada di satu struktur pada satu periode (structure_units).
//
// Pembagian ini menjawab syarat yang paling mudah salah: SATU nama unit ("Departemen Kesehatan")
// dipakai lebih dari satu struktur, dan Bidang penaungnya BERBEDA tergantung strukturnya -- DPP
// menaungi lewat "Bidang Pelayanan Umat", Muslimah Pusat lewat "Subbidang Pelayanan Umat". Karena
// itu Bidang tidak pernah jadi atribut katalog unit; ia hidup di relasi struktur+periode+unit.

require_once ROOT_DIR . '/app/Models/PositionModel.php'; // PositionModel::CORE_SQL: batas Pengurus Inti

class UnitModel {
    // Urutan tampil jenis unit, dipakai kartu rekap maupun pengurutan tabel supaya keduanya
    // tidak pernah berselisih. Nilainya literal (bukan masukan pengguna), jadi aman disisipkan
    // ke FIELD() di dalam SQL.
    const KIND_ORDER = ['departemen', 'lembaga', 'biro', 'badan', 'pusat', 'divisi', 'lainnya'];

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ---------- Katalog unit (lintas struktur) ----------

    public function catalogAll($activeOnly = false) {
        $sql = "SELECT * FROM unit_catalog" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY name";
        return $this->db->query($sql)->fetchAll();
    }

    // Jumlah entri katalog per jenis, untuk kartu rekap. Tidak ikut difilter: kartu harus tetap
    // menunjukkan seluruh isi katalog walau daftarnya sedang disaring, kalau tidak angkanya
    // berubah setiap kali disaring dan tidak bisa dipakai membandingkan.
    public function catalogCountsByKind() {
        return $this->db->query("SELECT kind, COUNT(*) FROM unit_catalog GROUP BY kind")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Katalog beserta JUMLAH PEMAKAIANNYA. "Dipakai" adalah satu-satunya angka yang menjawab
    // apakah sebuah entri katalog masih hidup atau sudah mati -- hal yang tidak bisa dibaca dari
    // daftar nama saja, dan itulah yang membuat 105 baris bisa dievaluasi, bukan cuma dibaca.
    //   used_count      = berapa penempatan unit (structure_units) menunjuk entri ini
    //   used_structures = di berapa struktur berbeda
    // $flat = true: satu daftar menurut urutan resmi SKUO (sort_order), bukan dipecah per jenis.
    public function catalogList($kind = '', $keyword = '', $flat = false) {
        $where = [];
        $params = [];

        if ($kind !== '' && in_array($kind, self::KIND_ORDER, true)) {
            $where[] = 'c.kind = :kind';
            $params[':kind'] = $kind;
        }

        // LIKE: % _ \ dari pengguna dinetralkan, kalau tidak "50%" cocok dengan semuanya.
        // Tiap placeholder bernama dipakai SEKALI (ATTR_EMULATE_PREPARES=false menolak ganda).
        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $where[] = '(c.name LIKE :kw_name OR c.abbreviation LIKE :kw_abbr)';
            $params[':kw_name'] = $like;
            $params[':kw_abbr'] = $like;
        }

        $order = $flat ? "c.sort_order" : "FIELD(c.kind, '" . implode("','", self::KIND_ORDER) . "'), c.sort_order";
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS head_name,
                   (SELECT COUNT(*) FROM structure_units su
                     WHERE su.unit_catalog_id = c.id AND su.deleted_at IS NULL) AS used_count,
                   (SELECT COUNT(DISTINCT su2.structure_id) FROM structure_units su2
                     WHERE su2.unit_catalog_id = c.id AND su2.deleted_at IS NULL) AS used_structures
            FROM unit_catalog c
            LEFT JOIN positions p ON p.id = c.default_head_position_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY $order, c.name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function catalogFindById($id) {
        $stmt = $this->db->prepare("SELECT * FROM unit_catalog WHERE id = :id");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Katalog dipakai bersama, jadi nama yang sudah ada DIPAKAI ULANG, tidak diduplikasi.
    public function catalogFindOrCreate($name, $kind = 'lainnya', $abbreviation = null) {
        $name = trim($name);
        $stmt = $this->db->prepare("SELECT * FROM unit_catalog WHERE name = :n LIMIT 1");
        $stmt->execute([':n' => $name]);
        if ($row = $stmt->fetch()) {
            return (int) $row['id'];
        }

        $ins = $this->db->prepare("
            INSERT INTO unit_catalog (uuid, name, abbreviation, kind, default_head_position_id)
            VALUES (:uuid, :name, :abbr, :kind, :head)
        ");
        $ins->execute([
            ':uuid' => Uuid::v4(),
            ':name' => $name,
            ':abbr' => $abbreviation ?: null,
            ':kind' => $kind,
            ':head' => $this->defaultHeadPositionId($kind),
        ]);
        return (int) $this->db->lastInsertId();
    }

    // Untuk isian "ketik nama unit" (halaman Pengelompokan Unit): nama yang sudah ada di katalog dipakai ulang (jenisnya tidak
    // diubah), yang belum ada dibuat dan dicatat. Mengembalikan [id katalog, apakah baru dibuat].
    public function catalogEnsure($name, $kind) {
        $stmt = $this->db->prepare("SELECT id FROM unit_catalog WHERE name = :n LIMIT 1");
        $stmt->execute([':n' => trim($name)]);
        if ($id = $stmt->fetchColumn()) return [(int) $id, false];
        return [$this->catalogSave(null, ['name' => $name, 'abbreviation' => '', 'kind' => $kind, 'is_active' => true]), true];
    }

    // Nomor urut berikutnya di urutan resmi SKUO: kelipatan 10 di atas yang terbesar, melewati 500 (500 = "belum diurutkan").
    public function catalogNextOrder() {
        $max = (int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM unit_catalog WHERE sort_order <> 500")->fetchColumn();
        $next = (int) (floor($max / 10) * 10) + 10;
        return $next === 500 ? 510 : min($next, 65535);
    }

    // $data['sort_order'] kosong/tak ada: entri baru memakai nomor urut berikutnya, entri lama tidak berubah urutannya.
    public function catalogSave($id, array $data) {
        $hasOrder = isset($data['sort_order']) && $data['sort_order'] !== '';
        $order = $hasOrder ? max(0, min(65535, (int) $data['sort_order'])) : null;

        if ($id) {
            $stmt = $this->db->prepare("
                UPDATE unit_catalog SET name = :name, abbreviation = :abbr, kind = :kind,
                       default_head_position_id = :head, is_active = :active, sort_order = COALESCE(:sort, sort_order)
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'   => trim($data['name']),
                ':abbr'   => $data['abbreviation'] !== '' ? $data['abbreviation'] : null,
                ':kind'   => $data['kind'],
                ':head'   => $this->defaultHeadPositionId($data['kind']),
                ':active' => !empty($data['is_active']) ? 1 : 0,
                ':sort'   => $order,
                ':id'     => (int) $id,
            ]);
            Audit::log('unit_catalog', $id, 'update', null, $data);
            return (int) $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO unit_catalog (uuid, name, abbreviation, kind, default_head_position_id, sort_order)
            VALUES (:uuid, :name, :abbr, :kind, :head, :sort)
        ");
        $stmt->execute([
            ':uuid' => Uuid::v4(),
            ':name' => trim($data['name']),
            ':abbr' => $data['abbreviation'] !== '' ? $data['abbreviation'] : null,
            ':kind' => $data['kind'],
            ':head' => $this->defaultHeadPositionId($data['kind']),
            ':sort' => $order ?? $this->catalogNextOrder(),
        ]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('unit_catalog', $newId, 'create', null, $data);
        return $newId;
    }

    public function catalogDelete($id) {
        $cat = $this->catalogFindById($id);
        if (!$cat) return false;

        $used = $this->db->prepare(
            "SELECT COUNT(*) FROM structure_units WHERE unit_catalog_id = :id AND deleted_at IS NULL"
        );
        $used->execute([':id' => (int) $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Unit ini masih dipakai di periode yang aktif. Hapus penempatannya dari periode itu lebih dulu.');
        }

        $this->db->prepare("DELETE FROM unit_catalog WHERE id = :id")->execute([':id' => (int) $id]);
        Audit::log('unit_catalog', $id, 'delete', $cat, null);
        return true;
    }

    // Istilah kepala unit mengikuti JENIS unitnya: Pusat dipimpin "Kepala", Divisi oleh
    // "Koordinator", sisanya oleh "Ketua". Ini default saat entri, bukan batasan keras.
    private function defaultHeadPositionId($kind) {
        $code = $kind === 'pusat' ? 'kepala' : ($kind === 'divisi' ? 'koordinator' : 'ketua');
        $stmt = $this->db->prepare("SELECT id FROM positions WHERE code = :c LIMIT 1");
        $stmt->execute([':c' => $code]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ---------- Bidang (per struktur + periode) ----------

    // Ketua Bidang ikut dibawa: coordinator_* kosong bila belum ditetapkan ATAU penempatannya sudah dihapus/diakhiri
    // (penempatan yang dihapus tidak memicu ON DELETE SET NULL karena hanya diberi deleted_at, jadi dicek di sini).
    public function groupsByTerm($termId) {
        $stmt = $this->db->prepare("
            SELECT g.*, (SELECT COUNT(*) FROM structure_units su
                          WHERE su.unit_group_id = g.id AND su.deleted_at IS NULL) AS unit_count,
                   cp.id AS coordinator_id, cp.person_id AS coordinator_person_id, cpe.full_name AS coordinator_name,
                   cpo.name AS coordinator_position
            FROM unit_groups g
            LEFT JOIN placements cp ON cp.id = g.coordinator_placement_id AND cp.deleted_at IS NULL
                                    AND (cp.end_date IS NULL OR cp.end_date >= CURDATE())
            LEFT JOIN people cpe ON cpe.id = cp.person_id AND cpe.deleted_at IS NULL
            LEFT JOIN positions cpo ON cpo.id = cp.position_id
            WHERE g.term_id = :t AND g.deleted_at IS NULL
            ORDER BY g.sort_order, g.name
        ");
        $stmt->execute([':t' => (int) $termId]);
        return $stmt->fetchAll();
    }

    // Calon Ketua Bidang: penempatan tingkat struktur (tanpa unit) berjabatan Ketua / Wakil Ketua yang inti di struktur ini
    // dan masih berlaku pada periode itu. Satu-satunya sumber pilihan di formulir, jadi isian lain tidak bisa dikirim.
    public function coordinatorCandidates($termId) {
        $stmt = $this->db->prepare("
            SELECT pl.id, p.full_name, po.name AS position_name
            FROM placements pl
            JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            WHERE pl.term_id = :t AND pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL
              AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
              AND po.code IN ('ketua', 'wakil_ketua') AND " . PositionModel::CORE_SQL . "
            ORDER BY po.sort_order, p.name
        ");
        $stmt->execute([':t' => (int) $termId]);
        return $stmt->fetchAll();
    }

    // Penempatan itu sah dijadikan Ketua Bidang di periode $termId? (id yang dikirim formulir tidak dipercaya begitu saja)
    public function isCoordinatorCandidate($termId, $placementId) {
        foreach ($this->coordinatorCandidates($termId) as $c) {
            if ((int) $c['id'] === (int) $placementId) return true;
        }
        return false;
    }

    // Ringkasan PENGURUS INTI (entri bawaan sistem di Unit Organisasi): jabatan yang menjadi inti dan pemakaiannya pada periode
    // berjalan. Tidak disimpan di katalog -- inti tetap berupa penempatan tanpa unit, aturannya satu-satunya ada di PositionModel::CORE_SQL.
    public function intiInfo() {
        $positions = $this->db->query("
            SELECT name, is_core, core_from_tier FROM positions
            WHERE is_active = 1 AND (is_core = 1 OR core_from_tier IS NOT NULL) ORDER BY sort_order
        ")->fetchAll();

        $use = $this->db->query("
            SELECT COUNT(DISTINCT pl.structure_id) AS structures, COUNT(*) AS placements
            FROM placements pl
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            JOIN terms t ON t.id = pl.term_id AND t.is_current = 1
            WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND " . PositionModel::CORE_SQL . "
        ")->fetch();

        return ['positions' => $positions, 'structures' => (int) $use['structures'], 'placements' => (int) $use['placements']];
    }

    public function groupFindById($id) {
        $stmt = $this->db->prepare("SELECT * FROM unit_groups WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // $data['coordinator_placement_id']: hanya ditulis bila kuncinya ada (null/0 = kosongkan). Formulir lama yang tak
    // mengirimnya tidak menghapus Ketua Bidang yang sudah ditetapkan.
    public function groupSave($id, array $data) {
        if ($id) {
            $setCoord = array_key_exists('coordinator_placement_id', $data);
            $stmt = $this->db->prepare("
                UPDATE unit_groups SET name = :name, kind = :kind, sort_order = :sort"
                . ($setCoord ? ", coordinator_placement_id = :coord" : "") . " WHERE id = :id
            ");
            $params = [
                ':name' => trim($data['name']),
                ':kind' => $data['kind'],
                ':sort' => (int) $data['sort_order'],
                ':id'   => (int) $id,
            ];
            if ($setCoord) $params[':coord'] = !empty($data['coordinator_placement_id']) ? (int) $data['coordinator_placement_id'] : null;
            $stmt->execute($params);
            Audit::log('unit_groups', $id, 'update', null, $data);
            return (int) $id;
        }

        // Nama Bidang unik per periode, termasuk yang sudah dihapus (soft delete). Membuat ulang Bidang yang pernah dihapus
        // berarti menghidupkan barisnya kembali, bukan menabrak kunci uniknya.
        $dead = $this->db->prepare("SELECT id FROM unit_groups WHERE term_id = :t AND name = :n AND deleted_at IS NOT NULL LIMIT 1");
        $dead->execute([':t' => (int) $data['term_id'], ':n' => trim($data['name'])]);
        if ($deadId = $dead->fetchColumn()) {
            $this->db->prepare("UPDATE unit_groups SET deleted_at = NULL, kind = :kind, sort_order = :sort WHERE id = :id")
                     ->execute([':kind' => $data['kind'], ':sort' => (int) $data['sort_order'], ':id' => (int) $deadId]);
            Audit::log('unit_groups', $deadId, 'update', null, $data);
            return (int) $deadId;
        }

        $stmt = $this->db->prepare("
            INSERT INTO unit_groups (uuid, structure_id, term_id, name, kind, sort_order)
            VALUES (:uuid, :sid, :tid, :name, :kind, :sort)
        ");
        $stmt->execute([
            ':uuid' => Uuid::v4(),
            ':sid'  => (int) $data['structure_id'],
            ':tid'  => (int) $data['term_id'],
            ':name' => trim($data['name']),
            ':kind' => $data['kind'],
            ':sort' => (int) $data['sort_order'],
        ]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('unit_groups', $newId, 'create', null, $data);
        return $newId;
    }

    public function groupFindOrCreate($structureId, $termId, $name, $kind = 'bidang', $sortOrder = 500) {
        $name = trim($name);
        if ($name === '') return null;

        $stmt = $this->db->prepare("
            SELECT id FROM unit_groups WHERE term_id = :t AND name = :n AND deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':t' => (int) $termId, ':n' => $name]);
        if ($id = $stmt->fetchColumn()) {
            return (int) $id;
        }
        return $this->groupSave(null, [
            'structure_id' => $structureId,
            'term_id'      => $termId,
            'name'         => $name,
            'kind'         => $kind,
            'sort_order'   => $sortOrder,
        ]);
    }

    public function groupDelete($id) {
        $before = $this->groupFindById($id);
        if (!$before) return false;

        // Database menolak lewat fk_structure_units_group (RESTRICT), tapi pesan di situ tidak bisa
        // dibaca pengguna. Dicek di sini supaya penjelasannya jelas.
        $used = $this->db->prepare("SELECT COUNT(*) FROM structure_units WHERE unit_group_id = :id AND deleted_at IS NULL");
        $used->execute([':id' => (int) $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Bidang ini masih menaungi unit. Pindahkan unitnya ke Bidang lain lebih dulu.');
        }

        $this->db->prepare("UPDATE unit_groups SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => (int) $id]);
        Audit::log('unit_groups', $id, 'delete', $before, null);
        return true;
    }

    // ---------- Unit per struktur+periode ----------

    // Daftar unit satu periode, dikelompokkan Bidang, lengkap dengan jumlah pengurusnya.
    public function unitsByTerm($termId) {
        $stmt = $this->db->prepare("
            SELECT su.*, c.name AS catalog_name, c.abbreviation, c.kind,
                   g.name AS group_name, g.kind AS group_kind, g.sort_order AS group_sort,
                   (SELECT COUNT(*) FROM placements pl
                     WHERE pl.structure_unit_id = su.id AND pl.deleted_at IS NULL) AS placement_count
            FROM structure_units su
            JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE su.term_id = :t AND su.deleted_at IS NULL
            ORDER BY COALESCE(g.sort_order, 9999), g.name, su.sort_order, c.name
        ");
        $stmt->execute([':t' => (int) $termId]);
        return $stmt->fetchAll();
    }

    public function unitFindById($id) {
        $stmt = $this->db->prepare("
            SELECT su.*, c.name AS catalog_name, c.kind, g.name AS group_name
            FROM structure_units su
            JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE su.id = :id AND su.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function unitSave($id, array $data) {
        if ($id) {
            $stmt = $this->db->prepare("
                UPDATE structure_units SET unit_group_id = :grp, display_name = :disp, sort_order = :sort
                WHERE id = :id
            ");
            $stmt->execute([
                ':grp'  => !empty($data['unit_group_id']) ? (int) $data['unit_group_id'] : null,
                ':disp' => $data['display_name'] !== '' ? $data['display_name'] : null,
                ':sort' => (int) $data['sort_order'],
                ':id'   => (int) $id,
            ]);
            Audit::log('structure_units', $id, 'update', null, $data);
            return (int) $id;
        }

        // Sama seperti Bidang: unit yang pernah dihapus dari periode ini dihidupkan kembali, bukan ditolak sebagai "sudah terdaftar".
        $dead = $this->db->prepare("SELECT id FROM structure_units WHERE term_id = :t AND unit_catalog_id = :c AND deleted_at IS NOT NULL LIMIT 1");
        $dead->execute([':t' => (int) $data['term_id'], ':c' => (int) $data['unit_catalog_id']]);
        if ($deadId = $dead->fetchColumn()) {
            $this->db->prepare("UPDATE structure_units SET deleted_at = NULL, unit_group_id = :grp, display_name = :disp, sort_order = :sort WHERE id = :id")
                     ->execute([
                         ':grp'  => !empty($data['unit_group_id']) ? (int) $data['unit_group_id'] : null,
                         ':disp' => $data['display_name'] !== '' ? $data['display_name'] : null,
                         ':sort' => (int) $data['sort_order'],
                         ':id'   => (int) $deadId,
                     ]);
            Audit::log('structure_units', $deadId, 'update', null, $data);
            return (int) $deadId;
        }

        $stmt = $this->db->prepare("
            INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id, unit_group_id, display_name, sort_order)
            VALUES (:uuid, :sid, :tid, :cat, :grp, :disp, :sort)
        ");
        $stmt->execute([
            ':uuid' => Uuid::v4(),
            ':sid'  => (int) $data['structure_id'],
            ':tid'  => (int) $data['term_id'],
            ':cat'  => (int) $data['unit_catalog_id'],
            ':grp'  => !empty($data['unit_group_id']) ? (int) $data['unit_group_id'] : null,
            ':disp' => $data['display_name'] !== '' ? $data['display_name'] : null,
            ':sort' => (int) $data['sort_order'],
        ]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('structure_units', $newId, 'create', null, $data);
        return $newId;
    }

    // Memindahkan banyak unit satu periode ke Bidangnya masing-masing sekaligus (halaman Pengelompokan Unit).
    // $moves: id unit => id Bidang ('' atau 0 = tanpa Bidang). Id yang bukan unit periode ini, atau isian yang bukan skalar,
    // diabaikan; satu Bidang yang bukan milik periode ini menggagalkan seluruhnya. Hanya unit yang Bidangnya benar-benar berubah yang ditulis dan dicatat.
    // Mengembalikan jumlah unit yang berpindah.
    public function regroup($termId, array $moves) {
        $termId = (int) $termId;
        $valid = [];
        foreach ($this->groupsByTerm($termId) as $g) $valid[(int) $g['id']] = true;
        $current = []; $structureOf = [];
        foreach ($this->unitsByTerm($termId) as $u) {
            $current[(int) $u['id']] = $u['unit_group_id'] !== null ? (int) $u['unit_group_id'] : null;
            $structureOf[(int) $u['id']] = (int) $u['structure_id'];   // supaya jejak audit tampil pada umpan aktivitas admin struktur itu
        }

        $changes = [];
        foreach ($moves as $unitId => $groupId) {
            $unitId = (int) $unitId;
            if (!array_key_exists($unitId, $current) || !is_scalar($groupId)) continue;
            $to = (int) $groupId > 0 ? (int) $groupId : null;
            if ($to !== null && !isset($valid[$to])) {
                throw new RuntimeException('Bidang yang dipilih bukan milik periode ini.');
            }
            if ($to !== $current[$unitId]) $changes[$unitId] = [$current[$unitId], $to];
        }
        if (!$changes) return 0;

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare("
                UPDATE structure_units SET unit_group_id = :grp WHERE id = :id AND term_id = :t AND deleted_at IS NULL
            ");
            foreach ($changes as $id => $move) {
                $upd->execute([':grp' => $move[1], ':id' => $id, ':t' => $termId]);
            }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
        foreach ($changes as $id => $move) {
            Audit::log('structure_units', $id, 'update',
                ['structure_id' => $structureOf[$id], 'unit_group_id' => $move[0]],
                ['structure_id' => $structureOf[$id], 'unit_group_id' => $move[1]]);
        }
        return count($changes);
    }

    public function unitDelete($id) {
        $before = $this->unitFindById($id);
        if (!$before) return false;

        $used = $this->db->prepare("SELECT COUNT(*) FROM placements WHERE structure_unit_id = :id AND deleted_at IS NULL");
        $used->execute([':id' => (int) $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Unit ini masih berisi pengurus. Pindahkan atau hapus penempatannya lebih dulu.');
        }

        $this->db->prepare("UPDATE structure_units SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => (int) $id]);
        Audit::log('structure_units', $id, 'delete', $before, null);
        return true;
    }
}
