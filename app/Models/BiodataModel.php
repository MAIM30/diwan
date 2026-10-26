<?php
// PATH LOKASI FILE: app/Models/BiodataModel.php
// Formulir "Biodata / Profil Singkat Pengurus" yang diisi pengurus sendiri. Satu baris per orang
// (upsert di person_id) -- lihat migrasi 010 dan app/Core/BiodataPdf.php.

class BiodataModel {
    private $db;

    // col => panjang maksimal. Semua teks bebas, tanpa validasi bentuk khusus.
    const TEXT_FIELDS = [
        'jabatan_text'          => 150,
        'bidang_text'           => 150,
        'pengalaman_organisasi' => 2000,
        'kompetensi'            => 1000,
        'karya_kontribusi'      => 2000,
        'signed_place'          => 100,
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findByPersonId($personId) {
        $stmt = $this->db->prepare("SELECT * FROM biodata_submissions WHERE person_id = :id");
        $stmt->execute([':id' => (int) $personId]);
        $row = $stmt->fetch();
        if ($row) $row['commitments'] = json_decode($row['commitments'], true) ?: [];
        return $row ?: null;
    }

    // ID berkas foto biodata di Drive (atau null) -- dipakai avatar portal sebagai cadangan foto profil.
    public function photoFileId($personId) {
        $stmt = $this->db->prepare("SELECT photo_gdrive_file_id FROM biodata_submissions WHERE person_id = :id");
        $stmt->execute([':id' => (int) $personId]);
        $id = $stmt->fetchColumn();
        return ($id !== false && $id !== '') ? $id : null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("SELECT * FROM biodata_submissions WHERE uuid = :u");
        $stmt->execute([':u' => $uuid]);
        $row = $stmt->fetch();
        if ($row) $row['commitments'] = json_decode($row['commitments'], true) ?: [];
        return $row ?: null;
    }

    // Validasi $post terhadap TEXT_FIELDS + signed_date + commitments[]. Mengembalikan
    // ['data' => [...bersih...], 'errors' => [kolom => pesan]] -- SEMUA-ATAU-TIDAK-SAMA-SEKALI,
    // pola yang sama dengan MemberModel::updateOwnProfile().
    public function validate(array $post) {
        $errors = []; $data = [];

        foreach (self::TEXT_FIELDS as $col => $max) {
            $v = trim((string) ($post[$col] ?? ''));
            $v = preg_replace('/\r\n?/', "\n", $v);   // normalisasi ganti baris, konsisten lintas peramban
            if (mb_strlen($v) > $max) {
                $errors[$col] = 'Terlalu panjang (maksimal ' . $max . ' karakter).';
                continue;
            }
            $data[$col] = $v !== '' ? $v : null;
        }

        $date = trim((string) ($post['signed_date'] ?? ''));
        if ($date !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                $errors['signed_date'] = 'Tanggal tidak valid.';
            } else {
                $data['signed_date'] = $date;
            }
        } else {
            $data['signed_date'] = date('Y-m-d');
        }

        $raw = is_array($post['commitments'] ?? null) ? $post['commitments'] : [];
        $commitments = array_values(array_unique(array_filter(array_map('intval', $raw), function ($n) {
            return $n >= 1 && $n <= count(BiodataPdf::COMMITMENTS);
        })));
        sort($commitments);
        $data['commitments'] = $commitments;

        return ['data' => $data, 'errors' => $errors];
    }

    // Simpan/perbarui baris orang ini. Mengembalikan baris lengkap (dengan uuid) setelah disimpan.
    public function upsert($personId, array $data) {
        $existing = $this->findByPersonId($personId);
        $uuid = $existing['uuid'] ?? Uuid::v4();

        $stmt = $this->db->prepare("
            INSERT INTO biodata_submissions
                (uuid, person_id, jabatan_text, bidang_text, pengalaman_organisasi, kompetensi,
                 karya_kontribusi, commitments, signed_place, signed_date)
            VALUES (:uuid, :pid, :jabatan, :bidang, :pengalaman, :kompetensi, :karya, :commitments, :tempat, :tanggal)
            ON DUPLICATE KEY UPDATE
                jabatan_text = VALUES(jabatan_text), bidang_text = VALUES(bidang_text),
                pengalaman_organisasi = VALUES(pengalaman_organisasi), kompetensi = VALUES(kompetensi),
                karya_kontribusi = VALUES(karya_kontribusi), commitments = VALUES(commitments),
                signed_place = VALUES(signed_place), signed_date = VALUES(signed_date)
        ");
        $stmt->execute([
            ':uuid'        => $uuid,
            ':pid'         => (int) $personId,
            ':jabatan'     => $data['jabatan_text'] ?? null,
            ':bidang'      => $data['bidang_text'] ?? null,
            ':pengalaman'  => $data['pengalaman_organisasi'] ?? null,
            ':kompetensi'  => $data['kompetensi'] ?? null,
            ':karya'       => $data['karya_kontribusi'] ?? null,
            ':commitments' => json_encode($data['commitments'] ?? [], JSON_UNESCAPED_UNICODE),
            ':tempat'      => $data['signed_place'] ?? null,
            ':tanggal'     => $data['signed_date'] ?? date('Y-m-d'),
        ]);

        return $this->findByPersonId($personId);
    }

    public function markDriveUpload($id, $fileId, $fileUrl) {
        $this->db->prepare("
            UPDATE biodata_submissions
            SET gdrive_file_id = :fid, gdrive_file_url = :furl, gdrive_uploaded_at = NOW(3)
            WHERE id = :id
        ")->execute([':fid' => $fileId, ':furl' => $fileUrl, ':id' => (int) $id]);
    }

    public function setPhotoFile($id, $fileId) {
        $this->db->prepare("UPDATE biodata_submissions SET photo_gdrive_file_id = :fid WHERE id = :id")
                 ->execute([':fid' => $fileId, ':id' => (int) $id]);
    }

    // Daftar untuk admin, dibatasi lingkup lewat struktur PENEMPATAN AKTIF orang itu (biodata_submissions
    // sendiri tidak punya structure_id -- satu orang bisa menjabat di beberapa struktur).
    public function listInScope() {
        $inScope = "EXISTS (SELECT 1 FROM placements pls WHERE pls.person_id = p.id AND pls.deleted_at IS NULL
                             AND " . AdminScope::sqlFilter('pls.structure_id') . ")";
        $stmt = $this->db->query("
            SELECT b.*, p.uuid AS person_uuid, p.full_name, p.photo_path,
                   (SELECT CONCAT(s.level, ' ', s.name) FROM placements pl
                      JOIN terms t ON t.id = pl.term_id JOIN structures s ON s.id = pl.structure_id
                      WHERE pl.person_id = p.id AND pl.deleted_at IS NULL
                      ORDER BY t.is_current DESC, t.start_date DESC LIMIT 1) AS structure_label
            FROM biodata_submissions b
            JOIN people p ON p.id = b.person_id AND p.deleted_at IS NULL
            WHERE $inScope
            ORDER BY b.updated_at DESC
        ");
        return $stmt->fetchAll();
    }
}
