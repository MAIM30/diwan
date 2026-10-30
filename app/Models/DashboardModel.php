<?php
// PATH LOKASI FILE: app/Models/DashboardModel.php
// Data untuk dasbor dan halaman profil. SEMUA query dibatasi lingkup admin yang sedang login --
// angka di dasbor harus cocok dengan apa yang benar-benar bisa ia buka, bukan total organisasi.
//
// Definisi "pengurus aktif" dipakai konsisten di seluruh berkas ini: penempatan yang tidak
// terhapus, di periode yang sedang berjalan (terms.is_current = 1), dan belum berakhir
// (end_date kosong atau belum lewat).

require_once ROOT_DIR . '/app/Models/PersonModel.php'; // PersonModel::scopeSql() dipakai query daftar orang

class DashboardModel {
    const CURRENT = "pl.deleted_at IS NULL AND t.deleted_at IS NULL AND t.is_current = 1
                     AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())";

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function sc($col = 's.id') {
        return AdminScope::sqlFilter($col);
    }

    // ---------- Kartu ringkasan ----------

    public function stats() {
        $cur = self::CURRENT;
        $sc = $this->sc('s.id');
        $scr = $this->sc('r.target_structure_id');

        return $this->db->query("
            SELECT
              (SELECT COUNT(DISTINCT pl.person_id) FROM placements pl
                 JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
                WHERE $cur AND $sc) AS pengurus_aktif,
              (SELECT COUNT(*) FROM placements pl
                 JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
                WHERE $cur AND $sc AND pl.created_at >= (NOW() - INTERVAL 30 DAY)) AS penempatan_baru_30,
              (SELECT COUNT(*) FROM structures s
                WHERE s.deleted_at IS NULL AND s.is_active = 1 AND $sc) AS struktur,
              (SELECT COUNT(*) FROM structures s
                WHERE s.deleted_at IS NULL AND s.is_active = 1 AND $sc
                  AND NOT EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.is_current = 1 AND t.deleted_at IS NULL)) AS tanpa_periode,
              (SELECT COUNT(*) FROM terms t JOIN structures s ON s.id = t.structure_id
                WHERE t.deleted_at IS NULL AND t.is_current = 1 AND $sc) AS periode_berjalan,
              (SELECT COUNT(*) FROM registration_requests r
                WHERE r.status = 'submitted' AND $scr) AS pending,
              (SELECT COUNT(*) FROM registration_requests r
                WHERE r.status = 'submitted' AND $scr AND r.created_at >= (NOW() - INTERVAL 7 DAY)) AS pending_baru_7
        ")->fetch();
    }

    // ---------- Grafik ----------

    // Pengurus aktif per tingkat (1=Pusat .. 5=Ranting). Orang yang menjabat di lebih dari satu
    // tingkat dihitung di tiap tingkatnya, jadi jumlah irisan bisa melebihi jumlah orang unik.
    public function byTier() {
        return $this->db->query("
            SELECT s.tier, COUNT(DISTINCT pl.person_id) AS c
            FROM placements pl
            JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
            WHERE " . self::CURRENT . " AND " . $this->sc('s.id') . "
            GROUP BY s.tier ORDER BY s.tier
        ")->fetchAll();
    }

    public function byGender() {
        return $this->db->query("
            SELECT p.gender, COUNT(DISTINCT p.id) AS c
            FROM people p
            JOIN placements pl ON pl.person_id = p.id
            JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
            WHERE p.deleted_at IS NULL AND " . self::CURRENT . " AND " . $this->sc('s.id') . "
            GROUP BY p.gender
        ")->fetchAll();
    }

    public function topStructures($limit = 8) {
        $stmt = $this->db->prepare("
            SELECT s.id, s.level, s.name, COUNT(DISTINCT pl.person_id) AS c
            FROM placements pl
            JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
            WHERE " . self::CURRENT . " AND " . $this->sc('s.id') . "
            GROUP BY s.id ORDER BY c DESC, s.name LIMIT :lim
        ");
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Penempatan yang dicatat per bulan selama 12 bulan terakhir. Bulan tanpa catatan diisi 0 supaya
    // sumbu waktunya utuh -- garis yang melompati bulan kosong akan menyesatkan.
    // Catatan baca: impor massal SK menaruh ratusan baris di satu bulan sehingga bulan itu menonjol;
    // grafik ini mengukur aktivitas pencatatan, bukan jumlah pengurus.
    public function monthlyPlacements() {
        $rows = $this->db->query("
            SELECT DATE_FORMAT(pl.created_at, '%Y-%m') AS ym, COUNT(*) AS c
            FROM placements pl JOIN structures s ON s.id = pl.structure_id
            WHERE pl.deleted_at IS NULL AND " . $this->sc('s.id') . "
              AND pl.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
            GROUP BY ym
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $points = [];
        $d = new DateTime('first day of this month');
        $d->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $ym = $d->format('Y-m');
            $points[] = [
                'label' => Ui::monthShort($d->format('n')),
                'title' => Ui::monthShort($d->format('n')) . ' ' . $d->format('Y'),
                'value' => (int) ($rows[$ym] ?? 0),
            ];
            $d->modify('+1 month');
        }
        return $points;
    }

    // ---------- Daftar ----------

    public function recentPeople($limit = 6) {
        $inScope = $this->sc('pl.structure_id');
        $latest = "pl.person_id = p.id AND pl.deleted_at IS NULL AND $inScope
                   ORDER BY t.is_current DESC, t.start_date DESC, po.sort_order LIMIT 1";

        $stmt = $this->db->prepare("
            SELECT p.id, p.uuid, p.title_prefix, p.name, p.title_suffix, p.full_name, p.gender, p.photo_path,
                   p.status, p.created_at,
                   (SELECT po.name FROM placements pl JOIN terms t ON t.id = pl.term_id
                      JOIN positions po ON po.id = pl.position_id WHERE $latest) AS position_name,
                   (SELECT CONCAT(s.level, ' ', s.name) FROM placements pl JOIN terms t ON t.id = pl.term_id
                      JOIN positions po ON po.id = pl.position_id JOIN structures s ON s.id = pl.structure_id WHERE $latest) AS structure_label
            FROM people p
            WHERE p.deleted_at IS NULL AND " . PersonModel::scopeSql('p') . "
            ORDER BY p.created_at DESC, p.id DESC LIMIT :lim
        ");
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Periode berjalan yang akan/sudah melewati tanggal akhir rencananya. days_left negatif berarti
    // sudah lewat tetapi serah terima belum dicatat -- kasus "molor menunggu musyawarah" yang memang
    // lazim, dan justru yang perlu dilihat admin.
    public function termEndings($limit = 6) {
        $stmt = $this->db->prepare("
            SELECT t.id, t.name, t.end_date_planned, s.level, s.name AS structure_name,
                   DATEDIFF(t.end_date_planned, CURDATE()) AS days_left,
                   (SELECT COUNT(*) FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) AS jumlah
            FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.deleted_at IS NULL AND t.is_current = 1
              AND t.end_date_planned IS NOT NULL AND t.end_date_actual IS NULL
              AND t.end_date_planned <= (CURDATE() + INTERVAL 365 DAY)
              AND " . $this->sc('s.id') . "
            ORDER BY t.end_date_planned, s.path LIMIT :lim
        ");
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function pendingRegistrations($limit = 4) {
        $stmt = $this->db->prepare("
            SELECT r.id, r.title_prefix, r.name, r.title_suffix, r.full_name, r.gender, r.created_at,
                   r.match_confidence, s.level, s.name AS structure_name
            FROM registration_requests r JOIN structures s ON s.id = r.target_structure_id
            WHERE r.status = 'submitted' AND " . $this->sc('r.target_structure_id') . "
            ORDER BY r.created_at DESC LIMIT :lim
        ");
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Kelengkapan data pengurus aktif. Ini pekerjaan nyata admin di aplikasi yang menjadi sumber
    // data tunggal: nomor WA yang kosong berarti pengurus tak bisa dihubungi.
    public function quality() {
        $cur = self::CURRENT;
        $sc = $this->sc('s.id');
        $q = $this->db->query("
            SELECT COUNT(DISTINCT p.id) AS total,
                   COUNT(DISTINCT CASE WHEN p.phone IS NULL OR p.phone = '' THEN p.id END) AS tanpa_hp,
                   COUNT(DISTINCT CASE WHEN p.nik IS NULL THEN p.id END) AS tanpa_nik
            FROM people p
            JOIN placements pl ON pl.person_id = p.id
            JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
            WHERE p.deleted_at IS NULL AND $cur AND $sc
        ")->fetch();

        $q['unit_tanpa_bidang'] = (int) $this->db->query("
            SELECT COUNT(*) FROM structure_units su
            JOIN terms t ON t.id = su.term_id JOIN structures s ON s.id = su.structure_id
            WHERE su.deleted_at IS NULL AND t.deleted_at IS NULL AND t.is_current = 1
              AND su.unit_group_id IS NULL AND $sc
        ")->fetchColumn();
        return $q;
    }

    // ---------- Aktivitas ----------

    // Umpan aktivitas terbaru. Pembatas lingkup memakai audit_log.structure_id; baris yang
    // struktur asalnya tidak diketahui (NULL) hanya terlihat oleh admin tanpa batas lingkup --
    // gagal tertutup. Peristiwa teknis (login, lookup NIK, rotasi token) tidak ditampilkan.
    private function activityScope() {
        return AdminScope::isUnbounded()
            ? '1=1'
            : '(a.structure_id IS NOT NULL AND ' . AdminScope::sqlFilter('a.structure_id') . ')';
    }

    private const NOISE = " AND a.event IS NULL";
    private const NOISE_PERSON = " AND (a.event IS NULL OR a.event = 'portal_edit')";

    public function activity($limit = 8) {
        $stmt = $this->db->prepare("
            SELECT a.id, a.entity, a.entity_id, a.action, a.before_data, a.after_data, a.created_at, acc.display_name AS actor
            FROM audit_log a LEFT JOIN accounts acc ON acc.id = a.account_id
            WHERE a.entity IN ('placements', 'people', 'terms', 'structures', 'registration_requests')
              AND " . $this->activityScope() . self::NOISE . "
            ORDER BY a.id DESC LIMIT :lim
        ");
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'describe'], $stmt->fetchAll());
    }

    // Aktivitas yang menyangkut satu orang (perubahan datanya + penempatannya).
    public function activityForPerson($personId, $limit = 10) {
        $scope = AdminScope::isUnbounded() ? '1=1' : $this->activityScope();
        $stmt = $this->db->prepare("
            SELECT a.id, a.entity, a.entity_id, a.action, a.before_data, a.after_data, a.created_at, acc.display_name AS actor
            FROM audit_log a LEFT JOIN accounts acc ON acc.id = a.account_id
            WHERE ((a.entity = 'people' AND a.entity_id = :pid_a)
                OR (a.entity = 'placements' AND a.entity_id IN (SELECT id FROM placements WHERE person_id = :pid_b)))
              AND (a.entity = 'people' OR $scope)" . self::NOISE_PERSON . "
            ORDER BY a.id DESC LIMIT :lim
        ");
        $stmt->bindValue(':pid_a', (int) $personId, PDO::PARAM_INT);
        $stmt->bindValue(':pid_b', (int) $personId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'describe'], $stmt->fetchAll());
    }

    // Mengubah satu baris audit menjadi kalimat untuk manusia. Seluruh teks di sini polos --
    // View yang meng-escape-nya.
    public function describe(array $a) {
        $after = $a['after_data'] ? (json_decode($a['after_data'], true) ?: []) : [];
        $before = $a['before_data'] ? (json_decode($a['before_data'], true) ?: []) : [];
        $d = $after ?: $before;
        $key = $a['entity'] . ':' . $a['action'];

        $icon = 'activity'; $tone = 'teal'; $title = 'Perubahan data'; $sub = '';
        $struct = trim(($d['level'] ?? '') . ' ' . ($d['structure_name'] ?? ''));

        switch ($key) {
            case 'placements:create':
                $icon = 'briefcase'; $tone = 'teal';
                $title = ($d['full_name'] ?? 'Seseorang') . ' ditempatkan sebagai ' . ($d['position_name'] ?? 'pengurus');
                $sub = trim($struct . ' · ' . ($d['term_name'] ?? ''), ' ·');
                break;
            case 'placements:update':
                $icon = 'briefcase'; $tone = 'slate';
                $ended = !empty($after['end_date']) && empty($before['end_date']);
                $title = 'Penempatan ' . ($d['full_name'] ?? '') . ($ended ? ' diakhiri' : ' diperbarui');
                $sub = trim($struct . ' · ' . ($d['term_name'] ?? ''), ' ·');
                break;
            case 'placements:delete':
                $icon = 'trash'; $tone = 'rose';
                $title = 'Penempatan ' . ($d['full_name'] ?? '') . ' dihapus';
                $sub = trim($struct . ' · ' . ($d['term_name'] ?? ''), ' ·');
                break;
            case 'people:create':
                $icon = 'user-plus'; $tone = 'green';
                $title = ($d['full_name'] ?? 'Orang baru') . ' ditambahkan';
                break;
            case 'people:update':
                $icon = 'edit'; $tone = 'slate';
                $title = 'Data ' . ($d['full_name'] ?? 'pengurus') . ' diperbarui';
                break;
            case 'people:delete':
                $icon = 'trash'; $tone = 'rose';
                $title = ($d['full_name'] ?? 'Data pengurus') . ' dihapus';
                break;
            case 'terms:create':
                $icon = 'calendar-check'; $tone = 'amber';
                $title = 'Periode ' . ($d['name'] ?? '') . ' dibuat';
                $sub = $struct;
                break;
            case 'terms:update':
                $icon = 'calendar-check'; $tone = 'slate';
                $title = 'Periode ' . ($d['name'] ?? '') . ' diperbarui';
                $sub = $struct;
                break;
            case 'terms:delete':
                $icon = 'trash'; $tone = 'rose';
                $title = 'Periode ' . ($d['name'] ?? '') . ' dihapus';
                $sub = $struct;
                break;
            case 'structures:create':
                $icon = 'network'; $tone = 'teal';
                $title = trim(($d['level'] ?? '') . ' ' . ($d['name'] ?? '')) . ' ditambahkan';
                break;
            case 'structures:update':
                $icon = 'network'; $tone = 'slate';
                $title = trim(($d['level'] ?? '') . ' ' . ($d['name'] ?? '')) . ' diperbarui';
                break;
            case 'structures:delete':
                $icon = 'trash'; $tone = 'rose';
                $title = trim(($d['level'] ?? '') . ' ' . ($d['name'] ?? '')) . ' dihapus';
                break;
            case 'registration_requests:create':
                $icon = 'inbox'; $tone = 'rose';
                $title = ($d['full_name'] ?? 'Pendaftar') . ' mendaftar';
                $sub = 'Menunggu verifikasi';
                break;
            case 'registration_requests:update':
                $icon = 'clipboard-check'; $tone = 'green';
                $verb = ['verified' => 'diverifikasi', 'rejected' => 'ditolak', 'activated' => 'diaktifkan'][$after['status'] ?? ''] ?? 'diperbarui';
                $title = 'Pendaftaran ' . ($d['full_name'] ?? '') . ' ' . $verb;
                $sub = $struct;
                break;
            case 'registration_requests:delete':
                $icon = 'trash'; $tone = 'rose';
                $title = 'Pendaftaran ' . ($d['full_name'] ?? '') . ' dihapus';
                $sub = $struct;
                break;
        }

        if (($after['event'] ?? '') === 'portal_edit') {
            $fields = array_intersect_key(Ui::PROFILE_LABELS, $after);
            $icon = 'user'; $tone = 'green';
            $title = 'Diubah sendiri oleh pengurus: ' . ($fields ? implode(', ', array_map('mb_strtolower', $fields)) : 'data diri');
            $sub = 'melalui Portal Pengurus';
        } elseif ($a['actor']) {
            $sub = trim($sub . ($sub !== '' ? ' · ' : '') . 'oleh ' . $a['actor']);
        }
        return ['icon' => $icon, 'tone' => $tone, 'title' => $title, 'sub' => $sub, 'time' => $a['created_at']];
    }
}
