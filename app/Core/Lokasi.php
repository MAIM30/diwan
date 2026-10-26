<?php
// PATH LOKASI FILE: app/Core/Lokasi.php
// Data wilayah administratif untuk isian domisili: provinsi -> kota/kabupaten -> kecamatan -> kelurahan/desa.
//
// Sumbernya API statis (LOKASI_API_BASE, bawaan wilayah.id: 38 provinsi, kode dan nama mengikuti Kepmendagri No 300.2.2-2138 Tahun 2025).
// Dipanggil dari SERVER, bukan peramban: API itu tidak menyatakan CORS, batas pemakaian, atau jaminan ketersediaan, dan lewat server
// alamat IP pengguna tidak sampai ke pihak ketiga. Tiap daftar disalin ke LOKASI_CACHE_DIR (di luar folder publik) dan dipakai ulang
// FRESH_DAYS hari; bila API gagal, salinan yang sudah kedaluwarsa tetap dilayani daripada mematikan isian.
//
// Kode induk yang diminta DIVERIFIKASI dulu terhadap daftar induknya (provinsi -> kabupaten -> kecamatan). Tanpa itu, siapa pun yang
// sudah masuk bisa meminta kode ngawur dan memaksa server menembak API tanpa henti atau menumpuk berkas cache.

class Lokasi {
    const FRESH_DAYS = 90;
    const MAX_BYTES = 2097152;
    const MAX_ITEMS = 20000;

    // tingkat => [jalur di API, pola kode INDUK]. Provinsi tak punya induk.
    const LEVELS = [
        'provinsi'  => ['provinces', null],
        'kabupaten' => ['regencies', '/^\d{2}$/'],
        'kecamatan' => ['districts', '/^\d{2}\.\d{2}$/'],
        'kelurahan' => ['villages',  '/^\d{2}\.\d{2}\.\d{2}$/'],
    ];

    // Hasil: ['status' => 200, 'data' => [['code' =>, 'name' =>], ...], 'stale' => bool]
    //   atau ['status' => 400|404|503]: kode tak sah | induk tak ada | sumber tak bisa dihubungi dan tak ada salinan.
    public static function get($level, $parent = '') {
        if (!isset(self::LEVELS[$level])) return ['status' => 400];
        [, $pattern] = self::LEVELS[$level];
        $parent = (string) $parent;
        if ($pattern === null ? $parent !== '' : !preg_match($pattern, $parent)) return ['status' => 400];

        if ($pattern !== null) {
            $up = self::parentExists($level, $parent);
            if ($up === null) return ['status' => 503];
            if ($up === false) return ['status' => 404];
        }
        $r = self::load($level, $parent);
        return $r === null ? ['status' => 503] : ['status' => 200, 'data' => $r['data'], 'stale' => $r['stale']];
    }

    // Apakah $code (kode di tingkat sebelum $level) ada? true | false | null (tak bisa dipastikan: sumber tak terjangkau).
    private static function parentExists($level, $code) {
        $levels = array_keys(self::LEVELS);
        $i = array_search($level, $levels, true) - 1;           // tingkat milik $code
        $grand = '';
        if ($i > 0) {
            $grand = substr($code, 0, strrpos($code, '.'));
            $g = self::parentExists($levels[$i], $grand);       // kakeknya harus ada dulu
            if ($g !== true) return $g;
        }
        $r = self::load($levels[$i], $grand);
        if ($r === null) return null;
        foreach ($r['data'] as $row) if ($row['code'] === $code) return true;
        return false;
    }

    // Satu daftar: salinan segar -> API -> salinan lama. null bila tak satu pun tersedia.
    private static function load($level, $parent) {
        $file = self::cacheFile($level, $parent);
        $cached = is_file($file) ? self::readCache($file) : null;
        if ($cached !== null && (time() - filemtime($file)) < self::FRESH_DAYS * 86400) return ['data' => $cached, 'stale' => false];

        $fresh = self::fetchList($level, $parent);
        if ($fresh !== null) { self::writeCache($file, $fresh); return ['data' => $fresh, 'stale' => false]; }
        return $cached !== null ? ['data' => $cached, 'stale' => true] : null;
    }

    private static function cacheFile($level, $parent) {
        $dir = defined('LOKASI_CACHE_DIR') ? LOKASI_CACHE_DIR : ROOT_DIR . '/storage/cache/lokasi';
        return rtrim($dir, '/\\') . '/' . $level . ($parent !== '' ? '_' . $parent : '') . '.json';
    }

    private static function readCache($file) {
        $d = json_decode((string) @file_get_contents($file), true);
        return self::clean($d, null);
    }

    private static function writeCache($file, array $data) {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['data' => $data], JSON_UNESCAPED_UNICODE)) === false) return;
        if (!@rename($tmp, $file)) @unlink($tmp);
    }

    // Mengambil dari API dan memvalidasi bentuknya; null bila gagal atau isinya tak masuk akal (tidak pernah dicache).
    private static function fetchList($level, $parent) {
        $base = defined('LOKASI_API_BASE') ? rtrim(LOKASI_API_BASE, '/') : '';
        if (!preg_match('#^https?://#', $base)) return null;
        // $parent sudah lolos pola (angka dan titik) -- aman dirangkai ke URL.
        $url = $base . '/' . self::LEVELS[$level][0] . ($parent !== '' ? '/' . $parent : '') . '.json';
        $body = self::httpGet($url);
        if ($body === null) return null;
        $list = self::clean(json_decode($body, true), $parent);
        if ($list === null) { error_log('[lokasi] isi tak sah dari ' . $url); return null; }
        return $list;
    }

    // Menyaring ke [{code, name}] terurut nama. $parent !== null: tiap kode wajib berawalan "<parent>." (bukan sekadar daftar acak).
    private static function clean($d, $parent) {
        if (!is_array($d) || !isset($d['data']) || !is_array($d['data']) || count($d['data']) === 0 || count($d['data']) > self::MAX_ITEMS) return null;
        $out = [];
        foreach ($d['data'] as $row) {
            if (!is_array($row) || !isset($row['code'], $row['name']) || !is_string($row['code']) || !is_string($row['name'])) return null;
            $code = $row['code']; $name = trim($row['name']);
            if (!preg_match('/^\d{2}(\.\d{2,4}){0,3}$/', $code) || $name === '' || mb_strlen($name) > 150) return null;
            if ($parent !== null && $parent !== '' && strpos($code, $parent . '.') !== 0) return null;
            $out[] = ['code' => $code, 'name' => $name];
        }
        usort($out, function ($a, $b) { return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])); });
        return $out;
    }

    private static function httpGet($url) {
        $ua = 'DIWAN-WI/1.0 (+' . (defined('BASEURL') ? BASEURL : '') . ')';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code !== 200 || strlen($body) > self::MAX_BYTES) { error_log("[lokasi] $url -> kode $code $err"); return null; }
            return $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => $ua, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES + 1);
        $ok = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
        if ($body === false || !$ok || strlen($body) > self::MAX_BYTES) { error_log("[lokasi] $url gagal"); return null; }
        return $body;
    }
}
