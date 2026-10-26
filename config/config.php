<?php
// PATH LOKASI FILE: config/config.php
// Override khusus server (BASEURL, kredensial DB, pepper token). File config.local.php TIDAK ikut
// paket deploy dan dibuat manual di server dari config.production.example.php.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Nilai bawaan untuk pengembangan lokal (Laragon)
if (!defined('BASEURL')) define('BASEURL', 'http://localhost/pengurus');

// Nama aplikasi: satu sumber untuk judul halaman, logo, dan halaman masuk.
if (!defined('APP_SHORT')) define('APP_SHORT', 'DIWAN');
if (!defined('APP_NAME')) define('APP_NAME', 'DIWAN Wahdah Islamiyah');
if (!defined('APP_ACRONYM')) define('APP_ACRONYM', 'Data Induk Dewan Pimpinan Wahdah Islamiyah');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', 'Sistem Tata Kelola Data Kepengurusan Wahdah Islamiyah');

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'db_pengurus');

// Zona waktu aplikasi (WITA). Dipakai PHP dan disetel juga ke sesi MySQL di Database.php supaya
// keduanya selalu sama -- penting karena kursor sinkronisasi API memakai updated_at.
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Makassar');
date_default_timezone_set(APP_TIMEZONE);

// Isi dengan nama header IP asli HANYA bila server berada di belakang proxy/CDN tepercaya
// (mis. 'HTTP_CF_CONNECTING_IP' untuk Cloudflare). Dipakai pembatas percobaan login & rate limit API.
if (!defined('TRUSTED_IP_HEADER')) define('TRUSTED_IP_HEADER', '');

// Sumber data wilayah (provinsi, kota/kabupaten, kecamatan, kelurahan) untuk isian domisili: API statis wilayah.id (38 provinsi, mengikuti
// Kepmendagri No 300.2.2-2138 Tahun 2025). Dipanggil dari server dan disalin ke LOKASI_CACHE_DIR (lihat app/Core/Lokasi.php).
if (!defined('LOKASI_API_BASE')) define('LOKASI_API_BASE', 'https://wilayah.id/api');
if (!defined('LOKASI_CACHE_DIR')) define('LOKASI_CACHE_DIR', dirname(__DIR__) . '/storage/cache/lokasi');

// Nama struktur akar, dipakai sebagai label lingkup "Nasional" di antarmuka admin.
if (!defined('SCOPE_LABEL_ROOT')) define('SCOPE_LABEL_ROOT', 'Nasional');

// Batas bawaan jumlah baris per halaman di API (klien boleh meminta lebih kecil, tidak lebih besar).
if (!defined('API_PAGE_SIZE')) define('API_PAGE_SIZE', 200);
if (!defined('API_PAGE_SIZE_MAX')) define('API_PAGE_SIZE_MAX', 1000);

// ---------- Portal Pengurus (login pengurus dengan nomor WhatsApp) ----------
// Saklar global: false mematikan seluruh portal (halaman masuk menolak semua), tanpa menyentuh data.
if (!defined('PORTAL_ENABLED')) define('PORTAL_ENABLED', true);
// Sesi portal sengaja pendek: nomor WA dan NIK sama-sama bukan rahasia.
if (!defined('PORTAL_IDLE_MINUTES')) define('PORTAL_IDLE_MINUTES', 30);      // tanpa aktivitas -> keluar
if (!defined('PORTAL_SESSION_HOURS')) define('PORTAL_SESSION_HOURS', 8);     // batas mutlak sejak masuk
// Pembatas percobaan GAGAL per alamat IP (bukan per nomor -- nomor yang salah memang tidak ada orangnya,
// jadi menghitung per nomor tidak menghentikan penyapuan nomor).
if (!defined('PORTAL_FAIL_MAX_SHORT')) define('PORTAL_FAIL_MAX_SHORT', 8);   // gagal per 15 menit
if (!defined('PORTAL_FAIL_MAX_DAY')) define('PORTAL_FAIL_MAX_DAY', 30);      // gagal per 24 jam
