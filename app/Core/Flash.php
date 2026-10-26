<?php
// PATH LOKASI FILE: app/Core/Flash.php
// Pesan sekali-tampil antar redirect (hasil simpan, penolakan, dsb).

class Flash {
    public static function set($type, $message) {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    // Mengambil SEKALIGUS menghapus -- dipanggil sekali per halaman, oleh layouts/flash.php.
    public static function take() {
        $all = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $all;
    }
}
