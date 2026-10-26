<?php
// PATH LOKASI FILE: app/Core/Database.php

class Database {
    private static $instance = null;
    private $conn;

    // Private constructor agar tidak bisa di-instansiasi menggunakan 'new' dari luar
    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // strict parameter typing
        ];

        try {
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Samakan zona waktu sesi MySQL dengan PHP (APP_TIMEZONE) agar NOW() dan kolom DATETIME
            // konsisten. Ini penting di aplikasi ini: kursor sinkronisasi API dibangun dari
            // updated_at, jadi selisih zona waktu akan membuat konsumen melewatkan perubahan.
            $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
            $this->conn->exec("SET time_zone = '" . $offset . "'");
        } catch (PDOException $e) {
            error_log('Koneksi DB gagal: ' . $e->getMessage());
            http_response_code(503);
            die('Koneksi Database Gagal. Silakan hubungi Administrator.');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}
