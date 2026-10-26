<?php
// PATH LOKASI FILE: app/Core/Csrf.php
// Token CSRF per sesi, diverifikasi terpusat di App untuk semua request non-GET.

class Csrf {
    const FIELD = '_csrf';

    public static function token() {
        if (empty($_SESSION[self::FIELD])) {
            $_SESSION[self::FIELD] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::FIELD];
    }

    // Input tersembunyi siap tempel di dalam <form>
    public static function field() {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }

    public static function verify() {
        $sent = $_POST[self::FIELD] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $session = $_SESSION[self::FIELD] ?? '';
        return $session !== '' && is_string($sent) && hash_equals($session, $sent);
    }

    public static function reject() {
        http_response_code(419);
        die('Sesi Anda sudah kedaluwarsa atau permintaan tidak sah. Silakan muat ulang halaman dan coba lagi.');
    }
}
