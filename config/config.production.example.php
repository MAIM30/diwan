<?php
// CARA PAKAI: di SERVER, salin file ini menjadi config/config.local.php lalu isi nilainya.
// Jangan gunakan user "root" / password kosong di server.

define('BASEURL', 'https://diwan.wahdah.or.id');

define('DB_HOST', 'localhost');
define('DB_USER', 'GANTI_USER_DB');
define('DB_PASS', 'GANTI_PASSWORD_DB');
define('DB_NAME', 'GANTI_NAMA_DB');

// Zona waktu aplikasi (bawaan Asia/Makassar = WITA). Ubah hanya bila perlu.
// define('APP_TIMEZONE', 'Asia/Makassar');

// Domain ini diarahkan lewat Cloudflare -- aktifkan supaya pembatas login dan rate limit API
// memakai IP pengunjung asli, bukan IP proxy Cloudflare.
define('TRUSTED_IP_HEADER', 'HTTP_CF_CONNECTING_IP');

// Jangan tampilkan error PHP ke pengunjung; catat ke log server saja
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
