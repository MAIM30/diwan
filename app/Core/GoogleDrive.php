<?php
// PATH LOKASI FILE: app/Core/GoogleDrive.php
// Unggah berkas ke Google Drive lewat service account -- tanpa Composer, tanpa SDK: cURL + openssl
// untuk alur OAuth2 "JWT Bearer" (RFC 7523), pola yang sama seperti Whatsapp::request().
//
// Scope sengaja SEMPIT (drive.file): service account ini hanya bisa menyentuh berkas yang DIBUATNYA
// sendiri, bukan seluruh Drive -- jadi kunci JSON yang bocor tidak membuka seluruh isi akun Drive
// pemiliknya. Folder tujuan harus di-share manual ke email service account (lihat /settings/gdrive).

class GoogleDrive {
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
    const SCOPE      = 'https://www.googleapis.com/auth/drive.file';
    const TIMEOUT     = 30;

    const SETTINGS_KEYS = [
        'gdrive_service_account_json',
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

    public static function isConfigured() {
        $s = self::settings();
        return !empty($s['gdrive_service_account_json']) && !empty($s['gdrive_folder_id']);
    }

    private static function credentials() {
        $json = self::settings()['gdrive_service_account_json'] ?? '';
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) return null;
        return $data;
    }

    private static function base64url($raw) {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // Menukar kunci service account dengan access token berumur pendek (~1 jam). Diambil ulang
    // setiap panggilan upload() -- alur ini jarang dipakai (sekali per pengiriman biodata), jadi
    // tidak perlu cache token lintas-permintaan.
    private static function accessToken() {
        $cred = self::credentials();
        if (!$cred) return ['ok' => false, 'error' => 'Kredensial service account belum diisi/tidak sah.'];

        $now = time();
        $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64url(json_encode([
            'iss'   => $cred['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $unsigned = $header . '.' . $claims;

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $cred['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) return ['ok' => false, 'error' => 'Gagal menandatangani JWT (private_key tidak sah).'];

        $jwt = $unsigned . '.' . self::base64url($signature);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::TOKEN_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
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
        return ['ok' => true, 'token' => $decoded['access_token']];
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

    // Tes kredensial tanpa benar-benar mengunggah apa pun -- cukup minta access token.
    public static function testCredentials() {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'URL/JSON service account dan ID folder harus diisi dulu.'];
        return self::accessToken();
    }
}
