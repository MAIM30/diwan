<?php
// PATH LOKASI FILE: app/Core/PeopleOverview.php
// Menyusun bahan tampilan "Per Struktur" di halaman Data Pengurus dari angka yang sudah dihitung PersonModel::overviewByStructure
// dan PersonModel::intiFlags. Murni penyusunan larik, tanpa basis data, supaya mudah diuji.
//
//   jenjang (DPP, DPW, DPD, ...)  ->  kelompok induk (mis. DPW Sulawesi Selatan untuk para DPD-nya)  ->  struktur
//
// Jenjang yang hanya punya SATU struktur (DPP, MWP, DSO, DSY, DPK) tidak menampilkan baris struktur: baris jenjangnya sekaligus
// baris strukturnya, dan isinya (Pengurus Inti, Bidang, unit) dimuat saat dibuka. Struktur yang induknya bukan akar dan masih dalam
// lingkup admin dikelompokkan di bawah induknya (DPD di bawah DPW, MWD di bawah MWW); selain itu ia berdiri sendiri di bawah jenjangnya.

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

    // Chip jenjang: ['umum' => ['DPP', 'DPW', ...], 'musl' => [...], 'dewan' => [...]], hanya jenjang yang punya orang. Kelompok kosong dibuang.
    public static function chipGroups(array $levelCounts) {
        $out = [];
        foreach (self::orderedLevels(array_keys(array_filter($levelCounts))) as $level) $out[self::wingOf($level)][] = $level;
        return $out;
    }

    // Jenjang menurut LEVEL_ORDER; jenjang yang belum tercantum di sana (kelak) menyusul di akhir.
    public static function orderedLevels(array $levels) {
        $known = array_values(array_filter(self::LEVEL_ORDER, function ($l) use ($levels) { return in_array($l, $levels, true); }));
        $other = array_values(array_diff($levels, self::LEVEL_ORDER));
        sort($other);
        return array_merge($known, $other);
    }

    // $overview = PersonModel::overviewByStructure; $inti = PersonModel::intiFlags.
    // Hasil: daftar blok jenjang. Blok: level, name, wing, strukt (jumlah struktur), inti_lengkap (struktur yang Ketua, Sekretaris, dan
    // Bendahara-nya terisi), num (orang/lengkap/l/p seluruh jenjang), single (hanya satu struktur), flags dan term_id (hanya bila single),
    // groups (kelompok induk: parent_id, title, strukt, inti_lengkap, num, rows), rows (struktur yang tak berkelompok).
    // Baris struktur: id, term_id, name, sub ("5 DPD"), num, flags, inti_ok.
    public static function build(array $overview, array $inti) {
        $zero = ['orang' => 0, 'lengkap' => 0, 'l' => 0, 'p' => 0];

        $childrenOf = [];
        foreach ($overview['structures'] as $s) {
            if ($s['parent_id'] !== null) $childrenOf[$s['parent_id']][$s['level']] = ($childrenOf[$s['parent_id']][$s['level']] ?? 0) + 1;
        }

        $row = function (array $s) use ($inti, $childrenOf) {
            $flags = $inti[$s['id']] ?? ['k' => false, 's' => false, 'b' => false];
            $sub = [];
            foreach ($childrenOf[$s['id']] ?? [] as $level => $n) $sub[] = $n . ' ' . $level;
            return [
                'id' => $s['id'], 'term_id' => $s['term_id'], 'name' => self::shortName($s['name']), 'sub' => implode(', ', $sub),
                'num' => ['orang' => $s['orang'], 'lengkap' => $s['lengkap'], 'l' => $s['l'], 'p' => $s['p']],
                'flags' => $flags, 'inti_ok' => $flags['k'] && $flags['s'] && $flags['b'],
            ];
        };
        $intiOk = function (array $rows) {
            return count(array_filter($rows, function ($r) { return $r['inti_ok']; }));
        };

        $byLevel = [];
        foreach ($overview['structures'] as $s) $byLevel[$s['level']][] = $s;

        $blocks = [];
        foreach (self::orderedLevels(array_keys($overview['levels'])) as $level) {
            if (empty($byLevel[$level])) continue;
            $list = $byLevel[$level];

            $all = []; $loose = []; $groups = [];
            foreach ($list as $s) {
                $r = $row($s);
                $all[] = $r;
                if ($s['parent_id'] === null) { $loose[] = $r; continue; }
                if (!isset($groups[$s['parent_id']])) {
                    $groups[$s['parent_id']] = ['parent_id' => $s['parent_id'], 'title' => $s['parent_level'] . ' ' . self::shortName($s['parent_name']), 'rows' => []];
                }
                $groups[$s['parent_id']]['rows'][] = $r;
            }
            foreach ($groups as $pid => &$g) {
                $g['strukt'] = count($g['rows']);
                $g['inti_lengkap'] = $intiOk($g['rows']);
                $g['num'] = $overview['parents'][$pid] ?? $zero;
            }
            unset($g);
            $groups = array_values($groups);
            usort($groups, function ($a, $b) { return strnatcasecmp($a['title'], $b['title']); });

            $single = count($all) === 1;
            $blocks[] = [
                'level' => $level, 'name' => self::LEVEL_NAMES[$level] ?? $level, 'wing' => self::wingOf($level),
                'strukt' => count($all), 'inti_lengkap' => $intiOk($all), 'num' => $overview['levels'][$level],
                'single' => $single, 'flags' => $single ? $all[0]['flags'] : null, 'term_id' => $single ? $all[0]['term_id'] : null,
                'groups' => $groups, 'rows' => $loose,
            ];
        }
        return $blocks;
    }

    // Jenjang yang terbuka sejak awal. Bila satu jenjang dipilih: hanya itu. Selain itu dua jenjang berstruktur banyak yang pertama
    // (cukup untuk melihat bentuknya tanpa memenuhi layar). Jenjang satu-struktur (DPP, ...) tidak dibuka otomatis: isinya dimuat lewat
    // jaringan dan bisa ratusan KB, jadi baru dimuat saat diketuk. Hanya bila tak ada jenjang berstruktur banyak (admin satu DPD),
    // jenjang pertama dibuka supaya halamannya tak tampak kosong.
    public static function defaultOpen(array $blocks, $selected = '') {
        if ($selected !== '') return [$selected];
        $open = array_slice(array_column(array_filter($blocks, function ($b) { return !$b['single']; }), 'level'), 0, 2);
        if (!$open && $blocks) $open = [$blocks[0]['level']];
        return $open;
    }
}
