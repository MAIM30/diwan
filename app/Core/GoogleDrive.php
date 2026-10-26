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

    const SETTINGS_KEYS = [
        'gdrive_client_id',
        'gdrive_client_secret',
        'gdrive_refresh_token',
        'gdrive_folder_id',
    ];

    public static $settings;

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

    // Access token hanya berumur ~1 jam. Diambil ulang tiap panggilan -- alur ini jarang dipakai
    // (sekali per pengiriman biodata), jadi tidak perlu cache lintas-permintaan.
    private static function accessToken() {
        $refresh = self::settings()['gdrive_refresh_token'] ?? '';
        if ($refresh === '') return ['ok' => false, 'error' => 'Google Drive belum dihubungkan.'];

        $r = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'token' => $r['data']['access_token']];
    }

    private static function apiJson($method, $url, $token, $payload = null) {
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) return ['ok' => false, 'error' => 'Tidak bisa menghubungi Google Drive: ' . $error];
        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || empty($decoded['id'])) {
            return ['ok' => false, 'error' => $decoded['error']['message'] ?? ('HTTP ' . $httpCode)];
        }
        return ['ok' => true, 'data' => $decoded];
    }

    // Membuat folder tujuan di Drive akun yang baru terhubung. $accessToken dari exchangeCode().
    public static function createFolder($accessToken) {
        return self::apiJson('POST', self::FILES_URL . '?fields=id', $accessToken, [
            'name'     => self::FOLDER_NAME,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
    }

    public static function folderExists($accessToken, $folderId) {
        $r = self::apiJson('GET', self::FILES_URL . '/' . rawurlencode($folderId) . '?fields=id,trashed', $accessToken);
        return $r['ok'] && empty($r['data']['trashed']);
    }

    // $pdfBytes: isi PDF mentah (string biner, dari BiodataPdf::render() -- TIDAK PERNAH dibaca dari
    // berkas lokal, karena memang tidak pernah ditulis ke disk).
    public static function upload($pdfBytes, $filename) {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Google Drive belum dikonfigurasi.'];

        $tok = self::accessToken();
        if (!$tok['ok']) return ['ok' => false, 'error' => $tok['error']];

        $folderId = self::settings()['gdrive_folder_id'];
        $boundary = 'diwan-' . bin2hex(random_bytes(12));
        $metadata = json_encode(['name' => $filename, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);

        $body = "--$boundary\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $metadata . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: application/pdf\r\n\r\n"
              . $pdfBytes . "\r\n"
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
