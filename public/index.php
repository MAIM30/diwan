<?php
// PATH LOKASI FILE: public/index.php
// Front controller tunggal: seluruh rute aplikasi masuk ke sini lewat .htaccess.

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/config/config.php';

// Core dimuat di awal karena hampir semua request memerlukannya. Model & Controller dimuat
// on-demand lewat Controller::model() dan App, pola yang sama dengan syuro.
require_once ROOT_DIR . '/app/Core/Database.php';
require_once ROOT_DIR . '/app/Core/Controller.php';
require_once ROOT_DIR . '/app/Core/Csrf.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Photo.php';
require_once ROOT_DIR . '/app/Core/AdminNaming.php';
require_once ROOT_DIR . '/app/Core/SkFile.php';
require_once ROOT_DIR . '/app/Core/Lokasi.php';
require_once ROOT_DIR . '/app/Core/Susunan.php';
require_once ROOT_DIR . '/app/Core/Ui.php';
require_once ROOT_DIR . '/app/Core/Chart.php';
require_once ROOT_DIR . '/app/Core/Flash.php';
require_once ROOT_DIR . '/app/Core/Auth.php';
require_once ROOT_DIR . '/app/Core/Member.php';
require_once ROOT_DIR . '/app/Core/MemberThrottle.php';
require_once ROOT_DIR . '/app/Core/AdminScope.php';
require_once ROOT_DIR . '/app/Core/Audit.php';
require_once ROOT_DIR . '/app/Core/Otp.php';
require_once ROOT_DIR . '/app/Core/Whatsapp.php';
require_once ROOT_DIR . '/app/Core/WaNotify.php';
require_once ROOT_DIR . '/app/Core/BiodataPdf.php';
require_once ROOT_DIR . '/app/Core/GoogleDrive.php';
require_once ROOT_DIR . '/app/Core/App.php';

// Sesi: cookie dikeraskan sebisanya tanpa menuntut HTTPS di localhost.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
]);
session_start();

new App();
