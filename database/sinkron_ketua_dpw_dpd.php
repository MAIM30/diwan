<?php
// PATH LOKASI FILE: database/sinkron_ketua_dpw_dpd.php
// Memasukkan Ketua DPW dan Ketua DPD menurut tab "DPW" dan "DPD" di Google Sheet pengurus ke jabatan
// "Ketua DPW" / "Ketua DPD" (dibuat migrasi 018). CLI:
//
//   php database/sinkron_ketua_dpw_dpd.php --user=diwan --pass=... --db=db_pengurus [--dpw=<berkas|URL>] [--dpd=<berkas|URL>]
//                                          [--ketua-lain-jadi-anggota] [--buat-orang='Nama Lengkap|HP|L;Nama Lain|HP|P']
//                                          [--commit]
//
// Opsi tambahan (tanpa keduanya, perilaku sama seperti semula):
//   --ketua-lain-jadi-anggota   di struktur yang Ketua DPW/DPD-nya BERHASIL dipasang, penempatan "Ketua" polos lain di tingkat struktur
//                               (Pengurus Inti, tanpa unit) yang dipegang orang yang bukan Ketua menurut sheet diubah menjadi "Anggota".
//                               Struktur yang Ketuanya tak dapat dipasang (ragu / tak ditemukan) TIDAK disentuh. Orang yang sudah Anggota
//                               di struktur itu tak bisa dijadikan Anggota lagi (satu orang tak boleh dua kali Anggota di tempat yang sama),
//                               jadi baris "Ketua" polosnya yang berlebih dianulir (hapus lunak, tercatat di audit_log).
//   --buat-orang='Nama|HP|L'   orang yang tak ditemukan di database tetapi diketahui benar (nama lengkap, nomor WA, L/P) dibuat di tabel
//                               people, lalu dipasang sebagai Ketua. Hanya dibuat bila HP-nya sama dengan baris sheet yang tak ditemukan.
//                               Beberapa orang dipisah titik koma. Tanpa akses portal (portal_enabled = 0) sampai admin menyalakannya.
//
// Tanpa --commit semuanya dicoba lalu di-rollback dan hanya laporan yang dicetak. Aman dijalankan ulang: hasil yang
// sama tidak mengubah apa pun lagi. WAJIB mysqldump dulu sebelum --commit.
//
// ATURAN
//
//  1. Satu baris sheet = satu Ketua. Baris tanpa nama Ketua dilewati. Struktur dicocokkan lewat nama provinsi (DPW)
//     atau provinsi + nama daerah (DPD), dan penempatannya masuk ke periode BERJALAN struktur itu. Struktur yang
//     tak punya periode berjalan dilaporkan, tidak dibuatkan periode.
//  2. Orang dicocokkan hanya bila PASTI: nomor HP sama + nama asli sama (kuat), atau nama asli sama dan kandidat
//     tunggal yang sudah tercatat di periode itu (sedang). Selebihnya DILAPORKAN beserta kandidatnya, tidak ditebak.
//     Orang yang tak ditemukan tidak dibuat otomatis (jenis kelamin wajib diisi dan sheet tidak memuatnya).
//  3. Bagi orang yang cocok: sudah Ketua DPW/DPD di struktur itu -> dibiarkan; sudah berjabatan "Ketua" polos di
//     tingkat struktur itu -> jabatannya DIGANTI (id penempatan tetap); orang ganda (baris people lain bernama sama
//     yang memegang "Ketua" polos di struktur itu) -> penempatan milik baris itu yang DIGANTI; selain itu ->
//     penempatan DITAMBAH (source 'manual', catatan menyebut sumbernya). Penempatan lain TIDAK disentuh.
//  4. Pemegang Ketua DPW/DPD lain yang tak ada di sheet tidak diubah; hanya dilaporkan sebagai bentrok.
//  5. Data induk orang tidak pernah diubah atau dihapus.
//
// Setiap perubahan dicatat di audit_log dengan event 'sinkron_ketua_dpw_dpd'.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';

const SHEET_ID = '1wJDsINpZ10Aahpv06WQVfZgxIKaPDCLWZvguS62Qof8';
const EVENT    = 'sinkron_ketua_dpw_dpd';

// Nama provinsi di sheet yang ditulis lain daripada di tabel structures.
const PROVINSI_ALIAS = ['diyogyakarta' => 'daerahistimewayogyakarta'];

// Nama daerah di sheet yang ditulis lain daripada di tabel structures (kunci dan nilai lewat nk(), tanpa provinsi).
const DAERAH_ALIAS = [
    'kabkepulauanseribu'     => 'kabadministrasikepulauanseribu',
    'kotajakartapusat'       => 'kotaadministrasijakartapusat',
    'kotajakartautara'       => 'kotaadministrasijakartautara',
    'kotajakartabarat'       => 'kotaadministrasijakartabarat',
    'kotajakartaselatan'     => 'kotaadministrasijakartaselatan',
    'kotajakartatimur'       => 'kotaadministrasijakartatimur',
    'kabpangkajenekepulauan' => 'kabpangkajenedankepulauan',
    'kabpahuwato'            => 'kabpohuwato',
];

$opts   = getopt('', ['db::', 'host::', 'user::', 'pass::', 'dpw::', 'dpd::', 'commit', 'ketua-lain-jadi-anggota', 'buat-orang::']);
$commit = isset($opts['commit']);
$jadiAnggota = isset($opts['ketua-lain-jadi-anggota']);
// --buat-orang='Nama Lengkap|HP|L;...' -> [HP ternormalisasi => ['nama' => ..., 'gender' => 'L'|'P']]
$buatOrang = [];
foreach (array_filter(array_map('trim', explode(';', (string) ($opts['buat-orang'] ?? '')))) as $spec) {
    $p = array_map('trim', explode('|', $spec));
    $hp = count($p) === 3 ? Text::normalizePhone($p[1]) : null;
    if ($hp === null || $p[0] === '' || !in_array($p[2], ['L', 'P'], true)) {
        fwrite(STDERR, "BATAL: --buat-orang harus berbentuk 'Nama Lengkap|HP|L' atau 'Nama Lengkap|HP|P' (diterima: \"$spec\").\n"); exit(1);
    }
    $buatOrang[$hp] = ['nama' => $p[0], 'gender' => $p[2]];
}
$srcs   = [
    'DPW' => $opts['dpw'] ?? 'https://docs.google.com/spreadsheets/d/' . SHEET_ID . '/gviz/tq?tqx=out:csv&sheet=DPW',
    'DPD' => $opts['dpd'] ?? 'https://docs.google.com/spreadsheets/d/' . SHEET_ID . '/gviz/tq?tqx=out:csv&sheet=DPD',
];

function say($m = '') { echo $m . "\n"; }
function nk($s) { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); }
function pkey($full) { return implode('', BiodataMatcher::nameParts($full)['base']); }
function gkey($full) { return BiodataMatcher::nameParts($full)['gelar']; }

// Karakter tak kasat mata (zero-width, word joiner, BOM) yang ikut tertempel dari WhatsApp/Docs, dan spasi ganda.
function cleanName($s) {
    $s = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', (string) $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

// Nomor yang tersimpan sebagai angka di sheet bisa tiba sebagai "6.285267760902E12" bila diekspor lewat xlsx.
function hpFromSheet($s) {
    $s = trim((string) $s);
    if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) $s = sprintf('%.0f', (float) $s);
    return Text::normalizePhone($s);
}

function readCsv($src) {
    if (is_file($src)) {
        $text = file_get_contents($src);
    } else {
        $ch = curl_init($src);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
        $text = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($text === false || $http !== 200) { fwrite(STDERR, "BATAL: gagal mengunduh sheet (HTTP $http): $src\n"); exit(1); }
    }
    $stream = fopen('php://memory', 'r+'); fwrite($stream, $text); rewind($stream);
    $rows = [];
    while (($r = fgetcsv($stream)) !== false) $rows[] = $r;
    return $rows;
}

// ---------- Koneksi ----------
try {
    $pdo = new PDO('mysql:host=' . ($opts['host'] ?? 'localhost') . ';dbname=' . ($opts['db'] ?? 'db_pengurus') . ';charset=utf8mb4',
        $opts['user'] ?? 'root', $opts['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    fwrite(STDERR, "BATAL: tidak bisa terhubung ke database: " . $e->getMessage() . "\n"); exit(1);
}
say($commit ? 'MODE: COMMIT (perubahan DISIMPAN)' : 'MODE: DRY-RUN (tidak ada yang disimpan)');

// ---------- Jabatan ----------
$posId = [];
foreach ($pdo->query("SELECT id, code FROM positions WHERE code IN ('ketua', 'ketua_dpw', 'ketua_dpd', 'anggota')") as $p) $posId[$p['code']] = (int) $p['id'];
foreach (['ketua', 'ketua_dpw', 'ketua_dpd', 'anggota'] as $c) {
    if (!isset($posId[$c])) { fwrite(STDERR, "BATAL: jabatan '$c' belum ada. Jalankan database/migrate.php dulu (migrasi 018).\n"); exit(1); }
}
$target = ['DPW' => $posId['ketua_dpw'], 'DPD' => $posId['ketua_dpd']];

// ---------- Struktur & periode berjalan ----------
$prov = []; $byProvId = [];          // nk(nama provinsi) => id struktur DPW
$dpd  = [];                          // nk(provinsi) . '|' . nk(daerah) => id struktur DPD
$sname = [];                         // id => 'DPW Aceh' / 'DPD Aceh Kab. Aceh Besar'
foreach ($pdo->query("SELECT id, name FROM structures WHERE level = 'DPW' AND deleted_at IS NULL") as $s) {
    $prov[nk($s['name'])] = (int) $s['id']; $byProvId[(int) $s['id']] = nk($s['name']); $sname[(int) $s['id']] = 'DPW ' . $s['name'];
}
foreach ($pdo->query("SELECT d.id, d.name, d.parent_id FROM structures d WHERE d.level = 'DPD' AND d.deleted_at IS NULL") as $s) {
    if (!isset($byProvId[(int) $s['parent_id']])) continue;
    $dpd[$byProvId[(int) $s['parent_id']] . '|' . nk($s['name'])] = (int) $s['id'];
    $sname[(int) $s['id']] = 'DPD ' . $s['name'];
}
$termOf = [];                        // id struktur => id periode berjalan
foreach ($pdo->query("SELECT structure_id, id FROM terms WHERE is_current = 1 AND deleted_at IS NULL") as $t) $termOf[(int) $t['structure_id']] = (int) $t['id'];

// ---------- Baca sheet ----------
$rows = [];                           // ['tab','no','structure','prov','daerah','nama','hp']
$report = ['struktur_tak_dikenali' => [], 'tanpa_periode' => [], 'tanpa_nama' => 0];
foreach ($srcs as $tab => $src) {
    $data = readCsv($src);
    $head = array_map('nk', array_shift($data) ?: []);
    $col  = array_flip($head);
    foreach (['provinsi', 'namaketua', 'waketua'] as $need) {
        if (!isset($col[$need]) || ($tab === 'DPD' && !isset($col['daerah']))) {
            fwrite(STDERR, "BATAL: kolom tab $tab tidak sesuai (diharapkan Provinsi" . ($tab === 'DPD' ? ', Daerah' : '') . ", Nama Ketua, WA Ketua).\n"); exit(1);
        }
    }
    $n = 0;
    foreach ($data as $r) {
        if (!isset($r[0]) || !ctype_digit(preg_replace('/\.0+$/', '', trim($r[0])))) continue;
        $n++;
        $nama = cleanName($r[$col['namaketua']] ?? '');
        $provNk = nk($r[$col['provinsi']] ?? ''); $provNk = PROVINSI_ALIAS[$provNk] ?? $provNk;
        $daerah = $tab === 'DPD' ? cleanName($r[$col['daerah']] ?? '') : '';
        $label  = $tab . ' ' . trim($r[$col['provinsi']] ?? '') . ($daerah !== '' ? ' / ' . $daerah : '');
        if ($nama === '') { $report['tanpa_nama']++; continue; }

        $sid = $tab === 'DPW' ? ($prov[$provNk] ?? null) : ($dpd[$provNk . '|' . (DAERAH_ALIAS[nk($daerah)] ?? nk($daerah))] ?? null);
        if ($sid === null) { $report['struktur_tak_dikenali'][] = "$label -- $nama"; continue; }
        if (!isset($termOf[$sid])) { $report['tanpa_periode'][] = "$label -- $nama"; continue; }
        $rows[] = ['tab' => $tab, 'label' => $label, 'structure' => $sid, 'term' => $termOf[$sid], 'nama' => $nama,
                   'hp' => hpFromSheet($r[$col['waketua']] ?? '')];
    }
    say("Tab $tab: $n baris.");
}
say("Baris yang bisa diproses (struktur dikenal, punya periode berjalan, ada nama Ketua): " . count($rows));

// ---------- Orang & penempatan ----------
$people = []; $byKey = []; $byPhone = [];
foreach ($pdo->query("SELECT id, full_name, phone FROM people WHERE deleted_at IS NULL") as $p) {
    $p['id'] = (int) $p['id']; $people[$p['id']] = $p;
    $byKey[pkey($p['full_name'])][] = $p['id'];
    if ($p['phone'] !== null && $p['phone'] !== '') $byPhone[$p['phone']][] = $p['id'];
}
$inTerm = []; $live = [];            // [term][person] => true ; [term][person][placement id] => baris
$allTerms = implode(',', array_unique(array_column($rows, 'term')) ?: [0]);
foreach ($pdo->query("SELECT id, person_id, term_id, structure_id, structure_unit_id, position_id, source FROM placements
                      WHERE term_id IN ($allTerms) AND deleted_at IS NULL") as $p) {
    $inTerm[(int) $p['term_id']][(int) $p['person_id']] = true;
    $live[(int) $p['term_id']][(int) $p['person_id']][(int) $p['id']] = $p;
}

// Setiap kata nama di sheet punya padanan (hampir) sama di nama database, atau sebaliknya: "Almuntazar" ~ "Andi Almuntazar",
// "Dihri" ~ "K.M Dihri", "Samsuddin" ~ "Syamsuddin Muhammad Syukuri". Hanya dipakai bila HP sama dan orangnya tercatat di periode itu.
function namaSebagian($a, $b) {
    $ta = BiodataMatcher::nameParts($a)['base']; $tb = BiodataMatcher::nameParts($b)['base'];
    $covered = function ($x, $y) {
        if (!$x) return false;
        foreach ($x as $t) {
            $hit = false;
            foreach ($y as $u) { similar_text($t, $u, $pc); if ($pc >= 85) { $hit = true; break; } }
            if (!$hit) return false;
        }
        return true;
    };
    return $covered($ta, $tb) || $covered($tb, $ta);
}

function describePerson(array $p, $termId, array $inTerm) {
    return "id {$p['id']} {$p['full_name']} (HP " . ($p['phone'] ?: '-') . ', ' . (isset($inTerm[$termId][$p['id']]) ? 'tercatat di periode ini' : 'tidak di periode ini') . ')';
}

// ['person' => id, 'how' => ...] atau ['ragu' => [id...], 'why' => ...] atau ['none' => true]
function matchPerson(array $r, array $people, array $byKey, array $byPhone, array $inTerm) {
    $key = pkey($r['nama']); $gs = gkey($r['nama']); $term = $r['term'];
    $nameOk = function ($id) use ($people, $key) {
        $pk = pkey($people[$id]['full_name']);
        if ($pk === $key) return true;
        similar_text($pk, $key, $pc);
        return $pc >= 90;
    };
    $phoneIds = ($r['hp'] !== null && isset($byPhone[$r['hp']])) ? $byPhone[$r['hp']] : [];

    if ($phoneIds) {
        $same = array_values(array_filter($phoneIds, $nameOk));
        if (count($same) === 1) return ['person' => $same[0], 'how' => 'HP+nama'];
        if (count($same) > 1) {
            $here = array_values(array_filter($same, fn($id) => isset($inTerm[$term][$id])));
            if (count($here) === 1) return ['person' => $here[0], 'how' => 'HP+nama+periode'];
            return ['ragu' => $same, 'why' => 'beberapa orang ber-HP dan bernama sama (data ganda)'];
        }
    }
    if ($phoneIds) {
        $near = array_values(array_filter($phoneIds, fn($id) => isset($inTerm[$term][$id]) && namaSebagian($r['nama'], $people[$id]['full_name'])));
        if (count($near) === 1) return ['person' => $near[0], 'how' => 'HP+periode, nama mirip'];
    }
    $cands = $byKey[$key] ?? [];
    $okDegree = array_values(array_filter($cands, fn($id) => $gs === '' || gkey($people[$id]['full_name']) === '' || gkey($people[$id]['full_name']) === $gs));
    $here = array_values(array_filter($okDegree, fn($id) => isset($inTerm[$term][$id])));
    if (count($here) === 1) return ['person' => $here[0], 'how' => 'nama+periode'];
    if (count($here) > 1) return ['ragu' => $here, 'why' => 'dua orang bernama sama di periode ini'];

    $loose = array_values(array_unique(array_merge($phoneIds, $cands)));
    if ($loose) return ['ragu' => $loose, 'why' => $phoneIds ? 'HP ada tetapi nama berbeda / orang itu tidak di periode ini' : 'nama sama tetapi tidak tercatat di periode ini'];
    return ['none' => true];
}

// ---------- Terapkan ----------
$audit = $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, event, before_data, after_data, account_id, structure_id, ip)
                        VALUES (:e, :id, :a, :ev, :b, :af, NULL, :s, NULL)");
function audit($audit, $id, $action, $before, $after, $structureId, $entity = 'placements') {
    $audit->execute([':e' => $entity, ':id' => $id, ':a' => $action, ':ev' => EVENT,
        ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        ':af' => json_encode($after + ['event' => EVENT], JSON_UNESCAPED_UNICODE), ':s' => $structureId]);
}

$stat = ['sama' => 0, 'diganti' => 0, 'ditambah' => 0, 'anggota' => 0, 'anulir' => 0, 'dibuat' => 0];
$report += ['mirip' => [], 'kembar' => [], 'ditambah' => [], 'ragu' => [], 'tak_ditemukan' => [], 'bentrok' => [], 'rangkap' => [], 'sisa_polos' => [],
            'dibuat' => [], 'jadi_anggota' => [], 'sudah_anggota' => []];
$sheetPerson = [];                   // "term|person" => true : orang yang dimaksud sheet sebagai Ketua struktur itu
$applied = [];                       // id struktur => true : struktur yang Ketua DPW/DPD-nya BERHASIL dipasang (dasar --ketua-lain-jadi-anggota)

$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE placements SET position_id = :p WHERE id = :id");
$ins = $pdo->prepare("INSERT INTO placements (uuid, person_id, term_id, structure_id, structure_unit_id, position_id, is_primary, source, notes)
                      VALUES (:uuid, :pid, :term, :str, NULL, :p, 0, 'manual', :notes)");

foreach ($rows as $r) {
    $m = matchPerson($r, $people, $byKey, $byPhone, $inTerm);
    // Orang yang tak ditemukan tetapi diizinkan dibuat (--buat-orang): HP-nya harus sama dengan baris sheet ini.
    if (isset($m['none']) && $r['hp'] !== null && isset($buatOrang[$r['hp']])) {
        $spec = $buatOrang[$r['hp']];
        [$pre, $nm, $suf] = Text::normalizeNameParts('', $spec['nama'], '');
        $full = Text::joinName($pre, $nm, $suf);
        $pdo->prepare("INSERT INTO people (uuid, title_prefix, name, title_suffix, name_normalized, gender, phone, status, portal_enabled, home_structure_id)
                       VALUES (:u, :pre, :nm, :suf, :norm, :g, :hp, 'active', 0, :h)")
            ->execute([':u' => Uuid::v4(), ':pre' => $pre, ':nm' => $nm, ':suf' => $suf, ':norm' => Text::normalizeBase($nm), ':g' => $spec['gender'], ':hp' => $r['hp'], ':h' => $r['structure']]);
        $newPid = (int) $pdo->lastInsertId();
        audit($audit, $newPid, 'create', null, ['full_name' => $full, 'gender' => $spec['gender'], 'phone' => $r['hp'], 'sumber' => 'sheet ' . $r['tab'] . ' (--buat-orang)'], $r['structure'], 'people');
        $people[$newPid] = ['id' => $newPid, 'full_name' => $full, 'phone' => $r['hp']];
        $byKey[pkey($full)][] = $newPid; $byPhone[$r['hp']][] = $newPid;
        $stat['dibuat']++;
        $report['dibuat'][] = "{$r['label']} -- $full ({$spec['gender']}, HP {$r['hp']}) -> id $newPid";
        $m = ['person' => $newPid, 'how' => 'orang baru (--buat-orang)'];
    }
    if (isset($m['none'])) { $report['tak_ditemukan'][] = "{$r['label']} -- {$r['nama']} (HP " . ($r['hp'] ?: '-') . ')'; continue; }
    if (isset($m['ragu'])) {
        $cd = array_map(fn($id) => '      - ' . describePerson($people[$id], $r['term'], $inTerm), $m['ragu']);
        $report['ragu'][] = "{$r['label']} -- {$r['nama']} (HP " . ($r['hp'] ?: '-') . ") -- {$m['why']}\n" . implode("\n", $cd);
        continue;
    }

    $pid = $m['person']; $want = $target[$r['tab']]; $term = $r['term']; $sid = $r['structure'];
    $sheetPerson[$term . '|' . $pid] = true;
    if ($m['how'] === 'HP+periode, nama mirip') $report['mirip'][] = "{$r['label']} -- sheet: {$r['nama']} | database: {$people[$pid]['full_name']} (id $pid)";
    $mine = array_filter($live[$term][$pid] ?? [], fn($pl) => (int) $pl['structure_id'] === $sid && $pl['structure_unit_id'] === null);

    $has = array_filter($mine, fn($pl) => (int) $pl['position_id'] === $want);
    if ($has) { $stat['sama']++; $applied[$sid] = true; continue; }

    $plain = array_filter($mine, fn($pl) => (int) $pl['position_id'] === $posId['ketua']);
    if ($plain) {
        $id = (int) array_key_first($plain);
        $upd->execute([':p' => $want, ':id' => $id]);
        audit($audit, $id, 'update', ['position_id' => $posId['ketua']], ['position_id' => $want, 'person_id' => $pid], $sid);
        $live[$term][$pid][$id]['position_id'] = $want;
        $stat['diganti']++; $applied[$sid] = true;
        continue;
    }

    // Baris orang ganda: orang lain bernama sama (gelar tak bertentangan) yang sudah memegang "Ketua" polos di struktur ini.
    // Orang yang sama muncul dua kali di people, jadi jabatan penempatan itu yang diganti -- bukan menambah baris kedua.
    $twin = []; $twinDone = null;
    foreach ($byKey[pkey($r['nama'])] ?? [] as $tid) {
        if ($tid === $pid) continue;
        $g = gkey($people[$tid]['full_name']); $gs = gkey($r['nama']);
        if ($g !== '' && $gs !== '' && $g !== $gs) continue;
        foreach ($live[$term][$tid] ?? [] as $pl) {
            if ((int) $pl['structure_id'] !== $sid || $pl['structure_unit_id'] !== null) continue;
            if ((int) $pl['position_id'] === $posId['ketua']) $twin[] = [$tid, (int) $pl['id']];
            elseif ((int) $pl['position_id'] === $want) $twinDone = $tid;
        }
    }
    if ($twinDone !== null) {                                 // kembarannya sudah Ketua DPW/DPD (hasil jalan sebelumnya)
        $sheetPerson[$term . '|' . $twinDone] = true;
        $stat['sama']++; $applied[$sid] = true;
        continue;
    }
    if (count($twin) === 1) {
        [$tid, $id] = $twin[0];
        $upd->execute([':p' => $want, ':id' => $id]);
        audit($audit, $id, 'update', ['position_id' => $posId['ketua']], ['position_id' => $want, 'person_id' => $tid], $sid);
        $live[$term][$tid][$id]['position_id'] = $want;
        $sheetPerson[$term . '|' . $tid] = true;
        $report['kembar'][] = "{$r['label']} -- {$r['nama']}: jabatan diberikan ke id $tid ({$people[$tid]['full_name']}), baris orang ganda dari id $pid ({$people[$pid]['full_name']}, HP " . ($people[$pid]['phone'] ?: '-') . ')';
        $stat['diganti']++; $applied[$sid] = true;
        continue;
    }

    $others = array_filter($mine, fn($pl) => (int) $pl['position_id'] !== $want);
    if ($others) {
        $names = $pdo->query("SELECT name FROM positions WHERE id IN (" . implode(',', array_map(fn($pl) => (int) $pl['position_id'], $others)) . ")")->fetchAll(PDO::FETCH_COLUMN);
        $report['rangkap'][] = "{$r['label']} -- {$people[$pid]['full_name']} sudah berjabatan " . implode(', ', $names) . ' di struktur ini; Ketua ditambahkan di samping jabatan itu';
    }
    $ins->execute([':uuid' => Uuid::v4(), ':pid' => $pid, ':term' => $term, ':str' => $sid, ':p' => $want,
                   ':notes' => 'Ketua menurut sheet pengurus (tab ' . $r['tab'] . ')']);
    $newId = (int) $pdo->lastInsertId();
    audit($audit, $newId, 'create', null, ['person_id' => $pid, 'term_id' => $term, 'position_id' => $want], $sid);
    $live[$term][$pid][$newId] = ['id' => $newId, 'person_id' => $pid, 'term_id' => $term, 'structure_id' => $sid, 'structure_unit_id' => null, 'position_id' => $want, 'source' => 'manual'];
    $inTerm[$term][$pid] = true;
    $stat['ditambah']++; $applied[$sid] = true;
    $report['ditambah'][] = "{$r['label']} -- {$people[$pid]['full_name']} (id $pid, cocok lewat {$m['how']})";
}

// --ketua-lain-jadi-anggota: pada struktur yang Ketua DPW/DPD-nya berhasil dipasang, "Ketua" polos lain di tingkat struktur (Pengurus Inti)
// yang dipegang orang yang bukan Ketua menurut sheet menjadi "Anggota". Struktur yang Ketuanya tak terpasang tidak disentuh. Orang yang
// SUDAH Anggota di struktur itu tidak bisa diubah (kunci unik: satu orang, satu jabatan, satu tempat); baris "Ketua" polosnya yang berlebih
// dianulir (hapus lunak, tercatat di audit_log), sehingga ia tinggal Anggota -- sama dengan hasil akhir yang dimaksud.
if ($jadiAnggota && $applied) {
    $hasAnggota = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE person_id = :p AND term_id = :t AND structure_unit_id IS NULL
                                 AND position_id = :a AND deleted_at IS NULL");
    $toAnggota = $pdo->prepare("UPDATE placements SET position_id = :a WHERE id = :id AND position_id = :k");
    $anulir = $pdo->prepare("UPDATE placements SET deleted_at = NOW(3) WHERE id = :id AND position_id = :k AND deleted_at IS NULL");
    $q = $pdo->query("SELECT id, term_id, structure_id, person_id FROM placements
                      WHERE deleted_at IS NULL AND structure_unit_id IS NULL AND position_id = " . (int) $posId['ketua'] . "
                        AND structure_id IN (" . implode(',', array_keys($applied)) . ") ORDER BY structure_id, id")->fetchAll();
    foreach ($q as $pl) {
        $term = (int) $pl['term_id']; $pid = (int) $pl['person_id']; $sid = (int) $pl['structure_id'];
        if (isset($sheetPerson[$term . '|' . $pid])) continue;                  // dialah Ketua menurut sheet
        $who = ($people[$pid]['full_name'] ?? "id $pid") . " (id $pid)";
        $hasAnggota->execute([':p' => $pid, ':t' => $term, ':a' => $posId['anggota']]);
        if ((int) $hasAnggota->fetchColumn() > 0) {
            $anulir->execute([':id' => (int) $pl['id'], ':k' => $posId['ketua']]);
            audit($audit, (int) $pl['id'], 'delete', ['position_id' => $posId['ketua'], 'person_id' => $pid], ['alasan' => 'sudah Anggota di struktur ini; baris Ketua polos berlebih dianulir'], $sid);
            $stat['anulir']++;
            $report['sudah_anggota'][] = $sname[$sid] . ' -- ' . $who;
            continue;
        }
        $toAnggota->execute([':a' => $posId['anggota'], ':id' => (int) $pl['id'], ':k' => $posId['ketua']]);
        audit($audit, (int) $pl['id'], 'update', ['position_id' => $posId['ketua']], ['position_id' => $posId['anggota'], 'person_id' => $pid, 'alasan' => 'bukan Ketua menurut sheet'], $sid);
        $stat['anggota']++;
        $report['jadi_anggota'][] = $sname[$sid] . ' -- ' . $who;
    }
}

// Bentrok: Ketua DPW/DPD yang sudah ada di struktur itu, dipegang orang yang bukan Ketua menurut sheet.
// Sisa polos: penempatan "Ketua" polos di tingkat struktur pada struktur yang diproses (data migrasi lama; tidak diubah).
$done = []; foreach ($rows as $r) $done[$r['structure']] = $r;
$q = $pdo->query("SELECT pl.id, pl.term_id, pl.structure_id, pl.person_id, pl.position_id FROM placements pl
                  WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND pl.position_id IN (" . implode(',', [$posId['ketua'], $posId['ketua_dpw'], $posId['ketua_dpd']]) . ")
                    AND pl.structure_id IN (" . implode(',', array_keys($done) ?: [0]) . ")");
$plain = 0; $plainStruct = [];
foreach ($q as $pl) {
    $term = (int) $pl['term_id']; $pid = (int) $pl['person_id'];
    if ((int) $pl['position_id'] === $posId['ketua']) {
        if (!isset($sheetPerson[$term . '|' . $pid])) { $plain++; $plainStruct[(int) $pl['structure_id']][] = $people[$pid]['full_name']; }
    } elseif ($term === $done[(int) $pl['structure_id']]['term'] && !isset($sheetPerson[$term . '|' . $pid])) {
        $report['bentrok'][] = $sname[(int) $pl['structure_id']] . ' -- ' . $people[$pid]['full_name'] . ' memegang ' . ((int) $pl['position_id'] === $posId['ketua_dpw'] ? 'Ketua DPW' : 'Ketua DPD') . ' tetapi bukan Ketua menurut sheet';
    }
}

// ---------- Laporan ----------
say();
say("HASIL: sudah sesuai {$stat['sama']} | jabatan diganti {$stat['diganti']} | penempatan ditambah {$stat['ditambah']}"
   . ($jadiAnggota ? " | Ketua lain menjadi Anggota {$stat['anggota']} | Ketua polos dianulir (sudah Anggota) {$stat['anulir']}" : '') . ($buatOrang ? " | orang baru dibuat {$stat['dibuat']}" : ''));
say('Struktur yang Ketua DPW/DPD-nya terpasang: ' . count($applied));
say("Baris tanpa nama Ketua (dilewati): {$report['tanpa_nama']}");
foreach ($plainStruct as $sid => $names) {
    $sheetNames = array_map(fn($x) => $x['nama'], array_filter($rows, fn($x) => $x['structure'] === $sid));
    $report['sisa_polos'][] = $sname[$sid] . ' -- Ketua menurut sheet: ' . implode(', ', $sheetNames) . ' | "Ketua" polos lain: ' . implode('; ', $names);
}
$sections = ['struktur_tak_dikenali' => 'STRUKTUR TAK DIKENALI (cek penulisan nama di sheet)',
             'tanpa_periode'         => 'STRUKTUR TANPA PERIODE BERJALAN (tidak diproses)',
             'dibuat'                => 'ORANG BARU DIBUAT (--buat-orang)',
             'tak_ditemukan'         => 'ORANG TAK DITEMUKAN DI DATABASE (tidak dibuat otomatis)',
             'ragu'                  => 'RAGU-RAGU (kandidat ada tetapi tidak pasti; tidak diubah)',
             'mirip'                 => 'DICOCOKKAN MESKI TULISAN NAMA BERBEDA (HP sama, orangnya tercatat di periode itu) -- mohon dicek',
             'kembar'                => 'DATA ORANG GANDA (nama sama dua baris; jabatan diganti pada penempatan yang sudah ada, tidak menambah baris)',
             'ditambah'              => 'PENEMPATAN KETUA YANG DITAMBAH (orang itu belum berjabatan "Ketua" polos di struktur tsb)',
             'rangkap'               => 'RANGKAP JABATAN DI STRUKTUR YANG SAMA (Ketua ditambahkan)',
             'jadi_anggota'          => '"KETUA" POLOS LAIN DIUBAH MENJADI ANGGOTA (--ketua-lain-jadi-anggota)',
             'sudah_anggota'         => '"KETUA" POLOS LAIN YANG SUDAH ANGGOTA DI STRUKTUR ITU (baris Ketua polos berlebih DIANULIR; ia tinggal Anggota)',
             'bentrok'               => 'BENTROK (pemegang Ketua DPW/DPD yang tak ada di sheet; tidak diubah)',
             'sisa_polos'            => 'MASIH ADA "KETUA" POLOS LAIN DI STRUKTUR INI (tidak diubah; bisa Ketua lama atau data migrasi salah petak)'];
foreach ($sections as $k => $title) {
    say();
    say("== $title: " . count($report[$k]));
    foreach ($report[$k] as $line) say("  - $line");
}
say();
say("Sisa penempatan \"Ketua\" polos di tingkat struktur pada " . count($plainStruct) . " struktur yang diproses, dipegang orang yang bukan Ketua menurut sheet: $plain (tidak diubah).");

if ($commit) { $pdo->commit(); say(); say('TERSIMPAN.'); }
else         { $pdo->rollBack(); say(); say('DRY-RUN: dibatalkan (tidak ada yang disimpan). Tambahkan --commit untuk menyimpan.'); }
