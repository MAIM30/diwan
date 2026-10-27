<?php
// PATH LOKASI FILE: database/sinkron_sheet_dpp.php
// Menjadikan spreadsheet "PENGURUS DPP 2026-2031" ACUAN UTAMA penempatan pengurus DPP. CLI:
//
//   php database/sinkron_sheet_dpp.php --user=diwan --pass=... --db=db_pengurus [--csv=<berkas|URL>] [--term=9]
//                                      [--backup-dir=<folder>] [--commit]
//
// Tanpa --commit semuanya dicoba lalu di-rollback dan hanya laporan yang dicetak. Aman dijalankan ulang:
// hasil yang sama tidak mengubah apa pun lagi. WAJIB mysqldump dulu sebelum --commit.
//
// ATURAN
//
//  1. Satu baris sheet = satu penempatan (orang, unit, jabatan). Unit dicocokkan ke unit milik periode
//     itu lewat namanya; "PENGURUS INTI" = penempatan tanpa unit. Baris "Divisi ..." di kolom jabatan =
//     Anggota pada unit "<Badan> - Divisi ...".
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
//  6. Pengajuan buatan sistem (submitted_ip kosong DAN extra_data.sumber terisi, yaitu impor biodata) dihapus
//     setelah dicadangkan ke JSON. Pengajuan dari formulir publik selalu punya IP, jadi tak pernah terkena.
//
// Setiap perubahan dicatat di audit_log dengan event 'sinkron_sheet_dpp'.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';

const SHEET_URL = 'https://docs.google.com/spreadsheets/d/1wJDsINpZ10Aahpv06WQVfZgxIKaPDCLWZvguS62Qof8/export?format=csv';
const EVENT = 'sinkron_sheet_dpp';

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'csv::', 'term::', 'backup-dir::', 'commit']);
$commit  = isset($opts['commit']);
$termId  = (int) ($opts['term'] ?? 9);
$csvSrc  = $opts['csv'] ?? SHEET_URL;
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

$term = $pdo->query("SELECT t.id, t.structure_id, t.name, t.start_date, t.sk_number, s.level, s.name AS sname
                     FROM terms t JOIN structures s ON s.id = t.structure_id WHERE t.id = " . $termId)->fetch();
if (!$term || $term['level'] !== 'DPP' || substr($term['start_date'], 0, 4) !== '2026') {
    fwrite(STDERR, "BATAL: periode #$termId bukan DPP 2026-2031. Periksa --term.\n"); exit(1);
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
if (!$head || nk($head[1] ?? '') !== 'nama' || nk($head[4] ?? '') !== 'skuo') {
    fwrite(STDERR, "BATAL: kolom sheet tidak sesuai (diharapkan NO, NAMA, NO HP, JABATAN, SKUO).\n"); exit(1);
}
$rows = [];
while (($r = fgetcsv($stream)) !== false) {
    if (count($r) < 5 || !ctype_digit(trim($r[0]))) continue;
    $rows[] = ['no' => (int) $r[0], 'nama_asli' => $r[1], 'nama' => cleanName($r[1]),
               'hp' => Text::normalizePhone($r[2]), 'jab' => cleanName($r[3]), 'unit' => cleanName($r[4])];
}
say("Sheet: " . count($rows) . " baris orang.");

// ---------- Acuan: jabatan & unit periode ----------
$positions = []; foreach ($pdo->query("SELECT id, name FROM positions") as $p) $positions[nk($p['name'])] = (int) $p['id'];
$posByLen = array_keys($positions); usort($posByLen, fn($a, $b) => strlen($b) - strlen($a));
$unitName = []; $unitsByNk = [];
foreach ($pdo->query("SELECT su.id, COALESCE(su.display_name, uc.name) AS name FROM structure_units su
                      LEFT JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                      WHERE su.term_id = $termId AND su.deleted_at IS NULL") as $u) {
    $unitName[(int) $u['id']] = $u['name']; $unitsByNk[nk($u['name'])] = (int) $u['id'];
}
$anggotaId = $positions['anggota'] ?? null;

// Baris sheet -> [unit_id|null, position_id] atau null + alasan.
function resolveTarget(array $r, array $positions, array $posByLen, array $unitName, array $unitsByNk, $anggotaId, &$why) {
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
    if ($unitNk !== 'pengurusinti') {
        if (!isset($unitsByNk[$unitNk])) { $why = 'unit "' . $r['unit'] . '" tak ada di periode ini'; return null; }
        $unitId = $unitsByNk[$unitNk];
    }
    return [$unitId, $posId];
}

// ---------- Orang ----------
$people = []; $byKey = []; $byPhone = [];
foreach ($pdo->query("SELECT id, full_name, title_prefix, name, title_suffix, phone FROM people WHERE deleted_at IS NULL") as $p) {
    $p['id'] = (int) $p['id']; $people[$p['id']] = $p;
    $byKey[pkey($p['full_name'])][] = $p['id'];
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

// ---------- Cocokkan semua baris ----------
$targets = [];        // person_id => [ ['no','unit','pos','jab_asli'] ... ]
$matched = [];        // person_id => ['how','nama','nama_asli']
$report = ['tak_dikenali' => [], 'ambigu' => [], 'tak_ditemukan' => []];
$protect = [];        // person_id yang tak boleh diarsipkan penempatannya
foreach ($rows as $r) {
    $why = '';
    $t = resolveTarget($r, $positions, $posByLen, $unitName, $unitsByNk, $anggotaId, $why);
    $m = matchPerson($r, $people, $byKey, $byPhone, $inTerm);
    if ($t === null) {
        $report['tak_dikenali'][] = "#{$r['no']} {$r['nama']} -- $why";
        foreach (($m['ambigu'] ?? $m['none'] ?? [$m['person'] ?? 0]) as $id) $protect[$id] = true;
        if (isset($m['person'])) $protect[$m['person']] = true;
        continue;
    }
    if (isset($m['ambigu'])) {
        $report['ambigu'][] = "#{$r['no']} {$r['nama']} ({$r['unit']}) -- kandidat id " . implode(', ', $m['ambigu']);
        foreach ($m['ambigu'] as $id) $protect[$id] = true;
        continue;
    }
    if (isset($m['none'])) {
        $report['tak_ditemukan'][] = "#{$r['no']} {$r['nama']} | {$r['jab']} | {$r['unit']}";
        foreach ($m['none'] as $id) $protect[$id] = true;
        continue;
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

$audit = $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, event, before_data, after_data, account_id, structure_id, ip)
                        VALUES (:e, :id, :a, :ev, :b, :af, NULL, :s, NULL)");
function audit($audit, $entity, $id, $action, $before, $after, $structureId) {
    $audit->execute([':e' => $entity, ':id' => $id, ':a' => $action, ':ev' => EVENT,
        ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        ':af' => $after === null ? null : json_encode($after + ['event' => EVENT], JSON_UNESCAPED_UNICODE), ':s' => $structureId]);
}

$stat = ['sama' => 0, 'diubah' => 0, 'ditambah' => 0, 'diarsipkan' => 0, 'dipertahankan_bukan_migrasi' => 0, 'dilindungi' => 0];
$errors = []; $primaryCandidate = [];
$pdo->beginTransaction();

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
            } else {
                $ins->execute([':uuid' => Uuid::v4(), ':pid' => $pid, ':term' => $termId, ':str' => $structureId, ':u' => $t['unit'], ':p' => $t['pos'], ':sk' => $term['sk_number']]);
                $newId = (int) $pdo->lastInsertId(); $claimed[$newId] = true;
                audit($audit, 'placements', $newId, 'create', null, ['person_id' => $pid, 'structure_unit_id' => $t['unit'], 'position_id' => $t['pos']], $structureId);
                $stat['ditambah']++; $primaryCandidate[$pid][$t['no']] = $newId;
            }
        } catch (PDOException $e) { $errors[] = "orang #$pid unit " . ($t['unit'] ?? '-') . " jabatan {$t['pos']}: " . $e->getMessage(); }
    }
    foreach ($mine as $id => $pl) {                           // 4) sisanya tidak ada di sheet
        if (isset($claimed[$id])) continue;
        if ($pl['source'] !== 'migrasi_syuro') { $stat['dipertahankan_bukan_migrasi']++; continue; }
        $pdo->prepare("UPDATE placements SET deleted_at = NOW(3) WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
        audit($audit, 'placements', $id, 'delete', ['person_id' => $pid, 'structure_unit_id' => $pl['structure_unit_id'], 'position_id' => $pl['position_id']], null, $structureId);
        $stat['diarsipkan']++;
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
$sys = $pdo->query("SELECT * FROM registration_requests WHERE submitted_ip IS NULL AND JSON_EXTRACT(extra_data, '$.sumber') IS NOT NULL")->fetchAll();
$pengajuan = ['dihapus' => count($sys), 'cadangan' => null];

// ---------- Hasil ----------
say("\n== PENEMPATAN DPP");
say("  sama dengan sheet: {$stat['sama']} | diubah unit/jabatan: {$stat['diubah']} | ditambah: {$stat['ditambah']} | penempatan utama ditetapkan: $promoted");
say("  diarsipkan (tidak ada di sheet, asal migrasi): {$stat['diarsipkan']} | dilindungi (kandidat kasus ambigu/tak ditemukan): {$stat['dilindungi']} | dipertahankan (bukan migrasi): {$stat['dipertahankan_bukan_migrasi']}");
say("\n== NAMA");
say("  sudah sama: {$names['sama']} | diganti mengikuti sheet: {$names['diganti']} | beda tapi kecocokan lemah (dilewati): {$names['lemah_dilewati']} | gelar database dipertahankan: {$names['gelar_dipertahankan']} | gelar bertentangan: " . count($names['bentrok']));
say("\n== PENGAJUAN SISTEM: {$pengajuan['dihapus']} akan dihapus");
foreach ($sys as $s) say("  #{$s['id']} {$s['full_name']} [{$s['status']}]");

foreach (['tak_dikenali' => 'BARIS SHEET TAK BISA DIPROSES (unit/jabatan tak dikenali)', 'ambigu' => 'BARIS AMBIGU (orang ganda di database) -- diputuskan manual', 'tak_ditemukan' => 'ORANG TAK DITEMUKAN DI DATABASE -- tidak dibuat otomatis'] as $k => $label) {
    say("\n== $label: " . count($report[$k]));
    foreach ($report[$k] as $line) say("  $line");
}
say("\n== NAMA BERTENTANGAN (gelar beda, tidak diubah): " . count($names['bentrok']));
foreach ($names['bentrok'] as $line) say("  $line");
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
