<?php
// PATH LOKASI FILE: app/Core/PortalHome.php
// Bahan Beranda Portal Pengurus yang tidak butuh database: memilih jabatan utama, menyusun garis periode,
// angka sisa waktu, "Perjalanan saya", dan teks waktu masuk. Dipisah dari view supaya bisa diuji tanpa sesi
// (tests/portal_beranda_test.php). Masukannya baris dari MemberModel::history() / MemberModel::life(); MemberModel
// sudah dimuat oleh pemanggilnya (controller memuatnya lewat $this->model()).

require_once __DIR__ . '/StructureLevels.php';

class PortalHome {
    // Batas tampil di Beranda: jabatan lain, rekan, dan baris "Perjalanan saya" (sisanya di halaman Susunan / Riwayat).
    const OTHERS_MAX = 4;
    const COLLEAGUES_MAX = 4;
    const CAREER_MAX = 3;

    // Jabatan inti tingkat struktur (tanpa unit, bertanda inti di katalog jabatan) = "Pengurus Inti".
    public static function isTopCore(array $c) {
        return empty($c['structure_unit_id']) && !empty($c['is_core']);
    }

    // Jabatan yang sedang berjalan, diurut untuk Beranda: Pengurus Inti lebih dulu, lalu urutan semula
    // (periode terbaru lebih dulu). Elemen pertama menjadi jabatan utama; urutan di antara yang setara tidak berubah.
    public static function orderCurrent(array $current) {
        $rows = [];
        foreach (array_values($current) as $i => $c) $rows[] = [self::isTopCore($c) ? 0 : 1, $i, $c];
        usort($rows, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
        return array_map(function ($r) { return $r[2]; }, $rows);
    }

    // Tempat jabatan di dalam struktur: nama unit; jabatan tingkat struktur = Pengurus Inti bila jabatannya inti, selain itu
    // Pimpinan Lainnya (kategori yang sama dengan halaman Susunan).
    public static function placeLabel(array $c) {
        if (!empty($c['unit_name'])) return (string) $c['unit_name'];
        return !empty($c['is_core']) ? 'Pengurus Inti' : 'Pimpinan Lainnya';
    }

    // Nama struktur untuk ditampilkan: "DPD Kota Kendari". Struktur yang namanya sudah jabaran dirinya ("Dewan Pimpinan Pusat",
    // "Dewan Pengawas Keuangan") cukup "DPP", atau nama itu saja untuk dewan lainnya.
    public static function structureLabel($level, $name) {
        $level = trim((string) $level); $name = trim((string) $name);
        if (strpos($name, 'Dewan ') === 0) return StructureLevels::isRoot($level) ? $level : $name;
        return trim($level . ' ' . $name);
    }

    public static function structureName(array $c) {
        return self::structureLabel($c['level'] ?? '', $c['structure_name'] ?? '');
    }

    // Judul blok rekan: rekan se-unit, atau pimpinan struktur bila jabatannya tingkat struktur.
    public static function colleaguesTitle(array $main) {
        return !empty($main['unit_name']) ? 'Rekan di ' . $main['unit_name'] : 'Pimpinan ' . self::structureName($main);
    }

    // Tanda tahun pada garis periode: letak (persen, 1..99) tiap 1 Januari yang jatuh di dalam rentang awal-akhir.
    public static function yearTicks(array $life) {
        $t0 = strtotime($life['start'] . ' UTC'); $t1 = strtotime($life['end'] . ' UTC');
        if (!$t0 || !$t1 || $t1 <= $t0) return [];
        $ticks = [];
        for ($y = (int) gmdate('Y', $t0) + 1; $y <= (int) gmdate('Y', $t1); $y++) {
            $x = (gmmktime(0, 0, 0, 1, 1, $y) - $t0) / ($t1 - $t0) * 100;
            if ($x > 1 && $x < 99) $ticks[] = round($x, 1);
        }
        return $ticks;
    }

    // Gelembung persen di atas garis periode: di tengah penandanya, kecuali dekat ujung (rata ke ujung supaya tak terpotong).
    public static function bubbleStyle($pct) {
        $pct = (int) $pct;
        if ($pct > 86) return 'right:0';
        if ($pct < 14) return 'left:0';
        return 'left:' . $pct . '%;transform:translateX(-50%)';
    }

    // Sisa waktu untuk angka besar: label, bagian angka ([[55, 'hari']]), dan apakah sudah lewat.
    public static function countdown(array $life) {
        return [
            'late'  => !empty($life['late']),
            'label' => !empty($life['late']) ? 'Lewat dari tanggal akhir rencana' : 'Sisa masa jabatan',
            'parts' => Ui::spanParts(abs((int) $life['days_left'])),
        ];
    }

    // "Perjalanan saya": penempatan terbaru ($limit baris, urutan dari MemberModel::history) dan sisanya.
    public static function career(array $history, $limit = self::CAREER_MAX, $today = null) {
        $rows = [];
        foreach (array_slice($history, 0, $limit) as $h) {
            [$start, $end] = MemberModel::range($h);
            $y0 = $start ? substr($start, 0, 4) : '';
            $y1 = $end ? substr($end, 0, 4) : '';
            $years = $y0 === '' ? '—' : ($y1 === '' ? 'sejak ' . $y0 : ($y0 === $y1 ? $y0 : $y0 . ' – ' . $y1));
            $rows[] = [
                'years' => $years,
                'role'  => $h['position_name'],
                'sub'   => self::structureName($h) . ' · ' . self::placeLabel($h),
                'now'   => MemberModel::isNow($h, $today),
            ];
        }
        return ['rows' => $rows, 'more' => max(0, count($history) - $limit)];
    }

    // Waktu masuk untuk daftar riwayat masuk: "Hari ini, 07:31", "Kemarin, 19:40", atau "3 Nov 2026, 06:58".
    public static function when($datetime, $now = null) {
        $t = strtotime((string) $datetime);
        if (!$t) return '—';
        $now = $now ?: time();
        $day = date('Y-m-d', $t);
        $label = $day === date('Y-m-d', $now) ? 'Hari ini' : ($day === date('Y-m-d', strtotime('-1 day', $now)) ? 'Kemarin' : Ui::date($t));
        return $label . ', ' . date('H:i', $t);
    }

    // Nama untuk sapaan: semua kata kecuali yang terakhir, dan kata terakhir (diwarnai); nama satu kata tidak diwarnai.
    // Nama panjang dikecilkan hurufnya (kelas ukuran) supaya tetap utuh di ponsel.
    public static function greeting($nameOnly) {
        $words = preg_split('/\s+/u', trim((string) $nameOnly), -1, PREG_SPLIT_NO_EMPTY) ?: [(string) $nameOnly];
        $last = count($words) > 1 ? array_pop($words) : '';
        $len = mb_strlen(trim((string) $nameOnly));
        return [
            'first' => implode(' ', $words),
            'last'  => $last,
            'size'  => $len > 34 ? ' is-xs' : ($len > 24 ? ' is-s' : ($len > 16 ? ' is-m' : '')),
        ];
    }
}
