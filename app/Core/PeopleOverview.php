<?php
// PATH LOKASI FILE: app/Core/PeopleOverview.php
// Menyusun bahan tampilan "Per Struktur" di halaman Data Pengurus dari angka yang sudah dihitung PersonModel::overviewByStructure
// dan PersonModel::intiFlags. Murni penyusunan larik, tanpa basis data, supaya mudah diuji.
//
//   jenjang (DPP, DPW, MWW, MWD, ...)  ->  struktur  ->  daftar pengurus
//
// Menekan jenjang langsung menampilkan struktur-strukturnya: MWW = seluruh provinsi, MWD = seluruh kabupaten/kota (DPW dan DPD sama).
// Tidak ada tingkatan perantara. Induk hanya KETERANGAN di bawah nama ("Kab. Barru" / "Sulawesi Selatan") dan pengurut, supaya kabupaten
// satu provinsi berdampingan. Jenjang yang hanya punya SATU struktur (DPP, MWP, DSO, DSY, DPK) tidak menampilkan baris struktur: baris
// jenjangnya sekaligus baris strukturnya.

class PeopleOverview {
    // Urutan jenjang di halaman: sayap Umum, lalu Muslimah, lalu ketiga Dewan.
    const LEVEL_ORDER = ['DPP', 'DPW', 'DPLN', 'DPD', 'DPC', 'DPRa', 'MWP', 'MWW', 'MWD', 'MWC', 'MWRa', 'DSO', 'DSY', 'DPK'];

    const LEVEL_NAMES = [
        'DPP' => 'Dewan Pimpinan Pusat', 'DPW' => 'Dewan Pimpinan Wilayah', 'DPLN' => 'Dewan Pimpinan Luar Negeri',
        'DPD' => 'Dewan Pimpinan Daerah', 'DPC' => 'Dewan Pimpinan Cabang', 'DPRa' => 'Dewan Pimpinan Ranting',
        'MWP' => 'Muslimah Wahdah Islamiyah Pusat', 'MWW' => 'Muslimah Wilayah', 'MWD' => 'Muslimah Daerah',
        'MWC' => 'Muslimah Cabang', 'MWRa' => 'Muslimah Ranting',
        'DSO' => 'Dewan Syura', 'DSY' => 'Dewan Syariah', 'DPK' => 'Dewan Pengawas Keuangan',
    ];

    const WING_LABELS = ['umum' => 'Umum', 'musl' => 'Muslimah', 'dewan' => 'Dewan'];

    // 'umum' | 'musl' | 'dewan': pengelompok chip jenjang dan warna garis di tepi baris jenjang.
    public static function wingOf($level) {
        if (in_array($level, ['DSO', 'DSY', 'DPK'], true)) return 'dewan';
        return strpos($level, 'MW') === 0 ? 'musl' : 'umum';
    }

    // Nama struktur untuk baris di bawah jenjangnya: "Muslimah Wahdah Islamiyah Wilayah Aceh" cukup "Aceh", sebab jenjangnya sudah tertulis.
    public static function shortName($name) {
        return trim(preg_replace('/^Muslimah Wahdah Islamiyah (Wilayah|Daerah|Cabang|Ranting)\s+/u', '', (string) $name));
    }

    // Chip jenjang: ['umum' => ['DPP', 'DPW', ...], 'musl' => [...], 'dewan' => [...]] untuk SEMUA kunci $levelCounts (level => jumlah, boleh nol).
    // Jenjang mana yang ikut ditentukan pemanggil: jenjang yang punya struktur dalam lingkup ATAU punya orang, bukan hanya yang berpenghuni,
    // supaya jenjang baru (mis. DPLN) langsung tampil. Kelompok sayap yang kosong tidak muncul.
    public static function chipGroups(array $levelCounts) {
        $out = [];
        foreach (self::orderedLevels(array_keys($levelCounts)) as $level) $out[self::wingOf($level)][] = $level;
        return $out;
    }

    // Jenjang menurut LEVEL_ORDER; jenjang yang belum tercantum di sana (kelak) menyusul di akhir.
    public static function orderedLevels(array $levels) {
        $known = array_values(array_filter(self::LEVEL_ORDER, function ($l) use ($levels) { return in_array($l, $levels, true); }));
        $other = array_values(array_diff($levels, self::LEVEL_ORDER));
        sort($other);
        return array_merge($known, $other);
    }

    // Jenjang yang tidak dibuka sendiri bila strukturnya lebih banyak dari ini (DPD dan MWD ratusan baris): daftarnya baru dibuka bila
    // orangnya memilih jenjang itu atau mengetuknya.
    const OPEN_MAX_ROWS = 60;

    // $overview = PersonModel::overviewByStructure; $inti = PersonModel::intiFlags.
    // Hasil: daftar blok jenjang. Blok: level, name, wing, strukt (jumlah struktur), inti_lengkap (struktur yang Ketua, Sekretaris, dan
    // Bendahara-nya terisi), num (orang/lengkap/l/p seluruh jenjang), single (hanya satu struktur), flags dan term_id (hanya bila single),
    // rows (SEMUA struktur jenjang itu, rata, terurut induk lalu nama).
    // Baris struktur: id, term_id (null bila belum punya periode berjalan: barisnya tak bisa dibuka, hanya menunjukkan bahwa strukturnya ada),
    // name, context (nama induk tingkat Wilayah ke bawah, mis. provinsi untuk sebuah kabupaten; kosong bila tak ada), sub ("5 MWD": jumlah
    // struktur anak), num, flags, inti_ok. Struktur tanpa pengurus ikut (angka nol, Ketua/Sekretaris/Bendahara kosong) bila overview memuatnya.
    public static function build(array $overview, array $inti) {
        $childrenOf = [];
        foreach ($overview['structures'] as $s) {
            if ($s['parent_id'] !== null) $childrenOf[$s['parent_id']][$s['level']] = ($childrenOf[$s['parent_id']][$s['level']] ?? 0) + 1;
        }

        $row = function (array $s) use ($inti, $childrenOf) {
            $flags = $inti[$s['id']] ?? ['k' => false, 's' => false, 'b' => false];
            $sub = [];
            foreach ($childrenOf[$s['id']] ?? [] as $level => $n) $sub[] = $n . ' ' . $level;
            return [
                'id' => $s['id'], 'term_id' => $s['term_id'], 'name' => self::shortName($s['name']),
                'context' => $s['parent_name'] !== null ? self::shortName($s['parent_name']) : '', 'sub' => implode(', ', $sub),
                'num' => ['orang' => $s['orang'], 'lengkap' => $s['lengkap'], 'l' => $s['l'], 'p' => $s['p']],
                'flags' => $flags, 'inti_ok' => $flags['k'] && $flags['s'] && $flags['b'],
            ];
        };

        $byLevel = [];
        foreach ($overview['structures'] as $s) $byLevel[$s['level']][] = $s;

        $blocks = [];
        foreach (self::orderedLevels(array_keys($overview['levels'])) as $level) {
            if (empty($byLevel[$level])) continue;

            $rows = array_map($row, $byLevel[$level]);
            // Induknya dulu (kabupaten satu provinsi berdampingan), lalu nama; urutan alami supaya "Kab. 2" mendahului "Kab. 10".
            usort($rows, function ($a, $b) { return strnatcasecmp($a['context'], $b['context']) ?: strnatcasecmp($a['name'], $b['name']); });

            $single = count($rows) === 1;
            $blocks[] = [
                'level' => $level, 'name' => self::LEVEL_NAMES[$level] ?? $level, 'wing' => self::wingOf($level),
                'strukt' => count($rows), 'inti_lengkap' => count(array_filter($rows, function ($r) { return $r['inti_ok']; })),
                'num' => $overview['levels'][$level],
                'single' => $single, 'flags' => $single ? $rows[0]['flags'] : null, 'term_id' => $single ? $rows[0]['term_id'] : null,
                'rows' => $rows,
            ];
        }
        return $blocks;
    }

    // Jenjang yang terbuka sejak awal. Bila satu jenjang dipilih: hanya itu. Selain itu dua jenjang berstruktur banyak yang pertama,
    // kecuali yang lebih dari OPEN_MAX_ROWS struktur (daftar DPD dan MWD akan memanjangkan halaman; ringkasannya sudah ada di baris
    // jenjangnya). Jenjang satu-struktur (DPP, ...) tidak dibuka otomatis: isinya dimuat lewat jaringan dan bisa ratusan KB, jadi baru
    // dimuat saat diketuk. Hanya bila tak ada yang memenuhi (admin satu DPD), jenjang pertama dibuka supaya halamannya tak tampak kosong.
    public static function defaultOpen(array $blocks, $selected = '') {
        if ($selected !== '') return [$selected];
        $open = array_slice(array_column(array_filter($blocks, function ($b) { return !$b['single'] && $b['strukt'] <= self::OPEN_MAX_ROWS; }), 'level'), 0, 2);
        if (!$open && $blocks) $open = [$blocks[0]['level']];
        return $open;
    }
}
