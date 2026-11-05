<?php
// PATH LOKASI FILE: app/Models/SyncModel.php
// Query khusus API baca-saja: snapshot penuh, perubahan inkremental, dan pengambilan per UUID.
//
// Dua aturan yang dipegang seluruh file ini:
//
//  1. SETIAP response dikunci UUID, bukan id internal. Kolom id tidak pernah keluar. Itu yang
//     memungkinkan database ini dirapikan/di-reindex tanpa memutus salinan lokal konsumen.
//
//  2. NIK TIDAK PERNAH ikut di sini. Daftar field setiap entitas ditulis eksplisit -- tidak ada
//     SELECT * yang mengalir ke response -- supaya menambah kolom sensitif ke tabel people di masa
//     depan tidak otomatis membocorkannya lewat API. Satu-satunya jalan NIK keluar adalah endpoint
//     lookup khusus di ApiController yang mensyaratkan scope 'people:nik'.
//
// Sinkronisasi inkremental memakai kursor gabungan (updated_at, id), bukan 'WHERE updated_at > ?'
// saja: beberapa baris bisa punya updated_at identik (impor massal menulis ratusan baris dalam
// milidetik yang sama), dan kursor berbasis waktu tunggal akan melewatkan atau mengulang baris di
// batas halaman. Penghapusan terkabarkan karena baris yang dihapus tetap ada dengan deleted_at
// terisi -- itulah sebabnya tabel-tabel ini memakai soft delete.

require_once ROOT_DIR . '/app/Models/PositionModel.php'; // PositionModel::CORE_SQL: batas Pengurus Inti

class SyncModel {
    private $db;
    private $scopeIds = null;  // null = tanpa batas struktur

    public function __construct($scopeStructureId = null) {
        $this->db = Database::getInstance()->getConnection();

        if ($scopeStructureId !== null) {
            require_once ROOT_DIR . '/app/Models/StructureModel.php';
            $structures = new StructureModel();
            $ids = $structures->descendantIds($scopeStructureId);
            // IN () kosong bukan SQL yang sah, dan 'tanpa batas' bukan arti yang benar untuk
            // lingkup yang tidak menemukan apa pun -- jadi dipaksa jadi himpunan yang tak pernah match.
            $this->scopeIds = empty($ids) ? [0] : $ids;
        }
    }

    // Fragmen pembatas lingkup klien untuk kolom struktur tertentu.
    private function scopeSql($column) {
        if ($this->scopeIds === null) return '1=1';
        return $column . ' IN (' . implode(',', array_map('intval', $this->scopeIds)) . ')';
    }

    // ---------- Kursor ----------

    // Kursor berbentuk '<epoch_ms>:<id>'. Dibuat dari baris TERAKHIR yang dikirim, bukan dari
    // waktu server, supaya klien tidak pernah kehilangan baris yang ditulis selagi halaman diambil.
    //
    // PRESISI MILIDETIK WAJIB dipertahankan: kolom updated_at bertipe DATETIME(3). strtotime()
    // membuang pecahan detiknya, sehingga kursor menunjuk sedikit SEBELUM baris terakhir yang
    // dikirim dan baris itu (serta tetangganya dalam detik yang sama) lolos lagi di halaman
    // berikutnya -- paginasi berputar tanpa pernah selesai.
    public static function encodeCursor($updatedAt, $id) {
        $updatedAt = (string) $updatedAt;
        $frac = 0;
        if (preg_match('/^(.*?)\.(\d{1,6})$/', $updatedAt, $m)) {
            $updatedAt = $m[1];
            $frac = (int) substr(str_pad($m[2], 3, '0'), 0, 3);
        }
        return (strtotime($updatedAt) * 1000 + $frac) . ':' . (int) $id;
    }

    private function cursorClause($cursor, &$params, $alias = 't') {
        if ($cursor === null || $cursor === '') return '1=1';
        if (!preg_match('/^(\d+):(\d+)$/', $cursor, $m)) {
            throw new InvalidArgumentException('Format cursor tidak sah.');
        }
        // Dua nama berbeda untuk nilai yang sama: dengan ATTR_EMULATE_PREPARES=false, MySQL menolak
        // satu placeholder bernama yang dipakai dua kali (SQLSTATE HY093).
        $ms = (int) $m[1];
        $ts = date('Y-m-d H:i:s', intdiv($ms, 1000)) . '.' . sprintf('%03d', $ms % 1000);
        $params[':cur_ts_gt'] = $ts;
        $params[':cur_ts_eq'] = $ts;
        $params[':cur_id'] = (int) $m[2];
        return "({$alias}.updated_at > :cur_ts_gt OR ({$alias}.updated_at = :cur_ts_eq AND {$alias}.id > :cur_id))";
    }

    private function sinceClause($since, &$params, $alias = 't') {
        if ($since === null || $since === '') return '1=1';
        $ts = is_numeric($since) ? (int) $since : strtotime($since);
        if (!$ts) {
            throw new InvalidArgumentException('Format parameter "since" tidak sah. Pakai ISO-8601 atau epoch detik.');
        }
        $params[':since'] = date('Y-m-d H:i:s.v', $ts);
        return "{$alias}.updated_at >= :since";
    }

    // ---------- Struktur ----------

    public function structures($since = null, $includeDeleted = false) {
        $params = [];
        $where = [$this->scopeSql('t.id'), $this->sinceClause($since, $params, 't')];
        if (!$includeDeleted) $where[] = 't.deleted_at IS NULL';

        $stmt = $this->db->prepare("
            SELECT t.uuid, t.level, t.wing, t.tier, t.name, t.region_code, t.depth, t.is_active,
                   t.updated_at, t.deleted_at, p.uuid AS parent_uuid
            FROM structures t
            LEFT JOIN structures p ON p.id = t.parent_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.path
        ");
        $stmt->execute($params);

        return array_map([$this, 'mapStructure'], $stmt->fetchAll());
    }

    public function structureByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("
            SELECT t.uuid, t.level, t.wing, t.tier, t.name, t.region_code, t.depth, t.is_active,
                   t.updated_at, t.deleted_at, p.uuid AS parent_uuid
            FROM structures t
            LEFT JOIN structures p ON p.id = t.parent_id
            WHERE t.uuid = :u AND t.deleted_at IS NULL AND " . $this->scopeSql('t.id')
        );
        $stmt->execute([':u' => $uuid]);
        $row = $stmt->fetch();
        return $row ? $this->mapStructure($row) : null;
    }

    private function mapStructure($r) {
        return [
            'uuid'        => $r['uuid'],
            'parent_uuid' => $r['parent_uuid'],
            'level'       => $r['level'],
            'wing'        => $r['wing'],      // 'umum' | 'muslimah'
            'tier'        => (int) $r['tier'], // 1=Pusat .. 5=Ranting
            'name'        => $r['name'],
            'region_code' => $r['region_code'],
            'depth'       => (int) $r['depth'],
            'is_active'   => (bool) $r['is_active'],
            'updated_at'  => $this->iso($r['updated_at']),
            'deleted'     => !empty($r['deleted_at']),
        ];
    }

    // ---------- Periode ----------

    public function terms($structureUuid = null, $since = null, $includeDeleted = false) {
        $params = [];
        $where = [$this->scopeSql('t.structure_id'), $this->sinceClause($since, $params, 't')];
        if (!$includeDeleted) $where[] = 't.deleted_at IS NULL';

        if ($structureUuid !== null && $structureUuid !== '') {
            if (!Uuid::isValid($structureUuid)) {
                throw new InvalidArgumentException('Parameter "structure" harus berupa UUID.');
            }
            $where[] = 's.uuid = :suuid';
            $params[':suuid'] = $structureUuid;
        }

        $stmt = $this->db->prepare("
            SELECT t.uuid, t.name, t.start_date, t.end_date_planned, t.end_date_actual,
                   t.status, t.is_current, t.sk_number, t.sk_date, t.updated_at, t.deleted_at,
                   s.uuid AS structure_uuid, gp.uuid AS governance_period_uuid
            FROM terms t
            JOIN structures s ON s.id = t.structure_id
            JOIN governance_periods gp ON gp.id = t.governance_period_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.path, t.start_date DESC
        ");
        $stmt->execute($params);

        return array_map([$this, 'mapTerm'], $stmt->fetchAll());
    }

    private function mapTerm($r) {
        return [
            'uuid'             => $r['uuid'],
            'structure_uuid'   => $r['structure_uuid'],
            'governance_period_uuid' => $r['governance_period_uuid'],
            'name'             => $r['name'],
            'start_date'       => $r['start_date'],
            'end_date_planned' => $r['end_date_planned'],
            'end_date_actual'  => $r['end_date_actual'],
            'status'           => $r['status'],
            'is_current'       => (bool) $r['is_current'],
            'sk_number'        => $r['sk_number'],
            'sk_date'          => $r['sk_date'],
            'updated_at'       => $this->iso($r['updated_at']),
            'deleted'          => !empty($r['deleted_at']),
        ];
    }

    // ---------- Orang ----------

    // Berhalaman dengan kursor. Mengembalikan ['rows' => [...], 'next_cursor' => string|null].
    public function people($since = null, $cursor = null, $limit = 200, $includeDeleted = false) {
        $params = [];
        $where = [$this->sinceClause($since, $params, 't'), $this->cursorClause($cursor, $params, 't')];
        if (!$includeDeleted) $where[] = 't.deleted_at IS NULL';

        // Klien yang dibatasi subtree hanya melihat orang yang punya penempatan di dalam subtree
        // itu. `people` tidak punya kolom struktur -- seseorang bisa menjabat di beberapa struktur
        // sekaligus, jadi keanggotaan lingkupnya ditentukan penempatannya.
        if ($this->scopeIds !== null) {
            $where[] = "EXISTS (SELECT 1 FROM placements pl
                                 WHERE pl.person_id = t.id AND pl.deleted_at IS NULL
                                   AND " . $this->scopeSql('pl.structure_id') . ")";
        }

        $stmt = $this->db->prepare("
            SELECT t.id, t.uuid, t.title_prefix, t.name, t.title_suffix, t.full_name, t.gender, t.phone, t.email, t.birth_date,
                   t.city, t.province, t.status, t.updated_at, t.deleted_at
            FROM people t
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.updated_at, t.id
            LIMIT :lim
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', (int) $limit + 1, PDO::PARAM_INT); // +1 untuk mendeteksi halaman berikutnya
        $stmt->execute();

        return $this->paginate($stmt->fetchAll(), $limit, [$this, 'mapPerson']);
    }

    public function personByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("
            SELECT t.id, t.uuid, t.title_prefix, t.name, t.title_suffix, t.full_name, t.gender, t.phone, t.email, t.birth_date,
                   t.city, t.province, t.status, t.updated_at, t.deleted_at
            FROM people t WHERE t.uuid = :u AND t.deleted_at IS NULL
        ");
        $stmt->execute([':u' => $uuid]);
        $row = $stmt->fetch();
        if (!$row) return null;

        // Lingkup klien dicek lewat penempatan, bukan lewat kolom di people (lihat catatan di people()).
        if ($this->scopeIds !== null && !$this->personInScope((int) $row['id'])) {
            return null;
        }
        return $this->mapPerson($row);
    }

    private function personInScope($personId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM placements pl
            WHERE pl.person_id = :pid AND pl.deleted_at IS NULL AND " . $this->scopeSql('pl.structure_id')
        );
        $stmt->execute([':pid' => $personId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function mapPerson($r) {
        return [
            'uuid'       => $r['uuid'],
            // Nama terpisah: konsumen yang ingin menampilkan "nama asli" tanpa gelar, mengurutkan
            // menurut nama, atau menata gelar sendiri tidak perlu lagi memecah full_name.
            // full_name tetap ada (gabungan ketiganya) demi kompatibilitas konsumen yang sudah ada.
            'title_prefix' => $r['title_prefix'],   // gelar depan, mis. "Dr. H." (null bila tidak ada)
            'name'         => $r['name'],           // nama asli tanpa gelar
            'title_suffix' => $r['title_suffix'],   // gelar belakang, mis. "Lc., M.A." (null bila tidak ada)
            'full_name'  => $r['full_name'],
            'gender'     => $r['gender'],       // 'L' | 'P'
            'phone'      => $r['phone'],        // E.164 tanpa '+', mis. '6281xxxxxxx'
            'email'      => $r['email'],
            'birth_date' => $r['birth_date'],
            'city'       => $r['city'],
            'province'   => $r['province'],
            'status'     => $r['status'],
            'updated_at' => $this->iso($r['updated_at']),
            'deleted'    => !empty($r['deleted_at']),
            // 'nik' SENGAJA tidak ada di sini. Lihat catatan di kepala file.
        ];
    }

    // ---------- Penempatan ----------

    public function placements($filters = [], $since = null, $cursor = null, $limit = 200, $includeDeleted = false) {
        $params = [];
        $where = [
            $this->scopeSql('t.structure_id'),
            $this->sinceClause($since, $params, 't'),
            $this->cursorClause($cursor, $params, 't'),
        ];
        if (!$includeDeleted) $where[] = 't.deleted_at IS NULL';

        if (!empty($filters['structure'])) {
            if (!Uuid::isValid($filters['structure'])) {
                throw new InvalidArgumentException('Parameter "structure" harus berupa UUID.');
            }
            $where[] = 's.uuid = :suuid';
            $params[':suuid'] = $filters['structure'];
        }
        if (!empty($filters['term'])) {
            if (!Uuid::isValid($filters['term'])) {
                throw new InvalidArgumentException('Parameter "term" harus berupa UUID.');
            }
            $where[] = 'tr.uuid = :tuuid';
            $params[':tuuid'] = $filters['term'];
        }
        if (!empty($filters['person'])) {
            if (!Uuid::isValid($filters['person'])) {
                throw new InvalidArgumentException('Parameter "person" harus berupa UUID.');
            }
            $where[] = 'p.uuid = :puuid';
            $params[':puuid'] = $filters['person'];
        }
        // current=1 -> hanya periode yang sedang berjalan. Inilah yang dipakai syuro untuk
        // pertanyaan "siapa pengurus aktif sekarang", tanpa perlu tahu nama periodenya.
        if (!empty($filters['current'])) {
            $where[] = 'tr.is_current = 1';
        }
        if (!empty($filters['core'])) {
            $where[] = 't.structure_unit_id IS NULL AND ' . PositionModel::CORE_SQL;
        }

        $stmt = $this->db->prepare("
            SELECT t.id, t.uuid, t.is_primary, t.start_date, t.end_date, t.end_reason,
                   t.updated_at, t.deleted_at,
                   p.uuid AS person_uuid, p.full_name,
                   s.uuid AS structure_uuid, s.level, s.name AS structure_name,
                   tr.uuid AS term_uuid, tr.name AS term_name, tr.is_current,
                   gp.uuid AS governance_period_uuid,
                   po.code AS position_code, po.name AS position_name, " . PositionModel::CORE_SQL . " AS is_core, po.sort_order,
                   su.uuid AS unit_uuid, c.name AS unit_name, c.abbreviation AS unit_abbr,
                   g.uuid AS group_uuid, g.name AS group_name, g.kind AS group_kind
            FROM placements t
            JOIN people p ON p.id = t.person_id
            JOIN structures s ON s.id = t.structure_id
            JOIN terms tr ON tr.id = t.term_id
            JOIN governance_periods gp ON gp.id = tr.governance_period_id
            JOIN positions po ON po.id = t.position_id
            LEFT JOIN structure_units su ON su.id = t.structure_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.updated_at, t.id
            LIMIT :lim
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', (int) $limit + 1, PDO::PARAM_INT);
        $stmt->execute();

        return $this->paginate($stmt->fetchAll(), $limit, [$this, 'mapPlacement']);
    }

    private function mapPlacement($r) {
        return [
            'uuid'           => $r['uuid'],
            'person_uuid'    => $r['person_uuid'],
            'person_name'    => $r['full_name'],
            'structure_uuid' => $r['structure_uuid'],
            'structure'      => trim($r['level'] . ' ' . $r['structure_name']),
            'term_uuid'      => $r['term_uuid'],
            'term_name'      => $r['term_name'],
            'governance_period_uuid' => $r['governance_period_uuid'],
            'term_is_current'=> (bool) $r['is_current'],
            'position_code'  => $r['position_code'],
            'position_name'  => $r['position_name'],
            // Pengurus Inti = jabatan di level struktur yang di katalog inti bagi tier struktur itu (PositionModel::CORE_SQL).
            // Dihitung di sini supaya konsumen tidak perlu menyalin aturannya (yang di syuro berupa CASE WHEN).
            'is_core'        => ((int) $r['is_core'] === 1 && $r['unit_uuid'] === null),
            'order'          => (int) $r['sort_order'],
            'unit_uuid'      => $r['unit_uuid'],
            'unit_name'      => $r['unit_name'],
            'unit_abbr'      => $r['unit_abbr'],
            'unit_group'     => $r['group_name'],
            'unit_group_kind'=> $r['group_kind'],
            'is_primary'     => (bool) $r['is_primary'],
            'start_date'     => $r['start_date'],
            'end_date'       => $r['end_date'],
            'end_reason'     => $r['end_reason'],
            'updated_at'     => $this->iso($r['updated_at']),
            'deleted'        => !empty($r['deleted_at']),
        ];
    }

    // ---------- Pembantu ----------

    // Memotong hasil ke $limit dan menyusun kursor halaman berikutnya dari baris terakhir yang
    // BENAR-BENAR dikirim (bukan dari baris ke-(limit+1) yang hanya dipakai sebagai penanda).
    private function paginate(array $rows, $limit, callable $mapper) {
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $next = null;
        if ($hasMore && !empty($rows)) {
            $last = $rows[count($rows) - 1];
            $next = self::encodeCursor($last['updated_at'], $last['id']);
        }
        return ['rows' => array_map($mapper, $rows), 'next_cursor' => $next];
    }

    private function iso($datetime) {
        if (empty($datetime)) return null;
        return date('c', strtotime($datetime));
    }
}
