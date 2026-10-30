<?php
// PATH LOKASI FILE: app/Core/Whatsapp.php
// Pengirim pesan WhatsApp lewat WA Gateway lokal (Baileys).

class Whatsapp {
    const TIMEOUT = 15;

    const SETTINGS_KEYS = [
        'wa_gateway_url',
        'wa_gateway_key',
        'wa_broadcast_delay_min',
        'wa_broadcast_delay_max',
        'wa_qr_display_minutes',
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
        return !empty($s['wa_gateway_url']) && !empty($s['wa_gateway_key']);
    }

    private static function gatewayUrl() {
        return rtrim(self::settings()['wa_gateway_url'] ?? '', '/');
    }

    private static function gatewayKey() {
        return self::settings()['wa_gateway_key'] ?? '';
    }

    private static function request($method, $path, $body = null, $timeout = null) {
        $url = self::gatewayUrl() . '/' . ltrim($path, '/');

        $headers = [
            'Authorization: ' . self::gatewayKey(),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout ?? self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'http' => 0, 'error' => 'Gateway tidak bisa dihubungi: ' . $error, 'data' => null];
        }

        $decoded = json_decode($response, true);
        $ok = !empty($decoded['ok']);
        return [
            'ok'    => $ok,
            'http'  => $httpCode,
            'error' => $ok ? null : ($decoded['error'] ?? $response),
            'data'  => $decoded,
        ];
    }

    public static function sendText($to, $text) {
        $phone = self::formatPhone($to);
        $body  = [
            'target'  => $phone,
            'message' => $text,
        ];

        $result = self::request('POST', 'send', $body);
        self::log($phone, 'notification', null, $body, $result);
        return $result;
    }

    public static function sendBroadcast($to, $text) {
        $phone = self::formatPhone($to);
        $s = self::settings();
        $body  = [
            'target'    => $phone,
            'message'   => $text,
            'delay_min' => (int) ($s['wa_broadcast_delay_min'] ?? 8),
            'delay_max' => (int) ($s['wa_broadcast_delay_max'] ?? 15),
        ];

        $result = self::request('POST', 'send-queued', $body, 120);
        self::log($phone, 'broadcast', null, $body, $result);
        return $result;
    }

    public static function testConnection() {
        return self::request('GET', 'status');
    }

    public static function requestPairingCode($phone) {
        $phone = Text::normalizePhone($phone);
        if ($phone === null) return ['ok' => false, 'error' => 'Nomor WhatsApp tidak valid.', 'data' => null];
        return self::request('POST', 'pairing-code', ['phone' => $phone], 30);
    }

    public static function formatPhone($phone) {
        $phone = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($phone, '0')) $phone = '62' . substr($phone, 1);
        if (!str_starts_with($phone, '62')) $phone = '62' . $phone;
        return $phone;
    }

    private static function log($phone, $type, $templateName, $payload, $result) {
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare("
                INSERT INTO whatsapp_log (message_id, phone, type, template_name, status, payload, response, error_message)
                VALUES (:mid, :ph, :tp, :tn, :st, :pl, :rs, :err)
            ")->execute([
                ':mid' => $result['data']['id'] ?? null,
                ':ph'  => $phone,
                ':tp'  => $type,
                ':tn'  => $templateName,
                ':st'  => $result['ok'] ? 'sent' : 'failed',
                ':pl'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':rs'  => json_encode($result, JSON_UNESCAPED_UNICODE),
                ':err' => $result['error'] ?? null,
            ]);
        } catch (PDOException $e) {
            error_log('Whatsapp::log gagal: ' . $e->getMessage());
        }
    }
}
