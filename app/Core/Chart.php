<?php
// PATH LOKASI FILE: app/Core/Chart.php
// Grafik SVG/HTML murni dari sisi server -- tanpa pustaka JavaScript dan tanpa CDN.
//
// Aturan yang dipegang di seluruh berkas ini (mengikuti panduan visualisasi data):
//   * Warna dipilih menurut TUGASNYA. Satu seri nominal = satu warna (--viz-1). Tingkat struktur
//     (Pusat -> Ranting) berurutan, jadi memakai ramp satu hue (--ord-1..5), bukan warna acak.
//     Dua seri berbeda identitas memakai --viz-1 dan --viz-2. Palet ini sudah divalidasi dengan
//     validate_palette.js (lihat catatan di public/assets/css/app.css).
//   * Tanda tipis: batang <= 24px dengan ujung data membulat 4px, garis 2px, titik >= 8px dengan
//     cincin permukaan 2px, jarak 2px antar segmen. Garis kisi hairline, solid, redup.
//   * Teks TIDAK memakai warna seri -- identitas dibawa oleh penanda berwarna di sebelahnya.
//   * Tooltip menambah, tidak menggantikan: setiap nilai juga ada di label/legenda dan di
//     padanan tabel (<details>) di bawah tiap grafik.
//   * Label dan nama kategori diperlakukan sebagai data tak tepercaya: di-escape di sini, dan
//     tooltip di JavaScript memakai textContent.

class Chart {
    // Padanan tabel -- setiap grafik punya satu, supaya tak ada nilai yang hanya bisa dibaca lewat warna.
    private static function tableTwin(array $headers, array $rows) {
        $h = '';
        foreach ($headers as $i => $head) {
            $h .= '<th' . ($i > 0 ? ' class="num"' : '') . '>' . e($head) . '</th>';
        }
        $b = '';
        foreach ($rows as $r) {
            $b .= '<tr>';
            foreach (array_values($r) as $i => $cell) {
                $b .= '<' . ($i === 0 ? 'th scope="row"' : 'td class="num"') . '>' . e((string) $cell) . '</' . ($i === 0 ? 'th' : 'td') . '>';
            }
            $b .= '</tr>';
        }
        return '<details class="viz-table"><summary>Lihat sebagai tabel</summary>'
            . '<div class="table-wrap"><table><thead><tr>' . $h . '</tr></thead><tbody>' . $b . '</tbody></table></div></details>';
    }

    public static function emptyState($message) {
        return '<div class="viz-empty">' . Ui::icon('activity', 20) . '<span>' . e($message) . '</span></div>';
    }

    // ---------- Donat (bagian terhadap keseluruhan, <= 6 segmen) ----------
    // $items: [['label' =>, 'value' =>, 'color' =>], ...]
    public static function donut(array $items, $centerBig, $centerSmall, $unit = 'orang', $caption = '') {
        $items = array_values(array_filter($items, function ($i) { return $i['value'] > 0; }));
        $total = array_sum(array_column($items, 'value'));
        if ($total <= 0) {
            return self::emptyState('Belum ada data untuk ditampilkan.');
        }

        $size = 152; $c = $size / 2; $r = 56; $sw = 22;
        $circ = 2 * M_PI * $r;
        $gap = count($items) > 1 ? 2 : 0;   // jarak permukaan 2px antar segmen
        $acc = 0; $segs = ''; $legend = ''; $table = [];

        foreach ($items as $it) {
            $frac = $it['value'] / $total;
            $len = $frac * $circ;
            $draw = max($len - $gap, 0.8);
            $pct = $frac * 100;
            $pctText = ($pct > 0 && $pct < 1) ? '<1%' : round($pct) . '%';
            $tip = Ui::n($it['value']) . ' ' . $unit . ' · ' . $pctText;

            $segs .= '<circle class="seg" cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="' . e($it['color'])
                . '" stroke-width="' . $sw . '" stroke-dasharray="' . round($draw, 2) . ' ' . round($circ - $draw, 2)
                . '" stroke-dashoffset="' . round(-$acc, 2) . '" tabindex="0" role="img"'
                . ' aria-label="' . e($it['label'] . ': ' . $tip) . '"'
                . ' data-tip-title="' . e($it['label']) . '" data-tip-value="' . e($tip) . '" data-tip-color="' . e($it['color']) . '"/>';
            $acc += $len;

            $legend .= '<li><span class="sw" style="background:' . e($it['color']) . '"></span>'
                . '<span class="lg-label">' . e($it['label']) . '</span>'
                . '<span class="lg-val">' . Ui::n($it['value']) . '</span>'
                . '<span class="lg-pct">' . $pctText . '</span></li>';
            $table[] = [$it['label'], Ui::n($it['value']), $pctText];
        }

        return '<figure class="viz viz-donut">'
            . '<div class="donut-wrap">'
            . '<svg class="donut" viewBox="0 0 ' . $size . ' ' . $size . '" role="group" aria-label="' . e($caption) . '">'
            . '<g transform="rotate(-90 ' . $c . ' ' . $c . ')">' . $segs . '</g>'
            . '<text class="donut-big" x="' . $c . '" y="' . ($c - 1) . '" text-anchor="middle">' . e($centerBig) . '</text>'
            . '<text class="donut-small" x="' . $c . '" y="' . ($c + 17) . '" text-anchor="middle">' . e($centerSmall) . '</text>'
            . '</svg>'
            . '<ul class="legend">' . $legend . '</ul>'
            . '</div>'
            . self::tableTwin(['Kategori', ucfirst($unit), 'Porsi'], $table)
            . '</figure>';
    }

    // ---------- Batang horizontal (membandingkan besaran antar kategori nominal) ----------
    // Satu seri -> satu warna untuk semua batang. Nilai di ujung batang. Baris setinggi >= 32px
    // sehingga area sentuh jauh lebih besar dari batangnya.
    // $rows: [['label' =>, 'value' =>, 'href' => (opsional), 'sub' => (opsional)], ...]
    public static function hbar(array $rows, $unit = 'orang', $caption = '') {
        if (empty($rows)) {
            return self::emptyState('Belum ada data untuk ditampilkan.');
        }
        $max = max(array_column($rows, 'value'));
        $max = $max > 0 ? $max : 1;

        $html = ''; $table = [];
        foreach ($rows as $r) {
            $w = max($r['value'] / $max, $r['value'] > 0 ? 0.015 : 0);
            $tip = Ui::n($r['value']) . ' ' . $unit;
            $attrs = ' tabindex="0" data-tip-title="' . e($r['label']) . '" data-tip-value="' . e($tip) . '" data-tip-color="var(--viz-1)"';
            $inner = '<span class="hbar-label" title="' . e($r['label']) . '">' . e($r['label']) . '</span>'
                . '<span class="hbar-track"><span class="hbar-bar" style="--w:' . round($w, 4) . '"></span>'
                . '<span class="hbar-val">' . Ui::n($r['value']) . '</span></span>';
            $html .= !empty($r['href'])
                ? '<a class="hbar-row" href="' . e($r['href']) . '"' . $attrs . '>' . $inner . '</a>'
                : '<div class="hbar-row"' . $attrs . '>' . $inner . '</div>';
            $table[] = [$r['label'], Ui::n($r['value'])];
        }

        return '<figure class="viz viz-hbar"><div class="hbar" role="group" aria-label="' . e($caption) . '">' . $html . '</div>'
            . self::tableTwin(['Kategori', ucfirst($unit)], $table) . '</figure>';
    }

    // ---------- Satu batang bertumpuk 100% (dua/tiga kategori, mis. jenis kelamin) ----------
    // Menggantikan donat 2 irisan, yang oleh panduan dianggap bentuk keliru: angkanya saja sudah
    // cukup, dan batang tumpuk lebih mudah dibandingkan.
    public static function stacked(array $segments, $unit = 'orang', $caption = '') {
        $segments = array_values(array_filter($segments, function ($s) { return $s['value'] > 0; }));
        $total = array_sum(array_column($segments, 'value'));
        if ($total <= 0) {
            return self::emptyState('Belum ada data untuk ditampilkan.');
        }

        $bar = ''; $legend = ''; $table = [];
        foreach ($segments as $s) {
            $pct = $s['value'] / $total * 100;
            $pctText = ($pct > 0 && $pct < 1) ? '<1%' : round($pct) . '%';
            $tip = Ui::n($s['value']) . ' ' . $unit . ' · ' . $pctText;
            $bar .= '<span class="sbar-seg" style="flex:' . $s['value'] . ';background:' . e($s['color']) . '" tabindex="0" role="img"'
                . ' aria-label="' . e($s['label'] . ': ' . $tip) . '"'
                . ' data-tip-title="' . e($s['label']) . '" data-tip-value="' . e($tip) . '" data-tip-color="' . e($s['color']) . '"></span>';
            $legend .= '<li><span class="sw" style="background:' . e($s['color']) . '"></span>'
                . '<span class="lg-label">' . e($s['label']) . '</span>'
                . '<span class="lg-val">' . Ui::n($s['value']) . '</span><span class="lg-pct">' . $pctText . '</span></li>';
            $table[] = [$s['label'], Ui::n($s['value']), $pctText];
        }

        return '<figure class="viz viz-stacked"><div class="sbar" role="group" aria-label="' . e($caption) . '">' . $bar . '</div>'
            . '<ul class="legend legend-inline">' . $legend . '</ul>'
            . self::tableTwin(['Kategori', ucfirst($unit), 'Porsi'], $table) . '</figure>';
    }

    // Tangga langkah sumbu: pilih kelipatan bersih supaya label sumbu y berupa angka bulat yang enak dibaca.
    private static function niceStep($max, $ticks) {
        $raw = max($max, 1) / $ticks;
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000, 20000, 50000, 100000] as $s) {
            if ($s >= $raw) return $s;
        }
        return 100000;
    }

    private static function compact($n) {
        return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . 'K' : (string) $n;
    }

    // ---------- Area/garis satu seri terhadap waktu ----------
    // $points: [['label' => 'Okt', 'title' => 'Okt 2026', 'value' => 12], ...]
    // Satu seri -> tanpa kotak legenda (judul kartu sudah menyebut isinya). Tooltip + crosshair
    // dikerjakan app.js lewat atribut data-* pada pita sentuh transparan.
    public static function area(array $points, $unit = 'penempatan', $caption = '') {
        $n = count($points);
        if ($n === 0) return self::emptyState('Belum ada data untuk ditampilkan.');

        $W = 380; $H = 250; $ml = 34; $mr = 14; $mt = 22; $mb = 28;
        $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;

        $max = max(array_column($points, 'value'));
        $step = self::niceStep($max, 3);
        $top = $step * 3;

        $x = function ($i) use ($n, $ml, $pw) { return $n === 1 ? $ml + $pw / 2 : $ml + $i * $pw / ($n - 1); };
        $y = function ($v) use ($mt, $ph, $top) { return $mt + $ph - ($v / $top) * $ph; };
        $bandW = $n === 1 ? $pw : $pw / ($n - 1);

        // Kisi + label sumbu y: hairline solid, redup.
        $grid = '';
        for ($k = 0; $k <= 3; $k++) {
            $v = $k * $step; $yy = round($y($v), 2);
            $grid .= '<line class="grid' . ($k === 0 ? ' base' : '') . '" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
                . '<text class="axis" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . self::compact($v) . '</text>';
        }

        $line = ''; $xlab = ''; $bands = ''; $table = [];
        foreach ($points as $i => $p) {
            $px = round($x($i), 2); $py = round($y($p['value']), 2);
            $line .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py . ' ';
            $xlab .= '<text class="axis" x="' . $px . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($p['label']) . '</text>';
            $tip = Ui::n($p['value']) . ' ' . $unit;
            // Pita sentuh selebar satu langkah penuh dan setinggi seluruh plot: jauh di atas 24px.
            $bands .= '<rect class="hit" x="' . round($px - $bandW / 2, 2) . '" y="' . $mt . '" width="' . round($bandW, 2) . '" height="' . $ph
                . '" fill="transparent" tabindex="0" role="img" aria-label="' . e($p['title'] . ': ' . $tip) . '"'
                . ' data-cx="' . $px . '" data-cy="' . $py . '"'
                . ' data-tip-title="' . e($p['title']) . '" data-tip-value="' . e($tip) . '" data-tip-color="var(--viz-1)"/>';
            $table[] = [$p['title'], Ui::n($p['value'])];
        }

        $lastX = round($x($n - 1), 2); $lastY = round($y($points[$n - 1]['value']), 2);
        $areaPath = trim($line) . ' L' . $lastX . ' ' . round($y(0), 2) . ' L' . round($x(0), 2) . ' ' . round($y(0), 2) . ' Z';

        return '<figure class="viz viz-area">'
            . '<svg class="area" viewBox="0 0 ' . $W . ' ' . $H . '" role="group" aria-label="' . e($caption) . '">'
            . $grid . $xlab
            . '<path class="area-fill" d="' . $areaPath . '"/>'                         // wash ~10%
            . '<path class="area-line" d="' . trim($line) . '"/>'                       // garis 2px
            . '<line class="xh" x1="0" x2="0" y1="' . $mt . '" y2="' . ($mt + $ph) . '"/>'
            . '<circle class="xdot" r="5" cx="0" cy="0"/>'
            . '<circle class="end-dot" cx="' . $lastX . '" cy="' . $lastY . '" r="4"/>' // titik >= 8px + cincin permukaan
            . '<text class="end-val" x="' . $lastX . '" y="' . ($lastY - 11) . '" text-anchor="end">' . Ui::n($points[$n - 1]['value']) . '</text>'
            . $bands
            . '</svg>'
            . self::tableTwin(['Periode', ucfirst($unit)], $table)
            . '</figure>';
    }

    // ---------- Linimasa (Gantt) rentang waktu ----------
    // Satu baris per penempatan: label di atas, batang tipis di bawahnya sepanjang masa jabatan.
    // Bentuk EMPHASIS: yang berjalan berwarna aksen, arsip abu-abu -- bukan dua warna kategori.
    // Warna tidak pernah sendirian: yang berjalan juga berlabel "· berjalan" dan tebal, ada legenda,
    // ada tooltip, dan ada padanan tabel (kontras abu-abu <3:1 sah karena penopang-penopang itu).
    // $rows: [['label'=>, 'sub'=>, 'start'=>Y-m-d, 'end'=>Y-m-d, 'current'=>bool], ...]
    public static function gantt(array $rows, $caption = '') {
        if (empty($rows)) return self::emptyState('Belum ada penempatan untuk ditampilkan.');
        usort($rows, function ($a, $b) { return strcmp($a['start'], $b['start']); });

        $today = strtotime(date('Y-m-d'));
        $minS = min(array_map(function ($r) { return strtotime($r['start']); }, $rows));
        $maxE = max(array_merge([$today], array_map(function ($r) { return strtotime($r['end']); }, $rows)));
        $y0 = (int) date('Y', $minS); $y1 = (int) date('Y', $maxE);
        $t0 = strtotime("$y0-01-01"); $t1 = strtotime(($y1 + 1) . '-01-01');

        // Lebar viewBox ~ lebar kartu di ponsel (±330px) supaya teks hampir 1:1; di layar lebar tampil
        // lebih besar tetapi dibatasi max-width di CSS.
        $W = 360; $ml = 12; $mr = 12; $top = 34; $rowH = 48;
        $n = count($rows); $H = $top + $n * $rowH + 24; $pw = $W - $ml - $mr;
        $x = function ($t) use ($t0, $t1, $ml, $pw) { return round($ml + ($t - $t0) / ($t1 - $t0) * $pw, 2); };

        // Garis tahun: tiap tahun bila rentang pendek, selang-seling bila panjang.
        $span = $y1 - $y0 + 1; $step = $span <= 6 ? 1 : ($span <= 12 ? 2 : 5);
        $grid = '';
        for ($y = $y0; $y <= $y1 + 1; $y += $step) {
            $gx = $x(strtotime("$y-01-01"));
            $grid .= '<line class="grid" x1="' . $gx . '" x2="' . $gx . '" y1="' . ($top - 8) . '" y2="' . ($H - 22) . '"/>'
                . '<text class="axis" x="' . ($gx + 3) . '" y="' . ($top - 14) . '">' . $y . '</text>';
        }

        $bars = ''; $hits = ''; $table = [];
        foreach ($rows as $i => $r) {
            $s = strtotime($r['start']); $e = strtotime($r['end']);
            $ry = $top + $i * $rowH;
            $bx = $x($s); $bw = max(6, $x($e) - $bx);
            $cls = !empty($r['current']) ? 'now' : 'past';
            $days = (int) round(($e - $s) / 86400);
            $status = !empty($r['current']) ? 'berjalan' : 'arsip';
            $title = $r['title'] ?? $r['label'];
            $tipVal = Ui::date($r['start']) . ' – ' . Ui::date($r['end']) . ' · ' . Ui::span($days) . ' · ' . $status;
            $color = !empty($r['current']) ? 'var(--gantt-now)' : 'var(--gantt-past)';

            $bars .= '<text class="g-label' . ($cls === 'now' ? ' now' : '') . '" x="' . $ml . '" y="' . ($ry + 13) . '">'
                . e($r['label']) . (!empty($r['current']) ? ' · berjalan' : '') . '</text>'
                . '<rect class="g-bar ' . $cls . '" x="' . $bx . '" y="' . ($ry + 21) . '" width="' . round($bw, 2) . '" height="10" rx="4"/>';
            // Area sentuh = seluruh lebar baris (tinggi 46px): jauh di atas 24px.
            $hits .= '<rect class="hit" x="0" y="' . $ry . '" width="' . $W . '" height="' . $rowH . '" fill="transparent" tabindex="0" role="img"'
                . ' aria-label="' . e($title . ', ' . $r['sub'] . ': ' . $tipVal) . '"'
                . ' data-tip-title="' . e($title . ' · ' . $r['sub']) . '" data-tip-value="' . e($tipVal) . '" data-tip-color="' . e($color) . '"/>';
            $table[] = [$title, $r['sub'], Ui::date($r['start']), Ui::date($r['end']), ucfirst($status)];
        }

        // Penanda "Hari ini".
        $tx = $x($today);
        $todayLine = '<line class="g-today" x1="' . $tx . '" x2="' . $tx . '" y1="' . ($top - 8) . '" y2="' . ($H - 22) . '"/>'
            . '<text class="axis today" x="' . $tx . '" y="' . ($H - 6) . '" text-anchor="middle">Hari ini</text>';

        $head = '';
        foreach (['Jabatan', 'Struktur', 'Mulai', 'Selesai', 'Status'] as $i => $h) $head .= '<th>' . e($h) . '</th>';
        $body = '';
        foreach ($table as $row) {
            $body .= '<tr>';
            foreach ($row as $i => $cell) $body .= ($i === 0 ? '<th scope="row">' : '<td>') . e($cell) . ($i === 0 ? '</th>' : '</td>');
            $body .= '</tr>';
        }

        return '<figure class="viz viz-gantt">'
            . '<svg class="gantt" viewBox="0 0 ' . $W . ' ' . $H . '" role="group" aria-label="' . e($caption) . '">'
            . $grid . $bars . $todayLine . $hits . '</svg>'
            . '<ul class="legend legend-inline"><li><span class="sw" style="background:var(--gantt-now)"></span><span class="lg-label">Berjalan</span></li>'
            . '<li><span class="sw" style="background:var(--gantt-past)"></span><span class="lg-label">Arsip</span></li></ul>'
            . '<details class="viz-table"><summary>Lihat sebagai tabel</summary><div class="table-wrap"><table><thead><tr>' . $head
            . '</tr></thead><tbody>' . $body . '</tbody></table></div></details></figure>';
    }
}
