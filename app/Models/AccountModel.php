<?php
// PATH LOKASI FILE: app/Models/AccountModel.php
// Akun pengelola data + peran/lingkupnya. Sumbu yang terpisah dari jabatan struktural (placements).

class AccountModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findByPhone($phone) {
        $phone = Text::normalizePhone($phone);
        if ($phone === null) return null;
        $stmt = $this->db->prepare("SELECT * FROM accounts WHERE phone = :p LIMIT 1");
        $stmt->execute([':p' => $phone]);
        return $stmt->fetch() ?: null;
    }

    // Nama pengguna berbentuk email (mis. admin.dpp@pengurus.wahdah.or.id). Kolom accounts.email ber-
    // collation tak peka huruf besar/kecil, jadi pencocokannya pun tak peka.
    public function findByEmail($email) {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $stmt = $this->db->prepare("SELECT * FROM accounts WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT a.*, p.full_name AS person_name
            FROM accounts a LEFT JOIN people p ON p.id = a.person_id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    public function rolesOf($accountId) {
        $stmt = $this->db->prepare("
            SELECT ar.*, s.level, s.name AS structure_name, s.parent_id
            FROM account_roles ar
            LEFT JOIN structures s ON s.id = ar.scope_structure_id
            WHERE ar.account_id = :id
            ORDER BY ar.role
        ");
        $stmt->execute([':id' => (int) $accountId]);
        return $stmt->fetchAll();
    }

    // Daftar akun yang boleh dikelola admin yang sedang login. Admin non-super hanya melihat akun
    // yang SELURUH lingkupnya berada di dalam lingkupnya sendiri -- supaya admin DPW tidak bisa
    // mengubah, atau bahkan melihat, akun Admin Nasional.
    public function listManageable() {
        $rows = $this->db->query("
            SELECT a.*, p.full_name AS person_name,
                   GROUP_CONCAT(CONCAT(ar.role, IFNULL(CONCAT(' @ ', s.level, ' ', s.name), ' @ semua'))
                                ORDER BY ar.role SEPARATOR ', ') AS role_summary
            FROM accounts a
            LEFT JOIN people p ON p.id = a.person_id
            LEFT JOIN account_roles ar ON ar.account_id = a.id
            LEFT JOIN structures s ON s.id = ar.scope_structure_id
            GROUP BY a.id
            ORDER BY a.display_name
        ")->fetchAll();

        if (AdminScope::isUnbounded()) return $rows;

        $allowed = AdminScope::structureIds();
        return array_values(array_filter($rows, function ($row) use ($allowed) {
            foreach ($this->rolesOf($row['id']) as $r) {
                // Lingkup NULL = seluruh sistem; itu di atas lingkup admin mana pun kecuali super admin.
                if (empty($r['scope_structure_id'])) return false;
                if (!in_array((int) $r['scope_structure_id'], $allowed, true)) return false;
            }
            return true;
        }));
    }

    // Admin yang lingkupnya TEPAT struktur-struktur ini (peran admin_struktur). Untuk kolom "Admin" di halaman
    // Struktur: satu struktur boleh punya beberapa admin, dan admin struktur induk TIDAK dihitung di sini
    // (lingkupnya memang mencakup, tetapi bukan "admin struktur ini").
    public function adminsByStructure(array $structureIds) {
        if (empty($structureIds)) return [];
        $in = implode(',', array_map('intval', $structureIds));
        $rows = $this->db->query("
            SELECT ar.scope_structure_id AS sid, a.id, a.display_name, a.phone, a.status
            FROM account_roles ar JOIN accounts a ON a.id = ar.account_id
            WHERE ar.role = 'admin_struktur' AND ar.scope_structure_id IN ($in)
            ORDER BY a.display_name
        ")->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[(int) $r['sid']][] = $r;
        return $map;
    }

    public function save($id, array $data) {
        $phone = Text::normalizePhone($data['phone']);
        if ($phone === null) {
            throw new RuntimeException('Nomor WhatsApp tidak valid.');
        }

        $clash = $this->db->prepare("SELECT id FROM accounts WHERE phone = :p AND id <> :id LIMIT 1");
        $clash->execute([':p' => $phone, ':id' => (int) $id]);
        if ($clash->fetch()) {
            throw new RuntimeException('Nomor WhatsApp tersebut sudah dipakai akun lain.');
        }

        // Email kini juga nama pengguna untuk masuk: harus sah dan tidak boleh dipakai akun lain.
        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : '';
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email tidak valid.');
            }
            $dupe = $this->db->prepare("SELECT id FROM accounts WHERE email = :e AND id <> :id LIMIT 1");
            $dupe->execute([':e' => $email, ':id' => (int) $id]);
            if ($dupe->fetch()) {
                throw new RuntimeException('Email tersebut sudah dipakai akun lain.');
            }
        }
        $data['email'] = $email;
        $mustChange = array_key_exists('must_change_password', $data) ? (!empty($data['must_change_password']) ? 1 : 0) : null;

        if ($id) {
            $before = $this->findById($id);
            $sql = "UPDATE accounts SET person_id = :person, phone = :phone, email = :email,
                           display_name = :name, status = :status";
            $params = [
                ':person' => !empty($data['person_id']) ? (int) $data['person_id'] : null,
                ':phone'  => $phone,
                ':email'  => !empty($data['email']) ? trim($data['email']) : null,
                ':name'   => trim($data['display_name']),
                ':status' => $data['status'],
                ':id'     => (int) $id,
            ];
            // Password hanya ditimpa bila diisi; form edit yang dikosongkan berarti "jangan diubah".
            if (!empty($data['password'])) {
                $sql .= ", password_hash = :pw";
                $params[':pw'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            if ($mustChange !== null) {
                $sql .= ", must_change_password = :mc";
                $params[':mc'] = $mustChange;
            }
            $sql .= " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
            Audit::log('accounts', $id, 'update', $before, $this->findById($id));
            return (int) $id;
        }

        if (empty($data['password'])) {
            throw new RuntimeException('Kata sandi wajib diisi untuk akun baru.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO accounts (uuid, person_id, phone, email, password_hash, display_name, status, must_change_password)
            VALUES (:uuid, :person, :phone, :email, :pw, :name, :status, :mc)
        ");
        $stmt->execute([
            ':uuid'   => Uuid::v4(),
            ':person' => !empty($data['person_id']) ? (int) $data['person_id'] : null,
            ':phone'  => $phone,
            ':email'  => !empty($data['email']) ? trim($data['email']) : null,
            ':pw'     => password_hash($data['password'], PASSWORD_DEFAULT),
            ':name'   => trim($data['display_name']),
            ':status' => $data['status'],
            ':mc'     => $mustChange ?? 0,
        ]);
        $newId = (int) $this->db->lastInsertId();
        Audit::log('accounts', $newId, 'create', null, $this->findById($newId));
        return $newId;
    }

    // Memeriksa bahwa pemanggil BERHAK memberikan daftar peran ini. Dipanggil SEBELUM menulis apa pun:
    // penjagaan eskalasi hak yang baru dijalankan setelah akunnya dibuat meninggalkan akun yatim tanpa
    // peran bila ditolak.
    // Admin non-super tidak boleh memberi lingkup di luar lingkupnya sendiri, dan tidak boleh
    // menerbitkan peran super_admin atau peran tanpa batas lingkup.
    public function assertCanGrant(array $roles) {
        if (AdminScope::isUnbounded()) return;
        foreach ($roles as $r) {
            $scope = !empty($r['scope_structure_id']) ? (int) $r['scope_structure_id'] : null;
            if ($r['role'] === 'super_admin' || $scope === null) {
                throw new RuntimeException('Anda tidak berhak memberikan peran tanpa batas lingkup.');
            }
            if (!AdminScope::allows($scope)) {
                throw new RuntimeException('Lingkup yang diberikan berada di luar lingkup akses Anda.');
            }
        }
    }

    // Menulis ulang baris peran. TANPA pemeriksaan hak dan tanpa transaksi sendiri -- pemanggil yang
    // bertanggung jawab atas keduanya (lihat setRoles dan saveWithRoles).
    private function writeRoles($accountId, array $roles) {
        $this->db->prepare("DELETE FROM account_roles WHERE account_id = :id")->execute([':id' => (int) $accountId]);
        $ins = $this->db->prepare("
            INSERT INTO account_roles (account_id, role, scope_structure_id, granted_by)
            VALUES (:acc, :role, :scope, :by)
        ");
        foreach ($roles as $r) {
            $ins->execute([
                ':acc'   => (int) $accountId,
                ':role'  => $r['role'],
                ':scope' => !empty($r['scope_structure_id']) ? (int) $r['scope_structure_id'] : null,
                ':by'    => Auth::id(),
            ]);
        }
    }

    // Mengganti seluruh baris peran sebuah akun dengan daftar baru.
    // $roles = [['role' => 'admin_struktur', 'scope_structure_id' => 2], ...]
    public function setRoles($accountId, array $roles) {
        $this->assertCanGrant($roles);
        $before = $this->rolesOf($accountId);

        $this->db->beginTransaction();
        try {
            $this->writeRoles($accountId, $roles);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        Audit::log('account_roles', $accountId, 'update', $before, $this->rolesOf($accountId));
        return true;
    }

    // Menyimpan akun DAN perannya dalam satu transaksi. Gagal di mana pun (nomor ganda, kata sandi
    // kosong, hak tidak cukup) berarti tidak ada yang tertulis -- tidak ada akun yatim tanpa peran.
    public function saveWithRoles($id, array $data, array $roles) {
        $this->assertCanGrant($roles);
        $this->db->beginTransaction();
        try {
            $accountId = $this->save($id, $data);
            $before = $this->rolesOf($accountId);
            $this->writeRoles($accountId, $roles);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        Audit::log('account_roles', $accountId, 'update', $before, $this->rolesOf($accountId));
        return $accountId;
    }

    // Aturan kata sandi baru saat DIGANTI sendiri oleh pemiliknya. Mengembalikan pesan galat, atau null bila lolos.
    // Yang ditolak khusus: kata sandi yang memuat nama pengguna / bagian depan emailnya atau nomor WA-nya --
    // persis pola kata sandi sementara yang mudah ditebak (mis. "admin.dpp123").
    public function checkNewPassword(array $account, $current, $new, $confirm) {
        if (strlen($new) < 10) return 'Kata sandi baru minimal 10 karakter.';
        if ($new !== $confirm) return 'Konfirmasi kata sandi tidak sama.';
        if ($new === $current) return 'Kata sandi baru harus berbeda dari yang sekarang.';
        $low = mb_strtolower($new);
        $local = !empty($account['email']) ? mb_strtolower(strstr($account['email'], '@', true)) : '';
        if (strlen($local) >= 4 && strpos($low, $local) !== false) {
            return 'Kata sandi tidak boleh memuat nama pengguna Anda (bagian depan email).';
        }
        $digits = preg_replace('/\D+/', '', (string) $account['phone']);
        if (strlen($digits) >= 8 && strpos(preg_replace('/\D+/', '', $new), substr($digits, -8)) !== false) {
            return 'Kata sandi tidak boleh memuat nomor WhatsApp Anda.';
        }
        if (preg_match('/^(.)\1+$/', $new)) return 'Kata sandi terlalu mudah ditebak.';
        return null;
    }

    public function changePassword($accountId, $new) {
        $this->db->prepare("UPDATE accounts SET password_hash = :pw, must_change_password = 0 WHERE id = :id")
                 ->execute([':pw' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int) $accountId]);
        Audit::log('accounts', $accountId, 'update', null, ['event' => 'password_change']);
    }

    public function verifyPassword($account, $password) {
        return !empty($account['password_hash']) && password_verify($password, $account['password_hash']);
    }

    public function touchLogin($accountId) {
        $this->db->prepare("UPDATE accounts SET last_login_at = NOW(3) WHERE id = :id")
                 ->execute([':id' => (int) $accountId]);
    }
}
