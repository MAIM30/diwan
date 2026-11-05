<?php
// PATH LOKASI FILE: database/sinkron_sheet_dpp.php
// Menjadikan spreadsheet pengurus (tab DPP atau MWP) ACUAN UTAMA penempatan pengurus struktur itu. CLI:
//
//   php database/sinkron_sheet_dpp.php --user=diwan --pass=... --db=db_pengurus [--tab=DPP|MWP] [--csv=<berkas|URL>]
//                                      [--term=<id periode>] [--backup-dir=<folder>] [--commit]
//
// --tab bawaan DPP (periode bawaan #9). Untuk MWP, periode bawaan = periode berjalan struktur MWP. Tab MWP kolomnya
// NO, NAMA, JABATAN, NO. Hp, DEP LEMBAGA ("PI" = Pengurus Inti); kolom dibaca menurut nama headernya.
//
// Pilihan tambahan (semuanya MATI bila tak diberikan, jadi keluaran tab DPP tak berubah). Berguna bila sheet tanpa nomor HP (MWP):
//   --rinci        cetak juga daftar penempatan yang diubah / ditambah / diarsipkan.
//   --mirip        orang yang ejaan/gelar namanya sedikit beda, atau yang ada di database tetapi belum tercatat di periode ini,
//                  dicocokkan HANYA bila gelarnya sepadan (yang satu termuat di yang lain), jenis kelaminnya cocok (tab yang jenis
//                  kelaminnya pasti), kandidatnya TUNGGAL, dan salah satu buktinya ada:
//                    (a) nama >= 90% mirip dan ia pernah menempati UNIT BERNAMA SAMA (di struktur/periode/sayap mana pun, termasuk
//                        penempatan yang sudah diarsipkan; dicocokkan lewat nama karena katalog Umum dan Muslimah terpisah), atau
//                    (b) nama >= 95% mirip dan ia sudah tercatat di periode ini (dipakai juga untuk baris tanpa unit, mis. PI).
//   --buat-unit    unit yang ada di sheet tetapi belum di SKUO periode dibuat dari katalog unit SAYAP struktur itu (Pusat dan Badan
//                  masuk kelompok "Pusat-Pusat" / "Badan-Badan", Biro ke "Biro-Biro"; jenis lain tanpa kelompok dan dilaporkan).
//                  Katalog dipisah per sayap (migrasi 022): bila namanya hanya ada di katalog sayap lain (mis. "Badan Anggaran" di
//                  katalog Umum untuk tab MWP), dibuat SALINANNYA di katalog sayap struktur ini, bukan dipakai bersama.
//   --buat-orang   orang yang benar-benar tak ada dibuat di tabel people (jenis kelamin menurut tab: MWP = P), HANYA bila tak ada
//                  nama yang >= 85% mirip di mana pun di tabel people; yang punya kemiripan dilaporkan, tidak dibuat.
//
// Tanpa --commit semuanya dicoba lalu di-rollback dan hanya laporan yang dicetak. Aman dijalankan ulang:
// hasil yang sama tidak mengubah apa pun lagi. WAJIB mysqldump dulu sebelum --commit.
//
// ATURAN
//
//  1. Satu baris sheet = satu penempatan (orang, unit, jabatan). Unit dicocokkan ke unit milik periode
//     itu lewat namanya; "PENGURUS INTI" = penempatan tanpa unit. Baris "Divisi ..." di kolom jabatan =
//     Anggota pada unit "<Badan> - Divisi ...". Jabatan "KETUA" / "WAKIL KETUA" dibaca menurut tempatnya:
//     di PENGURUS INTI DPP = Ketua Bidang / Wakil Ketua Bidang (migrasi 020; struktur lain, mis. MWP, tetap Ketua / Wakil Ketua), di dalam unit = Ketua <jenis unit> (Departemen,
//     Lembaga, Biro, Badan; migrasi 021) / Wakil Ketua Departemen/Lembaga (migrasi 019).
//  2. Orang dicocokkan berlapis dan hanya bila PASTI: nomor HP sama + nama asli sama (kuat), atau nama
//     asli sama dan kandidat tunggal yang sudah tercatat di periode ini (sedang). Nama yang bisa dua
//     orang (kandidat ganda) TIDAK ditebak: barisnya dilaporkan dan SEMUA kandidatnya dilindungi dari
//     pengarsipan. Orang yang tak ditemukan tidak dibuat otomatis (butuh jenis kelamin dsb.).
//  3. Penempatan orang yang cocok disamakan dengan sheet: yang sudah sama dibiarkan; yang beda unit/
//     jabatan DIUBAH (id tetap); yang belum ada DITAMBAH (source 'sk', mengikuti SK periode).
//  4. Penempatan periode ini yang TIDAK ada di sheet DIARSIPKAN (deleted_at; bukan dihapus), hanya yang
//     berasal dari migrasi syuro. Yang masuk lewat pengajuan manual (source 'registrasi') atau dibuat admin
//     ('manual'/'sk') tidak disentuh. Data induk orang TIDAK PERNAH dihapus.
//  5. Nama diganti mengikuti sheet HANYA pada kecocokan kuat, setelah membuang karakter tak kasat mata dan
//     merapikan spasi. Gelar yang bertentangan dilaporkan, tidak diubah. Gelar yang ada di database tidak
//     pernah dibuang bila sheet tak mencantumkannya.
//  6. (Hanya tab DPP.) Pengajuan buatan sistem (submitted_ip kosong DAN extra_data.sumber terisi, yaitu impor biodata)
//     dihapus setelah dicadangkan ke JSON. Pengajuan dari formulir publik selalu punya IP, jadi tak pernah terkena.
//
// Setiap perubahan dicatat di audit_log dengan event 'sinkron_sheet_dpp' (tab DPP) atau 'sinkron_sheet_mwp' (tab MWP).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';

const SHEET_ID  = '1wJDsINpZ10Aahpv06WQVfZgxIKaPDCLWZvguS62Qof8';
const SHEET_URL = 'https://docs.google.com/spreadsheets/d/' . SHEET_ID . '/export?format=csv';   // tab pertama = DPP
const TABS      = ['DPP', 'MWP'];

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'csv::', 'tab::', 'term::', 'backup-dir::', 'commit', 'rinci', 'mirip', 'buat-unit', 'buat-orang']);
$commit  = isset($opts['commit']);
$rinci   = isset($opts['rinci']);   // cetak juga daftar penempatan yang diubah / ditambah / diarsipkan (keluaran bawaan tak berubah)
$tab     = strtoupper($opts['tab'] ?? 'DPP');
$mirip   = isset($opts['mirip']); $buatUnit = isset($opts['buat-unit']); $buatOrang = isset($opts['buat-orang']);
const GENDER_TAB = ['MWP' => 'P'];   // tab yang seluruh pengurusnya sudah pasti satu jenis kelamin (sayap Muslimah)
$gender  = GENDER_TAB[$tab] ?? null;
if ($buatOrang && $gender === null) { fwrite(STDERR, "BATAL: --buat-orang hanya untuk tab yang jenis kelaminnya pasti (" . implode(', ', array_keys(GENDER_TAB)) . ").\n"); exit(1); }
if (!in_array($tab, TABS, true)) { fwrite(STDERR, "BATAL: --tab harus salah satu dari " . implode(', ', TABS) . ".\n"); exit(1); }
define('EVENT', 'sinkron_sheet_' . strtolower($tab));
$termId  = (int) ($opts['term'] ?? ($tab === 'DPP' ? 9 : 0));   // 0 = periode berjalan struktur $tab
$csvSrc  = $opts['csv'] ?? ($tab === 'DPP' ? SHEET_URL : 'https://docs.google.com/spreadsheets/d/' . SHEET_ID . '/gviz/tq?tqx=out:csv&sheet=' . $tab);
$stamp   = date('Ymd_His');

function say($m = '') { echo $m . "\n"; }
function nk($s) { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); }
function pkey($full) { return implode('', BiodataMatcher::nameParts($full)['base']); }
function gkey($full) { return BiodataMatcher::nameParts($full)['gelar']; }

// Karakter tak kasat mata (zero-width, word joiner, BOM) yang ikut tertempel dari WhatsApp/Docs, spasi
// ganda, spasi sebelum koma, ",." yang terbalik, dan gelar yang menempel ke nama ("Kurniawan.S.H.").
function cleanName($s) {
    $s = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', (string) $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = preg_replace('/\s+,/u', ',', $s);
    $s = str_replace(',.', '.,', $s);
    $s = preg_replace('/,(?=\S)/u', ', ', $s);
    $s = preg_replace('/(?<=\p{Ll}{3})\.(?=\p{Lu}\.)/u', ' ', $s);
    return trim($s);
}

// ---------- Koneksi ----------
try {
    $pdo = new PDO('mysql:host=' . ($opts['host'] ?? 'localhost') . ';dbname=' . ($opts['db'] ?? 'db_pengurus') . ';charset=utf8mb4',
        $opts['user'] ?? 'root', $opts['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    fwrite(STDERR, "BATAL: tidak bisa terhubung ke database: " . $e->getMessage() . "\n"); exit(1);
}

if ($termId === 0) {   // periode berjalan struktur $tab; harus tepat satu
    $cur = $pdo->prepare("SELECT t.id FROM terms t JOIN structures s ON s.id = t.structure_id
                          WHERE s.level = :l AND s.deleted_at IS NULL AND t.is_current = 1 AND t.deleted_at IS NULL");
    $cur->execute([':l' => $tab]);
    $ids = $cur->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) !== 1) { fwrite(STDERR, "BATAL: periode berjalan $tab tidak tunggal (" . count($ids) . "). Beri --term.\n"); exit(1); }
    $termId = (int) $ids[0];
}
$term = $pdo->query("SELECT t.id, t.structure_id, t.name, t.start_date, t.sk_number, s.level, s.name AS sname
                     FROM terms t JOIN structures s ON s.id = t.structure_id WHERE t.id = " . $termId)->fetch();
if (!$term || $term['level'] !== $tab || substr($term['start_date'], 0, 4) !== '2026') {
    fwrite(STDERR, "BATAL: periode #$termId bukan $tab 2026-2031. Periksa --term / --tab.\n"); exit(1);
}
$structureId = (int) $term['structure_id'];
say(($commit ? 'MODE: COMMIT (perubahan DISIMPAN)' : 'MODE: DRY-RUN (tidak ada yang disimpan)') . " | periode #{$term['id']} {$term['level']} {$term['sname']} {$term['name']} | SK {$term['sk_number']}");

// ---------- Sheet ----------
if (is_file($csvSrc)) {
    $csvText = file_get_contents($csvSrc);
} else {
    $ch = curl_init($csvSrc);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    $csvText = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($csvText === false || $http !== 200) { fwrite(STDERR, "BATAL: gagal mengunduh sheet (HTTP $http).\n"); exit(1); }
}
$stream = fopen('php://memory', 'r+'); fwrite($stream, $csvText); rewind($stream);
$head = fgetcsv($stream);
// Kolom dicari menurut nama headernya (urutannya beda antar tab): NAMA, NO HP / NO. Hp, JABATAN, SKUO / DEP LEMBAGA.
$col = [];
foreach (($head ?: []) as $i => $h) {
    $k = nk($h);
    if ($k === 'nama') $col['nama'] = $i; elseif ($k === 'nohp') $col['hp'] = $i; elseif ($k === 'jabatan') $col['jab'] = $i;
    elseif ($k === 'skuo' || $k === 'deplembaga') $col['unit'] = $i;
}
if (count($col) < 4) {
    fwrite(STDERR, "BATAL: kolom sheet tidak sesuai (diharapkan NAMA, NO HP, JABATAN, SKUO atau DEP LEMBAGA).\n"); exit(1);
}
$rows = [];
while (($r = fgetcsv($stream)) !== false) {
    if (!isset($r[0]) || !ctype_digit(trim($r[0])) || count($r) <= max($col)) continue;
    $rows[] = ['no' => (int) $r[0], 'nama_asli' => $r[$col['nama']], 'nama' => cleanName($r[$col['nama']]),
               'hp' => Text::normalizePhone($r[$col['hp']]), 'jab' => cleanName($r[$col['jab']]), 'unit' => cleanName($r[$col['unit']])];
}
say("Sheet: " . count($rows) . " baris orang.");

// ---------- Acuan: jabatan & unit periode ----------
$positions = []; $posLabel = []; foreach ($pdo->query("SELECT id, name FROM positions") as $p) { $positions[nk($p['name'])] = (int) $p['id']; $posLabel[(int) $p['id']] = $p['name']; }
$posByLen = array_keys($positions); usort($posByLen, fn($a, $b) => strlen($b) - strlen($a));
$unitName = []; $unitsByNk = []; $unitKind = [];
foreach ($pdo->query("SELECT su.id, COALESCE(su.display_name, uc.name) AS name, uc.kind FROM structure_units su
                      LEFT JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                      WHERE su.term_id = $termId AND su.deleted_at IS NULL") as $u) {
    $unitName[(int) $u['id']] = $u['name']; $unitsByNk[nk($u['name'])] = (int) $u['id']; $unitKind[(int) $u['id']] = $u['kind'];
}
$anggotaId = $positions['anggota'] ?? null;

// Baris sheet -> [unit_id|null, position_id] atau null + alasan.
function resolveTarget(array $r, array $positions, array $posByLen, array $unitName, array $unitsByNk, array $unitKind, $anggotaId, $level, &$why) {
    $unitNk = nk($r['unit']); $jabNk = nk($r['jab']);
    $unitId = null; $posId = null;

    if (strpos($jabNk, 'divisi') === 0) {                  // "Divisi X" di kolom jabatan: Anggota di unit "<Badan> - Divisi X"
        $divisi = substr($jabNk, 6); $best = 0; $bestId = null;
        foreach ($unitName as $id => $nm) {
            if (stripos($nm, ' - Divisi') === false || strpos(nk($nm), $unitNk) !== 0) continue;
            similar_text($divisi, substr(nk($nm), strlen($unitNk) + 6), $pc);
            if ($pc > $best) { $best = $pc; $bestId = $id; }
        }
        if ($bestId === null || $best < 85) { $why = 'divisi "' . $r['jab'] . '" tak dikenali pada unit ' . $r['unit']; return null; }
        return [$bestId, $anggotaId];
    }
    foreach ($posByLen as $pn) if (strpos($jabNk, $pn) === 0) { $posId = $positions[$pn]; break; }   // "ANGGOTA Kalbar" -> Anggota
    if ($posId === null) { $why = 'jabatan "' . $r['jab'] . '" tak dikenali'; return null; }
    if (!in_array($unitNk, ['pengurusinti', 'pi'], true)) {   // tab DPP menulis "PENGURUS INTI", tab MWP "PI"
        if (!isset($unitsByNk[$unitNk])) { $why = 'unit "' . $r['unit'] . '" tak ada di periode ini'; return null; }
        $unitId = $unitsByNk[$unitNk];
        // Sheet hanya menulis "WAKIL KETUA"; di dalam unit jabatannya "Wakil Ketua Departemen/Lembaga" (migrasi 019).
        if ($posId === ($positions['wakilketua'] ?? null) && isset($positions['wakilketuadepartemenlembaga'])) $posId = $positions['wakilketuadepartemenlembaga'];
        // "KETUA" di dalam unit = Ketua <jenis unit> (migrasi 021: Departemen, Lembaga, Biro, Badan). Jenis lain (Divisi, Komisi,
        // ...) tak punya jabatan sendiri, jadi tetap "Ketua".
        $own = 'ketua' . ($unitKind[$unitId] ?? '');
        if ($posId === ($positions['ketua'] ?? null) && isset($positions[$own])) $posId = $positions[$own];
    } elseif ($level === 'DPP') {
        // PENGURUS INTI DPP: "KETUA" adalah Ketua Bidang dan "WAKIL KETUA" Wakil Ketua Bidang (migrasi 020). Struktur lain
        // (MWP) tak punya Bidang di tingkat ini: Ketua / Wakil Ketua Pengurus Inti tetap jabatan polos.
        if ($posId === ($positions['ketua'] ?? null) && isset($positions['ketuabidang'])) $posId = $positions['ketuabidang'];
        elseif ($posId === ($positions['wakilketua'] ?? null) && isset($positions['wakilketuabidang'])) $posId = $positions['wakilketuabidang'];
    }
    return [$unitId, $posId];
}

// ---------- Orang ----------
$people = []; $byKey = []; $byPhone = []; $pkOf = [];
foreach ($pdo->query("SELECT id, full_name, title_prefix, name, title_suffix, phone, gender FROM people WHERE deleted_at IS NULL") as $p) {
    $p['id'] = (int) $p['id']; $people[$p['id']] = $p;
    $pkOf[$p['id']] = pkey($p['full_name']);
    $byKey[$pkOf[$p['id']]][] = $p['id'];
    if ($p['phone'] !== null && $p['phone'] !== '') $byPhone[$p['phone']][] = $p['id'];
}
$inTerm = [];   // person_id => true: sudah punya penempatan di periode ini
foreach ($pdo->query("SELECT DISTINCT person_id FROM placements WHERE term_id = $termId AND deleted_at IS NULL") as $r) $inTerm[(int) $r['person_id']] = true;

// Mengembalikan ['person' => id, 'how' => 'hp'|'nama'] atau ['ambigu' => [id...]] atau ['none' => [id lepas...]].
function matchPerson(array $r, array $people, array $byKey, array $byPhone, array $inTerm) {
    $key = pkey($r['nama']); $loose = [];
    if ($r['hp'] !== null && isset($byPhone[$r['hp']])) {
        $loose = array_merge($loose, $byPhone[$r['hp']]);
        $same = array_values(array_filter($byPhone[$r['hp']], function ($id) use ($people, $key, $r) {
            if (pkey($people[$id]['full_name']) === $key) return true;
            similar_text(implode('', BiodataMatcher::nameParts($people[$id]['full_name'])['base']), $key, $pc);
            return $pc >= 90;
        }));
        if (count($same) === 1) return ['person' => $same[0], 'how' => 'hp'];
        if (count($same) > 1) return ['ambigu' => $same];
    }
    $cands = $byKey[$key] ?? [];
    $loose = array_merge($loose, $cands);
    $gs = gkey($r['nama']);
    $cands = array_values(array_filter($cands, fn($id) => $gs === '' || gkey($people[$id]['full_name']) === '' || gkey($people[$id]['full_name']) === $gs));
    // Yang sudah tercatat di periode ini didahulukan: sheet adalah daftar pengurus DPP, sedangkan orang bernama
    // sama yang hanya ada di periode lain bukan kandidat penempatan ini.
    $here = array_values(array_filter($cands, fn($id) => isset($inTerm[$id])));
    if (count($here) === 1) return ['person' => $here[0], 'how' => 'nama'];
    if (count($here) > 1) return ['ambigu' => $here];
    return ['none' => array_values(array_unique(array_merge($loose, $cands)))];
}

// Gelar sepadan: salah satunya kosong, atau huruf gelar yang satu termuat seluruhnya di yang lain ("S.P., M.I.Kom." memuat "M.I.Kom.").
// gkey() = huruf gelar terurut. "S.Si., Apt." vs "S.Pd., M.Pd." tidak sepadan, jadi tak pernah dianggap orang yang sama.
function gelarSepadan($a, $b) {
    if ($a === '' || $b === '') return true;
    [$small, $big] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
    $have = count_chars($big, 1);
    foreach (count_chars($small, 1) as $ch => $n) if (($have[$ch] ?? 0) < $n) return false;
    return true;
}

// Pencocokan MIRIP (--mirip): orang yang pernah menempati unit katalog yang sama (struktur/periode mana pun), namanya >= 90% mirip,
// gelarnya sepadan, jenis kelaminnya cocok. Kemiripan nama saja bukan bukti; unit yang sama itulah buktinya.
function matchMirip(array $r, $unitId, array $people, array $pkOf, array $peopleOfCatalog, array $catOfUnit, array $inTerm, $gender) {
    $key = pkey($r['nama']); $gs = gkey($r['nama']); $hit = [];
    $cat = $unitId === null ? null : ($catOfUnit[$unitId] ?? null);
    $layak = function ($pid, $min) use ($people, $pkOf, $key, $gs, $gender) {
        if (!isset($people[$pid])) return false;
        if ($gender !== null && ($people[$pid]['gender'] ?? null) !== $gender) return false;
        if (!gelarSepadan($gs, gkey($people[$pid]['full_name']))) return false;
        similar_text($key, $pkOf[$pid], $pc);
        return $pc >= $min;
    };
    foreach (array_keys($peopleOfCatalog[$cat] ?? []) as $pid) if ($layak($pid, 90)) $hit[$pid] = true;   // (a)
    foreach (array_keys($inTerm) as $pid) if ($layak($pid, 95)) $hit[$pid] = true;                         // (b)
    return array_keys($hit);
}

// Kandidat bernama mirip (>= 85%) di SELURUH tabel people, dipakai --buat-orang agar tak membuat data ganda.
function namaMirip($nama, array $pkOf) {
    $key = pkey($nama); $out = [];
    foreach ($pkOf as $id => $k) {
        if (abs(strlen($k) - strlen($key)) > 6) continue;
        similar_text($key, $k, $pc);
        if ($pc >= 85) $out[] = [(int) round($pc), $id];
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return array_slice($out, 0, 3);
}

// ---------- Cocokkan semua baris ----------
// Semua pembuatan (unit, kelompok, orang) dan perubahan di bawah ini terjadi DI DALAM satu transaksi: dry-run membatalkannya semua.
$pdo->beginTransaction();
$audit = $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, event, before_data, after_data, account_id, structure_id, ip)
                        VALUES (:e, :id, :a, :ev, :b, :af, NULL, :s, NULL)");
$created = ['unit' => [], 'kelompok' => [], 'katalog' => [], 'orang' => []];

// Bukti untuk --mirip: siapa saja yang pernah menempati unit bernama tertentu (penempatan mana pun, termasuk yang sudah diarsipkan --
// mis. pengurus Muslimah yang dulu salah masuk unit DPP), dan unit periode ini -> kunci namanya (nk). Lewat NAMA, bukan id katalog:
// katalog Umum dan Muslimah terpisah (migrasi 022), padahal "Pusat Penelitian dan Pengembangan" di DPP dan di MWP orang yang sama.
$peopleOfCatalog = []; $catOfUnit = [];
if ($mirip) {
    foreach ($pdo->query("SELECT pl.person_id, uc.name FROM placements pl JOIN structure_units su ON su.id = pl.structure_unit_id
                          JOIN unit_catalog uc ON uc.id = su.unit_catalog_id") as $x) $peopleOfCatalog[nk($x['name'])][(int) $x['person_id']] = true;
    foreach ($pdo->query("SELECT su.id, uc.name FROM structure_units su JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                          WHERE su.term_id = $termId AND su.deleted_at IS NULL") as $x) $catOfUnit[(int) $x['id']] = nk($x['name']);
}

// --buat-unit: katalog unit + pembuat unit/kelompok.
$ensureUnits = function (array $r) {};
if ($buatUnit) {
    $structureWing = $pdo->query("SELECT wing FROM structures WHERE id = $structureId")->fetchColumn();
    // Entri katalog menurut nama; yang SESAYAP dengan struktur ini dimuat paling akhir supaya menggantikan entri bernama sama dari sayap lain.
    $catByNk = [];
    foreach ($pdo->query("SELECT id, name, kind, wing, sort_order FROM unit_catalog ORDER BY (wing = " . $pdo->quote($structureWing) . ") ASC") as $c) $catByNk[nk($c['name'])] = $c;
    $groupIds = [];
    foreach ($pdo->query("SELECT id, name, sort_order FROM unit_groups WHERE term_id = $termId AND kind = 'grup' AND deleted_at IS NULL") as $g) $groupIds[$g['name']] = (int) $g['id'];
    $biroSort = (int) ($pdo->query("SELECT sort_order FROM unit_groups WHERE term_id = $termId AND kind = 'grup' AND name = 'Biro-Biro' AND deleted_at IS NULL")->fetchColumn() ?: 890);
    $groupOfKind = ['pusat' => ['Pusat-Pusat', $biroSort - 20], 'badan' => ['Badan-Badan', $biroSort - 10], 'biro' => ['Biro-Biro', $biroSort]];
    $insUnit  = $pdo->prepare("INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id, unit_group_id, sort_order) VALUES (:u, :s, :t, :c, :g, :o)");
    $insGroup = $pdo->prepare("INSERT INTO unit_groups (uuid, structure_id, term_id, name, kind, sort_order) VALUES (:u, :s, :t, :n, 'grup', :o)");

    $makeUnit = function (array $c) use (&$unitName, &$unitsByNk, &$unitKind, &$catOfUnit, &$groupIds, &$created, $groupOfKind, $insUnit, $insGroup, $pdo, $structureId, $termId, $audit, $structureWing) {
        if ($c['wing'] !== $structureWing) {   // hanya ada di katalog sayap lain: buat SALINANNYA di katalog sayap struktur ini
            $pdo->prepare("INSERT INTO unit_catalog (uuid, name, abbreviation, kind, wing, sort_order, default_head_position_id, is_active)
                           SELECT :u, name, abbreviation, kind, :w, sort_order, default_head_position_id, is_active FROM unit_catalog WHERE id = :id")
                ->execute([':u' => Uuid::v4(), ':w' => $structureWing, ':id' => (int) $c['id']]);
            $newCat = (int) $pdo->lastInsertId();
            audit($audit, 'unit_catalog', $newCat, 'create', null, ['name' => $c['name'], 'wing' => $structureWing, 'salinan_dari' => (int) $c['id']], $structureId);
            $created['katalog'][] = $c['name'] . " (salinan dari katalog sayap " . $c['wing'] . ")";
            $c['id'] = $newCat; $c['wing'] = $structureWing;
        }
        $gid = null; $gname = null;
        if (isset($groupOfKind[$c['kind']])) {
            [$gname, $gsort] = $groupOfKind[$c['kind']];
            if (!isset($groupIds[$gname])) {
                $insGroup->execute([':u' => Uuid::v4(), ':s' => $structureId, ':t' => $termId, ':n' => $gname, ':o' => $gsort]);
                $groupIds[$gname] = (int) $pdo->lastInsertId();
                audit($audit, 'unit_groups', $groupIds[$gname], 'create', null, ['name' => $gname, 'kind' => 'grup', 'term_id' => $termId], $structureId);
                $created['kelompok'][] = $gname;
            }
            $gid = $groupIds[$gname];
        }
        $insUnit->execute([':u' => Uuid::v4(), ':s' => $structureId, ':t' => $termId, ':c' => $c['id'], ':g' => $gid, ':o' => (int) $c['sort_order']]);
        $id = (int) $pdo->lastInsertId();
        audit($audit, 'structure_units', $id, 'create', null, ['unit_catalog_id' => (int) $c['id'], 'name' => $c['name'], 'unit_group_id' => $gid, 'term_id' => $termId], $structureId);
        $unitName[$id] = $c['name']; $unitsByNk[nk($c['name'])] = $id; $unitKind[$id] = $c['kind']; $catOfUnit[$id] = nk($c['name']);
        $created['unit'][] = $c['name'] . ($gname !== null ? " -> kelompok $gname" : ' -> TANPA kelompok/Bidang (tentukan sendiri)');
    };
    $ensureUnits = function (array $r) use (&$unitsByNk, &$unitName, $catByNk, $makeUnit) {
        $unitNk = nk($r['unit']);
        if (in_array($unitNk, ['pengurusinti', 'pi'], true)) return;
        if (!isset($unitsByNk[$unitNk]) && isset($catByNk[$unitNk])) $makeUnit($catByNk[$unitNk]);
        if (strpos(nk($r['jab']), 'divisi') === 0 && isset($unitsByNk[$unitNk])) {            // "Divisi X" -> unit "<Badan> - Divisi X"
            $divisi = substr(nk($r['jab']), 6); $best = 0; $bestC = null;
            foreach ($catByNk as $k => $c) {
                if (stripos($c['name'], ' - Divisi') === false || strpos($k, $unitNk) !== 0) continue;
                similar_text($divisi, substr($k, strlen($unitNk) + 6), $pc);
                if ($pc > $best) { $best = $pc; $bestC = $c; }
            }
            if ($bestC !== null && $best >= 85) {
                foreach ($unitName as $nm) if (nk($nm) === nk($bestC['name'])) return;       // sudah ada
                $makeUnit($bestC);
            }
        }
    };
}

$targets = [];        // person_id => [ ['no','unit','pos','jab_asli'] ... ]
$matched = [];        // person_id => ['how','nama','nama_asli']
$report = ['tak_dikenali' => [], 'ambigu' => [], 'tak_ditemukan' => [], 'mirip' => [], 'dibuat' => []];
$protect = [];        // person_id yang tak boleh diarsipkan penempatannya
foreach ($rows as $r) {
    $why = '';
    $ensureUnits($r);
    $t = resolveTarget($r, $positions, $posByLen, $unitName, $unitsByNk, $unitKind, $anggotaId, $term['level'], $why);
    $m = matchPerson($r, $people, $byKey, $byPhone, $inTerm);
    if ($t === null) {
        $report['tak_dikenali'][] = "#{$r['no']} {$r['nama']} -- $why";
        foreach (($m['ambigu'] ?? $m['none'] ?? [$m['person'] ?? 0]) as $id) $protect[$id] = true;
        if (isset($m['person'])) $protect[$m['person']] = true;
        continue;
    }
    if (isset($m['none']) && $mirip) {                        // --mirip: ejaan beda, tetapi unit yang sama membuktikannya
        $hit = matchMirip($r, $t[0], $people, $pkOf, $peopleOfCatalog, $catOfUnit, $inTerm, $gender);
        if (count($hit) === 1) {
            $m = ['person' => $hit[0], 'how' => 'mirip+unit'];
            $report['mirip'][] = "#{$r['no']} sheet: {$r['nama']} | database: {$people[$hit[0]]['full_name']} (id {$hit[0]}) | {$r['unit']}" . (isset($inTerm[$hit[0]]) ? '' : ' [belum di periode ini]');
        } elseif (count($hit) > 1) {
            $m = ['ambigu' => $hit];
        }
    }
    if (isset($m['ambigu'])) {
        $report['ambigu'][] = "#{$r['no']} {$r['nama']} ({$r['unit']}) -- kandidat id " . implode(', ', $m['ambigu']);
        foreach ($m['ambigu'] as $id) $protect[$id] = true;
        continue;
    }
    if (isset($m['none'])) {
        $near = ($mirip || $buatOrang) ? namaMirip($r['nama'], $pkOf) : [];
        if ($buatOrang && !$near) {                           // benar-benar baru: dibuat
            [$pre, $nm, $suf] = Text::normalizeNameParts('', $r['nama'], '');
            $full = Text::joinName($pre, $nm, $suf);
            $pdo->prepare("INSERT INTO people (uuid, title_prefix, name, title_suffix, name_normalized, gender, phone, status, portal_enabled, home_structure_id)
                           VALUES (:u, :pre, :nm, :suf, :norm, :g, NULL, 'active', 0, :h)")
                ->execute([':u' => Uuid::v4(), ':pre' => $pre, ':nm' => $nm, ':suf' => $suf, ':norm' => Text::normalizeBase($nm), ':g' => $gender, ':h' => $structureId]);
            $newId = (int) $pdo->lastInsertId();
            audit($audit, 'people', $newId, 'create', null, ['full_name' => $full, 'gender' => $gender, 'sumber' => 'sheet ' . $tab], $structureId);
            $people[$newId] = ['id' => $newId, 'full_name' => $full, 'title_prefix' => $pre, 'name' => $nm, 'title_suffix' => $suf, 'phone' => null];
            $pkOf[$newId] = pkey($full); $byKey[$pkOf[$newId]][] = $newId;
            $report['dibuat'][] = "#{$r['no']} $full ($gender) | {$r['jab']} | {$r['unit']} -> id $newId";
            $m = ['person' => $newId, 'how' => 'baru'];
        } else {
            $report['tak_ditemukan'][] = "#{$r['no']} {$r['nama']} | {$r['jab']} | {$r['unit']}"
                . ($near ? ' -- MIRIP: ' . implode(' ; ', array_map(fn($n) => "{$n[0]}% #{$n[1]} {$people[$n[1]]['full_name']}", array_slice($near, 0, 2))) : '');
            foreach ($m['none'] as $id) $protect[$id] = true;
            foreach ($near as $n) $protect[$n[1]] = true;     // kandidat mirip tak boleh kehilangan penempatannya diam-diam
            continue;
        }
    }
    $pid = $m['person'];
    $targets[$pid][] = ['no' => $r['no'], 'unit' => $t[0], 'pos' => $t[1]];
    if (!isset($matched[$pid])) $matched[$pid] = ['how' => $m['how'], 'nama' => $r['nama'], 'nama_asli' => $r['nama_asli']];
    elseif ($m['how'] === 'hp') $matched[$pid]['how'] = 'hp';
}

// ---------- Penempatan ----------
$live = [];   // person_id => [ id => row ]
foreach ($pdo->query("SELECT id, person_id, structure_unit_id, position_id, is_primary, source FROM placements
                      WHERE term_id = $termId AND deleted_at IS NULL") as $p) {
    $live[(int) $p['person_id']][(int) $p['id']] = $p;
}

function audit($audit, $entity, $id, $action, $before, $after, $structureId) {
    $audit->execute([':e' => $entity, ':id' => $id, ':a' => $action, ':ev' => EVENT,
        ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        ':af' => $after === null ? null : json_encode($after + ['event' => EVENT], JSON_UNESCAPED_UNICODE), ':s' => $structureId]);
}

$detail = ['diubah' => [], 'ditambah' => [], 'diarsipkan' => []];
// "Pengurus Inti / Ketua" atau "<unit> / <jabatan>" untuk daftar rinci.
$lbl = function ($unit, $pos) use ($unitName, $posLabel) { return ($unit ? ($unitName[(int) $unit] ?? ('unit #' . $unit)) : 'Pengurus Inti') . ' / ' . ($posLabel[(int) $pos] ?? ('jabatan #' . $pos)); };
$stat = ['sama' => 0, 'diubah' => 0, 'ditambah' => 0, 'diarsipkan' => 0, 'dipertahankan_bukan_migrasi' => 0, 'dilindungi' => 0];
$errors = []; $primaryCandidate = [];

$upd = $pdo->prepare("UPDATE placements SET structure_unit_id = :u, position_id = :p WHERE id = :id");
$ins = $pdo->prepare("INSERT INTO placements (uuid, person_id, term_id, structure_id, structure_unit_id, position_id, is_primary, source, sk_reference)
                      VALUES (:uuid, :pid, :term, :str, :u, :p, 0, 'sk', :sk)");

foreach ($targets as $pid => $list) {
    $mine = $live[$pid] ?? []; $claimed = [];
    $seen = []; $list = array_values(array_filter($list, function ($t) use (&$seen) { $k = ($t['unit'] ?? 0) . '-' . $t['pos']; return isset($seen[$k]) ? false : ($seen[$k] = true); }));

    $open = [];
    foreach ($list as $t) {                                   // 1) sama persis
        $hit = null;
        foreach ($mine as $id => $pl) if (!isset($claimed[$id]) && (int) $pl['structure_unit_id'] === (int) ($t['unit'] ?? 0) && (int) $pl['position_id'] === $t['pos']) { $hit = $id; break; }
        if ($hit !== null) { $claimed[$hit] = true; $stat['sama']++; $primaryCandidate[$pid][$t['no']] = $hit; } else $open[] = $t;
    }
    foreach ($open as $t) {                                   // 2) pakai ulang penempatan migrasi yang belum terpakai, 3) tambah
        $pick = null;
        foreach ([fn($pl) => (int) $pl['structure_unit_id'] === (int) ($t['unit'] ?? 0),
                  fn($pl) => (int) $pl['position_id'] === $t['pos'],
                  fn($pl) => true] as $rule) {
            foreach ($mine as $id => $pl) if (!isset($claimed[$id]) && $pl['source'] === 'migrasi_syuro' && $rule($pl)) { $pick = $id; break 2; }
        }
        try {
            if ($pick !== null) {
                $before = $mine[$pick]; $claimed[$pick] = true;
                $upd->execute([':u' => $t['unit'], ':p' => $t['pos'], ':id' => $pick]);
                audit($audit, 'placements', $pick, 'update', ['structure_unit_id' => $before['structure_unit_id'], 'position_id' => $before['position_id']],
                      ['structure_unit_id' => $t['unit'], 'position_id' => $t['pos'], 'person_id' => $pid], $structureId);
                $stat['diubah']++; $primaryCandidate[$pid][$t['no']] = $pick;
                $detail['diubah'][] = $people[$pid]['full_name'] . ': ' . $lbl($before['structure_unit_id'], $before['position_id']) . ' -> ' . $lbl($t['unit'], $t['pos']);
            } else {
                $ins->execute([':uuid' => Uuid::v4(), ':pid' => $pid, ':term' => $termId, ':str' => $structureId, ':u' => $t['unit'], ':p' => $t['pos'], ':sk' => $term['sk_number']]);
                $newId = (int) $pdo->lastInsertId(); $claimed[$newId] = true;
                audit($audit, 'placements', $newId, 'create', null, ['person_id' => $pid, 'structure_unit_id' => $t['unit'], 'position_id' => $t['pos']], $structureId);
                $stat['ditambah']++; $primaryCandidate[$pid][$t['no']] = $newId;
                $detail['ditambah'][] = $people[$pid]['full_name'] . ': ' . $lbl($t['unit'], $t['pos']);
            }
        } catch (PDOException $e) { $errors[] = "orang #$pid unit " . ($t['unit'] ?? '-') . " jabatan {$t['pos']}: " . $e->getMessage(); }
    }
    foreach ($mine as $id => $pl) {                           // 4) sisanya tidak ada di sheet
        if (isset($claimed[$id])) continue;
        if ($pl['source'] !== 'migrasi_syuro') { $stat['dipertahankan_bukan_migrasi']++; continue; }
        $pdo->prepare("UPDATE placements SET deleted_at = NOW(3) WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
        audit($audit, 'placements', $id, 'delete', ['person_id' => $pid, 'structure_unit_id' => $pl['structure_unit_id'], 'position_id' => $pl['position_id']], null, $structureId);
        $stat['diarsipkan']++;
        $detail['diarsipkan'][] = $people[$pid]['full_name'] . ': ' . $lbl($pl['structure_unit_id'], $pl['position_id']);
    }
}

// Orang yang TIDAK ada di sheet sama sekali: arsipkan penempatan migrasinya, kecuali yang dilindungi.
foreach ($live as $pid => $mine) {
    if (isset($targets[$pid])) continue;
    foreach ($mine as $id => $pl) {
        if (isset($protect[$pid])) { $stat['dilindungi']++; continue; }
        if ($pl['source'] !== 'migrasi_syuro') { $stat['dipertahankan_bukan_migrasi']++; continue; }
        $pdo->prepare("UPDATE placements SET deleted_at = NOW(3) WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
        audit($audit, 'placements', $id, 'delete', ['person_id' => $pid, 'structure_unit_id' => $pl['structure_unit_id'], 'position_id' => $pl['position_id']], null, $structureId);
        $stat['diarsipkan']++;
        $detail['diarsipkan'][] = $people[$pid]['full_name'] . ': ' . $lbl($pl['structure_unit_id'], $pl['position_id']);
    }
}

// Satu penempatan utama per orang yang disentuh: bila tak ada yang utama, pilih baris sheet pertamanya.
$promoted = 0;
foreach ($primaryCandidate as $pid => $byNo) {
    $has = (int) $pdo->query("SELECT COUNT(*) FROM placements WHERE person_id = $pid AND term_id = $termId AND deleted_at IS NULL AND is_primary = 1")->fetchColumn();
    if ($has > 0) continue;
    ksort($byNo); $first = reset($byNo);
    $pdo->prepare("UPDATE placements SET is_primary = 1 WHERE id = ?")->execute([$first]);
    audit($audit, 'placements', $first, 'update', ['is_primary' => 0], ['is_primary' => 1, 'person_id' => $pid], $structureId);
    $promoted++;
}

// ---------- Nama ----------
$names = ['sama' => 0, 'diganti' => 0, 'lemah_dilewati' => 0, 'gelar_dipertahankan' => 0, 'bentrok' => []];
$upName = $pdo->prepare("UPDATE people SET title_prefix = :pre, name = :nm, title_suffix = :suf, name_normalized = :norm WHERE id = :id");
foreach ($matched as $pid => $m) {
    $db = $people[$pid];
    [$pre, $nm, $suf] = Text::normalizeNameParts('', $m['nama'], '');
    if ($pre === null && $db['title_prefix'] !== null && $db['title_prefix'] !== '') $pre = $db['title_prefix'];   // jangan buang gelar depan
    $newFull = Text::joinName($pre, $nm, $suf);
    if ($newFull === $db['full_name']) { $names['sama']++; continue; }
    if ($m['how'] !== 'hp') { $names['lemah_dilewati']++; continue; }

    $gDb = gkey($db['full_name']); $gSh = gkey($m['nama']);
    if ($gDb !== '' && $gSh === '') { $names['gelar_dipertahankan']++; continue; }
    if ($gDb !== '' && $gSh !== '' && $gDb !== $gSh) { $names['bentrok'][] = "#$pid DB: {$db['full_name']}  |  sheet: {$m['nama']}"; continue; }
    try {
        $upName->execute([':pre' => $pre, ':nm' => $nm, ':suf' => $suf, ':norm' => Text::normalizeBase($nm), ':id' => $pid]);
        audit($audit, 'people', $pid, 'update', ['full_name' => $db['full_name']], ['full_name' => $newFull], $structureId);
        $names['diganti']++;
    } catch (PDOException $e) { $errors[] = "nama orang #$pid: " . $e->getMessage(); }
}

// ---------- Pengajuan buatan sistem ----------
$sys = $tab === 'DPP' ? $pdo->query("SELECT * FROM registration_requests WHERE submitted_ip IS NULL AND JSON_EXTRACT(extra_data, '$.sumber') IS NOT NULL")->fetchAll() : [];
$pengajuan = ['dihapus' => count($sys), 'cadangan' => null];

// ---------- Hasil ----------
say("\n== PENEMPATAN $tab");
say("  sama dengan sheet: {$stat['sama']} | diubah unit/jabatan: {$stat['diubah']} | ditambah: {$stat['ditambah']} | penempatan utama ditetapkan: $promoted");
say("  diarsipkan (tidak ada di sheet, asal migrasi): {$stat['diarsipkan']} | dilindungi (kandidat kasus ambigu/tak ditemukan): {$stat['dilindungi']} | dipertahankan (bukan migrasi): {$stat['dipertahankan_bukan_migrasi']}");
say("\n== NAMA");
say("  sudah sama: {$names['sama']} | diganti mengikuti sheet: {$names['diganti']} | beda tapi kecocokan lemah (dilewati): {$names['lemah_dilewati']} | gelar database dipertahankan: {$names['gelar_dipertahankan']} | gelar bertentangan: " . count($names['bentrok']));
if ($tab === 'DPP') {
    say("\n== PENGAJUAN SISTEM: {$pengajuan['dihapus']} akan dihapus");
    foreach ($sys as $s) say("  #{$s['id']} {$s['full_name']} [{$s['status']}]");
}

foreach (['tak_dikenali' => 'BARIS SHEET TAK BISA DIPROSES (unit/jabatan tak dikenali)', 'ambigu' => 'BARIS AMBIGU (orang ganda di database) -- diputuskan manual', 'tak_ditemukan' => 'ORANG TAK DITEMUKAN DI DATABASE -- tidak dibuat otomatis'] as $k => $label) {
    say("\n== $label: " . count($report[$k]));
    foreach ($report[$k] as $line) say("  $line");
}
say("\n== NAMA BERTENTANGAN (gelar beda, tidak diubah): " . count($names['bentrok']));
foreach ($names['bentrok'] as $line) say("  $line");
if ($created['kelompok'] || $created['unit']) {
    say("\n== UNIT DIBUAT dari katalog (--buat-unit): " . count($created['unit']) . ($created['kelompok'] ? " | kelompok baru: " . implode(', ', $created['kelompok']) : ''));
    foreach ($created['unit'] as $line) say("  $line");
}
if ($created['katalog']) {
    say("\n== ENTRI KATALOG BARU di sayap " . $structureWing . " (salinan dari sayap lain): " . count($created['katalog']));
    foreach ($created['katalog'] as $line) say("  $line");
}
if ($report['dibuat']) { say("\n== ORANG BARU DIBUAT (--buat-orang): " . count($report['dibuat'])); foreach ($report['dibuat'] as $line) say("  $line"); }
if ($report['mirip']) { say("\n== DICOCOKKAN KARENA NAMA MIRIP + BUKTI UNIT/PERIODE (--mirip) -- mohon dicek: " . count($report['mirip'])); foreach ($report['mirip'] as $line) say("  $line"); }
if ($rinci) {
    foreach (['diubah' => 'DIUBAH (unit/jabatan disamakan dengan sheet)', 'ditambah' => 'DITAMBAH', 'diarsipkan' => 'DIARSIPKAN (tidak ada di sheet, asal migrasi)'] as $k => $label) {
        say("\n== RINCI $label: " . count($detail[$k]));
        sort($detail[$k]);
        foreach ($detail[$k] as $line) say("  $line");
    }
}
if ($errors) { say("\n== GALAT: " . count($errors)); foreach ($errors as $e) say("  $e"); }

if (!$commit) { $pdo->rollBack(); say("\nDRY-RUN selesai: tidak ada yang disimpan. Tambahkan --commit setelah mysqldump."); exit($errors ? 2 : 0); }
if ($errors) { $pdo->rollBack(); fwrite(STDERR, "\nBATAL: ada galat, semua perubahan dibatalkan.\n"); exit(2); }

// Cadangan pengajuan HARUS tertulis sebelum dihapus; gagal menulis = batal total.
if ($sys) {
    $dir = rtrim($opts['backup-dir'] ?? (ROOT_DIR . '/storage/backup'), '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { $pdo->rollBack(); fwrite(STDERR, "BATAL: folder cadangan $dir tak bisa dibuat. Beri --backup-dir yang bisa ditulis.\n"); exit(2); }
    $file = "$dir/pengajuan_sistem_$stamp.json";
    if (@file_put_contents($file, json_encode($sys, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { $pdo->rollBack(); fwrite(STDERR, "BATAL: gagal menulis $file.\n"); exit(2); }
    foreach ($sys as $s) audit($audit, 'registration_requests', (int) $s['id'], 'delete', ['full_name' => $s['full_name'], 'status' => $s['status']], null, (int) $s['target_structure_id']);
    $pdo->exec("DELETE FROM registration_requests WHERE submitted_ip IS NULL AND JSON_EXTRACT(extra_data, '$.sumber') IS NOT NULL");
    say("\nCadangan pengajuan: $file");
}
$pdo->commit();
say("\nCOMMIT selesai.");
