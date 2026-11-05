<?php
// PATH LOKASI FILE: app/Models/ApiClientModel.php
// Token aplikasi konsumen (syuro dll). Token utuh hanya pernah ada sekali -- di layar, saat dibuat.

class ApiClientModel {
    // Izin yang bisa diberikan ke sebuah token. 'people:nik' DISENGAJA terpisah dari 'people:read':
    // token sinkronisasi harian syuro tidak perlu, dan karena itu tidak boleh, bisa membuka nomor KTP.
    const SCOPES = [
        'structures:read'  => 'Baca struktur organisasi & periode',
        'people:read'      => 'Baca data orang (tanpa NIK)',
        'placements:read'  => 'Baca penempatan jabatan',
        'people:nik'       => 'Lookup orang berdasarkan NIK (data pribadi sensitif)',
    ];

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all() {
        return $this->db->query("
            SELECT c.*, s.level, s.name AS scope_name,
                   (SELECT COUNT(*) FROM api_request_log l
                     WHERE l.client_id = c.id AND l.requested_at > (NOW(3) - INTERVAL 24 HOUR)) AS calls_24h
            FROM api_clients c
            LEFT JOIN structures s ON s.id = c.scope_structure_id
            ORDER BY c.name
        ")->fetchAll();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM api_clients WHERE id = :id");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch() ?: null;
    }

    // Membuat klien baru. Mengembalikan token utuh SEKALI -- setelah ini hanya hash-nya yang ada,
    // jadi tidak ada cara menampilkannya ulang (dan itu memang yang diinginkan).
    public function create(array $data) {
        $token = Uuid::apiToken();
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), (array) ($data['scopes'] ?? [])));

        $stmt = $this->db->prepare("
            INSERT INTO api_clients (uuid, name, token_prefix, token_hash, scopes, scope_structure_id,
                                     rate_limit_per_min, status, expires_at)
            VALUES (:uuid, :name, :prefix, :hash, :scopes, :scope, :rate, 'active', :expires)
        ");
        $stmt->execute([
            ':uuid'    => Uuid::v4(),
            ':name'    => trim($data['name']),
            ':prefix'  => $token['prefix'],
            ':hash'    => $token['hash'],
            ':scopes'  => json_encode($scopes),
            ':scope'   => !empty($data['scope_structure_id']) ? (int) $data['scope_structure_id'] : null,
            ':rate'    => max(1, (int) ($data['rate_limit_per_min'] ?: 60)),
            ':expires' => !empty($data['expires_at']) ? $data['expires_at'] : null,
        ]);
        $id = (int) $this->db->lastInsertId();

        Audit::log('api_clients', $id, 'create', null, ['name' => $data['name'], 'scopes' => $scopes]);
        return ['id' => $id, 'token' => $token['plain']];
    }

    public function updateScopes($id, array $data) {
        $before = $this->findById($id);
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), (array) ($data['scopes'] ?? [])));

        $stmt = $this->db->prepare("
            UPDATE api_clients SET name = :name, scopes = :scopes, scope_structure_id = :scope,
                   rate_limit_per_min = :rate, expires_at = :expires
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'    => trim($data['name']),
            ':scopes'  => json_encode($scopes),
            ':scope'   => !empty($data['scope_structure_id']) ? (int) $data['scope_structure_id'] : null,
            ':rate'    => max(1, (int) ($data['rate_limit_per_min'] ?: 60)),
            ':expires' => !empty($data['expires_at']) ? $data['expires_at'] : null,
            ':id'      => (int) $id,
        ]);
        Audit::log('api_clients', $id, 'update', $before, $this->findById($id));
        return true;
    }

    public function revoke($id) {
        $before = $this->findById($id);
        $this->db->prepare("UPDATE api_clients SET status = 'revoked' WHERE id = :id")->execute([':id' => (int) $id]);
        Audit::log('api_clients', $id, 'update', $before, $this->findById($id));
        return true;
    }

    // Menerbitkan token baru untuk klien yang sama (rotasi). Token lama langsung tidak berlaku
    // karena hash-nya ditimpa -- rotasi di sini memang sengaja tanpa masa tumpang tindih, supaya
    // tidak ada token "lama tapi masih hidup" yang terlupakan.
    public function rotate($id) {
        $token = Uuid::apiToken();
        $this->db->prepare("
            UPDATE api_clients SET token_prefix = :prefix, token_hash = :hash, status = 'active'
            WHERE id = :id
        ")->execute([':prefix' => $token['prefix'], ':hash' => $token['hash'], ':id' => (int) $id]);

        Audit::log('api_clients', $id, 'update', null, ['event' => 'token_rotated']);
        return $token['plain'];
    }

    public function recentLog($clientId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT * FROM api_request_log WHERE client_id = :id
            ORDER BY requested_at DESC LIMIT :lim
        ");
        $stmt->bindValue(':id', (int) $clientId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
