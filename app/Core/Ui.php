<?php
// PATH LOKASI FILE: app/Core/Ui.php
// Pembantu tampilan: ikon SVG, avatar, nama bergelar yang dipisah, tanggal & waktu relatif.
// Semua keluaran sudah di-escape -- aman dicetak apa adanya di View.

class Ui {
    // Ikon garis 24x24 (gaya Feather/Lucide), digambar dengan currentColor sehingga mengikuti warna
    // teks di sekitarnya. Sengaja disematkan inline: tanpa pustaka ikon, tanpa permintaan jaringan,
    // dan tidak ada CDN yang perlu dipercaya.
    private static $icons = [
        'home'      => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'network'   => '<rect x="9" y="3" width="6" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M5 16v-2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'calendar-check' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="M10.85 12.15L19 4M18 5l3 3M15 8l3 3"/>',
        'plug'      => '<path d="M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/>',
        'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-up'  => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'chevron-down'  => '<path d="M6 9l6 6 6-6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'log-out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'phone'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail'      => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M22 6l-10 7L2 6"/>',
        'map-pin'   => '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'id-card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M13 10h5M13 14h5"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'check'     => '<path d="M20 6L9 17l-5-5"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'     => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'camera'    => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'trending-up' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'clipboard-check' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>',
        'layers'    => '<path d="M12 2l10 5-10 5L2 7l10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'trash'     => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'filter'    => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
        'activity'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'   => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/>',
        'circle'    => '<circle cx="12" cy="12" r="9"/>',
        'message-circle' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'send'      => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
        'radio'     => '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 16.24a6 6 0 0 1 0-8.49"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 19.07a10 10 0 0 1 0-14.14"/>',
    ];

    public static function icon($name, $size = 18, $class = '') {
        $body = self::$icons[$name] ?? self::$icons['circle'];
        return '<svg class="ic' . ($class !== '' ? ' ' . e($class) : '') . '" width="' . (int) $size . '" height="' . (int) $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    // ---------- Nama ----------

    // [prefix, name, suffix] dari sebuah baris orang. Baris lama yang belum punya kolom terpisah
    // (mis. hasil query yang hanya memilih full_name) dipecah lewat Text::splitName().
    public static function nameParts(array $p) {
        if (isset($p['name']) && $p['name'] !== '') {
            return [$p['title_prefix'] ?? null, $p['name'], $p['title_suffix'] ?? null];
        }
        $x = Text::splitName($p['full_name'] ?? ($p['person_name'] ?? ''));
        return [$x['title_prefix'], $x['name'], $x['title_suffix']];
    }

    // Nama lengkap polos (tanpa HTML), untuk <option>, <title>, dan atribut.
    public static function fullName(array $p) {
        [$pre, $name, $suf] = self::nameParts($p);
        return Text::joinName($pre, $name, $suf);
    }

    // Nama inline dengan gelar dibuat redup: yang menonjol adalah NAMA ASLI, gelarnya mendampingi.
    public static function nameHtml(array $p) {
        [$pre, $name, $suf] = self::nameParts($p);
        return '<span class="pn">'
            . ($pre ? '<span class="pt">' . e($pre) . '</span> ' : '')
            . '<span class="pc">' . e($name) . '</span>'
            . ($suf ? '<span class="pt">, ' . e($suf) . '</span>' : '')
            . '</span>';
    }

    // Baris gelar untuk di bawah nama: "Dr. · Lc., M.A." (kosong bila tak bergelar).
    public static function titlesLine(array $p) {
        [$pre, , $suf] = self::nameParts($p);
        $parts = array_filter([$pre, $suf], function ($x) { return $x !== null && $x !== ''; });
        return implode(' · ', array_map('strval', $parts));
    }

    // ---------- Avatar ----------

    public static function avatar(array $p, $size = 'md') {
        [, $name] = self::nameParts($p);
        $seed = (string) ($p['uuid'] ?? $p['person_uuid'] ?? $p['id'] ?? $name);
        $hue = abs(crc32($seed)) % 6;
        $photo = $p['photo_path'] ?? '';

        if ($photo !== '' && $photo !== null) {
            $file = ROOT_DIR . '/public/' . $photo;
            $v = is_file($file) ? '?v=' . filemtime($file) : '';
            return '<span class="av av-' . e($size) . '"><img src="' . BASEURL . '/' . e($photo) . $v . '" alt="" loading="lazy"></span>';
        }
        return '<span class="av av-' . e($size) . ' av-c' . $hue . '" aria-hidden="true">' . e(Text::initials($name)) . '</span>';
    }

    // Sel tabel/daftar: avatar + nama asli + baris gelar (atau $sub bila diberikan).
    public static function personCell(array $p, $href = null, $sub = null, $size = 'md') {
        $name = '<span class="pcell-name">' . ($href ? '<a href="' . e($href) . '">' : '')
              . self::nameOnly($p) . ($href ? '</a>' : '') . '</span>';
        $line = $sub !== null ? $sub : e(self::titlesLine($p));
        return '<div class="pcell">' . self::avatar($p, $size) . '<div class="pcell-text">' . $name
            . ($line !== '' ? '<span class="pcell-sub">' . $line . '</span>' : '') . '</div></div>';
    }

    // Hanya nama asli (tanpa gelar), untuk tempat di mana gelar ditampilkan terpisah.
    public static function nameOnly(array $p) {
        [, $name] = self::nameParts($p);
        return e($name);
    }

    // ---------- Waktu ----------

    private static $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    private static $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    public static function date($d) {
        $t = is_numeric($d) ? (int) $d : strtotime((string) $d);
        if (!$t) return '—';
        return date('j', $t) . ' ' . self::$months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    public static function dateLong($t = null) {
        $t = $t ?: time();
        return self::$days[(int) date('w', $t)] . ', ' . self::date($t);
    }

    public static function monthShort($n) {
        return self::$months[((int) $n - 1) % 12];
    }

    public static function ago($datetime) {
        $t = strtotime((string) $datetime);
        if (!$t) return '';
        $d = time() - $t;
        if ($d < 60)     return 'baru saja';
        if ($d < 3600)   return floor($d / 60) . ' mnt lalu';
        if ($d < 86400)  return floor($d / 3600) . ' jam lalu';
        if ($d < 604800) return floor($d / 86400) . ' hari lalu';
        return self::date($t);
    }

    // Salam pembuka dasbor admin dan beranda portal: tetap sepanjang hari.
    public static function greeting() {
        return 'Assalamu alaykum';
    }

    // ---------- Angka & label ----------

    public static function n($x) {
        return number_format((float) $x, 0, ',', '.');
    }

    // Durasi singkat dari jumlah hari: "2 th 4 bln", "9 bln", "12 hr". Bulan dihitung 30 hari --
    // cukup untuk sisa masa jabatan, bukan untuk akuntansi tanggal.
    public static function span($days) {
        $days = max(0, (int) $days);
        if ($days >= 365) {
            $y = intdiv($days, 365);
            $m = intdiv($days % 365, 30);
            return $y . ' th' . ($m > 0 ? ' ' . $m . ' bln' : '');
        }
        if ($days >= 60) return intdiv($days, 30) . ' bln';
        return $days . ' hr';
    }

    // Label kolom data diri yang boleh diubah pengurus sendiri (Portal) -- dipakai form, riwayat, dan umpan admin.
    const PROFILE_LABELS = [
        'address_text' => 'Jalan/BTN/Lorong', 'village' => 'Kelurahan/Desa', 'district' => 'Kecamatan', 'city' => 'Kota/Kabupaten',
        'province' => 'Provinsi', 'birth_place' => 'Tempat lahir', 'birth_date' => 'Tanggal lahir', 'email' => 'Email',
        'occupation' => 'Pekerjaan', 'family_phone' => 'HP keluarga',
    ];

    // Alamat lengkap satu baris: "Jl. X, Kel/Desa Y, Kec. Z, Kabupaten W, Provinsi". Bagian kosong dilewati. Kota/Kabupaten
    // dan Provinsi ditulis apa adanya (nama resmi dari data wilayah sudah memuat "Kabupaten"/"Kota").
    public static function address(array $p) {
        $parts = [];
        if (!empty($p['address_text'])) $parts[] = $p['address_text'];
        if (!empty($p['village'])) $parts[] = 'Kel/Desa ' . $p['village'];
        if (!empty($p['district'])) $parts[] = 'Kec. ' . $p['district'];
        if (!empty($p['city'])) $parts[] = $p['city'];
        if (!empty($p['province'])) $parts[] = $p['province'];
        return implode(', ', $parts);
    }

    public static function tierName($tier) {
        return [1 => 'Pusat', 2 => 'Wilayah', 3 => 'Daerah', 4 => 'Cabang', 5 => 'Ranting'][(int) $tier] ?? '—';
    }

    public static function roleLabel($role) {
        return [
            'super_admin'    => 'Super Admin',
            'admin_struktur' => 'Admin Struktur',
            'verifikator'    => 'Verifikator',
            'viewer'         => 'Peninjau',
        ][$role] ?? $role;
    }

    // Peran tertinggi akun yang sedang masuk, untuk ditampilkan di topbar.
    public static function primaryRoleLabel() {
        foreach (['super_admin', 'admin_struktur', 'verifikator', 'viewer'] as $r) {
            if (in_array($r, Auth::roles(), true)) return self::roleLabel($r);
        }
        return '';
    }

    public static function firstName($displayName) {
        $x = Text::splitName($displayName);
        $first = preg_split('/\s+/u', $x['name'], -1, PREG_SPLIT_NO_EMPTY);
        return $first[0] ?? $displayName;
    }
}
