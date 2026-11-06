<?php
// PATH LOKASI FILE: app/Models/PersonModel.php
// Data orang (subjek data): nama terpisah (gelar depan | nama | gelar belakang), pencocokan calon
// duplikat, lookup by NIK, foto, dan visibilitas menurut lingkup admin.
//
// Visibilitas: `people` tidak punya kolom struktur -- seseorang bisa menjabat di beberapa struktur
// sekaligus. Maka sebuah baris orang terlihat oleh admin bila SALAH SATU berlaku:
//   (a) punya penempatan di dalam lingkupnya, atau
//   (b) pertama kali didaftarkan di dalam lingkupnya (people.home_structure_id).
// Butir (b) ada karena orang yang baru dibuat belum punya penempatan: tanpanya ia tak terlihat oleh
// admin yang baru saja membuatnya, dan tidak bisa dipilih saat menambahkan penempatan pertamanya.

require_once ROOT_DIR . '/app/Models/PositionModel.php'; // PositionModel::INTI_*: kode jabatan penanda "inti lengkap"

class PersonModel {
    // Filter cepat di daftar pengurus. Semuanya soal kelengkapan data -- itulah pekerjaan admin.
    const LIST_FILTERS = [
        'tanpa_hp'         => 'Tanpa nomor WA',
        'tanpa_nik'        => 'Tanpa NIK',
        'tanpa_penempatan' => 'Belum punya penempatan',
        'tanpa_unit'       => 'Tanpa Unit Organisasi (SKUO)',
        'nonaktif'         => 'Nonaktif',
    ];

    // Definisi tunggal "data lengkap", dipakai kartu rekap DAN filter daftar supaya angka di kartu
    // selalu sama dengan isi halaman yang dibukanya.
    //
    // Bila definisinya berubah, ubah BERSAMA, semuanya di sini: COMPLETE_SQL (hitungan dan filter), missingFields() (padanan PHP untuk label
    // "Kurang: ..." per baris Daftar), dan teksnya COMPLETE_RULE / COMPLETE_SHORT (tooltip badge, keterangan di bawah tabel Daftar, kartu
    // "Data lengkap", legenda Per Struktur). tests/people_per_struktur_test.php menjaga COMPLETE_SQL dan missingFields() tetap sepakat.
    //
    // NIK untuk SAAT INI sengaja belum disyaratkan (diputuskan 2026-10-24, ditegaskan 2026-11-06). NIK akan menjadi ID unik penghubung data
    // personal ke aplikasi lain dan kelak diwajibkan; sumbernya diharapkan ditarik dari Database Kader (DIWAN hanya database Pengurus).
    // Kelengkapan NIK tetap terlihat terpisah di dasbor dan lewat filter 'tanpa_nik'.
    //
    // Jenis kelamin hari ini selalu terisi (kolomnya NOT NULL), jadi syarat ini belum menyaring
    // apa-apa; ia ditulis agar tetap benar bila kolomnya kelak boleh kosong.
    const COMPLETE_SQL = "(p.phone IS NOT NULL AND p.phone <> ''
                           AND p.gender IS NOT NULL AND p.gender <> '')";
    const COMPLETE_SHORT = 'WA dan jenis kelamin';
    const COMPLETE_RULE = 'nomor WA dan jenis kelamin terisi. NIK belum dihitung sebagai syarat.';

    // Yang masih kurang pada satu baris orang (padanan PHP dari COMPLETE_SQL); kosong = lengkap.
    public static function missingFields(array $p) {
        $out = [];
        if ((string) ($p['phone'] ?? '') === '') $out[] = 'WA';
        if ((string) ($p['gender'] ?? '') === '') $out[] = 'jenis kelamin';
        return $out;
    }

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Fragmen SQL pembatas visibilitas untuk tabel people beralias $alias. Seluruh nilai berasal dari
    // intval() di StructureModel, bukan input pengguna, jadi aman disisipkan langsung.
    public static function scopeSql($alias = 'p') {
        if (AdminScope::isUnbounded()) return '1=1';
        $ids = AdminScope::structureIds();
        if (empty($ids)) return '1=0'; // gagal tertutup
        $in = implode(',', array_map('intval', $ids));
        return "({$alias}.home_structure_id IN ($in)
                 OR EXISTS (SELECT 1 FROM placements plv
                             WHERE plv.person_id = {$alias}.id AND plv.deleted_at IS NULL
                               AND plv.structure_id IN ($in)))";
    }

    public function isVisible($personId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM people p WHERE p.id = :id AND p.deleted_at IS NULL AND " . self::scopeSql('p'));
        $stmt->execute([':id' => (int) $personId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM people WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("SELECT * FROM people WHERE uuid = :u AND deleted_at IS NULL");
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    // Lookup by nomor HP/WA. `phone` SENGAJA bukan kolom unik (lihat komentar di schema) -- nomor
    // dipakai oleh lebih dari satu orang dianggap TIDAK ditemukan (ambigu), bukan mengembalikan salah
    // satu secara sembarang. Pola yang sama dengan MemberModel::findEligibleByPhone().
    public function findByPhone($phone) {
        $phone = Text::normalizePhone($phone);
        if ($phone === null) return null;

        $stmt = $this->db->prepare("SELECT id FROM people WHERE phone = :p AND deleted_at IS NULL LIMIT 2");
        $stmt->execute([':p' => $phone]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) return null;

        return $this->findById((int) $ids[0]);
    }

    // Lookup by NIK. SETIAP pemanggilan diaudit, termasuk yang tidak menemukan apa pun: upaya
    // menebak-nebak NIK adalah justru hal yang perlu terlihat di jejak audit, dan pencarian yang
    // gagal tidak boleh jadi cara menguji keberadaan seseorang tanpa meninggalkan bekas.
    // $actor: 'api:<nama klien>' atau 'admin' -- dicatat di jejak audit.
    public function findByNik($nik, $actor = 'admin') {
        $nik = Text::normalizeNik($nik);
        if ($nik === null) return null;

        // nik_guard = nik untuk baris yang belum dihapus, dan SATU-SATUNYA yang terindeks (unik).
        // `nik = :n` memindai seluruh tabel pada tiap lookup.
        $stmt = $this->db->prepare("SELECT * FROM people WHERE nik_guard = :n LIMIT 1");
        $stmt->execute([':n' => $nik]);
        $row = $stmt->fetch() ?: null;

        Audit::log('people', $row ? (int) $row['id'] : 0, 'update', null, [
            'event'     => 'nik_lookup',
            'actor'     => $actor,
            'nik'       => $nik,       // diredaksi jadi bertopeng oleh Audit::redact()
            'ditemukan' => $row ? true : false,
        ]);

        return $row;
    }

    // ---------- Daftar ----------

    // Rekap untuk kartu di atas daftar: orang yang cocok dengan pencarian ($keyword) dan jenjang ($level) yang dipilih. Penyaring milik
    // kartu itu sendiri (jenis kelamin, kelengkapan, rangkap, ...) sengaja TIDAK ikut: kartu-kartu itu memecah himpunan ini, jadi kalau
    // ikut menyaring, memilih "Perempuan" akan menolkan kartu "Laki-laki". Tanpa pencarian dan jenjang, angkanya seluruh lingkup admin.
    // Selalu saling cocok: menjabat + tidak_menjabat = total, rangkap + tunggal = menjabat, lengkap + belum_lengkap = total.
    public function recapCounts($keyword = '', $level = '') {
        $params = [];
        $where = $this->listWhere($keyword, ['level' => $level], $params);
        $inScope = AdminScope::sqlFilter('plr.structure_id');

        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(p.gender = 'L'), 0) AS l, COALESCE(SUM(p.gender = 'P'), 0) AS pr,
                   COALESCE(SUM(" . self::COMPLETE_SQL . "), 0) AS lengkap,
                   COALESCE(SUM(sc.n >= 1), 0) AS menjabat, COALESCE(SUM(sc.n > 1), 0) AS rangkap
            FROM people p
            LEFT JOIN (
                SELECT plr.person_id, COUNT(DISTINCT plr.structure_id) AS n
                FROM placements plr
                JOIN terms tr ON tr.id = plr.term_id AND tr.is_current = 1 AND tr.deleted_at IS NULL
                WHERE plr.deleted_at IS NULL AND (plr.end_date IS NULL OR plr.end_date >= CURDATE()) AND $inScope
                GROUP BY plr.person_id
            ) sc ON sc.person_id = p.id
            WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        $r = $stmt->fetch();

        $total = (int) $r['total'];
        $menjabat = (int) $r['menjabat'];
        $lengkap = (int) $r['lengkap'];
        return [
            'total' => $total,
            'L' => (int) $r['l'],
            'P' => (int) $r['pr'],
            'lengkap' => $lengkap,
            'belum_lengkap' => $total - $lengkap,
            'menjabat' => $menjabat,
            'tidak_menjabat' => $total - $menjabat,
            'rangkap' => (int) $r['rangkap'],
            'tunggal' => $menjabat - (int) $r['rangkap'],
        ];
    }

    // Jumlah orang per jenjang (level struktur) tempat ia menjabat: periode berjalan, belum berakhir, dalam lingkup admin.
    // Satu orang yang menjabat di dua jenjang dihitung di keduanya, jadi jumlahnya tak harus sama dengan total orang.
    public function levelCounts() {
        return array_map('intval', $this->db->query("
            SELECT s.level, COUNT(DISTINCT pl.person_id)
            FROM placements pl
            JOIN terms tr ON tr.id = pl.term_id AND tr.is_current = 1 AND tr.deleted_at IS NULL
            JOIN structures s ON s.id = pl.structure_id AND s.deleted_at IS NULL
            JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL AND " . self::scopeSql('p') . "
            WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
              AND " . AdminScope::sqlFilter('pl.structure_id') . "
            GROUP BY s.level
        ")->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // Bahan tampilan "Per Struktur": angka per struktur dan per jenjang, dari penempatan yang berlaku (periode berjalan, belum berakhir,
    // dalam lingkup admin) milik orang yang cocok dengan pencarian dan penyaring yang sama dengan daftar.
    // Baris penempatannya dibaca SEKALI (sekitar 4.500) lalu dikelompokkan di sini; query COUNT(DISTINCT) terpisah untuk struktur dan
    // jenjang terukur beberapa kali lebih lambat. Orang dihitung sekali per kelompok walau rangkap di dalamnya. Hasil:
    //   structures: id, term_id, level, name, parent_id / parent_level / parent_name (induk tingkat Wilayah ke bawah yang masih dalam
    //               lingkup, selain itu null; hanya KETERANGAN dan dasar hitung "5 MWD", bukan pengelompok), orang, lengkap, l, p;
    //               terurut nama
    //   levels:     level => orang, lengkap, l, p
    // $includeEmpty: ikut menyertakan struktur dalam lingkup yang TIDAK punya orang yang cocok (angkanya nol; term_id null bila belum punya
    // periode berjalan). Dipakai bila tak ada pencarian atau penyaring orang: dulu struktur baru (mis. DPLN) dan struktur kerangka tak tampil
    // sama sekali sampai ada pengurusnya, padahal justru yang kosong itulah yang perlu dipantau. Dengan penyaring orang, struktur kosong
    // memang tak punya apa-apa untuk dicocokkan, jadi tidak disertakan.
    public function overviewByStructure($keyword = '', array $filters = [], $includeEmpty = false) {
        $params = [];
        $where = $this->listWhere($keyword, $filters, $params);
        $cond = "WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())
                   AND " . AdminScope::sqlFilter('pl.structure_id') . " AND " . implode(' AND ', $where);
        // Jenjang terpilih membatasi ORANGNYA (listWhere), bukan tempatnya: orang DPW juga menjabat di DPP, DPD, dst. Pohon hanya boleh
        // memuat jenjang yang dipilih, jadi penempatannya dibatasi juga (nilai sudah melalui quote, bukan placeholder: :f_level sudah terpakai).
        if (!empty($filters['level'])) $cond .= " AND s.level = " . $this->db->quote($filters['level']);
        // Induk hanya dipakai bila ia sendiri tingkat Wilayah ke bawah (tier >= 2: DPW/MWW, DPD/MWD, ...). DPP dan MWP (tier 1) adalah
        // pucuk nasional: "MWP" bukan keterangan yang berguna bagi 36 MWW. Dulu yang dipakai depth >= 1, padahal MWP anak DPP (depth 1),
        // sehingga seluruh MWW tampil di bawah satu kelompok "MWP". Induk juga harus masih di dalam lingkup admin:
        // admin DPD tidak boleh melihat nama DPW di atasnya.
        $parentOn = "sp.id = s.parent_id AND sp.deleted_at IS NULL AND sp.tier >= 2 AND " . AdminScope::sqlFilter('sp.id');

        $stmt = $this->db->prepare("
            SELECT pl.person_id, s.id AS structure_id, s.level, s.name, tr.id AS term_id,
                   sp.id AS parent_id, sp.level AS parent_level, sp.name AS parent_name,
                   p.gender, " . self::COMPLETE_SQL . " AS complete
            FROM placements pl
            JOIN terms tr ON tr.id = pl.term_id AND tr.is_current = 1 AND tr.deleted_at IS NULL
            JOIN structures s ON s.id = pl.structure_id AND s.deleted_at IS NULL
            JOIN people p ON p.id = pl.person_id
            LEFT JOIN structures sp ON $parentOn
            $cond
            ORDER BY s.name, s.id
        ");
        $stmt->execute($params);

        // Himpunan orang per kelompok (id orang => [jenis kelamin, data lengkap]); angkanya dibaca dari himpunan itu.
        $count = function (array $people) {
            $n = ['orang' => count($people), 'lengkap' => 0, 'l' => 0, 'p' => 0];
            foreach ($people as [$gender, $complete]) {
                if ($complete) $n['lengkap']++;
                if ($gender === 'L') $n['l']++; elseif ($gender === 'P') $n['p']++;
            }
            return $n;
        };

        $structures = []; $byStructure = []; $byLevel = [];
        while ($r = $stmt->fetch()) {
            $pid = (int) $r['person_id'];
            $sid = (int) $r['structure_id'];
            $who = [(string) $r['gender'], (bool) $r['complete']];
            if (!isset($structures[$sid])) {
                $structures[$sid] = ['id' => $sid, 'term_id' => (int) $r['term_id'], 'level' => $r['level'], 'name' => $r['name'],
                                     'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                                     'parent_level' => $r['parent_level'], 'parent_name' => $r['parent_name']];
            }
            $byStructure[$sid][$pid] = $who;
            $byLevel[$r['level']][$pid] = $who;
        }

        if ($includeEmpty) {
            // Satu periode berjalan per struktur (yang terbaru bila datanya ganda); null bila belum punya.
            $empty = $this->db->query("
                SELECT s.id, s.level, s.name, sp.id AS parent_id, sp.level AS parent_level, sp.name AS parent_name,
                       (SELECT tr.id FROM terms tr WHERE tr.structure_id = s.id AND tr.is_current = 1 AND tr.deleted_at IS NULL
                         ORDER BY tr.start_date DESC, tr.id DESC LIMIT 1) AS term_id,
                       (SELECT tr.id FROM terms tr WHERE tr.structure_id = s.id AND tr.is_current = 0 AND tr.status <> 'ended' AND tr.deleted_at IS NULL
                         ORDER BY tr.start_date DESC, tr.id DESC LIMIT 1) AS pending_id,
                       (SELECT tr.name FROM terms tr WHERE tr.structure_id = s.id AND tr.is_current = 0 AND tr.status <> 'ended' AND tr.deleted_at IS NULL
                         ORDER BY tr.start_date DESC, tr.id DESC LIMIT 1) AS pending_name
                FROM structures s
                LEFT JOIN structures sp ON $parentOn
                WHERE s.deleted_at IS NULL AND " . AdminScope::sqlFilter('s.id')
                . (!empty($filters['level']) ? " AND s.level = " . $this->db->quote($filters['level']) : '') . "
                ORDER BY s.name, s.id
            ")->fetchAll();
            foreach ($empty as $r) {
                $sid = (int) $r['id'];
                if (isset($structures[$sid])) continue;
                $hasTerm = $r['term_id'] !== null;
                // Periode yang ada tetapi belum ditandai berjalan: hanya berarti bila strukturnya belum punya periode berjalan.
                $structures[$sid] = ['id' => $sid, 'term_id' => $hasTerm ? (int) $r['term_id'] : null, 'level' => $r['level'], 'name' => $r['name'],
                                     'pending' => !$hasTerm && $r['pending_id'] !== null ? ['id' => (int) $r['pending_id'], 'name' => $r['pending_name']] : null,
                                     'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                                     'parent_level' => $r['parent_level'], 'parent_name' => $r['parent_name']];
                $byStructure[$sid] = [];
                if (!isset($byLevel[$r['level']])) $byLevel[$r['level']] = [];
            }
        }

        foreach ($structures as $sid => &$s) $s += $count($byStructure[$sid]);
        unset($s);

        return ['structures' => array_values($structures), 'levels' => array_map($count, $byLevel)];
    }

    // Apakah Ketua, Sekretaris, dan Bendahara sebuah struktur terisi (penempatan tingkat struktur, periode berjalan, belum berakhir).
    // Sengaja TIDAK memakai penyaring daftar: kelengkapan inti adalah sifat strukturnya, bukan hasil pencarian. $structureId = satu
    // struktur saja; null = semua struktur dalam lingkup. Hasil: structure_id => ['k' => bool, 's' => bool, 'b' => bool];
    // struktur tanpa penempatan inti sama sekali tidak ada di hasil (anggap ketiganya kosong).
    public function intiFlags($structureId = null) {
        $in = function (array $codes) { return implode(',', array_map([$this->db, 'quote'], $codes)); };
        $rows = $this->db->query("
            SELECT pl.structure_id,
                   MAX(po.code IN (" . $in(PositionModel::INTI_KETUA) . ")) AS k,
                   MAX(po.code IN (" . $in(PositionModel::INTI_SEKRETARIS) . ")) AS s,
                   MAX(po.code IN (" . $in(PositionModel::INTI_BENDAHARA) . ")) AS b
            FROM placements pl
            JOIN terms tr ON tr.id = pl.term_id AND tr.is_current = 1 AND tr.deleted_at IS NULL
            JOIN positions po ON po.id = pl.position_id
            JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
            WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND pl.structure_unit_id IS NULL
              AND " . AdminScope::sqlFilter('pl.structure_id') . ($structureId !== null ? ' AND pl.structure_id = ' . (int) $structureId : '') . "
            GROUP BY pl.structure_id
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) $out[(int) $r['structure_id']] = ['k' => (bool) $r['k'], 's' => (bool) $r['s'], 'b' => (bool) $r['b']];
        return $out;
    }

    // Klausa WHERE + parameter untuk daftar/hitung. Dipakai bersama supaya jumlah dan isi halaman
    // tidak pernah berselisih. Tiap placeholder bernama dipakai SEKALI (ATTR_EMULATE_PREPARES=false
    // menolak pemakaian ganda).
    private function listWhere($keyword, array $filters, array &$params) {
        $where = ['p.deleted_at IS NULL', self::scopeSql('p')];

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            // LIKE: karakter % _ \ dari pengguna dinetralkan, kalau tidak "50%" cocok dengan semuanya.
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $or = ['p.full_name LIKE :kw_full', 'p.name LIKE :kw_name', 'p.phone LIKE :kw_phone'];
            $params[':kw_full']  = $like;
            $params[':kw_name']  = $like;
            $params[':kw_phone'] = $like;

            // Nama tanpa gelar. Dilewati bila hasil normalisasi kosong (mis. kata kunci berupa angka):
            // LIKE '%%' cocok dengan SEMUA baris dan membuat pencarian nomor telepon mengembalikan semuanya.
            $norm = Text::normalizeBase($keyword);
            if ($norm !== '') {
                $or[] = 'p.name_normalized LIKE :kw_norm';
                $params[':kw_norm'] = '%' . addcslashes($norm, '%_\\') . '%';
            }
            // NIK hanya cocok bila diisi utuh 16 digit -- pencarian sebagian nomor KTP tidak difasilitasi.
            $nik = Text::normalizeNik($keyword);
            if ($nik !== null) {
                $or[] = 'p.nik = :kw_nik';
                $params[':kw_nik'] = $nik;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        if (!empty($filters['gender']) && in_array($filters['gender'], ['L', 'P'], true)) {
            $where[] = 'p.gender = :f_gender';
            $params[':f_gender'] = $filters['gender'];
        }

        // "Sedang menjabat" memakai definisi yang SAMA dengan dasbor (DashboardModel::CURRENT): periode
        // berjalan, belum berakhir, di dalam lingkup. Tautan dari dasbor menghitung himpunan yang sama.
        $serving = "EXISTS (SELECT 1 FROM placements pls JOIN terms ts ON ts.id = pls.term_id
                             WHERE pls.person_id = p.id AND pls.deleted_at IS NULL AND ts.deleted_at IS NULL
                               AND ts.is_current = 1 AND (pls.end_date IS NULL OR pls.end_date >= CURDATE())
                               AND " . AdminScope::sqlFilter('pls.structure_id') . ")";
        if (!empty($filters['serving'])) $where[] = $serving;

        // Jenjang: orang yang sedang menjabat (definisi yang sama) di struktur berjenjang itu, mis. 'DPW'.
        if (!empty($filters['level'])) {
            $where[] = "EXISTS (SELECT 1 FROM placements pll
                                 JOIN terms tl ON tl.id = pll.term_id AND tl.is_current = 1 AND tl.deleted_at IS NULL
                                 JOIN structures sl ON sl.id = pll.structure_id AND sl.deleted_at IS NULL
                                 WHERE pll.person_id = p.id AND pll.deleted_at IS NULL
                                   AND (pll.end_date IS NULL OR pll.end_date >= CURDATE()) AND sl.level = :f_level
                                   AND " . AdminScope::sqlFilter('pll.structure_id') . ")";
            $params[':f_level'] = $filters['level'];
        }

        $inScope = AdminScope::sqlFilter('plf.structure_id');
        switch ($filters['filter'] ?? '') {
            case 'tidak_menjabat': $where[] = "NOT $serving"; break;
            case 'tanpa_hp':  $where[] = "(p.phone IS NULL OR p.phone = '')"; break;
            case 'tanpa_nik': $where[] = "p.nik IS NULL"; break;
            case 'nonaktif':  $where[] = "p.status <> 'active'"; break;
            case 'tanpa_penempatan':
                $where[] = "NOT EXISTS (SELECT 1 FROM placements pln WHERE pln.person_id = p.id AND pln.deleted_at IS NULL)";
                break;
            case 'tanpa_unit':
                // Hanya SKUO yang aktif pada periode berjalan dan dalam lingkup admin yang dihitung.
                // Jabatan Pengurus Inti (structure_unit_id NULL) tidak dianggap sebagai keanggotaan SKUO.
                $where[] = "NOT EXISTS (SELECT 1 FROM placements plu
                              JOIN terms tu ON tu.id = plu.term_id AND tu.is_current = 1 AND tu.deleted_at IS NULL
                              JOIN structure_units su ON su.id = plu.structure_unit_id
                                   AND su.term_id = plu.term_id AND su.structure_id = plu.structure_id AND su.deleted_at IS NULL
                              WHERE plu.person_id = p.id AND plu.deleted_at IS NULL
                                AND plu.structure_unit_id IS NOT NULL
                                AND (plu.start_date IS NULL OR plu.start_date <= CURDATE())
                                AND (plu.end_date IS NULL OR plu.end_date >= CURDATE())
                                AND " . AdminScope::sqlFilter('plu.structure_id') . ")";
                break;
            case 'lengkap':
                $where[] = self::COMPLETE_SQL;
                break;
            case 'belum_lengkap':
                $where[] = "NOT " . self::COMPLETE_SQL;
                break;
            case 'rangkap':
                $where[] = "(SELECT COUNT(DISTINCT plf.structure_id) FROM placements plf
                              JOIN terms tf ON tf.id = plf.term_id AND tf.is_current = 1 AND tf.deleted_at IS NULL
                              WHERE plf.person_id = p.id AND plf.deleted_at IS NULL
                                AND (plf.end_date IS NULL OR plf.end_date >= CURDATE()) AND $inScope) > 1";
                break;
            case 'tidak_rangkap':
                $where[] = "(SELECT COUNT(DISTINCT plf.structure_id) FROM placements plf
                              JOIN terms tf ON tf.id = plf.term_id AND tf.is_current = 1 AND tf.deleted_at IS NULL
                              WHERE plf.person_id = p.id AND plf.deleted_at IS NULL
                                AND (plf.end_date IS NULL OR plf.end_date >= CURDATE()) AND $inScope) <= 1";
                break;
        }
        return $where;
    }

    // Daftar orang dalam lingkup admin, lengkap dengan jabatan terkininya. Jabatan yang ditampilkan
    // dibatasi pada penempatan DI DALAM lingkup admin -- struktur lain tidak dibocorkan lewat daftar.
    public function searchInScope($keyword = '', $limit = 50, $offset = 0, array $filters = []) {
        $params = [];
        $where = $this->listWhere($keyword, $filters, $params);
        $inScope = AdminScope::sqlFilter('pl.structure_id');
        $activeScope = AdminScope::sqlFilter('pla.structure_id');
        $latest = "pl.person_id = p.id AND pl.deleted_at IS NULL AND t.is_current = 1 AND t.deleted_at IS NULL
                   AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND $inScope
                   ORDER BY t.start_date DESC, po.sort_order, pl.id LIMIT 1";
        $activeLatest = "pla.person_id = p.id AND pla.deleted_at IS NULL AND ta.is_current = 1 AND ta.deleted_at IS NULL
                         AND (pla.end_date IS NULL OR pla.end_date >= CURDATE()) AND $activeScope
                         ORDER BY ta.start_date DESC, poa.sort_order, pla.id LIMIT 1";

        $stmt = $this->db->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM placements pl WHERE pl.person_id = p.id AND pl.deleted_at IS NULL AND $inScope) AS placement_count,
                   (SELECT COUNT(*) FROM placements pla JOIN terms ta ON ta.id = pla.term_id
                      WHERE pla.person_id = p.id AND pla.deleted_at IS NULL AND ta.is_current = 1 AND ta.deleted_at IS NULL
                        AND (pla.end_date IS NULL OR pla.end_date >= CURDATE()) AND $activeScope) AS current_placement_count,
                   (SELECT pla.id FROM placements pla JOIN terms ta ON ta.id = pla.term_id
                      JOIN positions poa ON poa.id = pla.position_id WHERE $activeLatest) AS current_placement_id,
                   (SELECT po.name FROM placements pl JOIN terms t ON t.id = pl.term_id
                      JOIN positions po ON po.id = pl.position_id WHERE $latest) AS position_name,
                   (SELECT CONCAT(s.level, ' ', s.name) FROM placements pl JOIN terms t ON t.id = pl.term_id
                      JOIN positions po ON po.id = pl.position_id JOIN structures s ON s.id = pl.structure_id WHERE $latest) AS structure_label,
                   EXISTS (SELECT 1 FROM placements pl JOIN terms t ON t.id = pl.term_id
                            WHERE pl.person_id = p.id AND pl.deleted_at IS NULL AND t.is_current = 1
                              AND (pl.end_date IS NULL OR pl.end_date >= CURDATE()) AND $inScope) AS is_serving
            FROM people p
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.name, p.id
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countInScope($keyword = '', array $filters = []) {
        $params = [];
        $where = $this->listWhere($keyword, $filters, $params);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM people p WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // Id semua orang yang cocok dengan pencarian/penyaringan yang sama dengan daftar (tanpa halaman). Dipakai isi tiap struktur di
    // tampilan "Per Struktur", yang menyaring penempatan menurut himpunan ini supaya cocok persis dengan daftar A-Z.
    public function idsInScope($keyword = '', array $filters = []) {
        $params = [];
        $where = $this->listWhere($keyword, $filters, $params);
        $stmt = $this->db->prepare("SELECT p.id FROM people p WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function byIds(array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $stmt = $this->db->query("SELECT p.* FROM people p WHERE p.deleted_at IS NULL AND p.id IN (" . implode(',', $ids) . ") ORDER BY p.name, p.id");
        return $stmt->fetchAll();
    }

    // Untuk <select> orang di formulir penempatan: ringan, hanya kolom yang dibutuhkan.
    public function optionsInScope($limit = 2000) {
        $stmt = $this->db->prepare("
            SELECT p.id, p.title_prefix, p.name, p.title_suffix, p.full_name, p.gender
            FROM people p
            WHERE p.deleted_at IS NULL AND " . self::scopeSql('p') . "
            ORDER BY p.name, p.id LIMIT :limit
        ");
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ---------- Tulis ----------

    // $data: title_prefix, name, title_suffix (atau full_name lama), gender, nik, phone, ...
    // Mengembalikan id. Melempar RuntimeException dengan pesan yang bisa dibaca pengguna.
    public function save($id, array $data) {
        // Tiga bagian nama. full_name lama (mis. dari skrip impor) dipecah otomatis.
        $pre = $data['title_prefix'] ?? '';
        $name = $data['name'] ?? '';
        $suf = $data['title_suffix'] ?? '';
        if (trim((string) $name) === '' && !empty($data['full_name'])) {
            $x = Text::splitName($data['full_name']);
            $name = $x['name'];
            $pre = $pre !== '' ? $pre : $x['title_prefix'];
            $suf = $suf !== '' ? $suf : $x['title_suffix'];
        }
        [$pre, $name, $suf] = Text::normalizeNameParts($pre, $name, $suf);
        if ($name === '') {
            throw new RuntimeException('Nama wajib diisi.');
        }

        $nik = isset($data['nik']) ? Text::normalizeNik($data['nik']) : null;
        $phone = isset($data['phone']) ? Text::normalizePhone($data['phone']) : null;

        // NIK yang sudah dipakai orang lain ditolak di sini dengan pesan yang bisa dibaca, bukan
        // dibiarkan menabrak uq_people_nik_live dan memunculkan error database ke pengguna.
        if ($nik !== null) {
            $clash = $this->db->prepare("
                SELECT id, full_name FROM people WHERE nik = :n AND deleted_at IS NULL AND id <> :id LIMIT 1
            ");
            $clash->execute([':n' => $nik, ':id' => (int) $id]);
            if ($other = $clash->fetch()) {
                // Nama pemilik NIK tidak dibuka ke admin di luar lingkupnya.
                $who = $this->isVisible($other['id']) ? ' pada: ' . $other['full_name'] . '. Periksa apakah ini orang yang sama sebelum melanjutkan' : '';
                throw new RuntimeException('NIK tersebut sudah terdaftar' . $who . '.');
            }
        }

        // Saklar Portal Pengurus. Pemanggil yang tidak menyebutnya (aktivasi pendaftaran, impor) tidak
        // boleh mengubahnya: orang baru bawaannya boleh masuk, orang lama dibiarkan seperti semula.
        $existing = $id ? $this->findById($id) : null;
        $portal = array_key_exists('portal_enabled', $data)
            ? (!empty($data['portal_enabled']) ? 1 : 0)
            : ($existing ? (int) $existing['portal_enabled'] : 1);

        // Pekerjaan & nomor HP keluarga: seperti saklar portal, hanya berubah bila pemanggil menyebutnya.
        // Aktivasi pendaftaran yang tak membawa keduanya tidak boleh menghapus isian yang sudah ada.
        $occupation = array_key_exists('occupation', $data)
            ? (trim(preg_replace('/\s+/u', ' ', (string) $data['occupation'])) !== '' ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $data['occupation'])), 0, 150) : null)
            : ($existing['occupation'] ?? null);
        $familyPhone = array_key_exists('family_phone', $data)
            ? Text::normalizePhone($data['family_phone'])
            : ($existing['family_phone'] ?? null);

        $fields = [
            ':occupation'      => $occupation,
            ':family_phone'    => $familyPhone,
            ':portal_enabled'  => $portal,
            ':title_prefix'    => $pre,
            ':name'            => $name,
            ':title_suffix'    => $suf,
            // name_normalized SELALU dihitung ulang di sini dari NAMA ASLI. Kalau ini terlewat,
            // pencocokan duplikat dan pencarian akan diam-diam memakai nama versi lama.
            ':name_normalized' => Text::normalizeBase($name),
            ':nik'             => $nik,
            ':gender'          => $data['gender'],
            ':phone'           => $phone,
            ':email'           => !empty($data['email']) ? trim($data['email']) : null,
            ':birth_date'      => !empty($data['birth_date']) ? $data['birth_date'] : null,
            ':birth_place'     => !empty($data['birth_place']) ? trim($data['birth_place']) : null,
            ':address_text'    => !empty($data['address_text']) ? trim($data['address_text']) : null,
            // Kelurahan & kecamatan: seperti occupation, hanya berubah bila pemanggil menyebutnya.
            ':village'         => array_key_exists('village', $data) ? (trim((string) $data['village']) !== '' ? trim($data['village']) : null) : ($existing['village'] ?? null),
            ':district'        => array_key_exists('district', $data) ? (trim((string) $data['district']) !== '' ? trim($data['district']) : null) : ($existing['district'] ?? null),
            ':city'            => !empty($data['city']) ? trim($data['city']) : null,
            ':province'        => !empty($data['province']) ? trim($data['province']) : null,
            ':status'          => $data['status'] ?? 'active',
            ':notes'           => !empty($data['notes']) ? trim($data['notes']) : null,
        ];

        if ($id) {
            $before = $this->findById($id);
            $stmt = $this->db->prepare("
                UPDATE people SET title_prefix = :title_prefix, name = :name, title_suffix = :title_suffix,
                       name_normalized = :name_normalized, nik = :nik, gender = :gender, phone = :phone,
                       family_phone = :family_phone, occupation = :occupation,
                       email = :email, birth_date = :birth_date, birth_place = :birth_place,
                       address_text = :address_text, village = :village, district = :district, city = :city, province = :province,
                       status = :status, portal_enabled = :portal_enabled, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute($fields + [':id' => (int) $id]);
            Audit::log('people', $id, 'update', $before, $this->findById($id));
            return (int) $id;
        }

        $home = $this->homeStructureFor($data['home_structure_id'] ?? null);
        $stmt = $this->db->prepare("
            INSERT INTO people (uuid, title_prefix, name, title_suffix, name_normalized, nik, gender, phone, family_phone, email,
                                birth_date, birth_place, address_text, village, district, city, province, occupation, status, portal_enabled, notes, home_structure_id)
            VALUES (:uuid, :title_prefix, :name, :title_suffix, :name_normalized, :nik, :gender, :phone, :family_phone, :email,
                    :birth_date, :birth_place, :address_text, :village, :district, :city, :province, :occupation, :status, :portal_enabled, :notes, :home)
        ");
        $stmt->execute($fields + [':uuid' => Uuid::v4(), ':home' => $home]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('people', $newId, 'create', null, $this->findById($newId));
        return $newId;
    }

    // Struktur asal orang baru: yang diminta eksplisit (mis. tujuan pendaftaran), selama berada di
    // lingkup pemanggil; kalau tidak ada, akar lingkup admin yang sedang login. Admin tanpa batas
    // lingkup tidak mengisinya (NULL) -- orang itu terlihat olehnya sebagai admin tanpa batas.
    private function homeStructureFor($requested) {
        if ($requested !== null && $requested !== '') {
            return (int) $requested; // pemanggil non-sesi (aktivasi pendaftaran) sudah memeriksa lingkup
        }
        if (AdminScope::isUnbounded()) return null;
        $roots = AdminScope::rootIds();
        return !empty($roots) ? (int) $roots[0] : null;
    }

    public function setPhoto($id, $path) {
        $before = $this->findById($id);
        $this->db->prepare("UPDATE people SET photo_path = :p WHERE id = :id")
                 ->execute([':p' => $path, ':id' => (int) $id]);
        Audit::log('people', $id, 'update', $before, $this->findById($id));
    }

    // Menghapus (menyembunyikan, deleted_at) satu orang. Penempatannya baru ikut ditiadakan bila $withPlacements -- itu pilihan
    // sadar dari tombol Hapus, yang konfirmasinya menyebut jumlahnya; tanpa itu orang bertempat tidak dihapus. Penempatan juga hanya
    // diberi deleted_at (bisa dipulihkan), dan SEMUA penempatan yang belum dihapus harus di dalam lingkup admin yang menghapus.
    // Mengembalikan jumlah penempatan yang ikut ditiadakan, atau false bila orangnya tak ada.
    public function softDelete($id, $withPlacements = false) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $stmt = $this->db->prepare("SELECT id, structure_id, term_id, structure_unit_id, position_id FROM placements
                                    WHERE person_id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        $placements = $stmt->fetchAll();

        if ($placements && !$withPlacements) {
            throw new RuntimeException('Orang ini masih memiliki ' . count($placements) . ' penempatan/riwayat yang belum dihapus.');
        }
        foreach ($placements as $pl) {
            if (!AdminScope::allows((int) $pl['structure_id'])) {
                throw new RuntimeException('Orang ini juga menjabat di struktur di luar lingkup Anda, jadi tidak bisa dihapus dari sini.');
            }
        }

        // Gunakan satu timestamp yang sama agar fitur pulihkan dapat membedakan penempatan yang
        // ikut terhapus bersama orang ini dari penempatan yang pernah dihapus sendiri.
        $now = microtime(true);
        $deletedAt = date('Y-m-d H:i:s', (int) $now) . '.' . sprintf('%03d', (int) (($now - floor($now)) * 1000));

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE placements SET deleted_at = :deleted_at WHERE person_id = :id AND deleted_at IS NULL")
                ->execute([':deleted_at' => $deletedAt, ':id' => $id]);
            $this->db->prepare("UPDATE people SET deleted_at = :deleted_at WHERE id = :id")
                ->execute([':deleted_at' => $deletedAt, ':id' => $id]);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($placements as $pl) Audit::log('placements', (int) $pl['id'], 'delete', $pl + ['person_id' => $id], ['event' => 'people_delete']);
        Audit::log('people', $id, 'delete', $before, ['event' => 'people_delete', 'placements_removed' => count($placements)]);
        return count($placements);
    }

    // Daftar pemulihan hanya menampilkan data yang termasuk lingkup admin. Import lama yang tidak
    // memiliki home_structure_id tetap dapat ditemukan lewat struktur pada riwayat penempatannya.
    public function deletedInScope($limit = 100) {
        $scope = AdminScope::isUnbounded()
            ? '1=1'
            : '(' . AdminScope::sqlFilter('p.home_structure_id') . '
               OR EXISTS (SELECT 1 FROM placements pls WHERE pls.person_id = p.id AND '
                    . AdminScope::sqlFilter('pls.structure_id') . '))';
        $stmt = $this->db->prepare("SELECT p.id, p.uuid, p.title_prefix, p.name, p.title_suffix, p.full_name,
                                           p.phone, p.deleted_at, p.home_structure_id,
                                           (SELECT COUNT(*) FROM placements pl
                                            WHERE pl.person_id = p.id AND pl.deleted_at = p.deleted_at) AS deleted_placements
                                    FROM people p WHERE p.deleted_at IS NOT NULL AND $scope
                                    ORDER BY p.deleted_at DESC, p.id DESC LIMIT :lim");
        $stmt->bindValue(':lim', max(1, min(500, (int) $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function restoreDeleted($id) {
        $id = (int) $id;
        $stmt = $this->db->prepare("SELECT * FROM people WHERE id = :id AND deleted_at IS NOT NULL");
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
        if (!$before) return false;

        $visible = AdminScope::allows($before['home_structure_id']);
        if (!$visible && !AdminScope::isUnbounded()) {
            $check = $this->db->prepare("SELECT COUNT(*) FROM placements pl WHERE pl.person_id = :id AND " . AdminScope::sqlFilter('pl.structure_id'));
            $check->execute([':id' => $id]);
            $visible = (int) $check->fetchColumn() > 0;
        }
        if (!$visible) throw new RuntimeException('Data ini berada di luar lingkup akses Anda.');

        $placementStmt = $this->db->prepare("SELECT * FROM placements WHERE person_id = :id AND deleted_at = :deleted_at");
        $placementStmt->execute([':id' => $id, ':deleted_at' => $before['deleted_at']]);
        $restoringPlacements = $placementStmt->fetchAll();

        $this->db->beginTransaction();
        try {
            // Timestamp identik hanya dimiliki penempatan yang terhapus dalam aksi hapus orang.
            // Penempatan yang sebelumnya dihapus satu per satu tidak ikut dipulihkan.
            $this->db->prepare("UPDATE placements SET deleted_at = NULL
                                WHERE person_id = :id AND deleted_at = :deleted_at")
                ->execute([':id' => $id, ':deleted_at' => $before['deleted_at']]);
            $this->db->prepare("UPDATE people SET deleted_at = NULL WHERE id = :id AND deleted_at = :deleted_at")
                ->execute([':id' => $id, ':deleted_at' => $before['deleted_at']]);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            if (strpos($e->getMessage(), 'uq_people_nik_live') !== false || strpos($e->getMessage(), 'uq_placements_live') !== false) {
                throw new RuntimeException('Data tidak dapat dipulihkan karena NIK atau penempatannya sudah dipakai data lain. Selesaikan benturan itu terlebih dahulu.');
            }
            throw $e;
        }

        foreach ($restoringPlacements as $placement) {
            $afterStmt = $this->db->prepare('SELECT * FROM placements WHERE id = :id');
            $afterStmt->execute([':id' => (int) $placement['id']]);
            Audit::log('placements', (int) $placement['id'], 'restore', $placement, $afterStmt->fetch() ?: null);
        }
        Audit::log('people', $id, 'restore', $before, ['full_name' => $before['full_name'], 'event' => 'restore']);
        return true;
    }

    // ---------- Pencocokan calon duplikat ----------

    // Mengembalikan ['confidence' => exact|normalized|ambiguous|none, 'candidates' => [...]].
    //
    // Mengikuti aturan impor SK syuro yang sudah terbukti pada ~600 baris, dan aturan terpentingnya
    // dipertahankan di sini: TIDAK PERNAH menggabungkan otomatis. Fungsi ini hanya MENGUSULKAN;
    // keputusan "ini orang yang sama" selalu diambil manusia. Kasus 'ambiguous' (beberapa orang
    // bernama mirip) sengaja tidak memilih salah satu -- justru itu kasus yang paling mudah salah.
    //
    // $fullName boleh bergelar maupun tidak: pencocokan "tanpa gelar" memakai NAMA ASLI, sehingga
    // "Rahmat Hidayat" menemukan "Rahmat Hidayat, S.Pd." dan sebaliknya.
    public function matchCandidates($fullName, $nik = null, $excludeId = null) {
        $nik = Text::normalizeNik($nik);
        $ex = (int) $excludeId;

        // 1. NIK cocok = sinyal terkuat yang kita punya, dan unik di database.
        if ($nik !== null) {
            $stmt = $this->db->prepare("SELECT * FROM people WHERE nik = :n AND deleted_at IS NULL AND id <> :ex LIMIT 1");
            $stmt->execute([':n' => $nik, ':ex' => $ex]);
            if ($row = $stmt->fetch()) {
                return ['confidence' => 'exact', 'candidates' => [$row], 'matched_on' => 'nik'];
            }
        }

        // 2. Nama persis sama apa adanya, termasuk gelarnya.
        $full = Text::normalizeFull($fullName);
        $stmt = $this->db->prepare("SELECT * FROM people WHERE LOWER(full_name) = :full AND deleted_at IS NULL AND id <> :ex");
        $stmt->execute([':full' => $full, ':ex' => $ex]);
        $exact = $stmt->fetchAll();
        if (count($exact) === 1) return ['confidence' => 'exact', 'candidates' => $exact, 'matched_on' => 'nama_lengkap'];
        if (count($exact) > 1)  return ['confidence' => 'ambiguous', 'candidates' => $exact, 'matched_on' => 'nama_lengkap'];

        // 3. Nama asli tanpa gelar. Inilah yang menjembatani "Rahmat Hidayat" di data lama dengan
        //    "Rahmat Hidayat, S.Pd., M.Si." di SK baru.
        $base = Text::normalizeBase($fullName);
        if ($base === '') {
            return ['confidence' => 'none', 'candidates' => [], 'matched_on' => null];
        }
        $stmt = $this->db->prepare("SELECT * FROM people WHERE name_normalized = :base AND deleted_at IS NULL AND id <> :ex");
        $stmt->execute([':base' => $base, ':ex' => $ex]);
        $norm = $stmt->fetchAll();

        if (count($norm) === 1) return ['confidence' => 'normalized', 'candidates' => $norm, 'matched_on' => 'nama_tanpa_gelar'];
        if (count($norm) > 1)   return ['confidence' => 'ambiguous',  'candidates' => $norm, 'matched_on' => 'nama_tanpa_gelar'];

        return ['confidence' => 'none', 'candidates' => [], 'matched_on' => null];
    }

    // Pencocokan duplikat berlaku ke SELURUH basis data (itu intinya: satu orang, satu baris), tapi
    // yang DITAMPILKAN ke admin berlingkup tidak boleh membuka biodata orang di luar lingkupnya.
    // Calon di luar lingkup dikembalikan hanya berisi nama + penanda out_of_scope.
    public function redactOutOfScope(array $rows) {
        if (empty($rows) || AdminScope::isUnbounded()) return $rows;

        $ids = array_map('intval', array_column($rows, 'id'));
        $in = implode(',', $ids);
        $visible = $this->db->query("SELECT p.id FROM people p WHERE p.id IN ($in) AND " . self::scopeSql('p'))
                            ->fetchAll(PDO::FETCH_COLUMN);
        $visible = array_map('intval', $visible);

        foreach ($rows as &$r) {
            if (in_array((int) $r['id'], $visible, true)) {
                $r['out_of_scope'] = false;
                continue;
            }
            $r = [
                'id' => $r['id'], 'uuid' => $r['uuid'], 'title_prefix' => $r['title_prefix'], 'name' => $r['name'],
                'title_suffix' => $r['title_suffix'], 'full_name' => $r['full_name'], 'gender' => $r['gender'],
                'phone' => null, 'nik' => null, 'photo_path' => null, 'out_of_scope' => true,
            ];
        }
        unset($r);
        return $rows;
    }
}
