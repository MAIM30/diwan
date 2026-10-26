<?php
// PATH LOKASI FILE: app/Core/Api.php
// Autentikasi token, rate limit, dan pembentuk response untuk API baca-saja.
//
// Catatan desain: API ini hanya menerima GET. Tidak ada endpoint tulis, jadi aplikasi konsumen tidak
// bisa -- bahkan karena bug sekalipun -- mengubah data master dari jauh. Satu-satunya jalan menulis
// adalah antarmuka admin yang melewati verifikasi manusia.

class Api {
    private $client = null;   // baris api_clients yang terautentikasi
    private $startedAt;

    public function __construct() {
        $this->startedAt = microtime(true);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // API ini dipanggil server-ke-server dengan token, bukan dari browser dengan cookie, jadi
        // tidak ada Access-Control-Allow-Origin: membukanya akan mengundang token dipasang di
        // JavaScript sisi klien, tempat token tidak pernah bisa dirahasiakan.
    }

    // Mengautentikasi pemanggil. Menghentikan request dengan 401 bila gagal.
    public function authenticate() {
        $token = $this->bearerToken();
        if ($token === null) {
            $this->fail(401, 'unauthorized', 'Header Authorization: Bearer <token> wajib ada.');
        }

        // Token berbentuk 'pgr_<prefix12>_<secret>'. Prefix dipakai untuk menemukan barisnya; bagian
        // rahasianya hanya pernah disimpan sebagai hash SHA-256.
        if (!preg_match('/^pgr_([0-9a-f]{12})_[0-9a-f]{64}$/', $token, $m)) {
            $this->fail(401, 'unauthorized', 'Format token tidak dikenali.');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM api_clients WHERE token_prefix = :p LIMIT 1");
        $stmt->execute([':p' => $m[1]]);
        $client = $stmt->fetch();

        // hash_equals, bukan '==': perbandingan waktu-tetap supaya waktu respons tidak membocorkan
        // seberapa jauh token tebakan sudah cocok.
        if (!$client || !hash_equals($client['token_hash'], hash('sha256', $token))) {
            $this->fail(401, 'unauthorized', 'Token tidak sah.');
        }
        if ($client['status'] !== 'active') {
            $this->fail(403, 'token_revoked', 'Token sudah dicabut.');
        }
        if (!empty($client['expires_at']) && strtotime($client['expires_at']) < time()) {
            $this->fail(403, 'token_expired', 'Token sudah kedaluwarsa.');
        }

        $this->client = $client;
        $this->enforceRateLimit();

        // Jejak pemakaian terakhir, untuk mendeteksi token yang tidak pernah dipakai lagi.
        $db->prepare("UPDATE api_clients SET last_used_at = NOW(3) WHERE id = :id")
           ->execute([':id' => $client['id']]);

        return $client;
    }

    private function bearerToken() {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return $m[1];
        }
        return null;
    }

    // Menolak klien yang melampaui kuota per menit. Dihitung dari api_request_log, bukan dari
    // penyimpanan di memori, supaya batasnya tetap berlaku walau proses PHP berganti tiap request.
    private function enforceRateLimit() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM api_request_log
            WHERE client_id = :id AND requested_at > (NOW(3) - INTERVAL 1 MINUTE)
        ");
        $stmt->execute([':id' => $this->client['id']]);
        $used = (int) $stmt->fetchColumn();
        $limit = (int) $this->client['rate_limit_per_min'];

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $used));

        if ($used >= $limit) {
            header('Retry-After: 60');
            $this->fail(429, 'rate_limited', 'Batas ' . $limit . ' permintaan per menit terlampaui.');
        }
    }

    // Memastikan token punya izin yang diminta endpoint. Scope disimpan sebagai array JSON, mis.
    // ["structures:read","people:read"]. Izin NIK ('people:nik') sengaja terpisah dari
    // 'people:read' supaya token sinkronisasi biasa tidak otomatis bisa membuka nomor KTP.
    public function requireScope($scope) {
        $scopes = json_decode($this->client['scopes'] ?? '[]', true);
        if (!is_array($scopes) || !in_array($scope, $scopes, true)) {
            $this->fail(403, 'insufficient_scope', 'Token ini tidak punya izin "' . $scope . '".');
        }
    }

    public function client() {
        return $this->client;
    }

    // Batas subtree opsional per klien: token tingkat wilayah hanya melihat wilayahnya.
    // NULL = tanpa batas struktur.
    public function clientScopeStructureId() {
        return isset($this->client['scope_structure_id']) && $this->client['scope_structure_id'] !== null
            ? (int) $this->client['scope_structure_id']
            : null;
    }

    public function ok($data, $meta = []) {
        $this->respond(200, [
            'data' => $data,
            'meta' => array_merge([
                'count'       => is_array($data) && array_is_list_compat($data) ? count($data) : 1,
                'server_time' => date('c'),
            ], $meta),
        ]);
    }

    public function fail($status, $code, $message) {
        $this->respond($status, ['error' => ['code' => $code, 'message' => $message]]);
    }

    private function respond($status, array $payload) {
        http_response_code($status);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logRequest($status, $payload['data'] ?? null);
        echo $body;
        exit;
    }

    private function logRequest($status, $data) {
        if ($this->client === null) return; // request yang gagal autentikasi tidak punya klien
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO api_request_log (client_id, endpoint, query_string, http_status, row_count, duration_ms, ip)
                VALUES (:client, :endpoint, :qs, :status, :rows, :ms, :ip)
            ");
            $stmt->execute([
                ':client'   => $this->client['id'],
                ':endpoint' => substr($this->redactNik($_GET['url'] ?? ''), 0, 120),
                ':qs'       => substr($this->redactNik($this->queryStringWithoutUrl()), 0, 255) ?: null,
                ':status'   => $status,
                ':rows'     => $this->rowCount($data),
                ':ms'       => (int) round((microtime(true) - $this->startedAt) * 1000),
                ':ip'       => Text::clientIp(),
            ]);
        } catch (PDOException $e) {
            error_log('Log API gagal: ' . $e->getMessage());
        }
    }

    // Jumlah baris untuk log pemakaian. Payload /sync/* berupa objek berkunci koleksi
    // ('structures', 'people', ...), jadi count() mentahnya hanya menghitung JUMLAH KOLEKSI -- 4,
    // untuk respons berisi ribuan baris. Yang berguna di log adalah total barisnya.
    private function rowCount($data) {
        if ($data === null) return null;
        if (!is_array($data)) return 1;
        if (array_is_list_compat($data)) return count($data);

        $total = 0;
        $anyList = false;
        foreach ($data as $v) {
            if (is_array($v) && array_is_list_compat($v)) { $total += count($v); $anyList = true; }
        }
        return $anyList ? $total : 1; // objek tunggal (mis. /whoami, /people/{uuid})
    }

    // NIK muncul di PATH endpoint lookup (/people/by-nik/32xxxxxxxxxxxxxx). Log API dibaca untuk
    // menelusuri pemakaian dan disimpan lama, jadi nomornya diganti penanda sebelum ditulis --
    // yang perlu terlacak adalah SIAPA membuka lookup NIK dan kapan, bukan nomornya. Siapa membuka
    // NIK siapa tetap tercatat utuh di audit_log, tabel yang aksesnya jauh lebih sempit.
    private function redactNik($s) {
        return preg_replace('/\b\d{16}\b/', '[nik-diredaksi]', (string) $s);
    }

    private function queryStringWithoutUrl() {
        $q = $_GET;
        unset($q['url']);
        return http_build_query($q);
    }
}

// array_is_list() baru ada di PHP 8.1; sebagian hosting masih di 7.4/8.0.
function array_is_list_compat(array $a) {
    if (function_exists('array_is_list')) return array_is_list($a);
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}
