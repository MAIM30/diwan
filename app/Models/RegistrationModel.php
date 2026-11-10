<?php
// PATH LOKASI FILE: app/Models/RegistrationModel.php
// Alur pendaftaran: Diajukan -> Diverifikasi -> Aktif (atau Ditolak).
//
// Dua hal yang dijaga di sini:
//  1. Selama verval AKTIF (bawaan), pengajuan TIDAK PERNAH langsung menulis ke tabel people. Data master tetap bersih, dan API
//     tidak pernah menyajikan orang yang belum diverifikasi manusia. Satu-satunya pengecualian disengaja dan bisa dimatikan: saklar verval
//     (vervalActive) yang dinonaktifkan admin DPP/MWP/Super Admin -- lalu pengajuan yang jelas diterima lewat acceptWithoutReview.
//  2. Pencocokan duplikat otomatis hanya MENGUSULKAN. Verifikatorlah yang memutuskan apakah
//     pendaftar ini orang yang sudah ada atau orang baru -- persis aturan impor SK di syuro.

class RegistrationModel {
    // Unit Organisasi yang dipilih pendaftar di formulir (extra_data.unit_catalog_id = unit_catalog.id), bukan structure_units: kebanyakan
    // struktur belum memasang unit apa pun, jadi yang bisa dipilih pendaftar adalah katalognya. Alias "uc" dipakai di dua query di bawah.
    const REQUESTED_UNIT_JOIN = "LEFT JOIN unit_catalog uc ON uc.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(r.extra_data, '\$.unit_catalog_id')) AS UNSIGNED)";

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, s.level, s.name AS structure_name,
                   t.name AS term_name, po.name AS position_name, c.name AS unit_name,
                   uc.id AS requested_unit_catalog_id, uc.name AS requested_unit_name,
                   m.full_name AS matched_name, m.uuid AS matched_uuid,
                   rev.display_name AS reviewer_name
            FROM registration_requests r
            JOIN structures s ON s.id = r.target_structure_id
            LEFT JOIN terms t ON t.id = r.target_term_id
            LEFT JOIN positions po ON po.id = r.target_position_id
            LEFT JOIN structure_units su ON su.id = r.target_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
            " . self::REQUESTED_UNIT_JOIN . "
            LEFT JOIN people m ON m.id = r.matched_person_id
            LEFT JOIN accounts rev ON rev.id = r.reviewed_by
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Daftar pengajuan dalam lingkup admin. Admin hanya melihat pendaftar yang struktur tujuannya
    // ada di bawah lingkupnya -- itulah arti "admin struktur terkait yang memverifikasi".
    public function listInScope($status = null) {
        $where = [AdminScope::sqlFilter('r.target_structure_id')];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = "r.status = :status";
            $params[':status'] = $status;
        }

        $stmt = $this->db->prepare("
            SELECT r.*, s.level, s.name AS structure_name, po.name AS position_name,
                   uc.name AS requested_unit_name, m.full_name AS matched_name
            FROM registration_requests r
            JOIN structures s ON s.id = r.target_structure_id
            LEFT JOIN positions po ON po.id = r.target_position_id
            " . self::REQUESTED_UNIT_JOIN . "
            LEFT JOIN people m ON m.id = r.matched_person_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(r.status,'submitted','verified','rejected','activated'), r.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countPending() {
        return (int) $this->db->query("
            SELECT COUNT(*) FROM registration_requests r
            WHERE r.status = 'submitted' AND " . AdminScope::sqlFilter('r.target_structure_id')
        )->fetchColumn();
    }

    // Untuk saran di formulir publik: hanya kembalikan identitas minimum dari orang tanpa nomor WA.
    public function peopleWithoutPhoneByName($query, $offset = 0) {
        $needle = Text::normalizeBase($query);
        if (mb_strlen($needle) < 3) return ['items' => [], 'has_more' => false];
        $words = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) return ['items' => [], 'has_more' => false];
        $conditions = [];
        $params = [':exact' => $needle, ':prefix' => $needle . '%'];
        foreach ($words as $i => $word) {
            $key = ':word' . $i;
            $conditions[] = "p.name_normalized LIKE $key";
            $params[$key] = '%' . addcslashes($word, '%_\\') . '%';
        }
        $stmt = $this->db->prepare("
            SELECT p.id, p.title_prefix, p.name, p.title_suffix, p.full_name, p.gender,
                   s.level AS origin_level, s.name AS origin_name
            FROM people p
            LEFT JOIN structures s ON s.id = p.home_structure_id
            WHERE p.deleted_at IS NULL AND (p.phone IS NULL OR p.phone = '')
              AND " . implode(' AND ', $conditions) . "
            ORDER BY (p.name_normalized = :exact) DESC,
                     (p.name_normalized LIKE :prefix) DESC, p.full_name
            LIMIT 51 OFFSET " . max(0, (int) $offset) . "
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        $hasMore = count($items) > 50;
        return ['items' => array_slice($items, 0, 50), 'has_more' => $hasMore];
    }

    // Menyimpan pengajuan dari formulir publik. Pencocokan duplikat dijalankan DI SINI supaya
    // verifikator sudah melihat calon duplikatnya sejak pengajuan masuk, bukan baru saat menyetujui.
    public function submit(array $data) {
        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();

        $nik = Text::normalizeNik($data['nik'] ?? null);
        $phone = Text::normalizePhone($data['phone']);
        if ($phone === null) {
            throw new RuntimeException('Nomor WhatsApp tidak valid.');
        }

        // Tiga bagian nama. Gelar yang terlanjur ditempel di kolom nama dipindahkan otomatis.
        [$pre, $name, $suf] = Text::normalizeNameParts($data['title_prefix'] ?? '', $data['name'] ?? '', $data['title_suffix'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Nama wajib diisi.');
        }
        $fullName = Text::joinName($pre, $name, $suf);

        $match = $people->matchCandidates($fullName, $nik, null, $phone);
        $matchedId = count($match['candidates']) === 1 ? (int) $match['candidates'][0]['id'] : null;
        $selectedPersonId = (int) ($data['selected_person_id'] ?? 0);
        $extraData = !empty($data['notes']) ? ['catatan' => $data['notes']] : [];

        // Unit Organisasi (opsional): membantu verifikator memeriksa jabatan pendaftar. Harus entri katalog yang aktif dan
        // sayapnya sama dengan struktur tujuan; pilihan yang tak cocok ditolak, bukan diam-diam dibuang.
        $unitCatalogId = (int) ($data['unit_catalog_id'] ?? 0);
        if ($unitCatalogId > 0) {
            require_once ROOT_DIR . '/app/Models/UnitModel.php';
            $units = new UnitModel();
            $unit = $units->catalogFindById($unitCatalogId);
            if (!$unit || !(int) $unit['is_active'] || $unit['wing'] !== $units->wingOfStructure($data['target_structure_id'])) {
                throw new RuntimeException('Unit Organisasi yang dipilih tidak sesuai dengan struktur tujuan. Pilih ulang dari daftar.');
            }
            $extraData['unit_catalog_id'] = $unitCatalogId;
        }

        if ($selectedPersonId > 0) {
            $selected = $this->db->prepare("SELECT id, name_normalized, gender, phone FROM people WHERE id = :id AND deleted_at IS NULL");
            $selected->execute([':id' => $selectedPersonId]);
            $person = $selected->fetch();
            if (!$person || !empty($person['phone'])
                || $person['name_normalized'] !== Text::normalizeBase($fullName)
                || $person['gender'] !== ($data['gender'] ?? '')) {
                throw new RuntimeException('Nama pilihan tidak cocok atau sudah memiliki nomor WA. Cari dan pilih kembali nama yang benar.');
            }
            $matchedId = $selectedPersonId;
            $match['confidence'] = 'exact';
            $extraData['registrant_selected_person_id'] = $selectedPersonId;
        }

        // Nomor WA menjadi kunci pencocokan pendaftaran. Jika sudah menjadi nomor orang di data
        // master, jangan buat pengajuan kedua untuk orang itu; perubahan penempatan ditangani admin.
        $existingPerson = $this->db->prepare("SELECT id FROM people WHERE phone = :phone AND deleted_at IS NULL LIMIT 1");
        $existingPerson->execute([':phone' => $phone]);
        if ($existingPerson->fetchColumn()) {
            throw new RuntimeException('Nomor WhatsApp ini sudah terdaftar. Anda tidak perlu mendaftar lagi; hubungi admin struktur jika ada data atau penempatan yang perlu diperbarui.');
        }

        // Cegah pengajuan kedua dengan nomor yang sama, meskipun nama yang diisi berbeda.
        $samePhone = $this->db->prepare("SELECT id FROM registration_requests WHERE phone = :phone AND status IN ('submitted','verified','activated') LIMIT 1");
        $samePhone->execute([':phone' => $phone]);
        if ($samePhone->fetchColumn()) {
            throw new RuntimeException('Nomor WhatsApp ini sudah pernah diajukan. Silakan hubungi admin struktur tujuan untuk memeriksa pengajuan Anda.');
        }

        // full_name adalah kolom turunan (gabungan tiga bagian), jadi tidak ikut di INSERT.
        $stmt = $this->db->prepare("
            INSERT INTO registration_requests
                (uuid, title_prefix, name, title_suffix, gender, phone, email, birth_date, nik, extra_data,
                 target_structure_id, target_term_id, target_unit_id, target_position_id,
                 status, matched_person_id, match_confidence, submitted_ip)
            VALUES (:uuid, :title_prefix, :name, :title_suffix, :gender, :phone, :email, :birth, :nik, :extra,
                    :structure, :term, :unit, :position,
                    'submitted', :matched, :confidence, :ip)
        ");
        $stmt->execute([
            ':uuid'         => Uuid::v4(),
            ':title_prefix' => $pre,
            ':name'         => $name,
            ':title_suffix' => $suf,
            ':gender'     => $data['gender'],
            ':phone'      => $phone,
            ':email'      => !empty($data['email']) ? trim($data['email']) : null,
            ':birth'      => !empty($data['birth_date']) ? $data['birth_date'] : null,
            ':nik'        => $nik,
            ':extra'      => $extraData ? json_encode($extraData, JSON_UNESCAPED_UNICODE) : null,
            ':structure'  => (int) $data['target_structure_id'],
            ':term'       => !empty($data['target_term_id']) ? (int) $data['target_term_id'] : null,
            ':unit'       => !empty($data['target_unit_id']) ? (int) $data['target_unit_id'] : null,
            ':position'   => !empty($data['target_position_id']) ? (int) $data['target_position_id'] : null,
            ':matched'    => $matchedId,
            ':confidence' => $match['confidence'],
            ':ip'         => Text::clientIp(),
        ]);

        $id = (int) $this->db->lastInsertId();
        Audit::log('registration_requests', $id, 'create', null, [
            'full_name' => $fullName, 'confidence' => $match['confidence'],
            // Dibutuhkan umpan aktivitas dasbor untuk membatasi lingkup baris audit ini.
            'target_structure_id' => (int) $data['target_structure_id'],
        ], null);
        return $id;
    }

    public function markVerified($id, $note) {
        $before = $this->findById($id);
        $stmt = $this->db->prepare("
            UPDATE registration_requests
            SET status = 'verified', reviewed_by = :by, reviewed_at = NOW(3), review_note = :note
            WHERE id = :id AND status = 'submitted'
        ");
        $stmt->execute([':by' => Auth::id(), ':note' => $note ?: null, ':id' => (int) $id]);
        Audit::log('registration_requests', $id, 'update', $before, $this->findById($id));
        return $stmt->rowCount() > 0;
    }

    public function reject($id, $note) {
        $before = $this->findById($id);
        $stmt = $this->db->prepare("
            UPDATE registration_requests
            SET status = 'rejected', reviewed_by = :by, reviewed_at = NOW(3), review_note = :note
            WHERE id = :id AND status IN ('submitted','verified')
        ");
        $stmt->execute([':by' => Auth::id(), ':note' => $note ?: null, ':id' => (int) $id]);
        Audit::log('registration_requests', $id, 'update', $before, $this->findById($id));
        return $stmt->rowCount() > 0;
    }

    // Menghapus pengajuan yang berulang/ganda. Baris dihapus sungguhan, tetapi isinya tersimpan di
    // audit_log (before_data) supaya masih bisa dilacak siapa yang menghapus dan apa isinya.
    // Pengajuan Aktif tidak boleh dihapus: ia satu-satunya catatan dari mana orang dan penempatan itu berasal.
    public function delete($id) {
        $reg = $this->findById($id);
        if (!$reg) return false;
        if ($reg['status'] === 'activated') {
            throw new RuntimeException('Pengajuan yang sudah Aktif tidak bisa dihapus, karena menjadi catatan asal data orang dan penempatannya.');
        }

        // Status diperiksa lagi di sini: bisa saja admin lain mengaktifkannya sejak halaman dimuat.
        $stmt = $this->db->prepare("DELETE FROM registration_requests WHERE id = :id AND status <> 'activated'");
        $stmt->execute([':id' => (int) $id]);
        if ($stmt->rowCount() === 0) return false;

        Audit::log('registration_requests', $id, 'delete', $reg, null);
        return true;
    }

    // Aktivasi: inilah satu-satunya tempat pengajuan berubah menjadi data master.
    //
    // $personId: diisi verifikator bila pendaftar ini TERNYATA orang yang sudah ada (hasil
    // peninjauan calon duplikat); null bila memang orang baru. Keputusan itu tidak pernah
    // disimpulkan sendiri oleh kode.
    //
    // Status "Aktif" di sini adalah SYARAT, bukan jaminan dapat akun di aplikasi lain: tidak ada
    // baris accounts yang dibuat di sini, dan tidak ada apa pun yang dikirim ke syuro.
    // $gender: koreksi jenis kelamin oleh verifikator ('L'/'P'), dipakai hanya untuk ORANG BARU. Pengajuan
    // yang jenis kelaminnya diduga dari nama (extra_data.gender_dugaan) wajib dikonfirmasi di sini.
    public function activate($id, $personId, array $placementData, $gender = null) {
        $reg = $this->findById($id);
        if (!$reg) {
            throw new RuntimeException('Pengajuan tidak ditemukan.');
        }
        if ($reg['status'] !== 'verified') {
            throw new RuntimeException('Pengajuan harus diverifikasi lebih dulu sebelum diaktifkan.');
        }

        require_once ROOT_DIR . '/app/Models/PlacementModel.php';
        $placements = new PlacementModel();
        $extra = !empty($reg['extra_data']) ? (json_decode($reg['extra_data'], true) ?: []) : [];

        $own = $this->txBegin();
        try {
            $finalPersonId = $this->resolvePerson($reg, $extra, $personId, $gender);

            $placementId = $placements->create([
                'person_id'         => $finalPersonId,
                'term_id'           => $placementData['term_id'],
                'structure_id'      => $placementData['structure_id'],
                'structure_unit_id' => $placementData['structure_unit_id'] ?? null,
                'position_id'       => $placementData['position_id'],
                'is_primary'        => $placementData['is_primary'] ?? 0,
                'source'            => 'registrasi',
                'notes'             => 'Dari pendaftaran #' . $reg['id'],
            ]);

            $this->db->prepare("
                UPDATE registration_requests
                SET status = 'activated', resulting_person_id = :pid, resulting_placement_id = :plid
                WHERE id = :id
            ")->execute([':pid' => $finalPersonId, ':plid' => $placementId, ':id' => (int) $id]);

            if ($own) $this->db->commit();
            Audit::log('registration_requests', $id, 'update', $reg, $this->findById($id));
            return ['person_id' => $finalPersonId, 'placement_id' => $placementId];
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // Memulai transaksi bila belum ada yang berjalan; true = transaksi ini milik pemanggil (dia yang commit/rollback). Bila pemanggilnya sendiri
    // sudah berada di dalam transaksi (mis. uji yang di-rollback), ia ikut di dalamnya.
    private function txBegin() {
        if ($this->db->inTransaction()) return false;
        $this->db->beginTransaction();
        return true;
    }

    // Orang hasil sebuah pengajuan: orang yang SUDAH ada ($personId, tautannya) atau orang baru. Dipanggil DI DALAM transaksi pemanggil,
    // oleh aktivasi manual (activate) maupun penerimaan otomatis (acceptWithoutReview), supaya keduanya memakai aturan yang sama.
    private function resolvePerson(array $reg, array $extra, $personId, $gender) {
        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();

        // Data bisa berubah setelah pengajuan dikirim. Kunci kecocokan nomor WA diperiksa
        // kembali saat aktivasi agar jeda tinjauan tidak menghasilkan orang duplikat.
        $phoneOwners = $this->db->prepare("SELECT id FROM people WHERE phone = :phone AND deleted_at IS NULL FOR UPDATE");
        $phoneOwners->execute([':phone' => $reg['phone']]);
        $phoneOwnerIds = array_map('intval', $phoneOwners->fetchAll(PDO::FETCH_COLUMN));
        foreach ($phoneOwnerIds as $phoneOwnerId) {
            if (!$personId || $phoneOwnerId !== (int) $personId) {
                throw new RuntimeException('Nomor WhatsApp ini sudah dipakai orang lain. Periksa kecocokan data sebelum mengaktifkan.');
            }
        }

        if ($personId) {
            // Dipakaikan ke orang yang sudah ada. Biodata yang sudah tercatat TIDAK ditimpa
            // oleh isian formulir -- data master biasanya lebih lengkap dan sudah terverifikasi.
            $finalPersonId = (int) $personId;
            if ((int) ($extra['registrant_selected_person_id'] ?? 0) === $finalPersonId) {
                // Pada tautan yang dipilih pendaftar, nomor WA melengkapi kolom kosong saja.
                // Bila data berubah sejak pengajuan, jangan menimpa nomor yang sudah diperbarui.
                $fillPhone = $this->db->prepare("UPDATE people SET phone = :phone WHERE id = :id AND deleted_at IS NULL AND (phone IS NULL OR phone = '')");
                $fillPhone->execute([':phone' => $reg['phone'], ':id' => $finalPersonId]);
                if ($fillPhone->rowCount() === 0) {
                    $checkPhone = $this->db->prepare("SELECT phone FROM people WHERE id = :id AND deleted_at IS NULL");
                    $checkPhone->execute([':id' => $finalPersonId]);
                    if ($checkPhone->fetchColumn() !== $reg['phone']) {
                        throw new RuntimeException('Nomor WA orang yang dipilih sudah berubah sejak pengajuan. Periksa data orang sebelum mengaktifkan.');
                    }
                }
            }
            return $finalPersonId;
        }

        // Biodata tambahan yang dibawa pengajuan (mis. dari impor formulir biodata) ikut menjadi
        // data orang baru. Hanya kunci yang dikenal yang disalin; sisanya tetap di extra_data.
        $profil = is_array($extra['profil'] ?? null) ? $extra['profil'] : [];
        $carry = [];
        foreach (['birth_place', 'city', 'address_text', 'occupation', 'family_phone'] as $k) {
            if (!empty($profil[$k])) $carry[$k] = $profil[$k];
        }
        return $people->save(null, $carry + [
            'title_prefix' => $reg['title_prefix'],
            'name'         => $reg['name'],
            'title_suffix' => $reg['title_suffix'],
            // Struktur tujuan pendaftaran = struktur asal orang ini, supaya admin struktur
            // itu langsung melihatnya (orang baru belum punya penempatan).
            'home_structure_id' => $reg['target_structure_id'],
            'gender'     => in_array($gender, ['L', 'P'], true) ? $gender : $reg['gender'],
            'phone'      => $reg['phone'],
            'email'      => $reg['email'],
            'birth_date' => $reg['birth_date'],
            'nik'        => $reg['nik'],
            'status'     => 'active',
        ]);
    }

    // ---------- Saklar verval (verifikasi dan validasi) pengajuan ----------
    //
    // Aktif (bawaan, juga bila pengaturannya belum pernah ditulis atau isinya tak dikenal): pengajuan menunggu verifikator, seperti di atas.
    // Nonaktif: pengajuan BARU yang jelas langsung diterima (acceptWithoutReview); pengajuan yang sudah menunggu tidak disentuh.
    const SETTING_VERVAL = 'verval_pengajuan';

    public function vervalActive() {
        require_once ROOT_DIR . '/app/Models/SettingModel.php';
        return (new SettingModel())->get(self::SETTING_VERVAL, '1') !== '0';
    }

    // Menyimpan saklar; mengembalikan nilai SEBELUMNYA (true = aktif) untuk jejak audit.
    public function setVerval($active, $accountId = null) {
        require_once ROOT_DIR . '/app/Models/SettingModel.php';
        $before = $this->vervalActive();
        (new SettingModel())->set(self::SETTING_VERVAL, $active ? '1' : '0', $accountId);
        return $before;
    }

    // Alasan sebuah pengajuan TIDAK boleh diterima otomatis (null = boleh). Menerima tanpa pemeriksaan hanya aman untuk kasus yang jelas;
    // sisanya tetap menunggu verifikator, seperti saat verval aktif:
    //  * nama mirip orang yang sudah ada, kecuali pendaftar sendiri menautkannya (itu pun sudah diperiksa saat dikirim: nama, jenis
    //    kelamin, dan belum bernomor WA). Sistem tak pernah memutuskan sendiri "orang baru atau orang yang sudah ada" -- akibatnya data ganda;
    //  * jenis kelamin tak sesuai sayap struktur tujuan (laki-laki di MWP, perempuan di DPP): form penempatan menolaknya juga.
    private function autoAcceptBlock(array $reg, array $extra) {
        $linked = (int) ($extra['registrant_selected_person_id'] ?? 0) > 0;
        if (!$linked && ($reg['match_confidence'] ?? 'none') !== 'none') {
            return 'Nama mirip dengan data pengurus yang sudah ada; verifikator yang memutuskan orang baru atau bukan.';
        }
        $wing = $this->db->prepare("SELECT wing FROM structures WHERE id = :id AND deleted_at IS NULL");
        $wing->execute([':id' => (int) $reg['target_structure_id']]);
        if ($wing->fetchColumn() !== ($reg['gender'] === 'P' ? 'muslimah' : 'umum')) {
            return 'Jenis kelamin tidak sesuai dengan sayap struktur tujuan.';
        }
        return null;
    }

    // Menerima pengajuan tanpa tinjauan (hanya dipanggil bila verval nonaktif): orangnya dibuat atau ditautkan lewat aturan aktivasi yang sama,
    // status langsung "Aktif", TANPA penempatan -- formulir pendaftaran tak memilih jabatan, jadi penempatannya diatur admin kemudian
    // (Data Pengurus > "Belum punya penempatan"). Tak pernah melempar: yang tak memenuhi syarat atau gagal tetap berstatus Diajukan dan
    // menunggu verifikator, jadi pengajuannya tak hilang. Mengembalikan ['accepted' => bool, 'reason' => alasan bila tidak, 'person_id'].
    public function acceptWithoutReview($id) {
        $reg = $this->findById($id);
        if (!$reg || $reg['status'] !== 'submitted') return ['accepted' => false, 'reason' => 'Pengajuan tidak berstatus Diajukan.'];

        $extra = !empty($reg['extra_data']) ? (json_decode($reg['extra_data'], true) ?: []) : [];
        $reason = $this->autoAcceptBlock($reg, $extra);
        if ($reason !== null) return ['accepted' => false, 'reason' => $reason];

        $linked = (int) ($extra['registrant_selected_person_id'] ?? 0);
        $own = $this->txBegin();
        try {
            $personId = $this->resolvePerson($reg, $extra, $linked > 0 ? $linked : null, null);
            $done = $this->db->prepare("
                UPDATE registration_requests
                SET status = 'activated', resulting_person_id = :pid, reviewed_at = NOW(3),
                    review_note = 'Diterima otomatis: verval pengajuan sedang dinonaktifkan.'
                WHERE id = :id AND status = 'submitted'
            ");
            $done->execute([':pid' => $personId, ':id' => (int) $id]);
            if ($done->rowCount() !== 1) throw new RuntimeException('Status pengajuan berubah saat diproses.');
            if ($own) $this->db->commit();
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            error_log('Terima otomatis pengajuan #' . (int) $id . ' gagal: ' . $e->getMessage());
            return ['accepted' => false, 'reason' => $e->getMessage()];
        }
        Audit::log('registration_requests', $id, 'update', $reg, $this->findById($id));
        return ['accepted' => true, 'person_id' => $personId];
    }

    // Calon duplikat untuk ditampilkan di halaman verifikasi, dihitung ulang saat ditinjau (data
    // bisa berubah sejak pengajuan masuk).
    public function matchesFor($reg) {
        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();
        return $people->matchCandidates($reg['full_name'], $reg['nik'], null, $reg['phone']);
    }
}
