<?php
// PATH LOKASI FILE: app/Models/PositionModel.php
// Katalog jabatan struktural. Tabel kecil dan hampir statis, tapi sengaja jadi tabel: inilah yang
// membuat definisi "Pengurus Inti" berhenti menjadi CASE WHEN di kode (lihat PengurusInti di syuro)
// dan menjadi data yang bisa diubah tanpa deploy.

require_once ROOT_DIR . '/app/Models/StructureModel.php'; // StructureModel::LEVELS: urutan jenjang untuk tombol penyaring struktur
require_once ROOT_DIR . '/app/Core/UnitKinds.php';        // daftar jenis unit (dikelola, bukan konstanta)

class PositionModel {
    // Pengurus Inti, sebagai ekspresi SQL untuk query yang menggabungkan positions (alias po): jabatan bertanda is_core.
    // Masih harus digabung dengan "placements.structure_unit_id IS NULL": jabatan di dalam unit tidak pernah inti.
    const CORE_SQL = '(po.is_core = 1)';

    // Kode jabatan "Ketua" pucuk pimpinan sebuah struktur: label polos (MWP, DSO, DSY, DPK, ... dan kepala unit) ditambah jabatan yang
    // dikhususkan per tingkat (migrasi 018: DPW, DPD; migrasi 020: Ketua Bidang di Pengurus Inti DPP; migrasi 026: MWW, MWD).
    // Ketua DPC / Ketua DPRa kelak ditambahkan di sini juga.
    const KETUA_CODES = ['ketua', 'ketua_dpw', 'ketua_dpd', 'ketua_mww', 'ketua_mwd', 'ketua_bidang'];

    // Kode jabatan "Wakil Ketua" tingkat struktur (Pengurus Inti): label polos (MWP, MWW, MWD, DSO, DSY, DPK) ditambah yang
    // dikhususkan (migrasi 019: DPW, DPD; migrasi 020: Wakil Ketua Bidang di Pengurus Inti DPP). Yang di dalam unit dipisah:
    // WAKIL_KETUA_UNIT.
    const WAKIL_KETUA_CODES = ['wakil_ketua', 'wakil_ketua_dpw', 'wakil_ketua_dpd', 'wakil_ketua_bidang'];
    const WAKIL_KETUA_UNIT  = 'wakil_ketua_departemen_lembaga';

    // Kode "Ketua" kepala unit menurut jenis unitnya (migrasi 021). "Ketua" polos tinggal untuk jenis unit yang belum punya
    // jabatan sendiri (Pusat, Divisi, Komisi, Belum ditentukan) dan untuk tingkat struktur.
    const KETUA_UNIT_CODES = ['ketua_departemen', 'ketua_lembaga', 'ketua_biro', 'ketua_badan'];

    // "Inti lengkap" di halaman Data Pengurus: struktur dianggap punya inti yang lengkap bila Ketua, Sekretaris, dan Bendahara-nya
    // masing-masing dipegang seseorang di tingkat struktur (tanpa unit). Ketua DPC / DPRa kelak ditambahkan ke INTI_KETUA di sini.
    const INTI_KETUA      = ['ketua_umum', 'ketua', 'ketua_dpw', 'ketua_dpd', 'ketua_mww', 'ketua_mwd'];
    const INTI_SEKRETARIS = ['sekretaris_jenderal', 'sekretaris'];
    const INTI_BENDAHARA  = ['bendahara_umum', 'bendahara'];

    // Jenis unit (unit_catalog.kind) beserta labelnya ada di UnitKinds (tabel unit_kinds, dikelola di halaman Unit Organisasi); batas jabatan
    // per jenis unit (positions.unit_kinds) menyimpan kodenya.

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
    // Untuk 'unit', $unitKind (jenis unit tujuan, mis. 'biro') menyaring jabatan yang dibatasi pada jenis unit lain.
    public function forContext($context, $unitKind = null) {
        $isUnit = $context === 'unit';
        $kindSql = $isUnit && $unitKind !== null ? " AND (unit_kinds IS NULL OR unit_kinds = '' OR FIND_IN_SET(:kind, unit_kinds))" : '';
        $stmt = $this->db->prepare("
            SELECT * FROM positions
            WHERE is_active = 1 AND applies_to IN (:ctx, 'both')$kindSql
            ORDER BY sort_order, name
        ");
        $params = [':ctx' => $isUnit ? 'unit' : 'structure'];
        if ($kindSql !== '') $params[':kind'] = (string) $unitKind;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Jenis unit yang diizinkan sebuah jabatan di dalam unit, dari kolom positions.unit_kinds ("biro" atau "pusat,divisi").
    // Larik kosong = semua jenis unit.
    public static function kindList($unitKinds) {
        $out = [];
        foreach (explode(',', (string) $unitKinds) as $k) {
            $k = trim($k);
            if ($k !== '' && UnitKinds::exists($k)) $out[$k] = true;
        }
        return array_keys($out);
    }

    // "Departemen, Lembaga" untuk tampilan; kosong bila jabatan tak dibatasi jenis unit.
    public static function kindsLabel($unitKinds) {
        return implode(', ', array_map(function ($k) { return UnitKinds::label($k); }, self::kindList($unitKinds)));
    }

    // Apakah jabatan ini boleh dipegang di tempat itu? $unitKind = jenis unit tempat penempatan (null = tingkat struktur, tanpa unit).
    // Mengembalikan null bila cocok, atau kalimat penolakan yang siap ditampilkan. SATU aturan untuk semua jalur penempatan
    // (formulir admin, persetujuan pendaftaran, pemindahan); formulir di browser hanya menyaring pilihannya mengikuti aturan yang sama.
    public static function mismatch(array $position, $unitKind) {
        $name = $position['name'];
        $at = $position['applies_to'];
        if ($unitKind === null) {
            return $at === 'unit' ? "Jabatan \"$name\" hanya berlaku di dalam unit (SKUO), bukan di tingkat struktur." : null;
        }
        if ($at === 'structure') return "Jabatan \"$name\" hanya berlaku di tingkat struktur, bukan di dalam unit (SKUO).";
        $allowed = self::kindList($position['unit_kinds'] ?? '');
        if ($allowed && !in_array($unitKind, $allowed, true)) {
            $has = UnitKinds::label($unitKind);
            return "Jabatan \"$name\" hanya berlaku di unit berjenis " . implode('/', array_map(function ($k) { return UnitKinds::label($k); }, $allowed))
                 . ", sedangkan unit ini berjenis $has.";
        }
        return null;
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

    // ---------- Saringan struktur (tombol DSO / DSY / DPK / DPP di halaman Jabatan) ----------

    // Tombol penyaring dibuat dari empat Lembaga Tinggi (tabel lembaga_tinggi; tiap struktur sudah tertaut ke salah satunya).
    // Yang hanya punya satu jenjang (DSO, DSY, DPK) tampil lebih dulu, DPP yang membawahi DPW, DPD, MWP, MWW, MWD paling akhir,
    // sebab tombolnya membuka baris jenjang di bawahnya. Hasil: [['id', 'abbr', 'name', 'levels' => [...]]], jenjang berurut
    // menurut StructureModel::LEVELS.
    public function structureFilters() {
        $rows = $this->db->query("
            SELECT lt.id, lt.abbreviation AS abbr, lt.name, lt.sort_order, s.level
            FROM lembaga_tinggi lt
            JOIN structures s ON s.lembaga_tinggi_id = lt.id AND s.deleted_at IS NULL
            GROUP BY lt.id, lt.abbreviation, lt.name, lt.sort_order, s.level
        ")->fetchAll();

        $by = [];
        foreach ($rows as $r) {
            if (!isset($by[$r['id']])) $by[$r['id']] = ['id' => (int) $r['id'], 'abbr' => $r['abbr'], 'name' => $r['name'], 'sort' => (int) $r['sort_order'], 'levels' => []];
            $by[$r['id']]['levels'][] = $r['level'];
        }
        foreach ($by as &$f) {
            $order = array_flip(StructureModel::LEVELS);
            usort($f['levels'], function ($a, $b) use ($order) { return ($order[$a] ?? 99) <=> ($order[$b] ?? 99); });
        }
        unset($f);
        $out = array_values($by);
        usort($out, function ($a, $b) { return [count($a['levels']), $a['sort']] <=> [count($b['levels']), $b['sort']]; });
        return $out;
    }

    // Pilihan penyaring dari URL (?st=DPP&lv=DPW) -> ['id', 'abbr', 'level'] yang sah, atau null bila tak ada / tak sah. $level hanya
    // berlaku untuk Lembaga Tinggi yang punya lebih dari satu jenjang dan harus salah satu jenjangnya; selebihnya dibuang diam-diam.
    public function resolveStructureFilter($st, $lv = '') {
        foreach ($this->structureFilters() as $f) {
            if ($f['abbr'] !== (string) $st) continue;
            $level = in_array($lv, $f['levels'], true) && count($f['levels']) > 1 ? $lv : '';
            return ['id' => $f['id'], 'abbr' => $f['abbr'], 'level' => $level];
        }
        return null;
    }

    // Syarat SQL pada tabel structures (alias $alias) untuk pilihan penyaring. Nilainya sudah dibakukan resolveStructureFilter
    // (angka dan nama jenjang dari daftar tetap), jadi aman disisipkan langsung dan tak menambah placeholder bernama.
    private function structureSql($structure, $alias) {
        if (!$structure) return '';
        return "$alias.lembaga_tinggi_id = " . (int) $structure['id']
             . ($structure['level'] !== '' ? " AND $alias.level = " . $this->db->quote($structure['level']) : '');
    }

    // $structure (hasil resolveStructureFilter): jumlah penempatan dan struktur dihitung hanya di situ, dan jabatan yang tak dipakai
    // di situ tidak ditampilkan. Tanpa $structure semuanya seperti semula.
    public function adminList($appliesTo = '', $keyword = '', $structure = null) {
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

        // Dengan saringan struktur, kedua hitungan memakai join ke structures; tanpa saringan teks SQL-nya tetap yang lama.
        $s1 = $this->structureSql($structure, 's1'); $s2 = $this->structureSql($structure, 's2');
        $usedPl = $structure
            ? "SELECT COUNT(*) FROM placements pl JOIN structures s1 ON s1.id = pl.structure_id
               WHERE pl.position_id = p.id AND pl.deleted_at IS NULL AND $s1"
            : "SELECT COUNT(*) FROM placements pl WHERE pl.position_id = p.id AND pl.deleted_at IS NULL";
        $usedSt = $structure
            ? "SELECT COUNT(DISTINCT pl2.structure_id) FROM placements pl2 JOIN structures s2 ON s2.id = pl2.structure_id
               WHERE pl2.position_id = p.id AND pl2.deleted_at IS NULL AND $s2"
            : "SELECT COUNT(DISTINCT pl2.structure_id) FROM placements pl2 WHERE pl2.position_id = p.id AND pl2.deleted_at IS NULL";

        $stmt = $this->db->prepare("
            SELECT p.*,
                   ($usedPl) AS used_placements,
                   ($usedSt) AS used_structures,
                   (SELECT COUNT(*) FROM unit_catalog uc
                     WHERE uc.default_head_position_id = p.id) AS used_as_head
            FROM positions p
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            " . ($structure ? 'HAVING used_placements > 0' : '') . "
            ORDER BY p.sort_order, p.name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---------- Daftar pemegang jabatan (tombol "Lihat" di Master Data > Jabatan) ----------

    // Satu baris = satu penempatan yang masih hidup (belum diarsipkan), yaitu himpunan yang sama dengan kolom "Dipakai".
    // Orang yang rangkap di beberapa struktur/unit muncul sekali per penempatan. $scoped = hanya struktur dalam lingkup akses admin.
    // $structure (hasil resolveStructureFilter, boleh null) membatasi pada Lembaga Tinggi / jenjang yang dipilih di halaman Jabatan.
    private function holdersFrom($positionId, $keyword, $scoped, array &$params, $structure = null) {
        $where = ['pl.position_id = :pid', 'pl.deleted_at IS NULL', 'p.deleted_at IS NULL'];
        $params[':pid'] = (int) $positionId;
        if ($scoped) $where[] = AdminScope::sqlFilter('pl.structure_id');
        if ($structure) $where[] = $this->structureSql($structure, 's');

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $where[] = '(p.full_name LIKE :kw_name OR s.name LIKE :kw_structure OR c.name LIKE :kw_unit OR su.display_name LIKE :kw_display)';
            $params[':kw_name'] = $like;
            $params[':kw_structure'] = $like;
            $params[':kw_unit'] = $like;
            $params[':kw_display'] = $like;
        }

        return "FROM placements pl
                JOIN people p ON p.id = pl.person_id
                JOIN structures s ON s.id = pl.structure_id
                JOIN terms t ON t.id = pl.term_id
                LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
                LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
                WHERE " . implode(' AND ', $where);
    }

    public function holders($positionId, $keyword = '', $limit = 25, $offset = 0, $structure = null) {
        $params = [];
        $from = $this->holdersFrom($positionId, $keyword, true, $params, $structure);
        $stmt = $this->db->prepare("
            SELECT pl.id AS placement_id, pl.start_date, pl.end_date,
                   p.id, p.uuid, p.title_prefix, p.name, p.title_suffix, p.full_name, p.photo_path,
                   s.id AS structure_id, s.level, s.name AS structure_name,
                   t.name AS term_name, t.is_current,
                   COALESCE(NULLIF(su.display_name, ''), c.name) AS unit_name
            $from
            ORDER BY s.tier, s.level, s.name, ISNULL(pl.structure_unit_id) DESC, unit_name, p.name, p.id
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Jumlah penempatan: yang cocok dengan pencarian dan ada dalam lingkup admin ($scoped = true), atau seluruhnya ($scoped = false;
    // dipakai untuk memberi tahu berapa yang tak ditampilkan karena di luar lingkup).
    public function holdersCount($positionId, $keyword = '', $scoped = true, $structure = null) {
        $params = [];
        $from = $this->holdersFrom($positionId, $keyword, $scoped, $params, $structure);
        $stmt = $this->db->prepare("SELECT COUNT(*) $from");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // Batas jenis unit yang tersimpan: hanya bermakna bila jabatan berlaku di unit, urut menurut daftar jenis unit, NULL bila tak dibatasi.
    private function normalizedKinds(array $data) {
        if (($data['applies_to'] ?? '') === 'structure') return null;
        $given = (array) ($data['unit_kinds'] ?? []);
        $keep = array_values(array_filter(UnitKinds::codes(), function ($k) use ($given) { return in_array($k, $given, true); }));
        return $keep ? implode(',', $keep) : null;
    }

    // Menyempitkan tempat berlaku sebuah jabatan ditolak selama masih ada penempatan hidup yang kelak melanggarnya: penempatan
    // itu akan gagal disunting (aturan yang sama dipakai di setiap penyimpanan penempatan), dan datanya jadi tak sesuai katalog.
    private function assertScopeKeepsPlacements($id, $appliesTo, $kinds) {
        $name = $this->findById($id)['name'] ?? '';
        $count = function ($extra, array $params = []) use ($id) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM placements pl
                LEFT JOIN structure_units su ON su.id = pl.structure_unit_id
                LEFT JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                WHERE pl.position_id = :pid AND pl.deleted_at IS NULL AND $extra
            ");
            $stmt->execute([':pid' => (int) $id] + $params);
            return (int) $stmt->fetchColumn();
        };

        $bad = 0; $where = '';
        if ($appliesTo === 'structure') {
            $bad = $count('pl.structure_unit_id IS NOT NULL'); $where = 'di dalam unit';
        } else {
            if ($appliesTo === 'unit') { $bad += $count('pl.structure_unit_id IS NULL'); $where = 'di tingkat struktur'; }
            $allowed = self::kindList($kinds);
            if ($allowed) {
                $ph = []; $params = [];
                foreach ($allowed as $i => $k) { $ph[] = ':k' . $i; $params[':k' . $i] = $k; }
                $bad += $count('pl.structure_unit_id IS NOT NULL AND uc.kind NOT IN (' . implode(',', $ph) . ')', $params);
                $where = $where !== '' ? $where . ' atau di unit berjenis lain' : 'di unit berjenis lain';
            }
        }
        if ($bad > 0) {
            throw new RuntimeException("Tempat berlaku jabatan \"$name\" tidak bisa dipersempit: masih ada $bad penempatan $where. "
                . 'Pindahkan penempatan itu ke jabatan yang sesuai lebih dulu.');
        }
    }

    // $data['is_core'] hanya ditulis bila kuncinya ada: tanpa kunci itu, jabatan yang sudah ada tetap seperti semula (UPDATE) dan jabatan baru
    // tidak inti (INSERT). Formulir Jabatan tak lagi memuat tanda inti, jadi menyimpan nama atau urutan tak boleh mematikan status inti.
    public function save($id, array $data) {
        $kinds = $this->normalizedKinds($data);

        if ($id) {
            $this->assertScopeKeepsPlacements($id, $data['applies_to'], $kinds);
            $stmt = $this->db->prepare("
                UPDATE positions SET name = :name, code = :code, applies_to = :at, unit_kinds = :kinds,
                       is_core = COALESCE(:core, is_core), sort_order = :sort, is_active = :active
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'   => trim($data['name']),
                ':code'   => trim($data['code']),
                ':at'     => $data['applies_to'],
                ':kinds'  => $kinds,
                ':core'   => array_key_exists('is_core', $data) ? (!empty($data['is_core']) ? 1 : 0) : null,
                ':sort'   => (int) $data['sort_order'],
                ':active' => !empty($data['is_active']) ? 1 : 0,
                ':id'     => (int) $id,
            ]);
            Audit::log('positions', $id, 'update', null, $data);
            return (int) $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO positions (name, code, applies_to, unit_kinds, is_core, sort_order, is_active)
            VALUES (:name, :code, :at, :kinds, :core, :sort, :active)
        ");
        $stmt->execute([
            ':name'   => trim($data['name']),
            ':code'   => trim($data['code']),
            ':at'     => $data['applies_to'],
            ':kinds'  => $kinds,
            ':core'   => !empty($data['is_core']) ? 1 : 0,
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
