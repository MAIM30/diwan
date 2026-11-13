<?php
// PATH LOKASI FILE: app/Models/TermModel.php
// Acuan periode dipakai bersama; baris terms tetap menjadi susunan periode milik satu struktur.

require_once ROOT_DIR . '/app/Core/StructureLevels.php'; // urutan jenjang di daftar periode
require_once ROOT_DIR . '/app/Core/PeopleOverview.php';

class TermModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT t.*, gp.name AS shared_period_name, gp.start_date AS shared_start_date,
                   gp.end_date_planned AS shared_end_date_planned,
                   s.level, s.tier, s.wing, s.name AS structure_name, s.uuid AS structure_uuid
            FROM terms t JOIN structures s ON s.id = t.structure_id
            JOIN governance_periods gp ON gp.id = t.governance_period_id
            WHERE t.id = :id AND t.deleted_at IS NULL
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid($uuid) {
        if (!Uuid::isValid($uuid)) return null;
        $stmt = $this->db->prepare("
            SELECT t.*, gp.name AS shared_period_name, gp.start_date AS shared_start_date,
                   gp.end_date_planned AS shared_end_date_planned,
                   s.level, s.tier, s.wing, s.name AS structure_name, s.uuid AS structure_uuid
            FROM terms t JOIN structures s ON s.id = t.structure_id
            JOIN governance_periods gp ON gp.id = t.governance_period_id
            WHERE t.uuid = :u AND t.deleted_at IS NULL
        ");
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function byStructure($structureId) {
        $stmt = $this->db->prepare("
            SELECT * FROM terms
            WHERE structure_id = :sid AND deleted_at IS NULL
            ORDER BY start_date DESC
        ");
        $stmt->execute([':sid' => (int) $structureId]);
        return $stmt->fetchAll();
    }

    public function currentOfStructure($structureId) {
        $stmt = $this->db->prepare("
            SELECT * FROM terms
            WHERE structure_id = :sid AND is_current = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':sid' => (int) $structureId]);
        return $stmt->fetch() ?: null;
    }

    public function governancePeriodFindById($id) {
        $stmt = $this->db->prepare('SELECT * FROM governance_periods WHERE id = :id');
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function governancePeriodsInScope() {
        return $this->db->query("SELECT gp.*,
                    (SELECT COUNT(*) FROM terms t JOIN structures s ON s.id = t.structure_id
                     WHERE t.governance_period_id = gp.id AND t.deleted_at IS NULL AND " . AdminScope::sqlFilter('s.id') . ") AS structure_count,
                    (SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id = pl.term_id
                     WHERE t.governance_period_id = gp.id AND t.deleted_at IS NULL AND pl.deleted_at IS NULL
                       AND " . AdminScope::sqlFilter('pl.structure_id') . ") AS placement_count,
                    (SELECT COUNT(*) FROM terms t
                     WHERE t.governance_period_id = gp.id AND t.deleted_at IS NULL AND NOT (" . AdminScope::sqlFilter('t.structure_id') . ")) AS outside_count
                FROM governance_periods gp
                ORDER BY gp.start_date DESC, gp.name DESC, gp.id DESC")->fetchAll();
    }

    public function termsForGovernancePeriod($periodId) {
        $stmt = $this->db->prepare("SELECT t.*, s.level, s.tier, s.wing, s.name AS structure_name,
                    s.path AS structure_path,
                    (SELECT COUNT(*) FROM placements pl WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) AS placement_count
                FROM terms t JOIN structures s ON s.id = t.structure_id
                WHERE t.governance_period_id = :period AND t.deleted_at IS NULL
                  AND " . AdminScope::sqlFilter('t.structure_id') . "
                ORDER BY s.path");
        $stmt->execute([':period' => (int) $periodId]);
        return $stmt->fetchAll();
    }

    public function structuresNotUsingGovernancePeriod($periodId) {
        $stmt = $this->db->prepare("SELECT s.* FROM structures s
            WHERE s.deleted_at IS NULL AND s.is_active = 1
              AND " . AdminScope::sqlFilter('s.id') . "
              AND NOT EXISTS (SELECT 1 FROM terms t
                  WHERE t.structure_id = s.id AND t.governance_period_id = :period AND t.deleted_at IS NULL)
            ORDER BY s.path");
        $stmt->execute([':period' => (int) $periodId]);
        return $stmt->fetchAll();
    }

    public function createGovernancePeriod(array $data) {
        $stmt = $this->db->prepare("INSERT INTO governance_periods (uuid, name, start_date, end_date_planned)
            VALUES (:uuid, :name, :start, :planned)");
        $stmt->execute([
            ':uuid' => Uuid::v4(),
            ':name' => trim($data['name']),
            ':start' => $data['start_date'],
            ':planned' => $data['end_date_planned'] ?: null,
        ]);
        $id = (int) $this->db->lastInsertId();
        Audit::log('governance_periods', $id, 'create', null, $this->governancePeriodFindById($id));
        return $id;
    }

    // Boleh mengubah acuan ini? Acuan dipakai bersama: mengubah namanya atau tanggalnya mengubah SEMUA struktur yang memakainya. Jadi hanya admin
    // yang lingkupnya mencakup semua struktur pemakai (Super Admin selalu) -- bukan admin satu DPW terhadap acuan yang juga dipakai DPW lain.
    public function governancePeriodEditable($periodId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM terms t
            WHERE t.governance_period_id = :id AND t.deleted_at IS NULL AND NOT (" . AdminScope::sqlFilter('t.structure_id') . ")");
        $stmt->execute([':id' => (int) $periodId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    // Membetulkan nama atau tanggal sebuah acuan yang keliru. Setiap baris periode yang memakainya menyimpan SALINAN nama, tanggal mulai, dan
    // akhir rencananya (terms.name/start_date/end_date_planned), jadi salinan itu ikut disesuaikan dalam satu transaksi (updated_at-nya bergerak,
    // sehingga API mengirim ulang periodenya). Penempatan tidak disentuh. Ditolak bila: sudah ada acuan lain yang identik (nama + kedua tanggal),
    // sebuah struktur pemakai sudah punya periode lain bernama itu (nama periode unik per struktur), atau sebuah periode sudah berakhir (aktual)
    // sebelum tanggal mulai yang baru. Mengembalikan jumlah baris periode yang berubah.
    public function updateGovernancePeriod($id, array $data) {
        $id = (int) $id;
        $before = $this->governancePeriodFindById($id);
        if (!$before) throw new RuntimeException('Acuan periode tidak ditemukan.');

        $name = trim((string) ($data['name'] ?? ''));
        $start = (string) ($data['start_date'] ?? '');
        $end = (string) ($data['end_date_planned'] ?? '');
        $valid = function ($d) { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]); };
        if ($name === '' || mb_strlen($name) > 50) throw new RuntimeException('Nama periode wajib diisi (paling banyak 50 huruf).');
        if (!$valid($start)) throw new RuntimeException('Tanggal mulai wajib diisi dengan benar.');
        if ($end !== '' && !$valid($end)) throw new RuntimeException('Tanggal akhir rencana tidak sah.');
        if ($end !== '' && $end < $start) throw new RuntimeException('Tanggal akhir rencana tidak boleh lebih awal dari tanggal mulai.');
        if (!$this->governancePeriodEditable($id)) {
            throw new RuntimeException('Acuan ini juga dipakai struktur di luar lingkup Anda, sehingga hanya admin yang lingkupnya mencakup semua pemakainya (Super Admin) yang boleh mengubahnya. Untuk struktur Anda sendiri, ganti acuannya lewat Ubah Periode.');
        }
        $end = $end === '' ? null : $end;
        if ($name === $before['name'] && $start === $before['start_date'] && $end === $before['end_date_planned']) return 0;

        // Acuan identik (kunci unik identity_key = nama + mulai + akhir rencana): rumusnya sama dengan kolom turunannya.
        $dup = $this->db->prepare("SELECT id, name FROM governance_periods
            WHERE identity_key = SHA2(CONCAT(:n, '#', :s, '#', COALESCE(:e, '')), 256) AND id <> :id LIMIT 1");
        $dup->execute([':n' => $name, ':s' => $start, ':e' => $end, ':id' => $id]);
        if ($row = $dup->fetch()) {
            throw new RuntimeException('Sudah ada acuan dengan nama dan tanggal yang sama (' . $row['name'] . '). Pakai acuan itu: ganti acuan pada periode struktur lewat Ubah Periode, bukan mengubah acuan ini.');
        }

        // Nama periode unik per struktur (juga terhadap yang sudah dihapus, kuncinya tak membedakan).
        $clash = $this->db->prepare("SELECT s.level, s.name FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.governance_period_id = :id AND EXISTS (SELECT 1 FROM terms t2 WHERE t2.structure_id = t.structure_id AND t2.name = :n AND t2.governance_period_id <> :id2)
            ORDER BY s.path LIMIT 1");
        $clash->execute([':id' => $id, ':n' => $name, ':id2' => $id]);
        if ($row = $clash->fetch()) {
            throw new RuntimeException($row['level'] . ' ' . $row['name'] . ' sudah punya periode lain bernama "' . $name . '", jadi nama itu tak bisa dipakai acuan ini. Pilih nama lain, atau ganti acuan periode struktur itu.');
        }

        $early = $this->db->prepare("SELECT COUNT(*) FROM terms WHERE governance_period_id = :id AND deleted_at IS NULL AND end_date_actual IS NOT NULL AND end_date_actual < :s");
        $early->execute([':id' => $id, ':s' => $start]);
        if ((int) $early->fetchColumn() > 0) {
            throw new RuntimeException('Ada periode yang akhir aktualnya lebih awal dari tanggal mulai yang baru. Betulkan akhir aktual periode itu lebih dulu.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE governance_periods SET name = :n, start_date = :s, end_date_planned = :e WHERE id = :id")
                     ->execute([':n' => $name, ':s' => $start, ':e' => $end, ':id' => $id]);
            $upd = $this->db->prepare("UPDATE terms SET name = :n, start_date = :s, end_date_planned = :e WHERE governance_period_id = :id");
            $upd->execute([':n' => $name, ':s' => $start, ':e' => $end, ':id' => $id]);
            $changed = $upd->rowCount();
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
        Audit::log('governance_periods', $id, 'update', $before, $this->governancePeriodFindById($id) + ['terms_changed' => $changed]);
        return $changed;
    }

    public function attachGovernancePeriodToStructures($periodId, array $structureIds, $markCurrent = false) {
        $period = $this->governancePeriodFindById($periodId);
        if (!$period) throw new RuntimeException('Acuan Periode Kepengurusan tidak ditemukan.');
        $ids = array_values(array_unique(array_map('intval', $structureIds)));
        if (!$ids) throw new RuntimeException('Pilih sedikitnya satu struktur.');

        $this->db->beginTransaction();
        try {
            $inserted = [];
            foreach ($ids as $structureId) {
                if ($this->termForStructureAndPeriod($structureId, $periodId)) continue;
                if ($markCurrent) $this->clearCurrent($structureId);
                $stmt = $this->db->prepare("INSERT INTO terms
                    (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, status, is_current)
                    VALUES (:uuid, :period, :structure, :name, :start, :planned, :status, :current)");
                $stmt->execute([
                    ':uuid' => Uuid::v4(), ':period' => (int) $periodId, ':structure' => $structureId,
                    ':name' => $period['name'], ':start' => $period['start_date'],
                    ':planned' => $period['end_date_planned'], ':status' => $markCurrent ? 'active' : 'draft',
                    ':current' => $markCurrent ? 1 : 0,
                ]);
                $termId = (int) $this->db->lastInsertId();
                $inserted[] = $termId;
                Audit::log('terms', $termId, 'create', null, $this->findById($termId));
            }
            $this->db->commit();
            return count($inserted);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function termForStructureAndPeriod($structureId, $periodId) {
        $stmt = $this->db->prepare("SELECT t.*, s.level, s.tier, s.wing, s.name AS structure_name
            FROM terms t JOIN structures s ON s.id = t.structure_id
            WHERE t.structure_id = :structure AND t.governance_period_id = :period
              AND t.deleted_at IS NULL LIMIT 1");
        $stmt->execute([':structure' => (int) $structureId, ':period' => (int) $periodId]);
        return $stmt->fetch() ?: null;
    }

    // Seluruh periode dalam lingkup admin, dengan jumlah penempatannya -- kolom itu yang membuat
    // halaman daftar langsung berguna ("periode mana yang sudah terisi, mana yang masih kosong").
    public function allInScope() {
        return $this->db->query("
            SELECT t.*, s.level, s.wing, s.name AS structure_name,
                   s.path AS structure_path, s.tier AS structure_tier, s.parent_id AS structure_parent_id,
                   gp.id AS governance_period_id, gp.name AS shared_period_name,
                   (SELECT COUNT(*) FROM placements pl
                     WHERE pl.term_id = t.id AND pl.deleted_at IS NULL) AS placement_count
            FROM terms t
            JOIN structures s ON s.id = t.structure_id
            JOIN governance_periods gp ON gp.id = t.governance_period_id
            WHERE t.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id') . "
            ORDER BY s.path, t.start_date DESC
        ")->fetchAll();
    }

    public function create(array $data) {
        $structureId = (int) $data['structure_id'];
        $period = $this->governancePeriodFindById($data['governance_period_id'] ?? 0);
        if (!$period) throw new RuntimeException('Acuan Periode Kepengurusan tidak ditemukan.');
        $isCurrent = !empty($data['is_current']) ? 1 : 0;

        if ($this->termForStructureAndPeriod($structureId, $period['id'])) {
            throw new RuntimeException('Struktur ini sudah menggunakan Periode Kepengurusan tersebut.');
        }

        $this->db->beginTransaction();
        try {
            // Database sudah menjamin maksimal satu periode is_current per struktur lewat
            // uq_terms_one_current. Di sini kita MEMINDAHKAN penanda itu, bukan menabraknya:
            // periode lama diturunkan dulu, baru yang baru dinaikkan.
            if ($isCurrent) {
                $this->clearCurrent($structureId);
            }

            $stmt = $this->db->prepare("
                INSERT INTO terms (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, end_date_actual,
                                   status, is_current, sk_number, sk_date, notes)
                VALUES (:uuid, :period, :sid, :name, :start, :planned, :actual, :status, :current, :sk_no, :sk_date, :notes)
            ");
            $stmt->execute([
                ':uuid'    => Uuid::v4(),
                ':period'  => (int) $period['id'],
                ':sid'     => $structureId,
                ':name'    => $period['name'],
                ':start'   => $period['start_date'],
                ':planned' => $period['end_date_planned'],
                ':actual'  => $data['end_date_actual'] ?: null,
                ':status'  => $data['status'],
                ':current' => $isCurrent,
                ':sk_no'   => $data['sk_number'] !== '' ? $data['sk_number'] : null,
                ':sk_date' => $data['sk_date'] ?: null,
                ':notes'   => $data['notes'] !== '' ? $data['notes'] : null,
            ]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
            Audit::log('terms', $id, 'create', null, $this->findById($id));
            return $id;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Memasang / mengganti / mencabut berkas SK sebuah periode. Berkas lama dihapus dari disk HANYA bila tak
    // dipakai periode lain: satu SK bisa mengesahkan dua periode sekaligus (mis. DPP dan MWP pada SK yang sama).
    public function setSkFile($id, $rel) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;
        $old = $before['sk_file_path'];
        $this->db->prepare("UPDATE terms SET sk_file_path = :p WHERE id = :id")->execute([':p' => $rel, ':id' => $id]);
        Audit::log('terms', $id, 'update', $before, $this->findById($id));
        if ($old && $old !== $rel) {
            $used = $this->db->prepare("SELECT COUNT(*) FROM terms WHERE sk_file_path = :p AND id <> :id");
            $used->execute([':p' => $old, ':id' => $id]);
            if (!(int) $used->fetchColumn()) SkFile::remove($old);
        }
        return true;
    }

    // Cakupan jejak SK sebuah periode: berapa penempatannya yang sudah tertaut ke SK (placements.sk_reference).
    public function skCoverage($termId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(sk_reference IS NOT NULL AND sk_reference <> ''), 0) AS linked
            FROM placements WHERE term_id = :t AND deleted_at IS NULL
        ");
        $stmt->execute([':t' => (int) $termId]);
        $r = $stmt->fetch();
        return ['total' => (int) $r['total'], 'linked' => (int) $r['linked']];
    }

    public function update($id, array $data) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        $isCurrent = !empty($data['is_current']) ? 1 : 0;

        // Acuan diganti (periode ini keliru mengacu): nama, mulai, dan akhir rencana mengikuti acuan yang baru. Hanya bila governance_period_id
        // diberikan dan berbeda; formulir lama / pemanggil lain yang tak mengirimnya tak mengubah acuan.
        $newPeriod = null;
        $newPeriodId = (int) ($data['governance_period_id'] ?? 0);
        if ($newPeriodId > 0 && $newPeriodId !== (int) $before['governance_period_id']) {
            $newPeriod = $this->governancePeriodFindById($newPeriodId);
            if (!$newPeriod) throw new RuntimeException('Acuan Periode Kepengurusan tidak ditemukan.');
            $this->assertPeriodFree((int) $before['structure_id'], $newPeriod, $id);
        }

        $this->db->beginTransaction();
        try {
            if ($isCurrent) {
                $this->clearCurrent((int) $before['structure_id'], $id);
            }

            $stmt = $this->db->prepare("
                UPDATE terms SET end_date_actual = :actual, status = :status, is_current = :current,
                       sk_number = :sk_no, sk_date = :sk_date, notes = :notes"
                . ($newPeriod ? ", governance_period_id = :gp, name = :name, start_date = :start, end_date_planned = :planned" : "") . "
                WHERE id = :id
            ");
            $stmt->execute([
                ':actual'  => $data['end_date_actual'] ?: null,
                ':status'  => $data['status'],
                ':current' => $isCurrent,
                ':sk_no'   => $data['sk_number'] !== '' ? $data['sk_number'] : null,
                ':sk_date' => $data['sk_date'] ?: null,
                ':notes'   => $data['notes'] !== '' ? $data['notes'] : null,
                ':id'      => $id,
            ] + ($newPeriod ? [':gp' => (int) $newPeriod['id'], ':name' => $newPeriod['name'], ':start' => $newPeriod['start_date'], ':planned' => $newPeriod['end_date_planned']] : []));

            $this->db->commit();
            Audit::log('terms', $id, 'update', $before, $this->findById($id));
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Boleh memasang $period pada periode $exceptId milik struktur ini? Satu struktur hanya punya satu baris untuk tiap acuan, dan namanya unik per
    // struktur; kedua kuncinya juga menahan baris yang sudah dihapus, jadi yang itu dilaporkan terpisah (tak bisa dibereskan dari formulir).
    private function assertPeriodFree($structureId, array $period, $exceptId) {
        $stmt = $this->db->prepare("SELECT id, name, deleted_at FROM terms
            WHERE structure_id = :s AND id <> :id AND (governance_period_id = :gp OR name = :n) ORDER BY deleted_at IS NOT NULL LIMIT 1");
        $stmt->execute([':s' => (int) $structureId, ':id' => (int) $exceptId, ':gp' => (int) $period['id'], ':n' => $period['name']]);
        if (!($row = $stmt->fetch())) return;
        if ($row['deleted_at'] !== null) {
            throw new RuntimeException('Struktur ini pernah punya periode "' . $row['name'] . '" yang sudah dihapus dan masih menahan acuan/nama ' . $period['name'] . '. Pilih acuan lain.');
        }
        throw new RuntimeException('Struktur ini sudah memakai periode "' . $row['name'] . '" (acuan ' . $period['name'] . '). Satu struktur hanya punya satu periode untuk tiap acuan: ubah periode itu, atau hapus salah satunya bila memang ganda dan kosong.');
    }

    // Menandai satu periode sebagai yang berjalan (satu aksi, tanpa membuka formulir): tanda dilepas dari periode lain di struktur yang sama
    // dan statusnya menjadi Aktif bila masih Draf (berjalan = aktif, sama seperti attachGovernancePeriodToStructures). Data periode lain utuh.
    public function markCurrent($id) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;
        if ((int) $before['is_current'] === 1) return true;

        $this->db->beginTransaction();
        try {
            $this->clearCurrent((int) $before['structure_id'], $id);
            $this->db->prepare("UPDATE terms SET is_current = 1, status = IF(status = 'draft', 'active', status) WHERE id = :id")->execute([':id' => $id]);
            $this->db->commit();
            Audit::log('terms', $id, 'update', $before, $this->findById($id));
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Sudah ada periode berjalan di struktur ini?
    public function hasCurrent($structureId) {
        $stmt = $this->db->prepare("SELECT 1 FROM terms WHERE structure_id = :sid AND is_current = 1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':sid' => (int) $structureId]);
        return (bool) $stmt->fetchColumn();
    }

    private function clearCurrent($structureId, $exceptId = null) {
        $sql = "UPDATE terms SET is_current = 0 WHERE structure_id = :sid AND is_current = 1";
        $params = [':sid' => (int) $structureId];
        if ($exceptId !== null) {
            $sql .= " AND id <> :except";
            $params[':except'] = (int) $exceptId;
        }
        $this->db->prepare($sql)->execute($params);
    }

    public function softDelete($id) {
        $id = (int) $id;
        $before = $this->findById($id);
        if (!$before) return false;

        // Periode yang masih memegang penempatan tidak boleh dihapus: itu justru arsip yang
        // keberadaannya jadi alasan aplikasi ini dibuat.
        $used = $this->db->prepare("SELECT COUNT(*) FROM placements WHERE term_id = :id AND deleted_at IS NULL");
        $used->execute([':id' => $id]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Periode ini masih berisi penempatan pengurus dan tidak boleh dihapus.');
        }

        $this->db->prepare("UPDATE terms SET deleted_at = NOW(3), is_current = 0 WHERE id = :id")
                 ->execute([':id' => $id]);
        Audit::log('terms', $id, 'delete', $before, null);
        return true;
    }

    // Menyalin susunan Bidang + unit dari periode lain ke periode ini. Dibutuhkan karena
    // unit_groups/structure_units terikat periode: tanpa penyalinan ini, setiap periode baru harus
    // disusun ulang dari nol. Penempatan TIDAK ikut disalin -- orangnya memang berganti.
    public function cloneUnitsFrom($sourceTermId, $targetTermId) {
        $source = $this->findById($sourceTermId);
        $target = $this->findById($targetTermId);
        if (!$source || !$target) {
            throw new RuntimeException('Periode asal atau tujuan tidak ditemukan.');
        }
        if ((int) $source['structure_id'] !== (int) $target['structure_id']) {
            throw new RuntimeException('Penyalinan hanya bisa antar periode pada struktur yang sama.');
        }

        $this->db->beginTransaction();
        try {
            // 1. Salin Bidang, sambil mencatat pemetaan id lama -> id baru.
            $groups = $this->db->prepare("
                SELECT * FROM unit_groups WHERE term_id = :t AND deleted_at IS NULL ORDER BY sort_order, name
            ");
            $groups->execute([':t' => (int) $sourceTermId]);

            $map = [];
            $insG = $this->db->prepare("
                INSERT INTO unit_groups (uuid, structure_id, term_id, name, kind, sort_order)
                VALUES (:uuid, :sid, :tid, :name, :kind, :sort)
            ");
            foreach ($groups->fetchAll() as $g) {
                $insG->execute([
                    ':uuid' => Uuid::v4(),
                    ':sid'  => (int) $target['structure_id'],
                    ':tid'  => (int) $targetTermId,
                    ':name' => $g['name'],
                    ':kind' => $g['kind'],
                    ':sort' => $g['sort_order'],
                ]);
                $map[(int) $g['id']] = (int) $this->db->lastInsertId();
            }

            // 2. Salin unit, mengarahkan unit_group_id ke Bidang hasil salinan di langkah 1.
            $units = $this->db->prepare("
                SELECT * FROM structure_units WHERE term_id = :t AND deleted_at IS NULL ORDER BY sort_order
            ");
            $units->execute([':t' => (int) $sourceTermId]);

            $insU = $this->db->prepare("
                INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id, unit_group_id, display_name, sort_order)
                VALUES (:uuid, :sid, :tid, :cat, :grp, :disp, :sort)
            ");
            $count = 0;
            foreach ($units->fetchAll() as $u) {
                $insU->execute([
                    ':uuid' => Uuid::v4(),
                    ':sid'  => (int) $target['structure_id'],
                    ':tid'  => (int) $targetTermId,
                    ':cat'  => (int) $u['unit_catalog_id'],
                    ':grp'  => isset($map[(int) $u['unit_group_id']]) ? $map[(int) $u['unit_group_id']] : null,
                    ':disp' => $u['display_name'],
                    ':sort' => $u['sort_order'],
                ]);
                $count++;
            }

            $this->db->commit();
            Audit::log('terms', $targetTermId, 'update', null,
                ['cloned_from_term_id' => (int) $sourceTermId, 'groups' => count($map), 'units' => $count]);
            return ['groups' => count($map), 'units' => $count];
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ---------- Pemilih struktur (Tambah Jabatan) ----------

    const PICKER_LIMIT = 8;       // baris yang dikirim per pencarian
    const PICKER_MAX_LIMIT = 15;

    // Pencarian struktur + periode untuk pemilih di formulir jabatan. $o: q (kata dipisah spasi, semuanya harus cocok), lv (jenjang),
    // wing ('umum' | 'muslimah' | ''), gp (acuan periode, 0 = semua), limit. Hanya struktur dalam lingkup admin. Nama induk hanya ikut dicocokkan
    // dan ditampilkan bila induknya juga dalam lingkup (nama induk di luar lingkup tak boleh bocor lewat pencarian).
    // Hasil: items (satu baris per periode: periode berjalan lebih dulu, lalu urutan jenjang baku, induk, nama), levels (jenjang => jumlah cocok,
    // menurut pencarian + sayap + periode, TANPA saringan jenjang, urut baku), total (yang cocok semua saringan).
    public function pickerSearch(array $o) {
        $wing = in_array($o['wing'] ?? '', ['umum', 'muslimah'], true) ? $o['wing'] : '';
        $gp = (int) ($o['gp'] ?? 0);
        $lv = is_string($o['lv'] ?? '') ? (string) ($o['lv'] ?? '') : '';
        $q = is_string($o['q'] ?? '') ? trim((string) ($o['q'] ?? '')) : '';
        $limit = max(1, min(self::PICKER_MAX_LIMIT, (int) ($o['limit'] ?? self::PICKER_LIMIT)));

        $parentIn = AdminScope::sqlFilter('sp.id');
        $from = " FROM terms t
                  JOIN structures s ON s.id = t.structure_id AND s.deleted_at IS NULL
                  JOIN governance_periods gp ON gp.id = t.governance_period_id
                  LEFT JOIN structures sp ON sp.id = s.parent_id AND sp.deleted_at IS NULL
                  WHERE t.deleted_at IS NULL AND " . AdminScope::sqlFilter('t.structure_id');
        $where = ''; $params = [];
        if ($wing !== '') { $where .= ' AND s.wing = :wing'; $params[':wing'] = $wing; }
        if ($gp > 0)      { $where .= ' AND t.governance_period_id = :gp'; $params[':gp'] = $gp; }
        // Satu placeholder per kata (placeholder bernama tak boleh dipakai dua kali). % dan _ dari pengguna dilepas supaya dicari apa adanya.
        $parentText = "CASE WHEN $parentIn THEN CONCAT(sp.level, ' ', sp.name) END";
        $words = array_slice(preg_split('/\s+/u', mb_substr($q, 0, 100), -1, PREG_SPLIT_NO_EMPTY), 0, 4);
        foreach ($words as $i => $w) {
            $where .= " AND CONCAT_WS(' ', s.level, s.name, $parentText, s.region_code, gp.name) LIKE :w$i";
            $params[":w$i"] = '%' . addcslashes($w, '%_\\') . '%';
        }

        $count = $this->db->prepare("SELECT s.level, COUNT(*) AS c" . $from . $where . " GROUP BY s.level");
        $count->execute($params);
        $byLevel = [];
        foreach ($count->fetchAll() as $r) $byLevel[$r['level']] = (int) $r['c'];
        $levels = [];
        foreach (PeopleOverview::orderedLevels(array_keys($byLevel)) as $l) $levels[$l] = $byLevel[$l];

        $whereLv = $where; $paramsLv = $params;
        if ($lv !== '') { $whereLv .= ' AND s.level = :lv'; $paramsLv[':lv'] = $lv; }
        $total = $lv === '' ? array_sum($byLevel) : ($byLevel[$lv] ?? 0);

        $order = StructureLevels::orderSql('s.level');
        $stmt = $this->db->prepare("SELECT t.id, t.is_current, gp.name AS period, s.level, s.name AS structure_name, s.wing,
                       CASE WHEN $parentIn THEN sp.level END AS parent_level, CASE WHEN $parentIn THEN sp.name END AS parent_name,
                       CASE WHEN $parentIn THEN sp.tier END AS parent_tier" . $from . $whereLv . "
                ORDER BY t.is_current DESC, ($order = 0), $order, parent_name, s.name, t.start_date DESC, t.id
                LIMIT " . (int) $limit);
        $stmt->execute($paramsLv);

        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $parent = '';
            if ($r['parent_level'] !== null) {
                $parent = (int) $r['parent_tier'] <= 1 ? $r['parent_level'] : $r['parent_level'] . ' ' . PeopleOverview::shortName($r['parent_name']);
            }
            $items[] = [
                'id'      => (int) $r['id'],
                'level'   => $r['level'],
                'name'    => PeopleOverview::shortName($r['structure_name']),
                'parent'  => $parent,
                'period'  => $r['period'],
                'current' => (int) $r['is_current'] === 1,
                'wing'    => $r['wing'],
            ];
        }
        return ['items' => $items, 'levels' => $levels, 'total' => (int) $total];
    }

    // Ada struktur dengan periode di lingkup admin untuk sayap ini? (tombol Tambah Jabatan hanya tampil bila ada yang bisa dipilih.)
    public function hasTermsForWing($wing) {
        $stmt = $this->db->prepare("SELECT 1 FROM terms t JOIN structures s ON s.id = t.structure_id AND s.deleted_at IS NULL
                WHERE t.deleted_at IS NULL AND s.wing = :wing AND " . AdminScope::sqlFilter('t.structure_id') . " LIMIT 1");
        $stmt->execute([':wing' => $wing]);
        return (bool) $stmt->fetchColumn();
    }
}
