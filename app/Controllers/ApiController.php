<?php
// PATH LOKASI FILE: app/Controllers/ApiController.php
// API baca-saja v1 untuk aplikasi konsumen (syuro dll).
//
// Hanya menerima GET. Tidak ada endpoint tulis sama sekali: data master hanya bisa berubah lewat
// antarmuka admin yang melewati verifikasi manusia, jadi bug di aplikasi konsumen tidak pernah bisa
// merusak sumber datanya.
//
// Kontrak yang dijanjikan ke konsumen:
//   * Setiap objek dikunci `uuid`. Id internal tidak pernah keluar.
//   * `updated_at` + `cursor` dipakai untuk sinkronisasi inkremental.
//   * Baris yang dihapus muncul dengan "deleted": true pada endpoint /sync/changes, sehingga
//     salinan lokal bisa ikut menghapus. Tanpa ini, penghapusan tidak akan pernah terkabarkan.

class ApiController extends Controller {
    private $api;

    public function dispatch(array $segments) {
        $this->api = new Api();

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->api->fail(405, 'method_not_allowed', 'API ini hanya menerima GET.');
        }

        $version = array_shift($segments);
        if ($version !== 'v1') {
            $this->api->fail(404, 'unknown_version', 'Versi API tidak dikenal. Gunakan /api/v1/.');
        }

        $this->api->authenticate();

        $resource = array_shift($segments) ?: '';

        try {
            switch ($resource) {
                case 'structures': $this->structures($segments); break;
                case 'terms':      $this->terms(); break;
                case 'people':     $this->people($segments); break;
                case 'placements': $this->placements(); break;
                case 'sync':       $this->sync($segments); break;
                case 'whoami':     $this->whoami(); break;
                default:
                    $this->api->fail(404, 'unknown_resource',
                        'Endpoint tidak dikenal. Tersedia: structures, terms, people, placements, sync, whoami.');
            }
        } catch (InvalidArgumentException $e) {
            // Kesalahan parameter dari klien -> 400, dengan pesan yang menjelaskan cara benarnya.
            $this->api->fail(400, 'bad_request', $e->getMessage());
        } catch (Exception $e) {
            error_log('API error: ' . $e->getMessage());
            $this->api->fail(500, 'server_error', 'Terjadi kesalahan di server.');
        }
    }

    // Membuat SyncModel dengan batas subtree milik token (bila ada).
    private function newSync() {
        require_once ROOT_DIR . '/app/Models/SyncModel.php';
        return new SyncModel($this->api->clientScopeStructureId());
    }

    // GET /api/v1/whoami -- untuk konsumen memastikan token & izinnya tanpa menarik data.
    private function whoami() {
        $c = $this->api->client();
        $this->api->ok([
            'client'             => $c['name'],
            'scopes'             => json_decode($c['scopes'], true),
            'scope_structure'    => $this->api->clientScopeStructureId() === null ? null : 'terbatas',
            'rate_limit_per_min' => (int) $c['rate_limit_per_min'],
            'expires_at'         => $c['expires_at'],
        ]);
    }

    // GET /api/v1/structures
    // GET /api/v1/structures/{uuid}
    private function structures(array $segments) {
        $this->api->requireScope('structures:read');
        $sync = $this->newSync();

        if (!empty($segments[0])) {
            $row = $sync->structureByUuid($segments[0]);
            if (!$row) $this->api->fail(404, 'not_found', 'Struktur tidak ditemukan.');
            $this->api->ok($row);
        }

        $rows = $sync->structures($_GET['since'] ?? null, $this->wantsDeleted());
        $this->api->ok($rows);
    }

    // GET /api/v1/terms?structure={uuid}
    private function terms() {
        $this->api->requireScope('structures:read');
        $rows = $this->newSync()->terms($_GET['structure'] ?? null, $_GET['since'] ?? null, $this->wantsDeleted());
        $this->api->ok($rows);
    }

    // GET /api/v1/people?since=&cursor=&limit=
    // GET /api/v1/people/{uuid}
    // GET /api/v1/people/by-nik/{nik}
    // GET /api/v1/people/by-phone/{phone}
    private function people(array $segments) {
        // Jalur lookup NIK dipisah SEBELUM pemeriksaan scope people:read, karena izinnya berbeda:
        // token sinkronisasi biasa boleh membaca daftar orang tapi tidak boleh membuka NIK.
        if (($segments[0] ?? '') === 'by-nik') {
            $this->peopleByNik($segments[1] ?? '');
        }
        if (($segments[0] ?? '') === 'by-phone') {
            $this->peopleByPhone($segments[1] ?? '');
        }

        $this->api->requireScope('people:read');
        $sync = $this->newSync();

        if (!empty($segments[0])) {
            $row = $sync->personByUuid($segments[0]);
            if (!$row) $this->api->fail(404, 'not_found', 'Orang tidak ditemukan.');
            $this->api->ok($row);
        }

        $result = $sync->people($_GET['since'] ?? null, $_GET['cursor'] ?? null, $this->limit(), $this->wantsDeleted());
        $this->api->ok($result['rows'], ['next_cursor' => $result['next_cursor']]);
    }

    // Lookup berdasarkan NIK. Dipisah dari endpoint lain dengan sengaja:
    //   * butuh izin 'people:nik' tersendiri, jadi token sinkronisasi harian tidak otomatis bisa;
    //   * hanya menerima NIK utuh 16 digit, tidak ada pencarian sebagian, jadi tidak bisa dipakai
    //     menyapu daftar nomor;
    //   * setiap pemanggilan diaudit lengkap dengan nama klien, termasuk yang tidak menemukan apa pun;
    //   * respons TIDAK memuat ulang NIK-nya -- pemanggil sudah tahu nomor yang ia kirim, dan
    //     mengembalikannya hanya menambah satu tempat lagi nomor itu tersalin (di log, cache, dsb).
    private function peopleByNik($nik) {
        $this->api->requireScope('people:nik');

        $normalized = Text::normalizeNik($nik);
        if ($normalized === null) {
            $this->api->fail(400, 'bad_request', 'NIK harus tepat 16 digit angka.');
        }

        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();
        $row = $people->findByNik($normalized, 'api:' . $this->api->client()['name']);

        if (!$row) {
            $this->api->fail(404, 'not_found', 'Tidak ada pengurus dengan NIK tersebut.');
        }

        // Lingkup subtree token tetap berlaku di jalur ini juga.
        $sync = $this->newSync();
        $person = $sync->personByUuid($row['uuid']);
        if (!$person) {
            $this->api->fail(404, 'not_found', 'Tidak ada pengurus dengan NIK tersebut.');
        }

        $this->api->ok($person);
    }

    // Lookup berdasarkan nomor HP/WhatsApp. BEDA dengan by-nik dalam satu hal penting: `phone` di
    // `people` SENGAJA bukan kolom unik (nomor kadang dipakai bersama, mis. nomor suami tercatat juga
    // di data pengurus muslimah) -- jadi nomor yang dipakai LEBIH dari satu orang diperlakukan sebagai
    // "tidak ditemukan", bukan mengembalikan salah satu secara sembarang. Pola yang sama dengan
    // MemberModel::findEligibleByPhone() di Portal Pengurus. Scope-nya people:read BIASA (bukan
    // people:nik yang terpisah): nomor HP memang sudah ikut terbuka apa adanya di /people.
    private function peopleByPhone($phone) {
        $this->api->requireScope('people:read');

        $normalized = Text::normalizePhone($phone);
        if ($normalized === null) {
            $this->api->fail(400, 'bad_request', 'Nomor HP/WhatsApp tidak valid.');
        }

        require_once ROOT_DIR . '/app/Models/PersonModel.php';
        $people = new PersonModel();
        $row = $people->findByPhone($normalized);

        if (!$row) {
            $this->api->fail(404, 'not_found', 'Tidak ada tepat satu pengurus dengan nomor itu.');
        }

        // Lingkup subtree token tetap berlaku di jalur ini juga.
        $sync = $this->newSync();
        $person = $sync->personByUuid($row['uuid']);
        if (!$person) {
            $this->api->fail(404, 'not_found', 'Tidak ada tepat satu pengurus dengan nomor itu.');
        }

        $this->api->ok($person);
    }

    // GET /api/v1/placements?structure=&term=&person=&current=1&core=1&since=&cursor=&limit=
    private function placements() {
        $this->api->requireScope('placements:read');

        $filters = [
            'structure' => $_GET['structure'] ?? null,
            'term'      => $_GET['term'] ?? null,
            'person'    => $_GET['person'] ?? null,
            'current'   => !empty($_GET['current']),
            'core'      => !empty($_GET['core']),
        ];

        $result = $this->newSync()->placements(
            $filters, $_GET['since'] ?? null, $_GET['cursor'] ?? null, $this->limit(), $this->wantsDeleted()
        );
        $this->api->ok($result['rows'], ['next_cursor' => $result['next_cursor']]);
    }

    // GET /api/v1/sync/full      -- tarikan awal: struktur + periode + penempatan periode berjalan
    // GET /api/v1/sync/changes   -- tarikan inkremental sejak waktu tertentu, TERMASUK penghapusan
    //
    // Dua aturan yang membuat sinkronisasi ini tidak pernah kehilangan baris:
    //
    //  1. sync_token diambil SEBELUM query pertama jalan, bukan sesudah yang terakhir. Baris yang
    //     ditulis selagi query berlangsung punya updated_at lebih besar dari token, jadi ia ikut
    //     terbawa di panggilan berikutnya. Kalau token diambil belakangan, baris itu lolos dari
    //     respons ini (query-nya sudah lewat) sekaligus dari panggilan berikutnya (updated_at-nya
    //     lebih kecil dari token) -- hilang diam-diam, selamanya.
    //
    //  2. sync_token HANYA dikirim bila seluruh kursor sudah habis. Selama masih ada *_next_cursor,
    //     jendela perubahan ini belum selesai dikirim; memberi token baru di tengah jalan membuat
    //     konsumen yang mengabaikan kursor memajukan penanda waktunya melewati baris yang belum
    //     pernah ia terima. Tanpa token, konsumen tidak punya pilihan lain selain menghabiskan
    //     kursornya dulu -- jalur yang salah jadi tidak bisa ditempuh.
    private function sync(array $segments) {
        $mode = $segments[0] ?? '';

        if ($mode !== 'full' && $mode !== 'changes') {
            $this->api->fail(404, 'unknown_resource', 'Gunakan /sync/full atau /sync/changes.');
        }

        $this->api->requireScope('structures:read');
        $this->api->requireScope('people:read');
        $this->api->requireScope('placements:read');

        // Lihat aturan 1 di atas: diambil sebelum query apa pun.
        $syncToken = date('c');
        $sync = $this->newSync();

        if ($mode === 'full') {
            // Penempatan diambil berhalaman supaya respons tidak membengkak tak terbatas; konsumen
            // melanjutkan lewat placements_next_cursor di endpoint ini juga.
            $cursor = $_GET['cursor_placements'] ?? null;
            $placements = $sync->placements(['current' => true], null, $cursor, $this->limit(), false);

            // structures & terms tidak berhalaman -- halaman pertama sudah memuat semuanya. Di
            // halaman lanjutan keduanya dikirim sebagai array kosong, bukan diulang: mengulang 1.100
            // struktur di setiap putaran kursor penempatan hanya menghabiskan kuota dan bandwidth
            // konsumen tanpa menambah satu baris baru pun.
            $firstPage = ($cursor === null || $cursor === '');

            $this->respondSync([
                'structures' => $firstPage ? $sync->structures() : [],
                'terms'      => $firstPage ? $sync->terms() : [],
                'placements' => $placements['rows'],
            ], ['placements_next_cursor' => $placements['next_cursor']], $syncToken);
        }

        $since = $_GET['since'] ?? null;
        if (empty($since)) {
            $this->api->fail(400, 'bad_request', 'Parameter "since" wajib. Pakai nilai sync_token dari /sync/full.');
        }

        // includeDeleted = true: inilah inti endpoint ini. Baris yang dihapus muncul dengan
        // "deleted": true supaya salinan lokal konsumen bisa ikut menghapusnya.
        $people = $sync->people($since, $_GET['cursor_people'] ?? null, $this->limit(), true);
        $placements = $sync->placements([], $since, $_GET['cursor_placements'] ?? null, $this->limit(), true);

        $this->respondSync([
            'structures' => $sync->structures($since, true),
            'terms'      => $sync->terms(null, $since, true),
            'people'     => $people['rows'],
            'placements' => $placements['rows'],
        ], [
            'people_next_cursor'     => $people['next_cursor'],
            'placements_next_cursor' => $placements['next_cursor'],
        ], $syncToken);
    }

    // Menyusun respons /sync/*: jumlah baris per koleksi (meta.count bawaan tidak berarti untuk
    // payload berkunci seperti ini), lalu sync_token hanya bila tidak ada kursor yang tersisa.
    private function respondSync(array $data, array $cursors, $syncToken) {
        $hasMore = false;
        foreach ($cursors as $c) {
            if ($c !== null) { $hasMore = true; break; }
        }

        $counts = [];
        foreach ($data as $k => $rows) $counts[$k] = count($rows);

        $meta = array_merge($cursors, [
            'count'    => $counts,
            'has_more' => $hasMore,
        ]);

        if ($hasMore) {
            $meta['catatan'] = 'Masih ada sisa. Ulangi permintaan dengan "since" yang SAMA ditambah '
                . 'kursor dari *_next_cursor, sampai has_more = false. sync_token baru terbit setelah itu.';
        } else {
            $meta['sync_token'] = $syncToken;
            $meta['catatan'] = 'Simpan sync_token, lalu panggil /sync/changes?since=<sync_token> untuk perubahan berikutnya.';
        }

        $this->api->ok($data, $meta);
    }

    private function limit() {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : API_PAGE_SIZE;
        if ($limit < 1) $limit = API_PAGE_SIZE;
        return min($limit, API_PAGE_SIZE_MAX);
    }

    // Baris terhapus hanya disertakan bila diminta eksplisit, supaya konsumen yang tidak peduli
    // riwayat penghapusan tidak perlu menyaringnya sendiri.
    private function wantsDeleted() {
        return !empty($_GET['include_deleted']);
    }
}
