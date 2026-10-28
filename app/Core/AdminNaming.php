<?php
// PATH LOKASI FILE: app/Core/AdminNaming.php
// Aturan penamaan akun "admin bawaan sebuah struktur" -- satu-satunya definisinya, dipakai skrip
// database/provision_admins.php, tombol "Buat admin" di halaman Struktur, dan tes.
//
// NOMOR PENGGANTI. Login memakai nomor WhatsApp, tetapi admin bawaan dibuat SEBELUM ada orang yang
// ditunjuk, jadi belum ada nomor sungguhan. Daripada memakai nomor karangan yang bisa saja milik orang
// lain, dipakai blok yang TIDAK MUNGKIN dimiliki siapa pun: nomor nasional Indonesia tidak pernah
// diawali angka 0 (itu awalan "trunk"), jadi 62 + "0..." bukan nomor WA yang sah. Isinya id struktur:
//
//   struktur #2   -> disimpan 62000000002, diketik di kolom Nomor WA:  0000000002
//   struktur #39  -> disimpan 62000000039, diketik di kolom Nomor WA:  0000000039
//
// Begitu admin sungguhan ditunjuk, nomornya diganti lewat menu Akun (Ubah) -- tidak ada yang lain yang
// perlu berubah. Pembatas percobaan masuk (LoginThrottle) bekerja per nomor + IP seperti biasa.

class AdminNaming {
    const PHONE_PREFIX = '62000';   // 62 + 000 + id struktur 6 digit = 11 digit, sah menurut Text::normalizePhone

    public static function placeholderPhone($structureId) {
        return self::PHONE_PREFIX . sprintf('%06d', (int) $structureId);
    }

    // Yang diketik di kolom "Nomor WhatsApp" halaman masuk admin. normalizePhone mengubah awalan 0
    // menjadi 62, sehingga hasilnya persis placeholderPhone().
    public static function typedUsername($structureId) {
        return '0000' . sprintf('%06d', (int) $structureId);
    }

    public static function isPlaceholderPhone($phone) {
        return is_string($phone) && strpos($phone, self::PHONE_PREFIX) === 0 && strlen($phone) === 11;
    }

    private static function tierName($level) {
        return ['DPP' => 'Pusat', 'MWP' => 'Pusat', 'DPW' => 'Wilayah', 'DPLN' => 'Luar Negeri', 'MWW' => 'Wilayah', 'DPD' => 'Daerah', 'MWD' => 'Daerah',
                'DPC' => 'Cabang', 'MWC' => 'Cabang', 'DPRa' => 'Ranting', 'MWRa' => 'Ranting'][$level] ?? $level;
    }

    // "Admin Daerah: DPD Kota Kendari", "Admin Wilayah: MWW Sulawesi Tenggara", "Admin Pusat: MWP". Awalan
    // "Muslimah Wahdah Islamiyah Pusat/Wilayah/Daerah/..." dibuang karena tingkatnya sudah tertulis (MWP/MWW/MWD).
    public static function displayName($level, $name) {
        $short = trim(preg_replace('/^Muslimah Wahdah Islamiyah (Pusat|Wilayah|Daerah|Cabang|Ranting)\s*/u', '', (string) $name));
        return 'Admin ' . self::tierName($level) . ': ' . trim($level . ' ' . $short);
    }

    // Kata sandi acak: tanpa karakter yang mudah tertukar (0/O, 1/l/I), 16 karakter (~93 bit).
    public static function randomPassword($length = 16) {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }
}
