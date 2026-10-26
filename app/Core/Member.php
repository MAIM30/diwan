<?php
// PATH LOKASI FILE: app/Core/Member.php
// Sesi Portal Pengurus (pengurus yang masuk dengan nomor WA). SENGAJA terpisah dari Auth (akun admin):
//
//   * Auth   -> $_SESSION['auth']   : akun pengelola data, punya peran + lingkup, boleh MENULIS data.
//   * Member -> $_SESSION['member'] : seorang pengurus, boleh melihat datanya sendiri dan mengubah sebagian
//                                     isian pribadinya (daftar putih MemberModel::SELF_FIELDS), tidak lebih.
//
// Kunci sesinya berbeda dan tidak ada satu pun pemeriksaan hak admin yang membaca kunci 'member',
// jadi sesi pengurus tidak pernah dianggap sesi admin -- walau keduanya hidup di browser yang sama.
//
// Mengapa sesinya pendek: nomor WA (dan NIK) bukan rahasia dan tidak ada faktor kedua. Yang bisa
// dikerjakan hanyalah memperkecil jendela penyalahgunaan: sesi habis bila
// menganggur (PORTAL_IDLE_MINUTES) dan ada batas mutlak sejak masuk (PORTAL_SESSION_HOURS).

class Member {
    const KEY = 'member';

    public static function login(array $person) {
        // Ganti ID sesi setelah masuk supaya ID pra-login tidak bisa dipakai ulang (session fixation).
        session_regenerate_id(true);
        $_SESSION[self::KEY] = [
            'person_id' => (int) $person['id'],
            'uuid'      => $person['uuid'],
            'since'     => time(),
            'last'      => time(),
        ];
    }

    // Masih sah? Sesi yang kedaluwarsa dibersihkan di sini juga, dengan pesan untuk pemiliknya.
    public static function check() {
        $m = $_SESSION[self::KEY] ?? null;
        if (!is_array($m) || empty($m['person_id'])) return false;

        $now = time();
        if ($now - (int) $m['last'] > PORTAL_IDLE_MINUTES * 60) {
            unset($_SESSION[self::KEY]);
            Flash::set('info', 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk lagi.');
            return false;
        }
        if ($now - (int) $m['since'] > PORTAL_SESSION_HOURS * 3600) {
            unset($_SESSION[self::KEY]);
            Flash::set('info', 'Sesi Anda telah berakhir. Silakan masuk lagi.');
            return false;
        }
        return true;
    }

    // Menandai aktivitas (menggeser batas menganggur). Dipanggil per permintaan halaman.
    public static function touch() {
        if (isset($_SESSION[self::KEY])) $_SESSION[self::KEY]['last'] = time();
    }

    public static function id() {
        return isset($_SESSION[self::KEY]['person_id']) ? (int) $_SESSION[self::KEY]['person_id'] : null;
    }

    // Keluar dan MENGHANCURKAN seluruh sesi (termasuk sesi admin bila ada di browser yang sama):
    // di komputer bersama, "keluar" harus berarti keluar dari semuanya.
    //
    // Caranya: kosongkan data lalu ganti ID sesi dengan session_regenerate_id(true), yang MENGHAPUS
    // berkas sesi lama dan menerbitkan ID baru yang kosong. Bukan session_destroy() + menghapus cookie:
    // cara itu membuat sesi pengganti (pembawa pesan "Anda sudah keluar") memakai ID yang cookie-nya
    // baru saja dihapus, sehingga pesannya tidak pernah sampai ke peramban.
    public static function logout() {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
