<?php
// PATH LOKASI FILE: app/Models/RegistrationModel.php
// Alur pendaftaran: Diajukan -> Diverifikasi -> Aktif (atau Ditolak).
//
// Dua hal yang dijaga di sini:
//  1. Pengajuan TIDAK PERNAH langsung menulis ke tabel people. Data master tetap bersih, dan API
//     tidak pernah menyajikan orang yang belum diverifikasi manusia.
//  2. Pencocokan duplikat otomatis hanya MENGUSULKAN. Verifikatorlah yang memutuskan apakah
//     pendaftar ini orang yang sudah ada atau orang baru -- persis aturan impor SK di syuro.

class RegistrationModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, s.level, s.name AS structure_name,
                   t.name AS term_name, po.name AS position_name, c.name AS unit_name,
                   m.full_name AS matched_name, m.uuid AS matched_uuid,
                   rev.display_name AS reviewer_name
            FROM registration_requests r
            JOIN structures s ON s.id = r.target_structure_id
            LEFT JOIN terms t ON t.id = r.target_term_id
            LEFT JOIN positions po ON po.id = r.target_position_id
            LEFT JOIN structure_units su ON su.id = r.target_unit_id
            LEFT JOIN unit_catalog c ON c.id = su.unit_catalog_id
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
                   m.full_name AS matched_name
            FROM registration_requests r
            JOIN structures s ON s.id = r.target_structure_id
            LEFT JOIN positions po ON po.id = r.target_position_id
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

        $match = $people->matchCandidates($fullName, $nik);
        $matchedId = count($match['candidates']) === 1 ? (int) $match['candidates'][0]['id'] : null;
        $selectedPersonId = (int) ($data['selected_person_id'] ?? 0);
        $extraData = !empty($data['notes']) ? ['catatan' => $data['notes']] : [];
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

        // Cegah pengiriman ulang pengajuan yang sama. Nomor WA yang sama tetap boleh dipakai
        // oleh orang berbeda; yang dibandingkan di sini adalah nomor DAN nama dasar.
        $samePhone = $this->db->prepare("SELECT full_name FROM registration_requests WHERE phone = :phone AND status IN ('submitted','verified','activated')");
        $samePhone->execute([':phone' => $phone]);
        foreach ($samePhone->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (Text::normalizeBase($existingName) === Text::normalizeBase($fullName)) {
                throw new RuntimeException('Pengajuan dengan nama dan nomor WhatsApp ini sudah pernah dikirim. Silakan hubungi admin struktur tujuan.');
            }
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

        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        require_once ROOT_DIR . '/app/Models/PlacementModel.php';
        $people = new PersonModel();
        $placements = new PlacementModel();
        $extra = !empty($reg['extra_data']) ? (json_decode($reg['extra_data'], true) ?: []) : [];

        $this->db->beginTransaction();
        try {
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
            } else {
                // Biodata tambahan yang dibawa pengajuan (mis. dari impor formulir biodata) ikut menjadi
                // data orang baru. Hanya kunci yang dikenal yang disalin; sisanya tetap di extra_data.
                $profil = is_array($extra['profil'] ?? null) ? $extra['profil'] : [];
                $carry = [];
                foreach (['birth_place', 'city', 'address_text', 'occupation', 'family_phone'] as $k) {
                    if (!empty($profil[$k])) $carry[$k] = $profil[$k];
                }
                $finalPersonId = $people->save(null, $carry + [
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

            $this->db->commit();
            Audit::log('registration_requests', $id, 'update', $reg, $this->findById($id));
            return ['person_id' => $finalPersonId, 'placement_id' => $placementId];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Calon duplikat untuk ditampilkan di halaman verifikasi, dihitung ulang saat ditinjau (data
    // bisa berubah sejak pengajuan masuk).
    public function matchesFor($reg) {
        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();
        return $people->matchCandidates($reg['full_name'], $reg['nik']);
    }
}
