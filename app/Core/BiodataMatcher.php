<?php
// PATH LOKASI FILE: app/Core/BiodataMatcher.php
// Fungsi murni untuk membaca isian formulir "Biodata / Profil Singkat Pengurus" yang ditulis bebas:
// tanggal lahir, telepon, nama (untuk dicocokkan dengan orang yang sudah ada), dan jabatan/bidang
// (untuk dipetakan ke katalog unit & jabatan). Dipakai database/import_biodata.php; tanpa akses
// basis data supaya bisa diuji sendiri (tests/biodata_matcher_test.php).
//
// Sikap umum: LEBIH BAIK TIDAK TAHU daripada salah. Tanggal lahir yang ragu dikembalikan null (bukan
// ditebak), dan pemetaan yang tak pasti tidak pernah dipakai sebagai satu-satunya dasar mencocokkan orang.

class BiodataMatcher {
    // ---------- Teks dasar ----------

    // Huruf kecil, selain huruf/angka jadi spasi: "LP2KS/Bidang V" -> "lp2ks bidang v".
    public static function norm($s) {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    // Isian kosong ("--", "-", "N/A", "tidak ada") -> null. Selain itu dirapikan spasinya.
    public static function clean($s) {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        if ($s === '' || preg_match('/^[-–—_.\s]+$/u', $s)) return null;
        if (preg_match('/^(n\/?a|tidak ada|belum ada|nihil|none)$/i', $s)) return null;
        return $s;
    }

    // ---------- Tanggal & tempat lahir ----------

    const MONTHS = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'pebruari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'ags' => 8, 'agt' => 8, 'agust' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'november' => 11, 'nopember' => 11, 'nov' => 11, 'desember' => 12, 'des' => 12,
    ];

    // "Lonrae, 27 Mei 2000" -> ['place' => 'Lonrae', 'date' => '2000-05-27'].
    // Tanggal TIDAK ditebak: bulan yang tak dikenal ("5 Menit 1968") atau tanggal mustahil -> date = null,
    // dan 'doubt' berisi teks aslinya supaya manusia yang memutuskan.
    public static function parseTtl($raw, $today = null) {
        $raw = self::clean($raw);
        if ($raw === null) return ['place' => null, 'date' => null, 'doubt' => null];
        $maxYear = (int) date('Y', $today ?: time()) - 10;   // pengurus berusia di bawah 10 tahun = salah ketik

        $place = null; $date = null;
        // 30 Agustus 1976 | 30Agustus 1976 | 09, Agustus 2000 | 5-Mei-1990
        if (preg_match('/^(.*?)[\s,;]*\b(\d{1,2})\s*[,.\-\/]?\s*([A-Za-z]+)\s*[,.\-\/]?\s*(\d{4})\s*$/u', $raw, $m)) {
            $place = $m[1];
            $mon = self::MONTHS[mb_strtolower($m[3])] ?? null;
            $date = $mon ? [(int) $m[4], $mon, (int) $m[2]] : null;
        } elseif (preg_match('/^(.*?)[\s,;]*\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\s*$/u', $raw, $m)) {   // 12-05-1990 (hari-bulan-tahun)
            $place = $m[1];
            $date = [(int) $m[4], (int) $m[3], (int) $m[2]];
        } elseif (preg_match('/^(.*?)[\s,;]*\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\s*$/u', $raw, $m)) {   // 1990-05-12
            $place = $m[1];
            $date = [(int) $m[2], (int) $m[3], (int) $m[4]];
        }

        $place = $place !== null ? trim($place, " \t,.;-") : null;
        $iso = null;
        if ($date && $date[0] >= 1930 && $date[0] <= $maxYear && checkdate($date[1], $date[2], $date[0])) {
            $iso = sprintf('%04d-%02d-%02d', $date[0], $date[1], $date[2]);
        }
        return [
            'place' => ($place !== null && $place !== '') ? $place : null,
            'date'  => $iso,
            'doubt' => ($iso === null) ? $raw : null,
        ];
    }

    // ---------- Telepon ----------

    // Nomor pertama yang sah dari isian yang mungkin berisi beberapa ("0812.. / 0813..").
    public static function phone($raw) {
        $raw = self::clean($raw);
        if ($raw === null) return null;
        foreach (preg_split('/[\/,;]|\s+(?:dan|atau)\s+|\s{2,}/u', $raw) as $part) {
            $p = Text::normalizePhone($part);
            if ($p !== null) return $p;
        }
        return null;
    }

    // ---------- Nama ----------

    private const SUFFIX_TOK = ['spd','mpd','spdi','mpdi','se','sh','mh','mm','mt','mp','ms','msi','ssi','skm','mkm','mkes',
        'stp','sp','spt','mpt','st','sst','ssos','ssosi','mikom','mag','ma','lc','lcma','ba','bsc','msc','mba','phd','sag','sthi',
        'shi','mhi','mei','me','sei','sk','sfarm','mfarm','apt','skom','mkom','spsi','psi','mpsi','sip','sikom','amd','amdkep',
        'skep','ners','ns','mars','sphut','mhut','shut','spi','mpi','sap','map','mhum','shum','sstp','sit','mit','mtp','mtr',
        'dipl','cprm','pia','gr','llm','mtkom','spkom','kn','mkn','sh','sy','ssy','msc','mscm','sstr','tr','strt','kg','skg','dr','drs','dra','drg','drh'];
    private const PREFIX_TOK = ['dr','drs','dra','drg','drh','prof','ir','h','hj','haji','hajjah','kh','ust','ustadz','ustadzah','ustz','ns','apt','dt','tgk','gr'];

    // Nama -> ['base' => token nama asli (tanpa gelar, Muhammad dibakukan), 'gelar' => huruf gelar belakang
    // terurut]. Pembakuan "M./Muh./Muhd" -> muhammad HANYA di token pertama (di tengah nama, "M" bisa inisial).
    public static function nameParts($full) {
        $s = mb_strtolower(trim((string) $full));
        $comma = mb_strpos($s, ',');
        $main = $comma === false ? $s : mb_substr($s, 0, $comma);
        $tail = $comma === false ? '' : mb_substr($s, $comma + 1);

        $main = preg_replace('/[^\p{L}\s\.]/u', ' ', $main);
        $tok = [];
        foreach (preg_split('/\s+/u', trim($main), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            // "spt.msi" / "Abd.Rahman": kata utuh (>= 2 huruf) yang disambung titik = dua token. Singkatan
            // satu huruf ("S.Pd", "A.M") tidak dipecah.
            if (preg_match('/^\p{L}{2,}(?:\.\p{L}{2,})+\.?$/u', $p)) {
                foreach (explode('.', rtrim($p, '.')) as $part) $tok[] = $part;
                continue;
            }
            $k = str_replace('.', '', $p);
            if ($k !== '') $tok[] = $k;
        }
        while (count($tok) > 1 && in_array($tok[0], self::PREFIX_TOK, true)) array_shift($tok);
        $popped = [];
        while (count($tok) > 1 && in_array(end($tok), self::SUFFIX_TOK, true)) array_unshift($popped, array_pop($tok));

        if ($tok && in_array($tok[0], ['m', 'muh', 'muhd', 'mohd', 'mohammad', 'muhamad', 'mohamad', 'muhammad'], true)) $tok[0] = 'muhammad';

        $g = preg_replace('/[^a-z]/', '', implode('', $popped) . $tail);
        $chars = str_split($g); sort($chars);
        return ['base' => $tok, 'gelar' => implode('', $chars)];
    }

    // Kemiripan 0-100 antara dua nama asli (huruf saja), untuk salah eja ("Abdi" ~ "Abdy").
    public static function similarity(array $a, array $b) {
        similar_text(implode('', $a), implode('', $b), $pc);
        return $pc;
    }

    // Nama yang jelas bukan nama orang (alamat, uji coba) -> tidak boleh dicocokkan/didaftarkan otomatis.
    public static function looksLikeName($full) {
        $n = self::norm($full);
        if (preg_match('/^(test|testing|tes|coba|asdf|xxx)\b/', $n)) return false;
        if (preg_match('/\b(btn|jl|jalan|perumahan|perum|blok|rt|rw|kompleks|komplek)\b/', $n)) return false;
        if (preg_match('/\d/', $n)) return false;
        return mb_strlen(preg_replace('/[^\p{L}]/u', '', $n)) >= 3;
    }

    // ---------- Jabatan ----------

    // Berurutan dari yang paling spesifik; tiap pencocokan dihapus dari teks supaya "wakil ketua"
    // tidak ikut dihitung sebagai "ketua".
    const POSITION_PATTERNS = [
        'Wakil Sekretaris Jenderal' => '/\b(wakil sekretaris jenderal|wasekjen|wakil sekjen)\b/',
        'Sekretaris Jenderal'       => '/\b(sekretaris jenderal|sekjen)\b/',
        'Wakil Bendahara Umum'      => '/\bwakil bendahara umum\b/',
        'Bendahara Umum'            => '/\bbendahara umum\b/',
        'Ketua Umum'                => '/\bketua umum\b/',
        'Ketua Harian'              => '/\bketua harian\b/',
        'Wakil Ketua'               => '/\b(wakil ketua|waket)\b/',
        'Wakil Sekretaris'          => '/\b(wakil sekretaris|wasek)\b/',
        'Wakil Bendahara'           => '/\bwakil bendahara\b/',
        'Wakil Kepala'              => '/\bwakil kepala\b/',
        'Wakil Koordinator'         => '/\bwakil koordinator\b/',
        'Tim Pakar'                 => '/\btim pakar\b/',
        'Ketua'                     => '/\bketua\b/',
        'Sekretaris'                => '/\bsekretaris\b/',
        'Bendahara'                 => '/\bbendahara\b/',
        'Koordinator'               => '/\bkoordinator\b/',
        'Kepala'                    => '/\bkepala\b/',
        'Pengarah'                  => '/\bpengarah\b/',
        'Anggota'                   => '/\b(anggota|staff|staf)\b/',
    ];

    // Unit katalog -> pola pada teks ternormalisasi. Nama unit harus persis sama dengan
    // unit_catalog.name. Singkatan & ejaan yang terlihat di formulir sungguhan dikumpulkan di sini.
    const UNIT_PATTERNS = [
        'Departemen Dakwah dan Pembinaan Masjid' => '/\b(ddpm|dakwah|masjid|mesjid)\b/',
        'Departemen Kaderisasi dan Pembinaan Murabbi' => '/\b(kaderisasi|dep kader)\b/',
        'Departemen Pengembangan Sumber Daya Manusia' => '/\b(psdm|pengembangan sumber daya manusia)\b/',
        'Lembaga Pendidikan Dai dan Murabbi' => '/\b(lpdm|pendidikan dai)\b/',
        "Lembaga Pengembangan Pendidikan Dasar Al-Qur'an" => '/\b(lp2dq|lppdq|lpdq|pendidikan dasar al ?qur ?an|pendidikan dasar alquran)\b/',
        "Lembaga Pengembangan Pengajaran Fahmul Qur'an" => '/\b(lp2fq|lppfq|fahmul)\b/',
        "Lembaga Pengembangan Pengajaran Tahsinul Qira'ah" => '/\b(lp2tq|lpptq|lpptqa|tahsin\w*|qira ?ah)\b/',
        "Lembaga Pengembangan Tahfizh Al-Qur'an" => '/\b(lptq|tahfi[zd]\w*)\b/',
        'Lembaga Pembinaan dan Pengembangan Anak dan Remaja' => '/\b(lppar|lpppar|lp2ar|anak (dan|&) remaja)\b/',
        'Lembaga Pembinaan Lanjut Usia' => '/\b(lplu|lanjut usia)\b/',
        'Lembaga Pernikahan dan Pembinaan Keluarga Sakinah' => '/\b(lp2ks|p2ks|lpks|pernikahan dan pembinaan keluarga|ketahanan keluarga)\b/',
        'Departemen Pendidikan Anak Usia Dini, Nonformal dan Informal' => '/\b(paud|nonformal|non formal)\b/',
        'Departemen Pendidikan Dasar dan Menengah' => '/\b(dikdasmen|depdikdasmen|pendidikan dasar dan menengah)\b/',
        'Departemen Pendidikan Tinggi' => '/\b(dikti|pendidikan tinggi)\b/',
        'Lembaga Pembinaan Yayasan Pendidikan' => '/\b(lpyp|pembinaan yayasan|yayasan pendidikan|ypwi|ypwiy|lp yp)\b|\byayasan\b/',
        'Departemen Urusan Luar Negeri' => '/\b(luar negeri|duln)\b/',
        'Departemen Urusan Wilayah dan Daerah' => '/\b(duwd|dud|urusan wilayah( dan)? daerah|urusan wilayah dan daerah)\b/',
        'Lembaga Pembinaan dan Pengembangan Organisasi Otonom' => '/\b(lp2ortom|ortom|organisasi otonom|lp2o)\b/',
        'Departemen Kesehatan' => '/\b(kesehatan|depkes|soskes|dep kes)\b/',
        'Departemen Lingkungan Hidup' => '/\b(lingkungan hidup|dlh|dep lh|lh)\b/',
        'Departemen Olahraga dan Seni' => '/\b(olah ?raga|orsen|dep orsen|seni)\b/',
        'Departemen Sosial' => '/\b(depsos|sosial)\b/',
        'Departemen Pembinaan Koperasi, Usaha Mikro, Kecil dan Menengah' => '/\b(umkm|koperasi)\b/',
        'Departemen Pengembangan Usaha' => '/\b(pengembangan usaha|dpu)\b/',
        'Departemen Pertanian' => '/\b(pertanian|deptan)\b/',
        'Lembaga Amil Zakat Wahdah Inspirasi Zakat' => '/\b(wiz|laz|wahdah inspirasi zakat|zakat)\b/',
        'Lembaga Pendamping Proses Produk Halal' => '/\b(produk halal|lp3h|ppph)\b/',
        'Lembaga Perencanaan dan Pengawasan Pembangunan' => '/\b(lp3|perencanaan dan pengawasan)\b/',
        'Lembaga Wakaf' => '/\bwakaf\b/',
        'Departemen Pembinaan Mahasiswa' => '/\b(pembinaan mahasiswa|dep mahasiswa)\b/',
        'Departemen Pembinaan Pelajar' => '/\b(pembinaan pelajar|dep pelajar)\b/',
        'Departemen Pembinaan Pemuda' => '/\b(pembinaan pemuda|dep pemuda)\b/',
        'Departemen Data dan Teknologi Informasi' => '/\b(datin|data dan teknologi|teknologi informasi)\b/',
        'Departemen Media Informasi dan Komunikasi' => '/\b(medikom|infokom|media informasi)\b/',
        'Pusat Penelitian dan Pengembangan' => '/\b(litbang|penelitian dan pengembangan|penelitian)\b/',
        'Pusat Penjaminan Mutu' => '/\b(ppm|penjamin(an)? mutu)\b/',
        'Satuan Pengawas Internal' => '/\b(spi|satuan pengawas)\b/',
        'Badan Anggaran' => '/\b(banggar|badan anggaran)\b/',
        'Badan Pencegahan dan Penanggulangan Stunting' => '/\b(stunting|bp2s)\b/',
        'Biro Administrasi Umum dan Kesekretariatan' => '/\b(adumsekra|kesekretariatan|kesektariatan|administrasi umum)\b/',
        'Biro Hubungan Masyarakat dan Kerja Sama' => '/\b(humas|hubungan masyarakat|humbungan masyarakat)\b/',
        'Biro Hukum dan Advokasi' => '/\b(hukum|advokasi)\b/',
        'Biro Kantor DPP Jakarta' => '/\b(kantor dpp jakarta|kantor jakarta|bjkt)\b/',
        'Biro Kantor Pusat' => '/\bkantor pusat\b/',
        'Biro Kearsipan Digital dan Pustaka' => '/\b(kearsipan|pustaka)\b/',
        'Biro Kepegawaian' => '/\bkepegawaian\b/',
        'Biro Keuangan dan Aset' => '/\b(biro (keuangan|aset)|keuangan dan aset|biro aset|aset)\b/',
        'Biro Protokoler Pimpinan' => '/\bprotokoler\b/',
    ];

    // Jabatan pucuk pimpinan DPP: dipegang di tingkat struktur, TANPA unit. Isian "Bidang" untuk jabatan
    // ini ("Kesetjenan", "Wakil Bendahara Umum") bukan unit SKUO, jadi tak boleh dipasangkan.
    const PUCUK_POSITIONS = ['Wakil Sekretaris Jenderal', 'Sekretaris Jenderal', 'Wakil Bendahara Umum', 'Bendahara Umum', 'Ketua Umum', 'Ketua Harian'];

    // Bidang (kelompok unit) yang bisa disebut sendiri di formulir. Dipakai sebagai petunjuk yang lebih
    // lemah daripada unit: "Bidang Ketahanan Keluarga" cocok dengan unit mana pun di bidang itu.
    const GROUP_PATTERNS = [
        'Bidang Ketahanan Keluarga' => '/\bketahanan keluarga\b/',
        "Bidang Khidmah Al-Qur'an" => '/\b(khidmah|khidmat|khitmat|khitmah|khiodmatul|khidmatul|khitmatul) ?(al|ul)? ?(qur ?an|quran)\b|\bkhidmah\b|\bkhidmat\w*\b|\bkhitmat\w*\b/',
        'Bidang Pendidikan' => '/\bbidang pendidikan\b/',
        'Bidang Keorganisasian' => '/\bkeorganisasian\b/',
        'Bidang Pelayanan Umat' => '/\bpelayanan (umat|ummat)\b/',
        'Bidang Ekonomi dan Keuangan' => '/\bekonomi dan keuangan\b/',
        'Bidang Pembinaan Generasi Muda' => '/\bgenerasi muda\b/',
        'Bidang Transformasi Digital' => '/\btransformasi digital\b/',
        'Bidang Dakwah, Kaderisasi dan Pengembangan Sumber Daya Manusia' => '/\bdakwah kaderisasi\b/',
    ];

    // Tanda klausa yang menyebut badan DAERAH/WILAYAH ("Ketua DPD WI Sragen"), bukan unit pusat.
    private const REGIONAL = '/\b(dpw|dpd|dpc|dpra|cabang|cab|daerah (kab|kota)|sulsel|sultra|sulteng|jawa tengah|bangka belitung)\b/';

    // Memecah satu isian menjadi klausa. Pemecahan HANYA di batas klausa baru: titik-koma/baris baru
    // selalu; koma, "dan", "&", "serta" hanya bila yang SESUDAHNYA diawali kata pembuka klausa
    // (jabatan; untuk isian Bidang juga jenis unit). Dengan begitu
    //   "Anggota Departemen Dakwah, Kaderisasi, & PSDM DPW WI Jawa Tengah"   tetap satu klausa
    //       (penanda daerah "DPW" berlaku untuk seluruh daftar, bukan cuma potongan terakhir),
    //   "Sekretaris Biro Aset dan Anggota Deptan"                            pecah jadi dua,
    //   "Anggota Departemen Dakwah dan Pembinaan Masjid"                     tidak pecah.
    public static function clauses($text, $forUnits = false) {
        $t = self::clean($text);
        if ($t === null) return [];
        $opener = 'wakil|ketua|sekretaris|bendahara|anggota|koordinator|kepala|staf|staff|pengarah';
        if ($forUnits) $opener .= '|departemen|dep|lembaga|biro|badan|pusat|divisi|bidang|dpp|dpw|dpd|dpc';
        $t = preg_replace('/\s*(?:,|;|\s&\s|\sdan\s|\sserta\s)\s*(?=(?:' . $opener . ')\b)/iu', "\n", $t);
        $out = [];
        foreach (preg_split('/[\n;]+/u', $t) as $c) {
            $c = trim($c, " \t,");
            if ($c !== '') $out[] = $c;
        }
        return $out;
    }

    public static function positionsIn($text) {
        $t = ' ' . self::norm($text) . ' ';
        $found = [];
        foreach (self::POSITION_PATTERNS as $name => $rx) {
            if (preg_match($rx, $t)) { $found[] = $name; $t = preg_replace($rx, ' ', $t); }
        }
        return $found;
    }

    public static function unitsIn($text) {
        $t = ' ' . self::norm($text) . ' ';
        $found = [];
        foreach (self::UNIT_PATTERNS as $name => $rx) {
            if (preg_match($rx, $t)) $found[] = $name;
        }
        // "Lembaga Pengembangan Pengajaran Tahsinul Qira'ah" vs "...Tahfizh": kata kunci yang
        // sama-sama muncul dalam satu klausa berarti dua unit, tetapi "pendidikan dasar al qur'an" tidak
        // boleh ikut menyalakan "Pendidikan Dasar dan Menengah" -- yang lebih spesifik menang.
        if (in_array("Lembaga Pengembangan Pendidikan Dasar Al-Qur'an", $found, true)) {
            $found = array_values(array_diff($found, ['Departemen Pendidikan Dasar dan Menengah']));
        }
        return $found;
    }

    public static function groupsIn($text) {
        $t = ' ' . self::norm($text) . ' ';
        $found = [];
        foreach (self::GROUP_PATTERNS as $name => $rx) if (preg_match($rx, $t)) $found[] = $name;
        return $found;
    }

    // Hasil akhir pembacaan jabatan: pasangan [jabatan, unit] per klausa. Klausa yang hanya menyebut
    // jabatan memakai unit dari isian "Bidang/Departemen/Lembaga" bila di sana TEPAT satu unit.
    // 'regional' berisi klausa yang menyebut badan daerah (dilewati, tidak dicocokkan ke unit pusat).
    public static function parseRole($jabatan, $bidang) {
        $pairs = []; $regional = []; $unitsAll = []; $posAll = [];

        $bidangUnits = [];
        foreach (self::clauses($bidang, true) as $c) {
            if (preg_match(self::REGIONAL, ' ' . self::norm($c) . ' ') && !preg_match('/\bdpp\b/', self::norm($c))) continue;
            $bidangUnits = array_merge($bidangUnits, self::unitsIn($c));
        }
        $bidangUnits = array_values(array_unique($bidangUnits));
        $bidangGroups = self::groupsIn($bidang);

        foreach (self::clauses($jabatan) as $c) {
            $n = ' ' . self::norm($c) . ' ';
            if (preg_match(self::REGIONAL, $n) && !preg_match('/\bdpp\b/', $n)) { $regional[] = $c; continue; }
            $pos = self::positionsIn($c);
            $units = self::unitsIn($c);
            $p = $pos[0] ?? null;
            if ($p !== null && in_array($p, self::PUCUK_POSITIONS, true)) $units = [];
            elseif (!$units && $p !== null && count($bidangUnits) === 1) $units = $bidangUnits;
            if ($units) foreach ($units as $u) { $pairs[] = [$p, $u]; $unitsAll[$u] = true; }
            elseif ($p !== null) $pairs[] = [$p, null];
            if ($p !== null) $posAll[$p] = true;
        }
        foreach ($bidangUnits as $u) $unitsAll[$u] = true;
        return [
            'pairs' => $pairs, 'units' => array_keys($unitsAll), 'groups' => $bidangGroups,
            'positions' => array_keys($posAll), 'regional' => $regional,
        ];
    }
}
