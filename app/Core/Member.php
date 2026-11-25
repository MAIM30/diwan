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
// Sesi pengurus tetap terpisah dari sesi admin. Setelah OTP, login dipulihkan lewat token acak
// per peramban sampai pemilik keluar atau akses portalnya dicabut. Token mentah hanya ada di cookie
// HttpOnly; basis data menyimpan hash-nya agar token yang keluar bisa dicabut saat logout.

class Member {
    const KEY = 'member';
    const REMEMBER_COOKIE = 'diwan_member_remember';
    const REMEMBER_SECONDS = 315360000; // 10 tahun; logout atau pencabutan akses mengakhiri sesi lebih dulu.

    private static function db() {
        return Database::getInstance()->getConnection();
    }

    private static function cookiePath() {
        $path = parse_url(BASEURL, PHP_URL_PATH);
        if (!$path) return '/';
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path;
    }

    private static function cookieOptions($expires) {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
        return [
            'expires'  => (int) $expires,
            'path'     => self::cookiePath(),
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function validToken($token) {
        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
    }

    private static function validHash($hash) {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/i', $hash) === 1;
    }

    private static function setRememberCookie($token, $expires) {
        setcookie(self::REMEMBER_COOKIE, $token, self::cookieOptions($expires));
        if ($expires > time()) $_COOKIE[self::REMEMBER_COOKIE] = $token;
    }

    private static function clearRememberCookie() {
        setcookie(self::REMEMBER_COOKIE, '', self::cookieOptions(time() - 3600));
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function deleteRememberHash($hash) {
        if (!self::validHash($hash)) return;
        self::db()->prepare('DELETE FROM portal_remember_tokens WHERE token_hash = :hash')->execute([':hash' => $hash]);
    }

    // Cabut token milik sesi ini dan hapus cookie perangkat saat login baru atau logout.
    private static function revokeRememberCookie() {
        $hashes = [];
        $sessionMember = $_SESSION[self::KEY] ?? null;
        if (is_array($sessionMember) && self::validHash($sessionMember['remember_hash'] ?? null)) {
            $hashes[] = $sessionMember['remember_hash'];
        }
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (self::validToken($token)) {
            $cookieHash = hash('sha256', $token);
            if (!in_array($cookieHash, $hashes, true)) $hashes[] = $cookieHash;
        }
        foreach ($hashes as $hash) self::deleteRememberHash($hash);
        self::clearRememberCookie();
    }

    private static function issueRememberToken($personId) {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        self::db()->prepare('INSERT INTO portal_remember_tokens (person_id, token_hash) VALUES (:person_id, :hash)')
            ->execute([':person_id' => (int) $personId, ':hash' => $hash]);
        self::setRememberCookie($token, time() + self::REMEMBER_SECONDS);
        return $hash;
    }

    private static function eligiblePerson($personId) {
        $stmt = self::db()->prepare("SELECT id, uuid FROM people
            WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND portal_enabled = 1 LIMIT 1");
        $stmt->execute([':id' => (int) $personId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Pulihkan sesi PHP setelah browser dibuka lagi atau sesi PHP dibersihkan server. Token yang tidak
    // cocok dengan orang aktif, izin portal, dan status saat ini langsung dicabut.
    private static function restoreRememberedMember() {
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!self::validToken($token)) {
            if (isset($_COOKIE[self::REMEMBER_COOKIE])) self::clearRememberCookie();
            return false;
        }

        $hash = hash('sha256', $token);
        $stmt = self::db()->prepare("SELECT p.id, p.uuid
            FROM portal_remember_tokens t
            JOIN people p ON p.id = t.person_id
            WHERE t.token_hash = :hash AND p.deleted_at IS NULL AND p.status = 'active' AND p.portal_enabled = 1
            LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            self::deleteRememberHash($hash);
            self::clearRememberCookie();
            return false;
        }

        // Jangan pertahankan ID sesi yang dibuat sebelum cookie pemulihan diverifikasi.
        session_regenerate_id(true);
        $now = time();
        $_SESSION[self::KEY] = [
            'person_id'    => (int) $person['id'],
            'uuid'         => $person['uuid'],
            'since'        => $now,
            'last'         => $now,
            'remember_hash'=> $hash,
        ];
        self::db()->prepare('UPDATE portal_remember_tokens SET last_used_at = CURRENT_TIMESTAMP(3) WHERE token_hash = :hash')
            ->execute([':hash' => $hash]);
        // Perpanjang masa cookie setiap sesi dipulihkan, tanpa memperpanjang sesi admin.
        self::setRememberCookie($token, time() + self::REMEMBER_SECONDS);
        return true;
    }

    public static function login(array $person) {
        self::revokeRememberCookie();
        // Ganti ID sesi setelah masuk supaya ID pra-login tidak bisa dipakai ulang (session fixation).
        session_regenerate_id(true);
        $rememberHash = self::issueRememberToken($person['id']);
        $_SESSION[self::KEY] = [
            'person_id'     => (int) $person['id'],
            'uuid'          => $person['uuid'],
            'since'         => time(),
            'last'          => time(),
            'remember_hash' => $rememberHash,
        ];
    }

    // Sesi pengurus tidak kedaluwarsa karena idle atau umur. Login selesai hanya setelah logout,
    // token perangkat dicabut, atau guard mendapati status/izin portal orang ini berubah.
    public static function check() {
        $m = $_SESSION[self::KEY] ?? null;
        if (is_array($m) && !empty($m['person_id'])) {
            $hash = $m['remember_hash'] ?? null;
            if (!self::validHash($hash)) {
                // Sesi yang masih hidup saat deploy dinaikkan ke sesi persisten tanpa meminta OTP lagi.
                $person = self::eligiblePerson($m['person_id']);
                if (!$person) {
                    unset($_SESSION[self::KEY]);
                    return false;
                }
                self::revokeRememberCookie();
                $hash = self::issueRememberToken($person['id']);
                $_SESSION[self::KEY]['uuid'] = $person['uuid'];
                $_SESSION[self::KEY]['remember_hash'] = $hash;
                return true;
            }

            $stmt = self::db()->prepare("SELECT 1 FROM portal_remember_tokens t
                JOIN people p ON p.id = t.person_id
                WHERE t.token_hash = :hash AND t.person_id = :person_id
                  AND p.deleted_at IS NULL AND p.status = 'active' AND p.portal_enabled = 1
                LIMIT 1");
            $stmt->execute([':hash' => $hash, ':person_id' => (int) $m['person_id']]);
            if ($stmt->fetchColumn()) return true;
            unset($_SESSION[self::KEY]);
            return self::restoreRememberedMember();
        }
        unset($_SESSION[self::KEY]);
        return self::restoreRememberedMember();
    }

    // Catat aktivitas pada sesi berjalan. Sesi tidak lagi diakhiri berdasarkan aktivitas.
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
        self::revokeRememberCookie();
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
