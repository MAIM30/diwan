<?php
// PATH LOKASI FILE: app/Core/Text.php
// Normalisasi nilai yang perlu dibandingkan atau dicocokkan: nama orang, nomor telepon, NIK.
// Aturan nama diambil dari strategi dedup impor SK syuro (database/import_sk_dpp_2026_2031.php)
// yang sudah terbukti pada ~600 baris data SK -- disatukan di sini supaya impor massal,
// pendaftaran publik, dan pencarian admin memakai aturan yang IDENTIK.

class Text {
    // Normalisasi ringan: rapikan spasi, lowercase. Untuk mendeteksi nama yang persis sama
    // (termasuk gelarnya) di dalam SATU batch impor -- artinya rangkap jabatan, bukan orang baru.
    public static function normalizeFull($s) {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
    }

    // Normalisasi dalam: buang gelar belakang (semua setelah koma pertama), buang gelar depan umum,
    // buang tanda baca. Untuk dicocokkan dengan data yang SUDAH ADA, yang seringkali tercatat tanpa
    // gelar. Hasilnya disimpan di people.name_normalized dan WAJIB dihitung ulang tiap nama berubah.
    public static function normalizeBase($s) {
        $s = trim((string) $s);
        $koma = strpos($s, ',');
        if ($koma !== false) {
            $s = substr($s, 0, $koma);
        }
        $s = mb_strtolower($s);
        $s = preg_replace('/\b(dr|drg|prof|ir|hj|h|kh|k\.h|ns|apt)\b\.?/u', '', $s);
        $s = preg_replace('/[^\p{L}\s]/u', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    // Nomor WA Indonesia ke bentuk E.164 tanpa tanda '+' ('628xxx'), supaya nomor yang sama tidak
    // tersimpan dalam tiga ejaan berbeda ('08xx', '+628xx', '628xx').
    // Mengembalikan null bila jelas bukan nomor yang bisa dipakai.
    public static function normalizePhone($s) {
        $d = preg_replace('/\D+/', '', (string) $s);
        if ($d === '') return null;
        if (strpos($d, '0') === 0)   $d = '62' . substr($d, 1);
        elseif (strpos($d, '62') !== 0) $d = '62' . $d;
        return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : null;
    }

    // NIK: tepat 16 digit. Tidak ada validasi isi (kode wilayah/tanggal lahir) karena data riil
    // kerap menyimpang dan penolakan di sini akan menghalangi pendaftaran yang sah.
    public static function normalizeNik($s) {
        $d = preg_replace('/\D+/', '', (string) $s);
        return strlen($d) === 16 ? $d : null;
    }

    // ---------- Nama: gelar depan | nama | gelar belakang ----------
    //
    // Data pengurus menyimpan tiga bagian terpisah (people.title_prefix, name, title_suffix); nama
    // lengkap hanya hasil gabungan. Memisahkannya membuat pencarian, pengurutan, dan deteksi data
    // ganda bekerja di atas NAMA ASLI, tidak terganggu "Dr." atau "M.Ag." yang menempel.

    // Gelar depan yang dikenali, ditulis tanpa titik. "M." sengaja TIDAK ada di sini: di data riil ia
    // hampir selalu singkatan "Muhammad" (mis. "M. Ali"), bukan Magister.
    const TITLE_PREFIXES = ['dr', 'drs', 'dra', 'drg', 'drh', 'prof', 'ir', 'h', 'hj', 'haji', 'hajjah',
                            'kh', 'ust', 'ustadz', 'ustadzah', 'ustz', 'ns', 'apt', 'dt', 'tgk'];

    // Gelar belakang tanpa tanda baca yang lazim ditulis tanpa titik. Dicocokkan persis (huruf besar).
    const TITLE_SUFFIX_PLAIN = ['Lc', 'MA', 'MM', 'MH', 'MT', 'MP', 'SE', 'SH', 'ST', 'SS', 'SP', 'SI', 'LLM'];

    // Gelar bertitik yang tiap ruasnya satu huruf. Bentuknya persis sama dengan inisial nama ("A.R."),
    // jadi hanya dianggap gelar bila ada di daftar ini.
    const TITLE_SUFFIX_PAIRS = ['M.A', 'M.M', 'M.H', 'M.T', 'M.E', 'M.P', 'M.D', 'S.E', 'S.H', 'S.T', 'S.S', 'S.I', 'S.K', 'S.P'];

    private static function titleKey($token) {
        return mb_strtolower(preg_replace('/[.\(\)\s,]/u', '', (string) $token));
    }

    // Memecah satu string nama lengkap menjadi ['title_prefix', 'name', 'title_suffix'].
    //
    // Aturan, dari yang paling pasti:
    //   1. Gelar belakang = semua yang ada SETELAH KOMA PERTAMA ("Ahmad Fauzi, S.Pd., M.Si.").
    //   2. Gelar depan   = token awal yang ada di TITLE_PREFIXES, berulang ("Prof. Dr. H. Ahmad ...").
    //   3. Tanpa koma, gelar belakang hanya ditebak dari token PENUTUP yang jelas berbentuk gelar:
    //      bertitik di dalamnya dengan dua ruas atau lebih (S.Pd, M.A., S.Pd.I) atau ada di
    //      TITLE_SUFFIX_PLAIN (Lc, MA). Inisial tunggal seperti "H." di ujung nama dibiarkan.
    //   Bila hasilnya mengosongkan nama (semua token ternyata gelar), string asli dikembalikan
    //   sebagai nama -- lebih baik gelar menempel daripada nama hilang.
    public static function splitName($raw) {
        $raw = trim(preg_replace('/\s+/u', ' ', (string) $raw));
        $original = $raw;
        if ($raw === '') {
            return ['title_prefix' => null, 'name' => '', 'title_suffix' => null];
        }

        $suffix = null;
        $comma = mb_strpos($raw, ',');
        if ($comma !== false) {
            $suffix = trim(mb_substr($raw, $comma + 1), " ,\t");
            $raw = trim(mb_substr($raw, 0, $comma));
        }

        $tokens = $raw === '' ? [] : explode(' ', $raw);

        // Gelar depan
        $prefix = [];
        while (count($tokens) > 1 && in_array(self::titleKey($tokens[0]), self::TITLE_PREFIXES, true)) {
            $prefix[] = array_shift($tokens);
        }

        // Gelar belakang tanpa koma: tebak dari token penutup yang jelas bentuknya.
        if ($suffix === null) {
            $tail = [];
            while (count($tokens) > 1) {
                $last = rtrim(end($tokens), ',');
                $isDotted = preg_match('/^[A-Z][A-Za-z]{0,2}\.[A-Za-z]{1,4}\.?([A-Za-z]{1,3}\.?)?$/u', $last) === 1
                    && (preg_match('/[A-Za-z]{2,}/', $last) === 1 || in_array(rtrim($last, '.'), self::TITLE_SUFFIX_PAIRS, true));
                $isPlain = in_array($last, self::TITLE_SUFFIX_PLAIN, true);
                if (!$isDotted && !$isPlain) break;
                array_unshift($tail, rtrim(array_pop($tokens), ','));
            }
            if ($tail) $suffix = implode(', ', $tail);
        }

        $name = trim(implode(' ', $tokens));
        if ($name === '') {
            return ['title_prefix' => null, 'name' => $original, 'title_suffix' => null];
        }

        return [
            'title_prefix' => $prefix ? implode(' ', $prefix) : null,
            'name'         => $name,
            'title_suffix' => ($suffix !== null && $suffix !== '') ? $suffix : null,
        ];
    }

    // Gabungan untuk tampilan polos. WAJIB sama dengan ekspresi kolom people.full_name di database
    // (lihat database/003_pisah_nama.php), supaya nama yang dihitung PHP dan yang dihitung MySQL
    // tidak pernah berbeda.
    public static function joinName($prefix, $name, $suffix) {
        $base = trim(trim((string) $prefix) . ' ' . trim((string) $name));
        $suffix = trim((string) $suffix);
        return $suffix === '' ? $base : $base . ', ' . $suffix;
    }

    // Merapikan tiga isian nama dari formulir. Bila pengguna menempelkan nama lengkap bergelar ke
    // kolom "Nama" ("Dr. H. Ahmad, Lc."), gelarnya dipindahkan otomatis ke kolom yang benar.
    // Mengembalikan [prefix|null, name, suffix|null, dipindah:bool].
    public static function normalizeNameParts($prefix, $name, $suffix) {
        $prefix = trim(preg_replace('/\s+/u', ' ', (string) $prefix));
        $name   = trim(preg_replace('/\s+/u', ' ', (string) $name));
        $suffix = trim(preg_replace('/\s+/u', ' ', (string) $suffix), " ,");
        $moved = false;

        $split = self::splitName($name);
        if ($split['title_prefix'] !== null || $split['title_suffix'] !== null) {
            if ($split['title_prefix'] !== null) {
                $prefix = trim($prefix . ' ' . $split['title_prefix']);
            }
            if ($split['title_suffix'] !== null) {
                $suffix = $suffix === '' ? $split['title_suffix'] : $suffix . ', ' . $split['title_suffix'];
            }
            $name = $split['name'];
            $moved = true;
        }

        return [$prefix !== '' ? $prefix : null, $name, $suffix !== '' ? $suffix : null, $moved];
    }

    // Inisial untuk avatar: huruf pertama dari dua kata pertama NAMA ASLI (bukan gelarnya).
    public static function initials($name) {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $out !== '' ? $out : '?';
    }

    // Menyamarkan NIK untuk jejak audit (Audit::redact), yang tidak menyimpan NIK utuh: 16 digit -> '3174********1234'.
    public static function maskNik($nik) {
        if (!is_string($nik) || strlen($nik) !== 16) return '';
        return substr($nik, 0, 4) . str_repeat('*', 8) . substr($nik, -4);
    }

    // Menyamarkan nomor telepon: '6281234500001' -> '62812*****001' (awal 5 + akhir 3 dibiarkan).
    public static function maskPhone($p) {
        $d = preg_replace('/\D+/', '', (string) $p);
        if (strlen($d) < 8) return $d === '' ? '' : str_repeat('*', strlen($d));
        return substr($d, 0, 5) . str_repeat('*', strlen($d) - 8) . substr($d, -3);
    }

    // 'muhammad@gmail.com' -> 'mu*******@gmail.com' (domain dibiarkan).
    public static function maskEmail($e) {
        $e = (string) $e;
        $at = strrpos($e, '@');
        if ($at === false || $at < 1) return $e === '' ? '' : str_repeat('*', mb_strlen($e));
        $local = mb_substr($e, 0, $at);
        return mb_substr($local, 0, min(2, mb_strlen($local))) . str_repeat('*', max(3, mb_strlen($local) - 2)) . substr($e, $at);
    }

    // IP biner (inet_pton) -> teks tersamar: '36.71.4.***' / '2001:db8:1:****'. Dipakai riwayat masuk
    // yang dilihat pemiliknya: cukup untuk mengenali "itu bukan jaringan saya".
    public static function maskIp($packed) {
        if ($packed === null || $packed === '') return '—';
        $ip = @inet_ntop($packed);
        if ($ip === false) return '—';
        if (strpos($ip, ':') === false) {
            $o = explode('.', $ip);
            return $o[0] . '.' . $o[1] . '.' . $o[2] . '.***';
        }
        return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':****';
    }

    // Escape untuk keluaran HTML. Dipakai di seluruh View -- disingkat supaya tidak ada alasan malas.
    public static function e($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // IP pemanggil, menghormati header proxy HANYA bila dikonfigurasi eksplisit di config.
    public static function clientIp() {
        if (TRUSTED_IP_HEADER !== '' && !empty($_SERVER[TRUSTED_IP_HEADER])) {
            $ip = trim(explode(',', $_SERVER[TRUSTED_IP_HEADER])[0]);
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }
}

// Alias pendek untuk View.
function e($s) { return Text::e($s); }
