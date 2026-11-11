<?php
// PATH LOKASI FILE: app/Core/UnitKinds.php
// Jenis unit (Departemen, Lembaga, Biro, Badan, ...) sebagai daftar yang DIKELOLA (tabel unit_kinds, migrasi 025), bukan konstanta di kode.
// Satu-satunya sumber untuk: pilihan jenis di formulir, label, urutan tampil, batas jenis pada jabatan (positions.unit_kinds), dan kelompok
// bawaan tiap jenis (mis. Biro masuk "Biro-Biro"). Kode jenis tetap (dipakai unit_catalog.kind, positions.unit_kinds, dan API); yang bisa
// berubah adalah label, urutan, kelompok bawaan, dan status aktif. Pengelolaannya ada di halaman Unit Organisasi (UnitModel::kind*).
//
// Dibaca sekali per permintaan lalu disimpan; panggil reset() setelah mengubah daftar. Bila tabelnya belum ada (kode sudah diperbarui
// sebelum migrate.php dijalankan), daftar bawaan di builtin() dipakai supaya halaman tak galat.

class UnitKinds {
    // Kode jenis penampung untuk unit yang jenisnya belum ditetapkan (dulu "Lainnya"). Bawaan sistem: tak bisa dihapus atau dinonaktifkan.
    const FALLBACK = 'belum';

    private static $rows = null;

    public static function reset() { self::$rows = null; }

    // Semua jenis terurut (urutan, lalu label), kunci = kode. $activeOnly: hanya yang masih aktif (untuk pilihan baru).
    public static function all($activeOnly = false) {
        if (self::$rows === null) {
            try {
                $rows = Database::getInstance()->getConnection()
                    ->query('SELECT * FROM unit_kinds ORDER BY sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $rows = self::builtin();
            }
            self::$rows = [];
            foreach ($rows as $r) self::$rows[$r['code']] = $r;
        }
        return $activeOnly ? array_filter(self::$rows, function ($r) { return !empty($r['is_active']); }) : self::$rows;
    }

    // kode => label.
    public static function labels($activeOnly = false) {
        return array_map(function ($r) { return $r['label']; }, self::all($activeOnly));
    }

    public static function codes($activeOnly = false) { return array_keys(self::all($activeOnly)); }

    public static function exists($code) { return isset(self::all()[(string) $code]); }

    public static function find($code) { return self::all()[(string) $code] ?? null; }

    // Label untuk tampilan; kode yang tak dikenal ditampilkan apa adanya.
    public static function label($code) { return self::all()[(string) $code]['label'] ?? (string) $code; }

    // Kode yang aman disimpan: kode itu sendiri bila dikenal, selain itu penampung "belum ditentukan".
    public static function normalize($code) { return self::exists($code) ? (string) $code : self::FALLBACK; }

    // Kelompok bawaan jenis ini (dibuat untuk unit baru yang tujuannya "otomatis menurut jenis"): [nama, urutan] atau null.
    public static function group($code) {
        $r = self::find($code);
        return ($r && (string) ($r['group_name'] ?? '') !== '') ? [$r['group_name'], (int) ($r['group_sort'] ?? 500)] : null;
    }

    // Daftar bawaan bila tabel belum ada. 'lainnya' ikut agar data sebelum migrasi 025 tetap terbaca.
    private static function builtin() {
        $mk = function ($code, $label, $sort, $group = null, $gsort = null, $system = 0) {
            return ['code' => $code, 'label' => $label, 'sort_order' => $sort, 'group_name' => $group, 'group_sort' => $gsort, 'is_active' => 1, 'is_system' => $system];
        };
        return [$mk('departemen', 'Departemen', 10), $mk('lembaga', 'Lembaga', 20), $mk('biro', 'Biro', 30, 'Biro-Biro', 910),
                $mk('badan', 'Badan', 40, 'Badan-Badan', 900), $mk('pusat', 'Pusat', 50, 'Pusat-Pusat', 890), $mk('divisi', 'Divisi', 60),
                $mk('komisi', 'Komisi', 70), $mk('belum', 'Belum ditentukan', 900, null, null, 1), $mk('lainnya', 'Belum ditentukan', 901)];
    }
}
