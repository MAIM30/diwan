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

    // Boleh menyalakan/mematikan verval pengajuan (RegistrationModel::vervalActive): Super Admin, atau admin_struktur yang lingkupnya berpangkal
    // di struktur pusat (tingkat bawaan sistem: DPP dan MWP, dikenali dari structure_levels.is_system, jadi singkatannya boleh diganti). Dibaca dari account_roles, bukan sesi: sesi hanya menyimpan GABUNGAN lingkup semua peran akun, jadi tak
    // bisa membedakan "admin DPW + verifikator DPP" dari "admin DPP". Pengaturannya berlaku untuk seluruh pendaftaran, bukan per struktur.
    public static function canManageVerval() {
        if (self::isSuperAdmin()) return true;
        if (!self::id() || !self::hasAnyRole(['admin_struktur'])) return false;
        $stmt = Database::getInstance()->getConnection()->prepare("
            SELECT COUNT(*) FROM account_roles ar
            JOIN structures s ON s.id = ar.scope_structure_id AND s.deleted_at IS NULL
            JOIN structure_levels sl ON sl.code = s.level AND sl.is_system = 1
            WHERE ar.account_id = :a AND ar.role = 'admin_struktur'
        ");
        $stmt->execute([':a' => (int) self::id()]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
