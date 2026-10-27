<?php
// PATH LOKASI FILE: app/Core/GoogleDrive.php
// Unggah berkas ke Google Drive atas nama satu akun Google (OAuth2 + refresh token) -- tanpa
// Composer, tanpa SDK: cukup cURL, pola yang sama seperti Whatsapp::request().
//
// Kenapa bukan service account: service account tidak punya kuota penyimpanan, jadi Google menolak
// berkas barunya di Drive pribadi ("Service Accounts do not have storage quota"). Berkas harus dimiliki
// akun manusia -- di sini akun yang menekan tombol "Hubungkan Google Drive" di /settings/gdrive.
//
// Scope sengaja SEMPIT (drive.file): aplikasi hanya bisa menyentuh berkas/folder yang DIBUATNYA
// sendiri, bukan seluruh Drive. Itu sebabnya folder tujuan dibuat oleh aplikasi saat tombol itu
// ditekan -- folder lama buatan manual tidak akan terlihat oleh scope ini.

class GoogleDrive {
    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const FILES_URL   = 'https://www.googleapis.com/drive/v3/files';
    const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
    const SCOPE       = 'https://www.googleapis.com/auth/drive.file';
    const TIMEOUT     = 30;
    const FOLDER_NAME = 'Biodata Pengurus DIWAN';
    const PHOTO_FOLDER_NAME = 'Foto Biodata Pengurus DIWAN';

    const SETTINGS_KEYS = [
        'gdrive_client_id',
        'gdrive_client_secret',
        'gdrive_refresh_token',
        'gdrive_folder_id',
        'gdrive_photo_folder_id',
    ];

    public static $settings;
    private static $tokenCache;

    private static function settings() {
        if (self::$settings === null) {
            require_once ROOT_DIR . '/app/Models/SettingModel.php';
            self::$settings = (new SettingModel())->getMany(self::SETTINGS_KEYS);
        }
        return self::$settings;
    }

    public static function hasClient() {
        $s = self::settings();
        return !empty($s['gdrive_client_id']) && !empty($s['gdrive_client_secret']);
    }

    public static function isConfigured() {
        $s = self::settings();
        return self::hasClient() && !empty($s['gdrive_refresh_token']) && !empty($s['gdrive_folder_id']);
    }

    public static function redirectUri() {
        return BASEURL . '/settings/gdrive_callback';
    }

    public static function authUrl($state) {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => self::settings()['gdrive_client_id'],
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    // Satu-satunya tempat POST ke endpoint token Google; dipakai penukaran kode dan penyegaran token.
    private static function tokenRequest(array $fields) {
        $s = self::settings();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::TOKEN_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields + [
                'client_id'     => $s['gdrive_client_id'],
                'client_secret' => $s['gdrive_client_secret'],
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) return ['ok' => false, 'error' => 'Tidak bisa menghubungi Google: ' . $error];
        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || empty($decoded['access_token'])) {
            return ['ok' => false, 'error' => $decoded['error_description'] ?? $decoded['error'] ?? ('HTTP ' . $httpCode)];
        }
        return ['ok' => true, 'data' => $decoded];
    }

    // Menukar kode dari layar persetujuan Google dengan refresh token (berumur panjang) dan access token.
    public static function exchangeCode($code) {
        return self::tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ]);
    }

    // Access token berumur ~1 jam. Disimpan di memori hanya selama satu permintaan, supaya beberapa
    // panggilan Drive berurutan (foto, hapus foto lama, PDF) memakai satu token.
    private static function accessToken() {
        if (self::$tokenCache && self::$tokenCache['exp'] > time() + 60) {
            return ['ok' => true, 'token' => self::$tokenCache['token']];
        }
        $refresh = self::settings()['gdrive_refresh_token'] ?? '';
        if ($refresh === '') return ['ok' => false, 'error' => 'Google Drive belum dihubungkan.'];

        $r = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        if (!$r['ok']) return $r;
        self::$tokenCache = ['token' => $r['data']['access_token'], 'exp' => time() + (int) ($r['data']['expires_in'] ?? 3600)];
        return ['ok' => true, 'token' => self::$tokenCache['token']];
    }

    // Satu-satunya tempat cURL ke API Drive. Mengembalikan ['code' => HTTP, 'body' => string, 'error' => string|null].
    private static function rawRequest($method, $url, $token, $payload = null) {
        $ch = curl_init();
        $headers = ['Authorization: Bearer ' . $token];
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json; charset=UTF-8';
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['code' => (int) $code, 'body' => (string) $body, 'error' => $error !== '' ? $error : null];
    }

    private static function apiJson($method, $url, $token, $payload = null) {
        $r = self::rawRequest($method, $url, $token, $payload);
        if ($r['error']) return ['ok' => false, 'error' => 'Tidak bisa menghubungi Google Drive: ' . $r['error']];
        $decoded = json_decode($r['body'], true);
        if ($r['code'] !== 200 || empty($decoded['id'])) {
            return ['ok' => false, 'error' => $decoded['error']['message'] ?? ('HTTP ' . $r['code'])];
        }
        return ['ok' => true, 'data' => $decoded];
    }

    // Isi berkas buatan aplikasi ini (mentah), atau null bila tak terbaca -- mis. sudah dihapus di Drive.
    public static function download($fileId) {
        $tok = self::accessToken();
        if (!$tok['ok']) return null;
        $r = self::rawRequest('GET', self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media', $tok['token']);
        return ($r['code'] === 200 && !$r['error']) ? $r['body'] : null;
    }

    // Hapus berkas buatan aplikasi ini. Sudah tidak ada (404) dianggap berhasil.
    public static function delete($fileId) {
        $tok = self::accessToken();
        if (!$tok['ok']) return false;
        $r = self::rawRequest('DELETE', self::FILES_URL . '/' . rawurlencode($fileId), $tok['token']);
        return !$r['error'] && in_array($r['code'], [200, 204, 404], true);
    }

    // Membuat folder di Drive akun yang terhubung. $accessToken dari exchangeCode() atau accessToken().
    public static function createFolder($accessToken, $name = self::FOLDER_NAME) {
        return self::apiJson('POST', self::FILES_URL . '?fields=id', $accessToken, [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
    }

    // Foto biodata ditaruh di folder tersendiri, terpisah dari PDF. Folder itu dibuat saat foto pertama
    // diunggah (bukan saat "Hubungkan"), jadi koneksi yang sudah berjalan tidak perlu dihubungkan ulang.
    // ID folder tersimpan bisa usang (folder terhapus/masuk sampah, atau Drive dihubungkan ke akun lain):
    // bila unggahan gagal DAN folder itu memang sudah tak ada, dibuat folder baru lalu dicoba sekali lagi.
    public static function uploadPhoto($bytes, $filename) {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Google Drive belum dikonfigurasi.'];

        $tok = self::accessToken();
        if (!$tok['ok']) return ['ok' => false, 'error' => $tok['error']];

        $folderId = (string) (self::settings()['gdrive_photo_folder_id'] ?? '');
        if ($folderId !== '') {
            $up = self::upload($bytes, $filename, 'image/jpeg', $folderId);
            if ($up['ok'] || self::folderExists($tok['token'], $folderId)) return $up;
        }

        $folder = self::createFolder($tok['token'], self::PHOTO_FOLDER_NAME);
        if (!$folder['ok']) return ['ok' => false, 'error' => 'Gagal membuat folder foto di Drive: ' . $folder['error']];
        $folderId = $folder['data']['id'];
        (new SettingModel())->set('gdrive_photo_folder_id', $folderId);
        self::$settings['gdrive_photo_folder_id'] = $folderId;
        return self::upload($bytes, $filename, 'image/jpeg', $folderId);
    }

    public static function folderExists($accessToken, $folderId) {
        $r = self::apiJson('GET', self::FILES_URL . '/' . rawurlencode($folderId) . '?fields=id,trashed', $accessToken);
        return $r['ok'] && empty($r['data']['trashed']);
    }

    // $bytes: isi berkas mentah (string biner) -- PDF dari BiodataPdf::render() atau JPEG foto yang sudah
    // diproses Photo. Tidak pernah dibaca dari berkas simpanan di server, karena memang tidak disimpan di sana.
    // $folderId: folder tujuan; bila kosong, folder PDF utama.
    public static function upload($bytes, $filename, $mime = 'application/pdf', $folderId = null) {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Google Drive belum dikonfigurasi.'];

        $tok = self::accessToken();
        if (!$tok['ok']) return ['ok' => false, 'error' => $tok['error']];

        $folderId = $folderId ?? self::settings()['gdrive_folder_id'];
        $boundary = 'diwan-' . bin2hex(random_bytes(12));
        $metadata = json_encode(['name' => $filename, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);

        $body = "--$boundary\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $metadata . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: $mime\r\n\r\n"
              . $bytes . "\r\n"
              . "--$boundary--";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::UPLOAD_URL . '&fields=id,webViewLink',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $tok['token'],
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) return ['ok' => false, 'error' => 'Tidak bisa menghubungi Google Drive: ' . $error];
        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || empty($decoded['id'])) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            return ['ok' => false, 'error' => $msg];
        }

        return [
            'ok'      => true,
            'file_id' => $decoded['id'],
            'url'     => $decoded['webViewLink'] ?? ('https://drive.google.com/file/d/' . $decoded['id'] . '/view'),
        ];
    }

    // Tes sungguhan tanpa mengunggah apa pun: ambil access token DAN pastikan folder tujuan terbaca.
    public static function testConnection() {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Google Drive belum dihubungkan.'];
        $tok = self::accessToken();
        if (!$tok['ok']) return $tok;
        if (!self::folderExists($tok['token'], self::settings()['gdrive_folder_id'])) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak ditemukan (terhapus atau di tempat sampah). Hubungkan ulang untuk membuat folder baru.'];
        }
        return ['ok' => true];
    }
}
