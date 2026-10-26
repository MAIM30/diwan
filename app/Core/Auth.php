<?php
// PATH LOKASI FILE: app/Core/Auth.php
// Sesi login pengelola data. SENGAJA hanya menangani "siapa yang masuk dan boleh apa di aplikasi
// INI" -- sumbu yang berbeda dari jabatan struktural seseorang di organisasi (itu ada di
// placements/positions). Di syuro keduanya pernah bercampur di satu kolom users.role; pemisahan di
// sini mencegahnya terulang: seorang Ketua Departemen belum tentu punya akun, dan seorang pemegang
// akun admin belum tentu pengurus.

class Auth {
    public static function login($account, $roles) {
        // Ganti ID sesi setelah login berhasil supaya ID sesi pra-login tidak bisa dipakai ulang.
        session_regenerate_id(true);

        $_SESSION['auth'] = [
            'account_id'   => (int) $account['id'],
            'uuid'         => $account['uuid'],
            'display_name' => $account['display_name'],
            'person_id'    => $account['person_id'] !== null ? (int) $account['person_id'] : null,
            'roles'        => array_values(array_unique(array_column($roles, 'role'))),
            // Kata sandi sementara: sampai diganti, requireLogin() hanya mengizinkan halaman ganti kata sandi.
            'must_change'  => !empty($account['must_change_password']),
        ];

        // Lingkup efektif dihitung SEKALI di sini, lalu dibaca dari sesi di tiap halaman.
        AdminScope::initSession($roles);
    }

    // Lihat Member::logout(): kosongkan + ganti ID sesi, supaya pesan sesudah keluar benar-benar sampai.
    public static function logout() {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function check() {
        return !empty($_SESSION['auth']['account_id']);
    }

    public static function mustChangePassword() {
        return !empty($_SESSION['auth']['must_change']);
    }

    public static function clearMustChange() {
        if (isset($_SESSION['auth'])) $_SESSION['auth']['must_change'] = false;
    }

    public static function id() {
        return $_SESSION['auth']['account_id'] ?? null;
    }

    public static function name() {
        return $_SESSION['auth']['display_name'] ?? null;
    }

    public static function personId() {
        return $_SESSION['auth']['person_id'] ?? null;
    }

    public static function roles() {
        return $_SESSION['auth']['roles'] ?? [];
    }

    public static function hasAnyRole(array $roles) {
        return (bool) array_intersect($roles, self::roles());
    }

    public static function isSuperAdmin() {
        return in_array('super_admin', self::roles(), true);
    }

    // Boleh mengubah data (bukan cuma melihat)? 'viewer' sengaja tidak termasuk.
    public static function canEdit() {
        return self::hasAnyRole(['super_admin', 'admin_struktur']);
    }

    // Boleh memverifikasi pendaftar?
    public static function canVerify() {
        return self::hasAnyRole(['super_admin', 'admin_struktur', 'verifikator']);
    }
}
