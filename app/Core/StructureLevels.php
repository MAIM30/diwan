<?php
// PATH LOKASI FILE: app/Core/StructureLevels.php
// Tingkat struktur (DPP, DPW, DPLN, DPD, ..., MWP, MWW, MWLN, ..., DSO, DSY, DPK) sebagai daftar yang DIKELOLA (tabel structure_levels,
// migrasi 027), bukan ENUM di kolom dan bukan konstanta di kode. Satu-satunya sumber untuk: pilihan Tingkat di formulir Struktur, urutan dan
// nama lengkap tingkat, kelompoknya (Umum / Muslimah / Dewan), tier-nya (setingkat Pusat / Wilayah / Daerah / Cabang / Ranting), dan induk
// yang lazim (atau wajib) bagi tingkat itu.
//
// Kode tingkat (singkatan) adalah kunci yang dipakai structures.level, ?lv= di alamat halaman, dan API. Super Admin boleh menggantinya
// (StructureLevelModel::save; foreign key ON UPDATE CASCADE, migrasi 028), yang mengubah `level` semua struktur bertingkat itu. Karena itu kode
// tak boleh ditulis sebagai literal untuk keputusan logika: pucuk nasional dikenali dari penanda is_system (isRoot), bukan dari 'DPP'.
// Nama, kelompok, tier, induk, urutan, dan status aktif juga bisa diubah. structures.wing dan structures.tier adalah SALINAN dari kelompok dan tier tingkatnya (StructureModel
// menuliskannya saat struktur disimpan, dan StructureLevelModel menyelaraskannya bila tingkatnya diubah).
//
// Dibaca sekali per permintaan lalu disimpan; panggil reset() setelah mengubah daftar. Tanpa basis data (uji murni) atau bila tabelnya belum
// ada (kode sudah diperbarui sebelum migrate.php dijalankan), daftar bawaan di builtin() dipakai supaya halaman tak galat.

class StructureLevels {
    // Kelompok tingkat. Nilainya sama dengan yang dulu dikembalikan PeopleOverview::wingOf: dipakai pengelompok chip dan warna garis baris.
    const GROUPS = ['umum' => 'Umum', 'musl' => 'Muslimah', 'dewan' => 'Dewan'];

    // Nama tier (structures.tier): 1 Pusat .. 5 Ranting. Sama dengan Ui::tierName; ditulis di sini supaya skrip CLI tak perlu memuat Ui.
    const TIER_NAMES = [1 => 'Pusat', 2 => 'Wilayah', 3 => 'Daerah', 4 => 'Cabang', 5 => 'Ranting'];

    private static $rows = null;

    public static function reset() { self::$rows = null; }

    // Untuk uji: ganti daftar tanpa menyentuh basis data. Baris yang kolomnya kurang dilengkapi seperti baris dari tabel.
    public static function load(array $rows) {
        self::$rows = [];
        foreach (array_values($rows) as $i => $r) {
            $r += ['name' => $r['code'], 'grp' => 'umum', 'tier' => 1, 'parent_code' => null, 'parent_strict' => 0, 'short_label' => null,
                   'sort_order' => ($i + 1) * 10, 'is_active' => 1, 'is_system' => 0];
            self::$rows[$r['code']] = $r;
        }
        self::sort();
    }

    // Semua tingkat terurut (urutan, lalu kode), kunci = kode. $activeOnly: hanya yang masih aktif (untuk pilihan baru).
    public static function all($activeOnly = false) {
        if (self::$rows === null) {
            try {
                if (!class_exists('Database')) throw new RuntimeException('tanpa basis data');
                $rows = Database::getInstance()->getConnection()->query('SELECT * FROM structure_levels')->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = self::builtin();
            }
            self::load($rows);
        }
        return $activeOnly ? array_filter(self::$rows, function ($r) { return !empty($r['is_active']); }) : self::$rows;
    }

    private static function sort() {
        uasort(self::$rows, function ($a, $b) { return [(int) $a['sort_order'], $a['code']] <=> [(int) $b['sort_order'], $b['code']]; });
    }

    public static function codes($activeOnly = false) { return array_keys(self::all($activeOnly)); }

    public static function exists($code) { return isset(self::all()[(string) $code]); }

    public static function find($code) { return self::all()[(string) $code] ?? null; }

    // kode => urutan (0, 1, 2, ...): untuk mengurutkan daftar kode di PHP.
    public static function order() { return array_flip(self::codes()); }

    // Ekspresi SQL pengurut menurut urutan daftar tingkat: FIELD(kolom, 'DPP', 'DPW', ...). structures.level kini VARCHAR, jadi ORDER BY biasa
    // menjadi alfabetis (dulu mengikuti urutan ENUM). Tingkat yang tak terdaftar mendapat nilai 0, yaitu paling depan. Kode dibatasi huruf dan
    // angka (aturan pembuatan tingkat), sehingga aman disisipkan langsung ke SQL.
    public static function orderSql($column) {
        $codes = array_values(array_filter(self::codes(), function ($c) { return preg_match('/^[A-Za-z0-9]+$/', $c); }));
        return $codes ? 'FIELD(' . $column . ", '" . implode("', '", $codes) . "')" : '0';
    }

    // Nama lengkap ("Dewan Pimpinan Luar Negeri"); kode yang tak dikenal ditampilkan apa adanya.
    public static function name($code) { return self::find($code)['name'] ?? (string) $code; }

    // 'umum' | 'musl' | 'dewan'. Kode yang tak dikenal (data lama yang tingkatnya sudah dihapus) ditebak seperti dulu: awalan MW = Muslimah.
    public static function group($code) {
        $r = self::find($code);
        if ($r) return $r['grp'];
        return strpos((string) $code, 'MW') === 0 ? 'musl' : 'umum';
    }

    // Nilai structures.wing untuk tingkat ini: 'muslimah' atau 'umum' (Dewan memakai katalog unit sayap Umum).
    public static function structureWing($code) { return self::group($code) === 'musl' ? 'muslimah' : 'umum'; }

    public static function tier($code) { return (int) (self::find($code)['tier'] ?? 0); }

    // Kode tingkat induk yang lazim bagi tingkat ini, atau null (akar, atau tak ditentukan).
    public static function parentOf($code) {
        $p = (string) (self::find($code)['parent_code'] ?? '');
        return $p !== '' ? $p : null;
    }

    // Tingkat bawaan sistem (DPP dan MWP): pucuk nasional. Dikenali dari penanda is_system, bukan dari kodenya, sehingga singkatannya boleh diganti.
    public static function isSystem($code) { return !empty(self::find($code)['is_system']); }

    // Pucuk nasional Umum (DPP): bawaan sistem dan tanpa induk tingkat. Dipakai untuk menyebut strukturnya dengan singkatan, bukan namanya.
    public static function isRoot($code) { return self::isSystem($code) && self::parentOf($code) === null; }

    // Induk yang WAJIB (struktur tingkat ini hanya boleh berinduk tingkat itu), atau null bila tak diwajibkan.
    public static function requiredParent($code) {
        $r = self::find($code);
        return ($r && !empty($r['parent_strict'])) ? self::parentOf($code) : null;
    }

    // Tingkat yang lazim berada langsung di bawah tingkat ini: kode => [kode anak, ...] untuk satu tingkat.
    public static function childrenOf($code) {
        $out = [];
        foreach (self::all() as $c => $r) if ((string) $r['parent_code'] === (string) $code) $out[] = $c;
        return $out;
    }

    // Sebutan untuk nama akun admin ("Admin Luar Negeri: DPLN Malaysia"): sebutan khusus tingkatnya bila ada, selain itu nama tier-nya.
    public static function adminLabel($code) {
        $r = self::find($code);
        if (!$r) return (string) $code;
        return trim((string) $r['short_label']) !== '' ? $r['short_label'] : (self::TIER_NAMES[(int) $r['tier']] ?? '—');
    }

    // Daftar bawaan (sama dengan isi awal migrasi 027) bila tabel belum ada atau tak ada basis data. Urut: induk lebih dulu daripada anaknya.
    public static function builtin() {
        $mk = function ($code, $name, $grp, $tier, $parent, $strict, $sort, $short = null, $system = 0) {
            return ['code' => $code, 'name' => $name, 'grp' => $grp, 'tier' => $tier, 'parent_code' => $parent, 'parent_strict' => $strict,
                    'short_label' => $short, 'sort_order' => $sort, 'is_active' => 1, 'is_system' => $system];
        };
        return [
            $mk('DPP', 'Dewan Pimpinan Pusat', 'umum', 1, null, 0, 10, null, 1),
            $mk('DPW', 'Dewan Pimpinan Wilayah', 'umum', 2, 'DPP', 0, 20),
            $mk('DPLN', 'Dewan Pimpinan Luar Negeri', 'umum', 2, 'DPP', 1, 30, 'Luar Negeri'),
            $mk('DPD', 'Dewan Pimpinan Daerah', 'umum', 3, 'DPW', 0, 40),
            $mk('DPC', 'Dewan Pimpinan Cabang', 'umum', 4, 'DPD', 0, 50),
            $mk('DPRa', 'Dewan Pimpinan Ranting', 'umum', 5, 'DPC', 0, 60),
            $mk('MWP', 'Muslimah Wahdah Islamiyah Pusat', 'musl', 1, 'DPP', 0, 110, null, 1),
            $mk('MWW', 'Muslimah Wilayah', 'musl', 2, 'MWP', 0, 120),
            $mk('MWLN', 'Muslimah Luar Negeri', 'musl', 2, 'MWP', 1, 130, 'Luar Negeri'),
            $mk('MWD', 'Muslimah Daerah', 'musl', 3, 'MWW', 0, 140),
            $mk('MWC', 'Muslimah Cabang', 'musl', 4, 'MWD', 0, 150),
            $mk('MWRa', 'Muslimah Ranting', 'musl', 5, 'MWC', 1, 160),
            $mk('DSO', 'Dewan Syura', 'dewan', 1, null, 0, 210),
            $mk('DSY', 'Dewan Syariah', 'dewan', 1, null, 0, 220),
            $mk('DPK', 'Dewan Pengawas Keuangan', 'dewan', 1, null, 0, 230),
        ];
    }
}
