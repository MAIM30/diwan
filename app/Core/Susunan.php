<?php
// PATH LOKASI FILE: app/Core/Susunan.php
// Mengelompokkan penempatan satu periode menjadi kategori bertingkat untuk halaman detail periode:
//
//   Pengurus Inti                        jabatan tingkat struktur yang inti bagi struktur ini menurut katalog jabatan (baris is_core dari PositionModel::CORE_SQL)
//   Pimpinan Lainnya                     jabatan tingkat struktur yang bukan inti (mis. Wakil Ketua di DPP/MWP)
//   Bidang  ->  Bidang X  ->  unit       tiap Bidang memuat Departemen, lalu Lembaga, lalu jenis unit lain
//   <kelompok lain>                      kelompok bukan-Bidang milik periode itu (Pusat-Pusat, Badan-Badan, Biro-Biro)
//   Unit tanpa Bidang                    unit yang belum dinaungi Bidang mana pun
//
// Murni penyusunan larik (tanpa basis data) dan setiap penempatan masuk TEPAT satu simpul, jadi tak ada yang hilang
// atau tampil dua kali. Bidang dan unit yang masih kosong tetap ikut tampil supaya kerangkanya terlihat.

class Susunan {
    // Urutan unit di dalam satu Bidang menurut jenisnya; jenis lain menyusul sesuai urutan SQL (sort_order, nama).
    const KIND_RANK = ['departemen' => 0, 'lembaga' => 1];

    // $rows: PlacementModel::byTerm; $groups: UnitModel::groupsByTerm; $units: UnitModel::unitsByTerm.
    // Hasil: daftar simpul tingkat atas. Simpul: title, badge (jenis unit atau null), rows (penempatan, null bila hanya
    // memuat anak), children, noun (sebutan anak, mis. "unit"), open, phone (tampilkan kolom kontak), empty, count (orang aktif).
    public static function arrange(array $rows, array $groups, array $units) {
        $unitById = [];
        foreach ($units as $u) $unitById[(int) $u['id']] = $u;

        $byUnit = []; $inti = []; $lain = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['structure_unit_id'] ?? 0);
            if ($uid && isset($unitById[$uid])) $byUnit[$uid][] = $r;
            elseif (!$uid && !empty($r['is_core'])) $inti[] = $r;
            else $lain[] = $r;   // tingkat struktur non-inti, atau unit yang sudah tak ada di periode ini (jangan sampai hilang)
        }

        $groupById = [];
        foreach ($groups as $g) $groupById[(int) $g['id']] = $g;

        $unitsOf = []; $loose = [];
        foreach ($units as $u) {
            $gid = (int) ($u['unit_group_id'] ?? 0);
            if ($gid && isset($groupById[$gid])) $unitsOf[$gid][] = $u; else $loose[] = $u;
        }

        $unitNodes = function (array $list) use ($byUnit) {
            return array_map(function ($u) use ($byUnit) {
                return self::node($u['display_name'] ?: $u['catalog_name'], [
                    'badge' => $u['kind'] !== 'lainnya' ? ucfirst($u['kind']) : null,
                    'rows'  => $byUnit[(int) $u['id']] ?? [],
                    'empty' => 'Belum ada pengurus di unit ini.',
                ]);
            }, self::sortUnits($list));
        };

        $bidang = []; $subbidang = []; $lainGrup = [];
        foreach ($groups as $g) {
            $n = self::node($g['name'], ['children' => $unitNodes($unitsOf[(int) $g['id']] ?? []), 'noun' => 'unit', 'empty' => 'Belum ada unit di sini.']);
            if ($g['kind'] === 'bidang') $bidang[] = $n;
            elseif ($g['kind'] === 'subbidang') $subbidang[] = $n;
            else $lainGrup[] = $n;
        }

        $out = [self::node('Pengurus Inti', ['rows' => $inti, 'open' => true, 'phone' => true, 'empty' => 'Belum ada pengurus inti.'])];
        if ($lain) $out[] = self::node('Pimpinan Lainnya', ['rows' => $lain]);
        if ($bidang) $out[] = self::node('Bidang', ['children' => $bidang, 'noun' => 'bidang']);
        if ($subbidang) $out[] = self::node('Subbidang', ['children' => $subbidang, 'noun' => 'subbidang']);
        foreach ($lainGrup as $n) $out[] = $n;
        if ($loose) $out[] = self::node('Unit tanpa Bidang', ['children' => $unitNodes($loose), 'noun' => 'unit']);

        foreach ($out as &$n) self::tally($n);
        unset($n);
        return $out;
    }

    // Urutan unit di dalam satu Bidang: Departemen, lalu Lembaga, lalu jenis lain; yang sejenis mengikuti sort_order
    // (urutan masukan dipertahankan bila sama). $list: UnitModel::unitsByTerm.
    public static function sortUnits(array $list) {
        usort($list, function ($a, $b) {
            $ra = self::KIND_RANK[$a['kind']] ?? 9; $rb = self::KIND_RANK[$b['kind']] ?? 9;
            return $ra <=> $rb ?: ((int) $a['sort_order'] <=> (int) $b['sort_order']);
        });
        return $list;
    }

    // Membungkus beberapa simpul di bawah satu simpul induk (mis. satu struktur beserta periode berjalannya).
    public static function wrap($title, array $children, $badge = null, $open = false) {
        $n = self::node($title, ['badge' => $badge, 'children' => $children, 'open' => $open]);
        self::tally($n);
        return $n;
    }

    // Membuang simpul yang tak berisi penempatan sama sekali. Untuk hasil penyaringan: Bidang dan unit kosong hanya mengganggu di situ.
    public static function prune(array $nodes) {
        $out = [];
        foreach ($nodes as $n) {
            $n['children'] = self::prune($n['children']);
            if ($n['rows'] || $n['children']) $out[] = $n;
        }
        return $out;
    }

    // Membuka semua simpul, supaya hasil penyaringan langsung terlihat tanpa diketuk satu per satu.
    public static function openAll(array $nodes) {
        foreach ($nodes as &$n) {
            $n['open'] = true;
            $n['children'] = self::openAll($n['children']);
        }
        return $nodes;
    }

    // Jumlah penempatan di seluruh simpul (untuk memastikan tak ada yang hilang atau ganda).
    public static function rowCount(array $nodes) {
        $n = 0;
        foreach ($nodes as $node) $n += count($node['rows'] ?? []) + self::rowCount($node['children']);
        return $n;
    }

    private static function node($title, array $o = []) {
        return $o + ['title' => $title, 'badge' => null, 'rows' => null, 'children' => [], 'noun' => null,
                     'open' => false, 'phone' => false, 'empty' => 'Belum ada pengurus.', 'count' => 0];
    }

    // Mengisi 'count' = jumlah ORANG yang masih menjabat (penempatan belum berakhir) di simpul beserta turunannya;
    // satu orang dengan dua jabatan di dalamnya dihitung sekali. Mengembalikan himpunan id orang untuk induknya.
    private static function tally(array &$node) {
        $ids = [];
        foreach ($node['rows'] ?? [] as $r) if (empty($r['end_date'])) $ids[(int) $r['person_id']] = true;
        foreach ($node['children'] as &$c) $ids += self::tally($c);
        unset($c);
        $node['count'] = count($ids);
        return $ids;
    }
}
