<?php
// PATH LOKASI FILE: app/Core/Uuid.php
// Pembangkit UUID tanpa Composer. Aplikasi ini sengaja tidak memakai dependency manager (konsisten
// dengan syuro), dan UUID v4 cukup 6 baris dengan random_bytes() yang sudah ada di PHP.

class Uuid {
    // UUID v4 (acak). Dipakai untuk SEMUA entitas yang dipublikasikan ke API: begitu diterbitkan,
    // nilainya tidak boleh berubah sepanjang umur baris -- itulah janji yang dipegang konsumen
    // (syuro dll) saat menyimpan salinan lokalnya.
    public static function v4() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // versi 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // varian RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function isValid($value) {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    // Token API: prefix yang boleh disimpan apa adanya untuk pencarian baris + rahasia yang hanya
    // disimpan sebagai hash. Nilai utuhnya ditampilkan SATU KALI saat token dibuat.
    public static function apiToken() {
        $prefix = substr(bin2hex(random_bytes(8)), 0, 12);
        $secret = bin2hex(random_bytes(32));
        return [
            'plain'  => 'pgr_' . $prefix . '_' . $secret,
            'prefix' => $prefix,
            'hash'   => hash('sha256', 'pgr_' . $prefix . '_' . $secret),
        ];
    }
}
