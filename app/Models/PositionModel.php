<?php
// PATH LOKASI FILE: app/Models/PositionModel.php
// Katalog jabatan struktural. Tabel kecil dan hampir statis, tapi sengaja jadi tabel: inilah yang
// membuat definisi "Pengurus Inti" berhenti menjadi CASE WHEN di kode (lihat PengurusInti di syuro)
// dan menjadi data yang bisa diubah tanpa deploy.

class PositionModel {
    // Pengurus Inti, sebagai ekspresi SQL untuk query yang menggabungkan positions (alias po) dan structures (alias s):
    // jabatan bertanda is_core, atau jabatan yang inti mulai tier tertentu (core_from_tier) pada struktur dengan tier itu atau di bawahnya.
    // Masih harus digabung dengan "placements.structure_unit_id IS NULL": jabatan di dalam unit tidak pernah inti.
    const CORE_SQL = '(po.is_core = 1 OR (po.core_from_tier IS NOT NULL AND s.tier >= po.core_from_tier))';

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all($activeOnly = true) {
        $sql = "SELECT * FROM positions" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, name";
        return $this->db->query($sql)->fetchAll();
    }

    // Jabatan yang wajar ditawarkan untuk konteks tertentu. 'structure' = pucuk pimpinan struktur,
    // 'unit' = di dalam Departemen/Lembaga/Biro. Yang applies_to='both' muncul di keduanya.
    public function forContext($context) {
        $stmt = $this->db->prepare("
            SELECT * FROM positions
            WHERE is_active = 1 AND applies_to IN (:ctx, 'both')
            ORDER BY sort_order, name
        ");
        $stmt->execute([':ctx' => $context === 'unit' ? 'unit' : 'structure']);
        return $stmt->fetchAll();
    }

    // Padanan PHP dari CORE_SQL untuk satu baris positions dan tier struktur yang sedang dilihat (mis. isian formulir penempatan).
    public static function isCoreAt(array $position, $tier) {
        return !empty($position['is_core'])
            || ($position['core_from_tier'] !== null && (int) $tier >= (int) $position['core_from_tier']);
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM positions WHERE id = :id");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByCode($code) {
        $stmt = $this->db->prepare("SELECT * FROM positions WHERE code = :c");
        $stmt->execute([':c' => $code]);
        return $stmt->fetch() ?: null;
    }

    // Pemetaan nama jabatan -> id, dipakai skrip impor/migrasi yang sumbernya berisi teks bebas
    // (mis. kolom position_name di syuro).
    public function nameToIdMap() {
        $map = [];
        foreach ($this->all(false) as $p) {
            $map[Text::normalizeFull($p['name'])] = (int) $p['id'];
        }
        return $map;
    }

    // ---------- Layar admin Master Data > Jabatan ----------

    const APPLIES_TO = ['structure', 'unit', 'both'];

    public function countsByAppliesTo() {
        return $this->db->query("SELECT applies_to, COUNT(*) FROM positions GROUP BY applies_to")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function adminList($appliesTo = '', $keyword = '') {
        $where = [];
        $params = [];

        if ($appliesTo !== '' && in_array($appliesTo, self::APPLIES_TO, true)) {
            $where[] = 'p.applies_to = :at';
            $params[':at'] = $appliesTo;
        }

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $where[] = '(p.name LIKE :kw_name OR p.code LIKE :kw_code)';
            $params[':kw_name'] = $like;
            $params[':kw_code'] = $like;
        }

        $stmt = $this->db->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM placements pl
                     WHERE pl.position_id = p.id AND pl.deleted_at IS NULL) AS used_placements,
                   (SELECT COUNT(DISTINCT pl2.structure_id) FROM placements pl2
                     WHERE pl2.position_id = p.id AND pl2.deleted_at IS NULL) AS used_structures,
                   (SELECT COUNT(*) FROM unit_catalog uc
                     WHERE uc.default_head_position_id = p.id) AS used_as_head
            FROM positions p
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY p.sort_order, p.name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function save($id, array $data) {
        if ($id) {
            $stmt = $this->db->prepare("
                UPDATE positions SET name = :name, code = :code, applies_to = :at,
                       is_core = :core, core_from_tier = :cft, sort_order = :sort, is_active = :active
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'   => trim($data['name']),
                ':code'   => trim($data['code']),
                ':at'     => $data['applies_to'],
                ':core'   => !empty($data['is_core']) ? 1 : 0,
                ':cft'    => $data['core_from_tier'] !== '' && $data['core_from_tier'] !== null
                                ? (int) $data['core_from_tier'] : null,
                ':sort'   => (int) $data['sort_order'],
                ':active' => !empty($data['is_active']) ? 1 : 0,
                ':id'     => (int) $id,
            ]);
            Audit::log('positions', $id, 'update', null, $data);
            return (int) $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO positions (name, code, applies_to, is_core, core_from_tier, sort_order, is_active)
            VALUES (:name, :code, :at, :core, :cft, :sort, :active)
        ");
        $stmt->execute([
            ':name'   => trim($data['name']),
            ':code'   => trim($data['code']),
            ':at'     => $data['applies_to'],
            ':core'   => !empty($data['is_core']) ? 1 : 0,
            ':cft'    => $data['core_from_tier'] !== '' && $data['core_from_tier'] !== null
                            ? (int) $data['core_from_tier'] : null,
            ':sort'   => (int) $data['sort_order'],
            ':active' => !empty($data['is_active']) ? 1 : 0,
        ]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('positions', $newId, 'create', null, $data);
        return $newId;
    }

    public function delete($id) {
        $pos = $this->findById($id);
        if (!$pos) return false;

        $used = $this->db->prepare("
            SELECT COUNT(*) FROM placements WHERE position_id = :id AND deleted_at IS NULL
        ");
        $used->execute([':id' => (int) $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Jabatan ini masih dipakai oleh penempatan aktif. Pindahkan pengurusnya ke jabatan lain lebih dulu.');
        }

        $head = $this->db->prepare("SELECT COUNT(*) FROM unit_catalog WHERE default_head_position_id = :id");
        $head->execute([':id' => (int) $id]);
        if ((int) $head->fetchColumn() > 0) {
            throw new RuntimeException('Jabatan ini masih dipakai sebagai kepala unit. Ubah jenis unitnya lebih dulu.');
        }

        $this->db->prepare("DELETE FROM positions WHERE id = :id")->execute([':id' => (int) $id]);
        Audit::log('positions', $id, 'delete', $pos, null);
        return true;
    }
}
