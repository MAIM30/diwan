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
require_once ROOT_DIR . '/app/Core/UnitKinds.php';        // daftar jenis unit (urutan, label, kelompok bawaan) -- dikelola, bukan konstanta

class UnitModel {
    // Sayap katalog (migrasi 022). Mengikuti structures.wing: struktur bertingkat kelompok Muslimah (MWP, MWW, ...; daftar tingkatnya di
    // Master Data > Tingkat Struktur) memakai katalog 'muslimah', yang lain 'umum'. Nama unit unik per sayap, bukan di seluruh katalog.
    const WINGS = ['umum' => 'Umum', 'muslimah' => 'Muslimah'];

    // Kelompok bawaan menurut jenis unit ada di unit_kinds.group_name / group_sort (UnitKinds::group): bawaannya Biro, Badan, dan Pusat tidak
    // hidup di dalam Bidang, melainkan di kelompok sendiri ("Biro-Biro", ...; urutan mengikuti DPP: Pusat 890, Badan 900, Biro 910). Jenis
    // tanpa kelompok bawaan (Departemen, Lembaga, Divisi, ...) hidup di Bidang yang harus dipilih orang.

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ---------- Katalog unit (dibagi per sayap: Umum dan Muslimah) ----------

    // $wing: 'umum' / 'muslimah' = hanya katalog sayap itu; kosong = keduanya.
    public function catalogAll($activeOnly = false, $wing = '') {
        $where = [];
        if ($activeOnly) $where[] = 'is_active = 1';
        if (isset(self::WINGS[$wing])) $where[] = 'wing = ' . $this->db->quote($wing);
        return $this->db->query("SELECT * FROM unit_catalog" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY name")->fetchAll();
    }

    // Jumlah entri katalog per jenis, untuk kartu rekap. Tidak ikut difilter menurut jenis atau pencarian: kartu harus tetap
    // menunjukkan seluruh isi katalog walau daftarnya sedang disaring, kalau tidak angkanya berubah setiap kali disaring dan tidak
    // bisa dipakai membandingkan. Sayap memang membatasinya: ia memilih katalog mana yang sedang dilihat.
    public function catalogCountsByKind($wing = '') {
        $where = isset(self::WINGS[$wing]) ? ' WHERE wing = ' . $this->db->quote($wing) : '';
        return $this->db->query("SELECT kind, COUNT(*) FROM unit_catalog$where GROUP BY kind")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function catalogCountsByWing() {
        return $this->db->query("SELECT wing, COUNT(*) FROM unit_catalog GROUP BY wing")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // ---------- Jenis unit (tabel unit_kinds; dibaca lewat UnitKinds) ----------

    // Tiap jenis beserta pemakaiannya: units = entri katalog berjenis itu, positions = jabatan yang batas jenis unitnya memuat jenis itu.
    // Dasar tombol Hapus di halaman Unit Organisasi: jenis yang masih dipakai tak bisa dihapus.
    public function kindList() {
        $units = $this->db->query("SELECT kind, COUNT(*) FROM unit_catalog GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
        $positions = [];
        foreach ($this->db->query("SELECT unit_kinds FROM positions WHERE unit_kinds IS NOT NULL AND unit_kinds <> ''")->fetchAll(PDO::FETCH_COLUMN) as $csv) {
            foreach (explode(',', $csv) as $k) {
                $k = trim($k);
                if ($k !== '') $positions[$k] = ($positions[$k] ?? 0) + 1;
            }
        }
        $out = [];
        foreach (UnitKinds::all() as $code => $r) $out[] = $r + ['units' => (int) ($units[$code] ?? 0), 'positions' => $positions[$code] ?? 0];
        return $out;
    }

    // Kode dari nama: huruf kecil, angka, dan garis bawah (cocok dengan unit_catalog.kind dan positions.unit_kinds). Kosong bila nama tak memuat
    // huruf atau angka biasa.
    public static function kindSlug($label) {
        return substr(trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $label)), '_'), 0, 30);
    }

    // $code null = jenis baru (kodenya dibuat dari nama dan TIDAK berubah lagi); selain itu mengubah nama, urutan, kelompok bawaan, dan status aktif.
    // Jenis nonaktif tak ditawarkan untuk unit baru, tetapi unit yang sudah berjenis itu tetap utuh. Jenis bawaan sistem ("belum ditentukan")
    // selalu aktif. $data: label, sort_order ('' = urutan berikutnya), group_name (kosong = tanpa kelompok bawaan), group_sort, is_active.
    public function kindSave($code, array $data) {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') throw new RuntimeException('Nama jenis unit wajib diisi.');
        if (mb_strlen($label) > 60) throw new RuntimeException('Nama jenis unit terlalu panjang (paling banyak 60 huruf).');
        $group = trim((string) ($data['group_name'] ?? ''));
        if (mb_strlen($group) > 100) throw new RuntimeException('Nama kelompok bawaan terlalu panjang (paling banyak 100 huruf).');
        $groupSort = $group === '' ? null : max(0, min(65535, (int) (($data['group_sort'] ?? '') === '' ? 500 : $data['group_sort'])));

        foreach (UnitKinds::all() as $c => $r) {   // nama harus unik (tanpa membedakan huruf besar-kecil), selain pada jenis ini sendiri
            if ($c !== $code && mb_strtolower($r['label']) === mb_strtolower($label)) {
                throw new RuntimeException('Jenis unit "' . $r['label'] . '" sudah ada.');
            }
        }

        if ($code === null || $code === '') {
            $new = self::kindSlug($label);
            if ($new === '') throw new RuntimeException('Nama jenis unit harus memuat huruf atau angka biasa.');
            if (UnitKinds::exists($new)) throw new RuntimeException('Sudah ada jenis unit berkode "' . $new . '" (' . UnitKinds::label($new) . '). Pakai nama yang berbeda.');
            $order = (($data['sort_order'] ?? '') !== '') ? max(0, min(65535, (int) $data['sort_order']))
                   : (int) min(890, (floor((int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM unit_kinds WHERE is_system = 0")->fetchColumn() / 10) * 10) + 10);
            $this->db->prepare("INSERT INTO unit_kinds (code, label, sort_order, group_name, group_sort, is_active) VALUES (?, ?, ?, ?, ?, 1)")
                     ->execute([$new, $label, $order, $group !== '' ? $group : null, $groupSort]);
            Audit::log('unit_kinds', 0, 'create', null, ['code' => $new, 'label' => $label, 'sort_order' => $order, 'group_name' => $group]);
            UnitKinds::reset();
            return $new;
        }

        $cur = UnitKinds::find($code);
        if (!$cur) throw new RuntimeException('Jenis unit tidak ditemukan.');
        $active = !empty($cur['is_system']) ? 1 : (!empty($data['is_active']) ? 1 : 0);
        $order = (($data['sort_order'] ?? '') !== '') ? max(0, min(65535, (int) $data['sort_order'])) : (int) $cur['sort_order'];
        $this->db->prepare("UPDATE unit_kinds SET label = ?, sort_order = ?, group_name = ?, group_sort = ?, is_active = ? WHERE code = ?")
                 ->execute([$label, $order, $group !== '' ? $group : null, $groupSort, $active, $code]);
        Audit::log('unit_kinds', 0, 'update', ['code' => $code, 'label' => $cur['label'], 'sort_order' => $cur['sort_order'], 'group_name' => $cur['group_name'], 'is_active' => $cur['is_active']],
                   ['code' => $code, 'label' => $label, 'sort_order' => $order, 'group_name' => $group, 'is_active' => $active]);
        UnitKinds::reset();
        return $code;
    }

    // Menghapus jenis yang tak terpakai. Yang masih dipakai ditolak dengan alasan yang bisa dikerjakan; yang hanya tak diperlukan lagi bisa
    // dinonaktifkan lewat kindSave (tak lagi ditawarkan, unit lama tetap utuh). Jenis bawaan sistem tak bisa dihapus.
    public function kindDelete($code) {
        $cur = UnitKinds::find($code);
        if (!$cur) throw new RuntimeException('Jenis unit tidak ditemukan.');
        if (!empty($cur['is_system'])) throw new RuntimeException('"' . $cur['label'] . '" adalah jenis bawaan sistem (penampung unit yang jenisnya belum ditetapkan), jadi tidak bisa dihapus.');
        $used = null;
        foreach ($this->kindList() as $r) if ($r['code'] === $code) $used = $r;
        if ($used && $used['units'] > 0) {
            throw new RuntimeException('"' . $cur['label'] . '" masih dipakai ' . $used['units'] . ' unit. Ubah jenis unit-unit itu lebih dulu (Ubah pada baris unitnya), atau nonaktifkan jenis ini bila hanya tak ingin ditawarkan lagi.');
        }
        if ($used && $used['positions'] > 0) {
            throw new RuntimeException('"' . $cur['label'] . '" masih tercantum pada batas jenis unit ' . $used['positions'] . ' jabatan. Lepas dulu di halaman Jabatan, atau nonaktifkan jenis ini.');
        }
        $this->db->prepare("DELETE FROM unit_kinds WHERE code = ? AND is_system = 0")->execute([$code]);
        Audit::log('unit_kinds', 0, 'delete', ['code' => $code, 'label' => $cur['label']], null);
        UnitKinds::reset();
        return true;
    }

    // Sayap katalog yang boleh dipakai sebuah struktur: mengikuti structures.wing.
    public function wingOfStructure($structureId) {
        $stmt = $this->db->prepare("SELECT wing FROM structures WHERE id = :id");
        $stmt->execute([':id' => (int) $structureId]);
        $w = $stmt->fetchColumn();
        return isset(self::WINGS[$w]) ? $w : 'umum';
    }

    // Katalog beserta JUMLAH PEMAKAIANNYA. "Dipakai" adalah satu-satunya angka yang menjawab
    // apakah sebuah entri katalog masih hidup atau sudah mati -- hal yang tidak bisa dibaca dari
    // daftar nama saja, dan itulah yang membuat 105 baris bisa dievaluasi, bukan cuma dibaca.
    //   used_count      = berapa penempatan unit (structure_units) menunjuk entri ini
    //   used_structures = di berapa struktur berbeda
    // $flat = true: satu daftar menurut urutan resmi SKUO (sort_order), bukan dipecah per jenis.
    public function catalogList($kind = '', $keyword = '', $flat = false, $wing = '') {
        $where = [];
        $params = [];

        if ($kind !== '' && UnitKinds::exists($kind)) {
            $where[] = 'c.kind = :kind';
            $params[':kind'] = $kind;
        }
        if (isset(self::WINGS[$wing])) {
            $where[] = 'c.wing = :wing';
            $params[':wing'] = $wing;
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

        // Urutan jenis mengikuti daftar jenis unit (urutan di unit_kinds); kodenya dibatasi huruf kecil, angka, dan garis bawah.
        $kindList = implode(',', array_map(function ($c) { return $this->db->quote($c); }, UnitKinds::codes()));
        $order = $flat || $kindList === '' ? "c.sort_order" : "FIELD(c.kind, $kindList), c.sort_order";
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS head_name,
                   (SELECT COUNT(*) FROM structure_units su
                     WHERE su.unit_catalog_id = c.id AND su.deleted_at IS NULL) AS used_count,
                   (SELECT COUNT(DISTINCT su2.structure_id) FROM structure_units su2
                     WHERE su2.unit_catalog_id = c.id AND su2.deleted_at IS NULL) AS used_structures
            FROM unit_catalog c
            LEFT JOIN positions p ON p.id = c.default_head_position_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY $order, c.wing, c.name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function catalogFindById($id) {
        $stmt = $this->db->prepare("SELECT * FROM unit_catalog WHERE id = :id");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Nama yang sudah ada DI SAYAP ITU dipakai ulang, tidak diduplikasi. (Nama yang sama di sayap lain adalah entri lain.)
    public function catalogFindOrCreate($name, $kind = UnitKinds::FALLBACK, $abbreviation = null, $wing = 'umum') {
        $name = trim($name);
        $kind = UnitKinds::normalize($kind);
        $wing = isset(self::WINGS[$wing]) ? $wing : 'umum';
        $stmt = $this->db->prepare("SELECT * FROM unit_catalog WHERE name = :n AND wing = :w LIMIT 1");
        $stmt->execute([':n' => $name, ':w' => $wing]);
        if ($row = $stmt->fetch()) {
            return (int) $row['id'];
        }

        $ins = $this->db->prepare("
            INSERT INTO unit_catalog (uuid, name, abbreviation, kind, wing, default_head_position_id)
            VALUES (:uuid, :name, :abbr, :kind, :wing, :head)
        ");
        $ins->execute([
            ':uuid' => Uuid::v4(),
            ':name' => $name,
            ':abbr' => $abbreviation ?: null,
            ':kind' => $kind,
            ':wing' => $wing,
            ':head' => $this->defaultHeadPositionId($kind),
        ]);
        return (int) $this->db->lastInsertId();
    }

    // Entri katalog menurut nama dan sayapnya (kunci uniknya), atau null.
    public function catalogFindByName($name, $wing) {
        $stmt = $this->db->prepare("SELECT * FROM unit_catalog WHERE name = :n AND wing = :w LIMIT 1");
        $stmt->execute([':n' => trim($name), ':w' => $wing]);
        return $stmt->fetch() ?: null;
    }

    // Untuk isian "ketik nama unit" (halaman Pengelompokan Unit): nama yang sudah ada di katalog SAYAP struktur itu dipakai ulang
    // (jenisnya tidak diubah), yang belum ada dibuat di sayap itu dan dicatat. Mengembalikan [id katalog, apakah baru dibuat].
    public function catalogEnsure($name, $kind, $wing = 'umum') {
        $wing = isset(self::WINGS[$wing]) ? $wing : 'umum';
        $stmt = $this->db->prepare("SELECT id FROM unit_catalog WHERE name = :n AND wing = :w LIMIT 1");
        $stmt->execute([':n' => trim($name), ':w' => $wing]);
        if ($id = $stmt->fetchColumn()) return [(int) $id, false];
        return [$this->catalogSave(null, ['name' => $name, 'abbreviation' => '', 'kind' => $kind, 'wing' => $wing, 'is_active' => true]), true];
    }

    // Nomor urut berikutnya di urutan resmi SKUO: kelipatan 10 di atas yang terbesar, melewati 500 (500 = "belum diurutkan").
    public function catalogNextOrder() {
        $max = (int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM unit_catalog WHERE sort_order <> 500")->fetchColumn();
        $next = (int) (floor($max / 10) * 10) + 10;
        return $next === 500 ? 510 : min($next, 65535);
    }

    // Mengganti jenis sebuah unit ditolak selama masih ada penempatan hidup di unit itu yang jabatannya tak berlaku di jenis barunya
    // (mis. "Ketua Biro" di unit yang hendak dijadikan Departemen): penempatan itu akan jadi tak sah dan gagal disunting.
    private function assertKindKeepsPlacements($catalogId, $newKind) {
        $stmt = $this->db->prepare("
            SELECT po.name, po.unit_kinds, COUNT(*) AS n
            FROM placements pl
            JOIN structure_units su ON su.id = pl.structure_unit_id
            JOIN positions po ON po.id = pl.position_id
            WHERE su.unit_catalog_id = :id AND pl.deleted_at IS NULL AND po.unit_kinds IS NOT NULL AND po.unit_kinds <> ''
            GROUP BY po.id, po.name, po.unit_kinds
        ");
        $stmt->execute([':id' => (int) $catalogId]);
        $bad = [];
        foreach ($stmt->fetchAll() as $r) {
            if (!in_array($newKind, PositionModel::kindList($r['unit_kinds']), true)) $bad[] = $r['name'] . ' (' . $r['n'] . ')';
        }
        if ($bad) {
            throw new RuntimeException('Jenis unit tidak bisa diubah ke ' . UnitKinds::label($newKind)
                . ': masih ada penempatan berjabatan ' . implode(', ', $bad) . ' di unit ini. Pindahkan penempatan itu ke jabatan yang sesuai lebih dulu.');
        }
    }

    // Sebuah unit hanya boleh dipasang pada struktur yang sayapnya sama dengan sayap katalognya: unit MWP diambil dari katalog
    // Muslimah, unit DPP dari katalog Umum. Dijaga di server karena pilihan di formulir hanyalah penyaring.
    public function assertCatalogFitsStructure($catalogId, $structureId) {
        $stmt = $this->db->prepare("
            SELECT c.name, c.wing AS cwing, s.wing AS swing, s.level, s.name AS sname
            FROM unit_catalog c, structures s WHERE c.id = :c AND s.id = :s
        ");
        $stmt->execute([':c' => (int) $catalogId, ':s' => (int) $structureId]);
        $r = $stmt->fetch();
        if (!$r) throw new RuntimeException('Unit atau struktur tidak ditemukan.');
        if ($r['cwing'] !== $r['swing']) {
            throw new RuntimeException('Unit "' . $r['name'] . '" ada di katalog sayap ' . self::WINGS[$r['cwing']] . ', sedangkan ' . $r['level'] . ' '
                . $r['sname'] . ' sayap ' . self::WINGS[$r['swing']] . '. Pilih unit dari katalog sayap ' . self::WINGS[$r['swing']] . ', atau buat dulu di Unit Organisasi.');
        }
    }

    // Mengganti sayap entri katalog ditolak selama masih ada unit (periode mana pun, yang masih hidup) pada struktur sayap lain:
    // unit itu akan menunjuk katalog yang bukan sayapnya.
    private function assertWingKeepsUnits($catalogId, $newWing) {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT s.id) FROM structure_units su JOIN structures s ON s.id = su.structure_id
            WHERE su.unit_catalog_id = :id AND su.deleted_at IS NULL AND s.wing <> :w
        ");
        $stmt->execute([':id' => (int) $catalogId, ':w' => $newWing]);
        $n = (int) $stmt->fetchColumn();
        if ($n > 0) {
            throw new RuntimeException('Sayap unit tidak bisa diubah ke ' . self::WINGS[$newWing] . ': unit ini masih dipakai di ' . $n
                . ' struktur sayap lain. Buat entri katalog di sayap itu untuk strukturnya, atau lepas unit ini dari struktur tersebut lebih dulu.');
        }
    }

    // $data['sort_order'] kosong/tak ada: entri baru memakai nomor urut berikutnya, entri lama tidak berubah urutannya.
    // $data['wing']: sayap katalog (bawaan 'umum' untuk entri baru; pada entri lama hanya berubah bila dikirim).
    public function catalogSave($id, array $data) {
        $data['kind'] = UnitKinds::normalize($data['kind'] ?? '');   // kode yang tak dikenal tak pernah tersimpan: jatuh ke "belum ditentukan"
        $hasOrder = isset($data['sort_order']) && $data['sort_order'] !== '';
        $order = $hasOrder ? max(0, min(65535, (int) $data['sort_order'])) : null;
        $wing = isset($data['wing']) && isset(self::WINGS[$data['wing']]) ? $data['wing'] : null;

        if ($id) {
            $this->assertKindKeepsPlacements($id, $data['kind']);
            $current = $this->catalogFindById($id);
            if ($wing !== null && $current && $current['wing'] !== $wing) $this->assertWingKeepsUnits($id, $wing);
            $stmt = $this->db->prepare("
                UPDATE unit_catalog SET name = :name, abbreviation = :abbr, kind = :kind, wing = COALESCE(:wing, wing),
                       default_head_position_id = :head, is_active = :active, sort_order = COALESCE(:sort, sort_order)
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'   => trim($data['name']),
                ':abbr'   => $data['abbreviation'] !== '' ? $data['abbreviation'] : null,
                ':kind'   => $data['kind'],
                ':wing'   => $wing,
                ':head'   => $this->defaultHeadPositionId($data['kind']),
                ':active' => !empty($data['is_active']) ? 1 : 0,
                ':sort'   => $order,
                ':id'     => (int) $id,
            ]);
            Audit::log('unit_catalog', $id, 'update', null, $data);
            return (int) $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO unit_catalog (uuid, name, abbreviation, kind, wing, default_head_position_id, sort_order)
            VALUES (:uuid, :name, :abbr, :kind, :wing, :head, :sort)
        ");
        $stmt->execute([
            ':uuid' => Uuid::v4(),
            ':name' => trim($data['name']),
            ':abbr' => $data['abbreviation'] !== '' ? $data['abbreviation'] : null,
            ':kind' => $data['kind'],
            ':wing' => $wing ?? 'umum',
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

    // Istilah kepala unit mengikuti JENIS unitnya: Pusat dipimpin "Kepala", Divisi oleh "Koordinator", Departemen / Lembaga / Biro /
    // Badan oleh "Ketua <jenisnya>" (migrasi 021), sisanya oleh "Ketua". Ini default saat entri; yang menjaganya adalah batas jenis
    // unit pada jabatan (PositionModel::mismatch), mis. "Ketua Biro" hanya bisa ditempatkan di Biro.
    private function defaultHeadPositionId($kind) {
        $code = $kind === 'pusat' ? 'kepala' : ($kind === 'divisi' ? 'koordinator'
              : (in_array($kind, ['departemen', 'lembaga', 'biro', 'badan'], true) ? 'ketua_' . $kind : 'ketua'));
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

    // Calon Ketua Bidang: penempatan tingkat struktur (tanpa unit) berjabatan Ketua atau Wakil Ketua (termasuk yang khusus
    // DPW / DPD) yang inti di struktur ini dan masih berlaku pada periode itu. Satu-satunya sumber pilihan di formulir,
    // jadi isian lain tidak bisa dikirim.
    public function coordinatorCandidates($termId) {
        $codes = "'" . implode("', '", array_merge(PositionModel::KETUA_CODES, PositionModel::WAKIL_KETUA_CODES)) . "'";
        $stmt = $this->db->prepare("
            SELECT pl.id, p.full_name, po.name AS position_name
            FROM placements pl
            JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            WHERE pl.term_id = :t AND pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL
              AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
              AND po.code IN ($codes) AND " . PositionModel::CORE_SQL . "
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
            SELECT name FROM positions
            WHERE is_active = 1 AND is_core = 1 ORDER BY sort_order, name
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

    // Jumlah ORANG Pengurus Inti yang masih berlaku per periode: [term_id => orang]. Untuk baris "Pengurus Inti" di Pengelompokan Unit
    // (entri pertama SKUO tiap struktur; hanya tampilan, penempatannya tetap tanpa unit). Periode tanpa penempatan inti tak muncul di hasil.
    public function intiCountsByTerm(array $termIds) {
        $ids = array_values(array_unique(array_map('intval', $termIds)));
        if (!$ids) return [];
        return array_map('intval', $this->db->query("
            SELECT pl.term_id, COUNT(DISTINCT pl.person_id)
            FROM placements pl
            JOIN positions po ON po.id = pl.position_id
            WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND " . PositionModel::CORE_SQL . "
              AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND pl.term_id IN (" . implode(',', $ids) . ")
            GROUP BY pl.term_id
        ")->fetchAll(PDO::FETCH_KEY_PAIR));
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

    // Kelompok untuk unit baru yang tujuannya "otomatis menurut jenis". Jenis yang punya kelompok bawaan (bawaannya Biro / Badan / Pusat; diatur
    // di unit_kinds): kelompok biasa (bukan Bidang) yang namanya memuat jenis itu dipakai bila periode ini sudah punya, kalau belum dibuat
    // ("Badan-Badan", dst.). Jenis lain: tanpa kelompok. Mengembalikan [id kelompok atau null, keterangan untuk pesan, mis. "kelompok Badan-Badan (baru dibuat)"].
    public function groupForKind($structureId, $termId, $kind) {
        $def = UnitKinds::group($kind);
        if ($def === null) return [null, ''];
        [$name, $sort] = $def;

        $groups = $this->groupsByTerm($termId);
        foreach ($groups as $g) {
            if (mb_strtolower($g['name']) === mb_strtolower($name)) return [(int) $g['id'], 'kelompok ' . $g['name']];
        }
        foreach ($groups as $g) {
            if ($g['kind'] === 'grup' && stripos($g['name'], $kind) !== false) return [(int) $g['id'], 'kelompok ' . $g['name']];
        }
        $id = $this->groupSave(null, ['structure_id' => $structureId, 'term_id' => $termId, 'name' => $name, 'kind' => 'grup', 'sort_order' => $sort]);
        return [$id, 'kelompok ' . $name . ' (baru dibuat)'];
    }

    // Berapa unit (di periode mana pun) yang masih hidup menunjuk entri katalog ini; 0 = belum terpasang di struktur mana pun.
    public function unitUsage($catalogId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM structure_units WHERE unit_catalog_id = :c AND deleted_at IS NULL");
        $stmt->execute([':c' => (int) $catalogId]);
        return (int) $stmt->fetchColumn();
    }

    // Sudah ada (dan masih hidup) unit katalog ini di periode itu? Dicek sebelum kelompok otomatis dibuat, supaya penolakan
    // tidak meninggalkan kelompok kosong.
    public function unitInTerm($termId, $catalogId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM structure_units WHERE term_id = :t AND unit_catalog_id = :c AND deleted_at IS NULL");
        $stmt->execute([':t' => (int) $termId, ':c' => (int) $catalogId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // Isi Bidang dan unit tiap periode berjalan dalam lingkup admin, satu query: term_id => ['groups' => n, 'units' => n].
    // Dasar saringan "Sudah disusun" di Pengelompokan Unit; isi rincinya baru dimuat untuk baris yang benar-benar ditampilkan.
    public function termCounts() {
        $rows = $this->db->query("
            SELECT t.id,
                   (SELECT COUNT(*) FROM unit_groups g WHERE g.term_id = t.id AND g.deleted_at IS NULL) AS groups_n,
                   (SELECT COUNT(*) FROM structure_units su WHERE su.term_id = t.id AND su.deleted_at IS NULL) AS units_n
            FROM terms t
            WHERE t.is_current = 1 AND t.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id') . "
        ")->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = ['groups' => (int) $r['groups_n'], 'units' => (int) $r['units_n']];
        return $out;
    }

    // Periode berjalan yang bisa dipilih untuk "Pasang ke struktur" dari katalog: term_id, level, name, wing, label, disusun.
    // Hanya struktur dalam lingkup admin; pembatasan menurut sayap dilakukan pemanggil.
    public function installTargets() {
        $counts = $this->termCounts();
        $rows = $this->db->query("
            SELECT t.id AS term_id, t.name AS term_name, s.level, s.name, s.wing
            FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.is_current = 1 AND t.deleted_at IS NULL AND s.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id') . "
            ORDER BY s.wing, s.tier, s.path
        ")->fetchAll();
        foreach ($rows as &$r) {
            $c = $counts[(int) $r['term_id']] ?? ['groups' => 0, 'units' => 0];
            $r['disusun'] = ($c['groups'] + $c['units']) > 0;
            $r['label'] = $r['level'] . ' ' . $r['name'];
        }
        unset($r);
        return $rows;
    }

    // ---------- Unit per struktur+periode ----------

    // Daftar unit satu periode, dikelompokkan Bidang, lengkap dengan jumlah pengurusnya.
    public function unitsByTerm($termId) {
        $stmt = $this->db->prepare("
            SELECT su.*, c.name AS catalog_name, c.abbreviation, c.kind,
                   g.name AS group_name, g.kind AS group_kind, g.sort_order AS group_sort,
                   (SELECT COUNT(*) FROM placements pl
                     WHERE pl.term_id = su.term_id AND pl.structure_unit_id = su.id AND pl.deleted_at IS NULL) AS placement_count
            FROM structure_units su
            JOIN unit_catalog c ON c.id = su.unit_catalog_id
            LEFT JOIN unit_groups g ON g.id = su.unit_group_id
            WHERE su.term_id = :t AND su.deleted_at IS NULL
            ORDER BY COALESCE(g.sort_order, 9999), g.name, su.sort_order, c.name
        ");
        $stmt->execute([':t' => (int) $termId]);
        // kind_label = nama jenisnya; kind_badge = yang dipasang sebagai lencana di susunan (kosong untuk "belum ditentukan": tak ada yang perlu diberi tahu).
        return array_map(function ($r) {
            $r['kind_label'] = UnitKinds::label($r['kind']);
            $r['kind_badge'] = in_array($r['kind'], [UnitKinds::FALLBACK, 'lainnya'], true) ? null : $r['kind_label'];   // 'lainnya' = sebutan lama, sebelum migrasi 025
            return $r;
        }, $stmt->fetchAll());
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

        $this->assertCatalogFitsStructure($data['unit_catalog_id'], $data['structure_id']);

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
