<?php
// PATH LOKASI FILE: app/Models/MemberModel.php
// Data untuk Portal Pengurus. PRINSIP: semua fungsi di sini bekerja dari id pengurus yang diambil
// dari SESI, tidak pernah dari URL atau formulir -- jadi tidak ada id yang bisa diganti untuk melihat
// data orang lain (IDOR) di portal ini.
//
// Karena kredensial portal hanya NOMOR WA (atau NIK) -- keduanya lemah, bukan rahasia -- yang dibuka ke
// pemiliknya dibatasi pada yang perlu: NIK dan nomor WA tampil utuh (miliknya sendiri), HP keluarga dan email
// tersamar, dan rekan se-unit hanya muncul nama + jabatannya, tanpa foto, telepon, NIK, maupun biodata lain.

require_once ROOT_DIR . '/app/Models/PlacementModel.php';

class MemberModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Syarat BOLEH masuk. Satu-satunya tempat syarat ini didefinisikan: dipakai saat masuk DAN
    // pada setiap halaman sesudahnya, supaya pencabutan akses oleh admin berlaku seketika.
    const ELIGIBLE = "p.deleted_at IS NULL AND p.status = 'active' AND p.portal_enabled = 1
                      AND EXISTS (SELECT 1 FROM placements plx WHERE plx.person_id = p.id AND plx.deleted_at IS NULL)";

    // Pencarian lewat nik_guard (kolom turunan ber-indeks unik), bukan nik: nik biasa tidak
    // terindeks, jadi pencarian lewat nik memindai seluruh tabel pada setiap percobaan masuk --
    // dan percobaan masuk adalah titik yang paling mungkin diserbu.
    public function findEligibleByNik($nik) {
        $nik = Text::normalizeNik($nik);
        if ($nik === null) return null;
        $stmt = $this->db->prepare("SELECT p.* FROM people p WHERE p.nik_guard = :nik AND " . self::ELIGIBLE . " LIMIT 1");
        $stmt->execute([':nik' => $nik]);
        return $stmt->fetch() ?: null;
    }

    // Masuk dengan nomor WA. Nomor itu HARUS dipakai tepat SATU orang (yang belum dihapus): `people.phone`
    // sengaja tidak unik (nomor suami kadang tercatat pada data pengurus muslimah), dan portal tak punya
    // faktor kedua untuk memilih di antara dua pemilik. Nomor bersama = tak bisa dipakai masuk -- bukan
    // "masuk sebagai yang pertama". Yang dihitung SEMUA orang ber-nomor itu, tidak hanya yang layak, supaya
    // hasilnya tidak berubah-ubah mengikuti status orang lain (mis. satu orang dicabut aksesnya).
    public function findEligibleByPhone($phone) {
        $phone = Text::normalizePhone($phone);
        if ($phone === null) return null;
        $stmt = $this->db->prepare("SELECT p.id FROM people p WHERE p.phone = :phone AND p.deleted_at IS NULL LIMIT 2");
        $stmt->execute([':phone' => $phone]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) return null;
        return $this->findEligibleById((int) $ids[0]);
    }

    // Untuk admin: berapa orang lain (belum dihapus) yang memakai nomor yang sama dengan orang ini.
    public function phoneSharedWith($personId, $phone) {
        if (empty($phone)) return 0;
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM people WHERE phone = :phone AND deleted_at IS NULL AND id <> :id");
        $stmt->execute([':phone' => $phone, ':id' => (int) $personId]);
        return (int) $stmt->fetchColumn();
    }

    public function findEligibleById($id) {
        $stmt = $this->db->prepare("SELECT p.* FROM people p WHERE p.id = :id AND " . self::ELIGIBLE . " LIMIT 1");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Riwayat penempatan milik sendiri, SEMUA struktur (tanpa pembatasan lingkup admin: ini data
    // pengurus itu sendiri).
    public function history($personId) {
        return (new PlacementModel())->historyOfPerson($personId);
    }

    // Yang sedang berjalan: periode berjalan dan belum berakhir.
    public function currentOf(array $history) {
        $today = date('Y-m-d');
        return array_values(array_filter($history, function ($h) use ($today) {
            return !empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= $today);
        }));
    }

    // Rentang waktu efektif sebuah penempatan: [mulai, selesai]. Mulai = tanggal mulai menjabat bila
    // dicatat, kalau tidak awal periodenya. Selesai = akhir penempatan bila ada (mundur/mutasi),
    // kalau tidak akhir periode (aktual, lalu rencana). Selesai bisa null (periode tanpa tanggal akhir).
    public static function range(array $h) {
        $start = !empty($h['start_date']) ? $h['start_date'] : ($h['term_start'] ?? null);
        $end = !empty($h['end_date']) ? $h['end_date']
             : (!empty($h['end_date_actual']) ? $h['end_date_actual'] : ($h['end_date_planned'] ?? null));
        return [$start ?: null, $end ?: null];
    }

    // Progres masa jabatan untuk jabatan yang sedang berjalan. null bila tanggalnya tak lengkap.
    // late = true bila tanggal akhir rencana sudah lewat tetapi periodenya masih berjalan (molor
    // menunggu musyawarah) -- kasus lazim, ditampilkan apa adanya dengan label, bukan disembunyikan.
    public static function life(array $h, $today = null) {
        [$start, $end] = self::range($h);
        if (!$start || !$end) return null;
        $s = strtotime($start); $e = strtotime($end); $t = strtotime($today ?: date('Y-m-d'));
        if ($e <= $s) return null;
        $left = (int) round(($e - $t) / 86400);
        return [
            'start'     => $start,
            'end'       => $end,
            'pct'       => (int) max(0, min(100, round(($t - $s) / ($e - $s) * 100))),
            'days_left' => $left,
            'late'      => $left < 0,
        ];
    }

    // Rantai struktur dari akar sampai struktur ini, mis. DPP > DPW > DPD.
    public function structureChain($structureId) {
        $stmt = $this->db->prepare("SELECT path FROM structures WHERE id = :id");
        $stmt->execute([':id' => (int) $structureId]);
        $path = (string) $stmt->fetchColumn();
        $ids = array_values(array_filter(array_map('intval', explode('/', $path))));
        if (empty($ids)) return [];

        $in = implode(',', $ids);
        return $this->db->query("SELECT id, level, name, wing FROM structures WHERE id IN ($in) AND deleted_at IS NULL ORDER BY depth")->fetchAll();
    }

    // Rekan yang saat ini menjabat di tempat yang SAMA (struktur + periode + unit; unit kosong =
    // pucuk pimpinan struktur). Hanya nama + jabatan. Foto sengaja tidak ikut -- dan tidak ada
    // kolom kontak atau identitas sama sekali di SELECT ini.
    public function colleagues(array $placement, $meId, $limit = 14) {
        $where = "pl.term_id = :term AND pl.deleted_at IS NULL AND pl.person_id <> :me
                  AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())";
        $params = [':term' => (int) $placement['term_id'], ':me' => (int) $meId];

        if (!empty($placement['structure_unit_id'])) {
            $where .= " AND pl.structure_unit_id = :unit";
            $params[':unit'] = (int) $placement['structure_unit_id'];
        } else {
            $where .= " AND pl.structure_unit_id IS NULL";
        }

        $stmt = $this->db->prepare("
            SELECT p.id, p.title_prefix, p.name, p.title_suffix, p.full_name, p.uuid,
                   po.name AS position_name, " . PositionModel::CORE_SQL . " AS is_core
            FROM placements pl
            JOIN people p ON p.id = pl.person_id AND p.deleted_at IS NULL
            JOIN positions po ON po.id = pl.position_id
            JOIN structures s ON s.id = pl.structure_id
            WHERE $where
            ORDER BY po.sort_order, p.name
            LIMIT :lim
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ---------- Riwayat masuk ----------

    // Dicatat di audit_log dengan penanda event=portal_login. Penanda itu juga yang membuatnya
    // tersaring dari umpan aktivitas admin (DashboardModel::NOISE): masuknya seorang pengurus
    // bukan "perubahan data".
    public function recordLogin($personId) {
        Audit::log('people', $personId, 'update', null, ['event' => 'portal_login']);
    }

    public function loginHistory($personId, $limit = 6) {
        $stmt = $this->db->prepare("
            SELECT created_at, ip FROM audit_log
            WHERE entity = 'people' AND entity_id = :id AND event = 'portal_login'
            ORDER BY id DESC LIMIT :lim
        ");
        $stmt->bindValue(':id', (int) $personId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Untuk admin: kapan orang ini terakhir memakai portal (null = belum pernah).
    public function lastLogin($personId) {
        $rows = $this->loginHistory($personId, 1);
        return $rows ? $rows[0]['created_at'] : null;
    }

    // ---------- Ubah data sendiri ----------
    //
    // DAFTAR PUTIH kolom yang boleh diubah pengurus dari portal. Yang TIDAK ada di sini tak bisa disentuh,
    // betapapun kiriman formulirnya (jenis kelamin, status, saklar portal, id orang, ...). Nama dan gelar
    // punya aturan sendiri (NAME_FIELDS, diproses bersama sebagai satu kesatuan), bukan bagian dari daftar ini.
    // Khusus NOMOR WA dan NIK -- kredensial masuk -- sengaja tidak ada: sesi portal hanya bermodal nomor WA yang
    // bukan rahasia, jadi bila nomor bisa diganti dari dalam, siapa pun yang tahu nomor seseorang bisa
    // memindahkannya ke nomornya sendiri dan mengunci pemilik aslinya. Perubahan keduanya lewat admin.
    //   [jenis, panjang maks, tulis-saja]. Tulis-saja = nilainya tak pernah ditampilkan ulang di formulir (sama
    //   seperti halaman Data Saya yang menyembunyikannya): kosong berarti "jangan ubah", bukan "hapus".
    const SELF_FIELDS = [
        'address_text' => ['text', 255, true],
        'village'      => ['text', 100, false],
        'district'     => ['text', 100, false],
        'city'         => ['text', 100, false],
        'province'     => ['text', 100, false],
        'birth_place'  => ['text', 100, false],
        'birth_date'   => ['date', 10, true],
        'email'        => ['email', 150, true],
        'occupation'   => ['text', 150, false],
        'family_phone' => ['phone', 20, true],
    ];
    // Nama: gelar depan, nama asli, gelar belakang -> panjang maksimal kolomnya di people.
    const NAME_FIELDS = ['title_prefix' => 60, 'name' => 200, 'title_suffix' => 120];
    const SELF_EDITS_PER_HOUR = 10;

    private static function cleanText($s) {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public function selfEditCountSince($personId, $minutes = 60) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_log
            WHERE entity = 'people' AND entity_id = :id AND event = 'portal_edit'
              AND created_at > (NOW(3) - INTERVAL :m MINUTE)
        ");
        $stmt->bindValue(':id', (int) $personId, PDO::PARAM_INT);
        $stmt->bindValue(':m', (int) $minutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // Perubahan terakhir oleh pemiliknya sendiri: waktu + NAMA bidangnya saja (nilainya tak ditampilkan).
    public function recentSelfEdits($personId, $limit = 3) {
        $stmt = $this->db->prepare("
            SELECT created_at, after_data FROM audit_log
            WHERE entity = 'people' AND entity_id = :id AND event = 'portal_edit'
            ORDER BY id DESC LIMIT :lim
        ");
        $stmt->bindValue(':id', (int) $personId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $after = json_decode($r['after_data'], true) ?: [];
            $out[] = ['at' => $r['created_at'], 'fields' => array_values(array_intersect_key(Ui::PROFILE_LABELS, $after))];
        }
        return $out;
    }

    // Memvalidasi dan menerapkan perubahan dari $post terhadap $person. SEMUA ATAU TIDAK SAMA SEKALI: satu galat
    // validasi membatalkan seluruhnya. Hanya kunci di SELF_FIELDS yang dibaca. Mengembalikan
    // ['changed' => [kolom, ...], 'errors' => [kolom => pesan]].
    public function updateOwnProfile(array $person, array $post) {
        $errors = []; $set = [];
        foreach (self::SELF_FIELDS as $col => [$type, $max, $writeOnly]) {
            if (!array_key_exists($col, $post)) continue;                     // tak dikirim = tak disentuh
            $label = Ui::PROFILE_LABELS[$col];
            if (!is_string($post[$col])) { $errors[$col] = "$label tidak valid."; continue; }   // kiriman bentuk larik
            $v = self::cleanText($post[$col]);

            if ($v === '') {
                if ($writeOnly) continue;                                     // tulis-saja: kosong = jangan ubah
                $new = null;                                                  // bidang kasar: kosong = hapus
            } else {
                if (mb_strlen($v) > $max) { $errors[$col] = "$label terlalu panjang (maksimal $max karakter)."; continue; }
                $new = $v;
                if ($type === 'date') {
                    $d = DateTime::createFromFormat('!Y-m-d', $v);
                    $ok = $d && $d->format('Y-m-d') === $v && (int) $d->format('Y') >= 1930 && (int) $d->format('Y') <= (int) date('Y') - 10;
                    if (!$ok) { $errors[$col] = "$label tidak masuk akal (format tahun-bulan-tanggal, antara 1930 dan " . ((int) date('Y') - 10) . ')'; continue; }
                } elseif ($type === 'email') {
                    $new = mb_strtolower($v);
                    if (!filter_var($new, FILTER_VALIDATE_EMAIL)) { $errors[$col] = 'Email tidak valid.'; continue; }
                } elseif ($type === 'phone') {
                    $new = Text::normalizePhone($v);
                    if ($new === null) { $errors[$col] = 'Nomor HP keluarga tidak valid.'; continue; }
                    if ($new === $person['phone']) { $errors[$col] = 'Nomor HP keluarga harus berbeda dari nomor WA Anda sendiri.'; continue; }
                }
            }
            if ((string) $new !== (string) ($person[$col] ?? '')) $set[$col] = $new;
        }

        // Nama hanya diproses bila ketiga isiannya dikirim (formulir selalu mengirim semuanya).
        if (isset($post['title_prefix'], $post['name'], $post['title_suffix'])) {
            foreach (self::NAME_FIELDS as $col => $max) {
                if (!is_string($post[$col])) $errors[$col] = Ui::PROFILE_LABELS[$col] . ' tidak valid.';
            }
            if (!array_intersect_key(self::NAME_FIELDS, $errors)) {
                // Gelar yang terlanjur diketik di kolom nama dipindahkan ke kolom gelarnya, sama seperti di formulir admin.
                [$pre, $nm, $suf] = Text::normalizeNameParts(
                    self::cleanText($post['title_prefix']), self::cleanText($post['name']), self::cleanText($post['title_suffix'])
                );
                if ($nm === '') $errors['name'] = 'Nama asli wajib diisi.';
                foreach (['title_prefix' => $pre, 'name' => $nm, 'title_suffix' => $suf] as $col => $new) {
                    if ($new !== null && mb_strlen($new) > self::NAME_FIELDS[$col]) {
                        $errors[$col] = Ui::PROFILE_LABELS[$col] . ' terlalu panjang (maksimal ' . self::NAME_FIELDS[$col] . ' karakter).';
                    } elseif ((string) $new !== (string) ($person[$col] ?? '')) {
                        $set[$col] = $new;
                    }
                }
            }
        }
        if ($errors || !$set) return ['changed' => [], 'errors' => $errors];

        $cols = []; $vals = []; $before = [];
        foreach ($set as $c => $v) { $cols[] = "$c = ?"; $vals[] = $v; $before[$c] = $person[$c] ?? null; }
        // name_normalized (dasar pencarian dan deteksi data ganda) harus ikut dihitung ulang dari nama asli yang baru.
        if (isset($set['name'])) { $cols[] = 'name_normalized = ?'; $vals[] = Text::normalizeBase($set['name']); }
        $vals[] = (int) $person['id'];
        $this->db->prepare('UPDATE people SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($vals);
        // home_structure_id ikut dicatat supaya umpan aktivitas admin wilayah bisa menyaring menurut lingkupnya.
        Audit::log('people', $person['id'], 'update', $before, $set + ['event' => 'portal_edit', 'home_structure_id' => $person['home_structure_id']]);
        return ['changed' => array_keys($set), 'errors' => []];
    }

    // Bidang yang masih kosong, sebagai daftar nama bidang -- untuk "Kelengkapan Data Saya".
    public function missingFields(array $person) {
        $labels = ['phone' => 'Nomor WhatsApp', 'nik' => 'NIK', 'email' => 'Email', 'birth_date' => 'Tanggal lahir',
                   'address_text' => 'Alamat', 'photo_path' => 'Foto profil'];
        $out = [];
        foreach ($labels as $col => $label) {
            if (empty($person[$col])) $out[] = $label;
        }
        return $out;
    }
}
