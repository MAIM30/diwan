<?php
// PATH LOKASI FILE: app/Models/StructureModel.php
// Pohon struktur organisasi + pemeliharaan materialized path.
//
// parent_id tetap SUMBER KEBENARAN hierarki; kolom `path` adalah turunan yang dipelihara di sini
// supaya pertanyaan "struktur ini + seluruh keturunannya" (pertanyaan paling sering di aplikasi ini,
// karena itulah definisi lingkup admin) jadi satu index range scan. Seluruh penulisan parent_id
// WAJIB lewat model ini, karena di sinilah path ikut diperbarui.

class StructureModel {
    // Urutan tampilan tingkat pada masing-masing sayap. DPLN setingkat DPW, MWLN (padanan Muslimahnya) setingkat MWW, dan MWRa tingkat Ranting.
    const BY_WING = [
        // DPLN sementara ditempatkan setingkat DPW di bawah DPP; MWLN setingkat MWW di bawah MWP; MWRa adalah tingkat ranting di bawah MWC.
        'umum'     => ['DPP', 'DPW', 'DPLN', 'DPD', 'DPC', 'DPRa'],
        'muslimah' => ['MWP', 'MWW', 'MWLN', 'MWD', 'MWC', 'MWRa'],
    ];
    const LEVELS = [...self::BY_WING['umum'], ...self::BY_WING['muslimah']];

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM structures WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("SELECT * FROM structures WHERE uuid = :u AND deleted_at IS NULL");
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function findManyById(array $ids) {
        if (empty($ids)) return [];
        $in = implode(',', array_map('intval', $ids));
        return $this->db->query("SELECT * FROM structures WHERE id IN ($in) AND deleted_at IS NULL ORDER BY path")
                        ->fetchAll();
    }

    // Seluruh struktur, diurutkan mengikuti path sehingga hasilnya sudah berbentuk pohon terurut
    // (induk selalu muncul sebelum anaknya) tanpa perlu menyusun ulang di PHP.
    public function allOrdered() {
        return $this->db->query("
            SELECT s.*, p.name AS parent_name, p.level AS parent_level
            FROM structures s
            LEFT JOIN structures p ON p.id = s.parent_id
            WHERE s.deleted_at IS NULL
            ORDER BY s.path
        ")->fetchAll();
    }

    // Dibatasi lingkup admin yang sedang login, urut mengikuti path.
    public function allInScope() {
        return $this->db->query("
            SELECT s.*
            FROM structures s
            WHERE s.deleted_at IS NULL AND " . AdminScope::sqlFilter('s.id') . "
            ORDER BY s.path
        ")->fetchAll();
    }

    // Jumlah struktur per jenjang dalam lingkup admin (level => jumlah), termasuk yang belum punya periode atau pengurus. Dasar chip
    // Jenjang di Data Pengurus dan Struktur: jenjang baru (mis. DPLN) langsung tampil begitu strukturnya dibuat, tak menunggu ada pengurusnya.
    public function levelCountsInScope() {
        return array_map('intval', $this->db->query("
            SELECT s.level, COUNT(*) FROM structures s
            WHERE s.deleted_at IS NULL AND " . AdminScope::sqlFilter('s.id') . "
            GROUP BY s.level
        ")->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // Periode berjalan tiap struktur dalam lingkup admin: structure_id => ['id' => id periode, 'name' => nama periode]. Struktur yang belum
    // punya periode berjalan tidak ada di hasil. Satu query untuk seluruh daftar Struktur (bukan satu per baris).
    public function currentTermsInScope() {
        $rows = $this->db->query("
            SELECT t.structure_id, t.id, t.name FROM terms t
            WHERE t.is_current = 1 AND t.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id') . "
            ORDER BY t.start_date DESC, t.id DESC
        ")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['structure_id'];
            if (!isset($out[$sid])) $out[$sid] = ['id' => (int) $r['id'], 'name' => $r['name']];   // yang terbaru bila datanya ganda
        }
        return $out;
    }

    // Struktur yang BELUM punya periode berjalan tetapi sudah punya periode (draf atau aktif, belum selesai) yang belum ditandai berjalan:
    // structure_id => ['id', 'name'] (yang terbaru). Tanpa ini halaman Struktur dan Data Pengurus menulis "belum ada periode" untuk struktur
    // yang periodenya sudah dibuat, lalu "Buat periode" gagal ("sudah menggunakan periode tersebut"); yang kurang hanya tandanya.
    public function pendingTermsInScope() {
        $rows = $this->db->query("
            SELECT t.structure_id, t.id, t.name FROM terms t
            WHERE t.deleted_at IS NULL AND t.is_current = 0 AND t.status <> 'ended' AND " . AdminScope::sqlFilter('t.structure_id') . "
              AND NOT EXISTS (SELECT 1 FROM terms c WHERE c.structure_id = t.structure_id AND c.is_current = 1 AND c.deleted_at IS NULL)
            ORDER BY t.start_date DESC, t.id DESC
        ")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['structure_id'];
            if (!isset($out[$sid])) $out[$sid] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        return $out;
    }

    // Tingkat yang sudah ada di lingkup admin dan tingkat yang dapat dibuat sebagai anaknya.
    public function levelsInScope() {
        if (AdminScope::isUnbounded()) return self::LEVELS;
        $rows = $this->db->query("SELECT s.level FROM structures s
            WHERE s.deleted_at IS NULL AND " . AdminScope::sqlFilter('s.id'))->fetchAll(PDO::FETCH_COLUMN);
        $children = [
            'DPP' => ['DPW', 'DPLN', 'MWP'], 'DPW' => ['DPD'], 'DPD' => ['DPC'], 'DPC' => ['DPRa'],
            'MWP' => ['MWW', 'MWLN'], 'MWW' => ['MWD'], 'MWD' => ['MWC'], 'MWC' => ['MWRa'],
        ];
        $allowed = array_fill_keys($rows, true);
        foreach ($rows as $level) foreach ($children[$level] ?? [] as $child) $allowed[$child] = true;
        return array_values(array_filter(self::LEVELS, function ($level) use ($allowed) { return isset($allowed[$level]); }));
    }

    public function roots() {
        return $this->db->query("
            SELECT * FROM structures WHERE parent_id IS NULL AND deleted_at IS NULL ORDER BY name
        ")->fetchAll();
    }

    public function anyIsRoot(array $ids) {
        if (empty($ids)) return false;
        $in = implode(',', array_map('intval', $ids));
        return (bool) $this->db->query("
            SELECT COUNT(*) FROM structures WHERE id IN ($in) AND parent_id IS NULL AND deleted_at IS NULL
        ")->fetchColumn();
    }

    // ID struktur ini + seluruh keturunannya. Memakai path, bukan recursive CTE.
    public function descendantIds($id) {
        return $this->descendantIdsOfMany([(int) $id]);
    }

    // Gabungan subtree dari beberapa titik akar sekaligus -- karena satu akun bisa punya beberapa
    // baris lingkup di account_roles.
    public function descendantIdsOfMany(array $rootIds) {
        if (empty($rootIds)) return [];

        $roots = $this->findManyById($rootIds);
        if (empty($roots)) return [];

        $clauses = [];
        $params = [];
        foreach ($roots as $i => $r) {
            // Path belum terisi (data lama/impor yang belum di-backfill): jatuh kembali ke
            // pencocokan id langsung supaya lingkupnya tidak jadi kosong tanpa pemberitahuan.
            if ($r['path'] === '' || $r['path'] === null) {
                $clauses[] = "id = :id$i";
                $params[":id$i"] = (int) $r['id'];
            } else {
                $clauses[] = "path LIKE :path$i";
                $params[":path$i"] = $r['path'] . '%';
            }
        }

        $stmt = $this->db->prepare("
            SELECT id FROM structures
            WHERE deleted_at IS NULL AND (" . implode(' OR ', $clauses) . ")
        ");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function create(array $data) {
        $parentId = empty($data['parent_id']) ? null : (int) $data['parent_id'];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO structures (uuid, level, name, parent_id, region_code, is_active)
                VALUES (:uuid, :level, :name, :parent_id, :region_code, 1)
            ");
            $stmt->execute([
                ':uuid'        => Uuid::v4(),
                ':level'       => $data['level'],
                ':name'        => trim($data['name']),
                ':parent_id'   => $parentId,
                ':region_code' => $data['region_code'] !== '' ? $data['region_code'] : null,
            ]);
            $id = (int) $this->db->lastInsertId();

            // path hanya bisa dihitung setelah id terbit, jadi diisi di langkah kedua -- di dalam
            // transaksi yang sama supaya tidak pernah ada baris dengan path kosong yang terlihat.
            $this->writePath($id, $parentId);

            $this->db->commit();
            Audit::log('structures', $id, 'create', null, $this->findById($id));
            return $id;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update($id, array $data) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $parentId = empty($data['parent_id']) ? null : (int) $data['parent_id'];

        // Mencegah struktur dijadikan keturunan dirinya sendiri, yang akan membuat pohonnya
        // bersiklus dan membuat perhitungan lingkup berulang tanpa henti.
        if ($parentId !== null && in_array($parentId, $this->descendantIds($id), true)) {
            throw new RuntimeException('Induk tidak boleh struktur itu sendiri atau keturunannya.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE structures
                SET level = :level, name = :name, parent_id = :parent_id,
                    region_code = :region_code, is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                ':level'       => $data['level'],
                ':name'        => trim($data['name']),
                ':parent_id'   => $parentId,
                ':region_code' => $data['region_code'] !== '' ? $data['region_code'] : null,
                ':is_active'   => !empty($data['is_active']) ? 1 : 0,
                ':id'          => $id,
            ]);

            // Induk berubah -> path seluruh subtree di bawahnya ikut berubah.
            if ((int) $before['parent_id'] !== (int) $parentId) {
                $this->rebuildSubtree($id, $parentId);
            }

            $this->db->commit();
            Audit::log('structures', $id, 'update', $before, $this->findById($id));
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Soft delete. Ditolak bila masih punya anak atau masih dipakai periode, supaya tidak ada
    // baris yatim yang menggantung di luar pohon.
    public function softDelete($id) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $kids = $this->db->prepare("SELECT COUNT(*) FROM structures WHERE parent_id = :id AND deleted_at IS NULL");
        $kids->execute([':id' => $id]);
        if ((int) $kids->fetchColumn() > 0) {
            throw new RuntimeException('Struktur ini masih menaungi struktur lain. Pindahkan atau hapus dulu yang di bawahnya.');
        }

        $terms = $this->db->prepare("SELECT COUNT(*) FROM terms WHERE structure_id = :id AND deleted_at IS NULL");
        $terms->execute([':id' => $id]);
        if ((int) $terms->fetchColumn() > 0) {
            throw new RuntimeException('Struktur ini masih punya periode kepengurusan. Hapus periodenya lebih dulu.');
        }

        $this->db->prepare("UPDATE structures SET deleted_at = NOW(3) WHERE id = :id")->execute([':id' => $id]);
        Audit::log('structures', $id, 'delete', $before, null);
        return true;
    }

    // Mengisi path+depth satu baris dari induknya.
    private function writePath($id, $parentId) {
        $parentPath = '/';
        $depth = 0;
        if ($parentId !== null) {
            $p = $this->db->prepare("SELECT path, depth FROM structures WHERE id = :id");
            $p->execute([':id' => $parentId]);
            if ($row = $p->fetch()) {
                $parentPath = $row['path'] !== '' ? $row['path'] : '/';
                $depth = (int) $row['depth'] + 1;
            }
        }
        $this->db->prepare("UPDATE structures SET path = :path, depth = :depth WHERE id = :id")
                 ->execute([':path' => $parentPath . $id . '/', ':depth' => $depth, ':id' => $id]);
    }

    // Menulis ulang path seluruh subtree setelah induknya dipindahkan. Ditelusuri dari atas ke
    // bawah supaya setiap anak selalu membaca path induknya yang SUDAH diperbarui.
    private function rebuildSubtree($id, $parentId) {
        $this->writePath($id, $parentId);

        $kids = $this->db->prepare("SELECT id FROM structures WHERE parent_id = :id");
        $kids->execute([':id' => $id]);
        foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $kid) {
            $this->rebuildSubtree((int) $kid, (int) $id);
        }
    }

    // Dipakai skrip migrasi/perbaikan untuk mengisi path seluruh tabel dari nol.
    public function rebuildAllPaths() {
        foreach ($this->roots() as $root) {
            $this->rebuildSubtree((int) $root['id'], null);
        }
    }
}
