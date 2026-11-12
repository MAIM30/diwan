<?php
// PATH LOKASI FILE: database/sinkron_ketua_mww_mwd.php
// Memasukkan Ketua MWW dan Ketua MWD menurut sheet "MWW" dan "MWD" di berkas Data MWW-MWD ke jabatan
// "Ketua MWW" / "Ketua MWD" (dibuat migrasi 026). CLI:
//
//   php database/sinkron_ketua_mww_mwd.php --user=diwan --pass=... --db=db_pengurus [--mww=<berkas.csv>] [--mwd=<berkas.csv>] [--commit]
//
// Berkas CSV (bawaan deploy/dump/ketua_mww.csv dan deploy/dump/ketua_mwd.csv, git-ignored karena memuat nama dan nomor HP) berasal dari
// sheet "MWW" dan "MWD" di docs/Data MWW-MWD Nov 2026.xlsx, satu baris per MWW / MWD, dengan judul kolom:
//   ketua_mww.csv:  no, provinsi, nama_mw, nama_ketua, hp, keterangan
//   ketua_mwd.csv:  no_provinsi, provinsi, kabkota, nama_mwd, nama_ketua, hp, keterangan
//
// Tanpa --commit semuanya dicoba lalu di-rollback dan hanya laporan yang dicetak. Aman dijalankan ulang: hasil yang
// sama tidak mengubah apa pun lagi. WAJIB mysqldump dulu sebelum --commit.
//
// ATURAN (sama dengan sinkron_ketua_dpw_dpd.php)
//
//  1. Satu baris sheet = satu Ketua. Baris tanpa nama Ketua dilewati (dilaporkan bila databasenya justru punya Ketua di sana).
//     MWW dicocokkan lewat nama provinsi; MWD lewat provinsi + nama kabupaten/kota di bawah MWW itu. Penempatannya masuk ke
//     periode BERJALAN struktur itu. Struktur yang tak punya periode berjalan dilaporkan, tidak dibuatkan periode.
//  2. Orang dicocokkan hanya bila PASTI: nomor HP sama + nama asli sama (kuat), atau nama asli sama dan kandidat
//     tunggal yang sudah tercatat di periode itu (sedang). Selebihnya DILAPORKAN beserta kandidatnya, tidak ditebak.
//     Orang yang tak ditemukan tidak dibuat otomatis.
//  3. Bagi orang yang cocok: sudah Ketua MWW/MWD di struktur itu -> dibiarkan; sudah berjabatan "Ketua" polos di
//     tingkat struktur itu -> jabatannya DIGANTI (id penempatan tetap); orang ganda (baris people lain bernama sama
//     yang memegang "Ketua" polos di struktur itu) -> penempatan milik baris itu yang DIGANTI; selain itu ->
//     penempatan DITAMBAH (source 'manual', catatan menyebut sumbernya). Penempatan lain TIDAK disentuh.
//  4. Pemegang Ketua MWW/MWD lain yang tak ada di sheet tidak diubah; hanya dilaporkan sebagai bentrok. Penempatan "Ketua"
//     polos milik orang lain di struktur yang sama juga tidak diubah; hanya dilaporkan sebagai sisa.
//  5. Data induk orang tidak pernah diubah atau dihapus.
//
// Setiap perubahan dicatat di audit_log dengan event 'sinkron_ketua_mww_mwd'.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';

const EVENT = 'sinkron_ketua_mww_mwd';

// Nama provinsi di sheet yang ditulis lain daripada di tabel structures (kunci dan nilai lewat nk()).
const PROVINSI_ALIAS = [
    'nangroeacehdarussalam' => 'aceh',
    'diyogyakarta'          => 'daerahistimewayogyakarta',
    'bangkabelitung'        => 'kepulauanbangkabelitung',
];

// Nama kabupaten/kota di sheet yang ditulis lain daripada di tabel structures (kunci dan nilai lewat nk(), tanpa "Kab."/"Kota").
const DAERAH_ALIAS = [
    'makasar'          => 'makassar',
    'manokwoari'       => 'manokwari',
    'marowaliutara'    => 'morowaliutara',
    'morotai'          => 'pulaumorotai',
    'kutaikertanegara' => 'kutaikartanegara',
    'kepsula'          => 'kepulauansula',
    'bintuni'          => 'telukbintuni',
    'yapen'            => 'kepulauanyapen',
    'pangkep'          => 'pangkajenedankepulauan',
    'sidrap'           => 'sidenrengrappang',
    'selayar'          => 'kepulauanselayar',
    'keptalaud'        => 'kepulauantalaud',
    'taliabu'          => 'pulautaliabu',
];

$opts   = getopt('', ['db::', 'host::', 'user::', 'pass::', 'mww::', 'mwd::', 'commit']);
$commit = isset($opts['commit']);
$srcs   = [
    'MWW' => $opts['mww'] ?? ROOT_DIR . '/deploy/dump/ketua_mww.csv',
    'MWD' => $opts['mwd'] ?? ROOT_DIR . '/deploy/dump/ketua_mwd.csv',
];

function say($m = '') { echo $m . "\n"; }
function nk($s) { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); }
function pkey($full) { return implode('', BiodataMatcher::nameParts($full)['base']); }
function gkey($full) { return BiodataMatcher::nameParts($full)['gelar']; }

// Karakter tak kasat mata (zero-width, penanda arah teks, BOM) yang ikut tertempel dari WhatsApp/Docs, dan spasi ganda.
function cleanName($s) {
    $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

// Nomor yang tersimpan sebagai angka di sheet bisa tiba sebagai "6.285267760902E12" bila diekspor lewat xlsx.
function hpFromSheet($s) {
    $s = trim((string) $s);
    if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) $s = sprintf('%.0f', (float) $s);
    return Text::normalizePhone($s);
}

function readCsv($src) {
    if (!is_file($src)) { fwrite(STDERR, "BATAL: berkas tidak ditemukan: $src\n"); exit(1); }
    $stream = fopen($src, 'r');
    $rows = [];
    while (($r = fgetcsv($stream)) !== false) $rows[] = $r;
    fclose($stream);
    if ($rows) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);  // BOM
    return $rows;
}

// "Kab. Kolaka" -> ['kab', 'kolaka'], "Jakarta Selatan" -> ['', 'jakartaselatan'], "Kota Surakarta/Solo" -> ['kota', 'surakarta'].
// Hanya awalan pertama yang dibuang ("Kab. Kota Waringin Timur" -> 'kotawaringintimur'); "Administrasi" tidak dihitung.
function daerahKey($name) {
    $name = trim(explode('/', (string) $name)[0]);
    $p = '';
    if (preg_match('/^(kab(?:upaten)?|kota)\b\.?\s*(.*)$/iu', $name, $m)) { $p = strtolower(substr($m[1], 0, 4)) === 'kota' ? 'kota' : 'kab'; $name = $m[2]; }
    $core = preg_replace('/^administrasi/', '', nk($name));
    return [$p, DAERAH_ALIAS[$core] ?? $core];
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
foreach ($pdo->query("SELECT id, code FROM positions WHERE code IN ('ketua', 'ketua_mww', 'ketua_mwd')") as $p) $posId[$p['code']] = (int) $p['id'];
foreach (['ketua', 'ketua_mww', 'ketua_mwd'] as $c) {
    if (!isset($posId[$c])) { fwrite(STDERR, "BATAL: jabatan '$c' belum ada. Jalankan database/migrate.php dulu (migrasi 026).\n"); exit(1); }
}
$target = ['MWW' => $posId['ketua_mww'], 'MWD' => $posId['ketua_mwd']];
$jabatanNama = [$posId['ketua_mww'] => 'Ketua MWW', $posId['ketua_mwd'] => 'Ketua MWD'];

// ---------- Struktur & periode berjalan ----------
$prov = []; $byProvId = [];          // nk(nama provinsi) => id struktur MWW ; id MWW => nk
$kids = [];                          // id MWW => [['id', 'p', 'core', 'name']] : MWD di bawahnya
$sname = [];                         // id => 'MWW Aceh' / 'MWD Aceh / Kota Banda Aceh'
foreach ($pdo->query("SELECT id, name FROM structures WHERE level = 'MWW' AND deleted_at IS NULL") as $s) {
    $short = trim(preg_replace('/^Muslimah Wahdah Islamiyah Wilayah\s+/i', '', $s['name']));
    $prov[nk($short)] = (int) $s['id']; $byProvId[(int) $s['id']] = $short; $sname[(int) $s['id']] = 'MWW ' . $short;
}
foreach ($pdo->query("SELECT id, name, parent_id FROM structures WHERE level = 'MWD' AND deleted_at IS NULL ORDER BY id") as $s) {
    if (!isset($byProvId[(int) $s['parent_id']])) continue;
    $short = trim(preg_replace('/^Muslimah Wahdah Islamiyah Daerah\s+/i', '', $s['name']));
    [$p, $core] = daerahKey($short);
    $kids[(int) $s['parent_id']][] = ['id' => (int) $s['id'], 'p' => $p, 'core' => $core, 'name' => $short];
    $sname[(int) $s['id']] = 'MWD ' . $byProvId[(int) $s['parent_id']] . ' / ' . $short;
}
$termOf = [];                        // id struktur => id periode berjalan
foreach ($pdo->query("SELECT structure_id, id FROM terms WHERE is_current = 1 AND deleted_at IS NULL") as $t) $termOf[(int) $t['structure_id']] = (int) $t['id'];

// Cari struktur MWD untuk satu baris sheet: ['id' => ..., 'catatan' => ...] atau ['galat' => ...].
// Nama dicari dulu di bawah MWW provinsi yang tertulis di sheet. Bila tak ada, dicari di semua MWW: sheet kadang mengelompokkan
// daerah menurut wilayah kerja, bukan menurut provinsinya ("DPW Jakarta - Depok" memuat Kota Depok, yang di database ada di Jawa Barat).
// Hasil seperti itu hanya dipakai bila tunggal dan selalu dilaporkan.
function cariMwd($provId, $kabkota, array $kids, array $byProvId) {
    [$p, $core] = daerahKey($kabkota);
    $same = array_values(array_filter($kids[$provId] ?? [], fn($k) => $k['core'] === $core));
    if (!$same) {
        $elsewhere = [];
        foreach ($kids as $pid => $list) foreach ($list as $k) if ($pid !== $provId && $k['core'] === $core) $elsewhere[] = $k + ['prov' => $byProvId[$pid]];
        if (count($elsewhere) === 1) {
            return ['id' => $elsewhere[0]['id'], 'catatan' => "sheet menaruhnya di wilayah lain; di database \"{$elsewhere[0]['name']}\" berada di bawah MWW {$elsewhere[0]['prov']}"];
        }
        return ['galat' => $elsewhere ? 'ambigu antar provinsi: ' . implode(' / ', array_map(fn($k) => $k['name'] . ' (' . $k['prov'] . ')', $elsewhere)) : 'tidak ada MWD bernama itu di database'];
    }
    $exact = $p === '' ? [] : array_values(array_filter($same, fn($k) => $k['p'] === $p));
    if (count($exact) === 1) return ['id' => $exact[0]['id']];
    if (count($same) === 1) {
        $note = ($p !== '' && $same[0]['p'] !== $p) ? "sheet menulis \"$kabkota\", database: \"{$same[0]['name']}\" (awalan Kab./Kota berbeda)" : null;
        return ['id' => $same[0]['id'], 'catatan' => $note];
    }
    return ['galat' => 'ambigu: ' . implode(' / ', array_column($same, 'name'))];
}

// ---------- Baca sheet ----------
$rows = [];                           // ['tab','label','structure','term','nama','hp','ket']
$report = ['struktur_tak_dikenali' => [], 'tanpa_periode' => [], 'tanpa_nama' => 0, 'nama_struktur_beda' => [], 'hp_mencurigakan' => [], 'kosong_tapi_ada' => []];
$kosong = [];                         // baris tanpa nama Ketua pada struktur yang dikenal
foreach ($srcs as $tab => $src) {
    $data = readCsv($src);
    $head = array_map('nk', array_shift($data) ?: []);
    $col  = array_flip($head);
    foreach (['provinsi', 'namaketua', 'hp'] as $need) {
        if (!isset($col[$need]) || ($tab === 'MWD' && !isset($col['kabkota']))) {
            fwrite(STDERR, "BATAL: kolom berkas $tab tidak sesuai (diharapkan provinsi, " . ($tab === 'MWD' ? 'kabkota, ' : '') . "nama_ketua, hp).\n"); exit(1);
        }
    }
    $n = 0;
    foreach ($data as $r) {
        if (count($r) < 3) continue;
        $n++;
        $nama = cleanName($r[$col['namaketua']] ?? '');
        $provNk = nk($r[$col['provinsi']] ?? ''); $provNk = PROVINSI_ALIAS[$provNk] ?? $provNk;
        $kab    = $tab === 'MWD' ? cleanName($r[$col['kabkota']] ?? '') : '';
        $label  = $tab . ' ' . cleanName($r[$col['provinsi']] ?? '') . ($kab !== '' ? ' / ' . $kab : '');
        $ket    = cleanName($r[$col['keterangan']] ?? '');

        $provId = $prov[$provNk] ?? null;
        $sid = null; $cat = null;
        if ($provId === null) { $report['struktur_tak_dikenali'][] = "$label -- " . ($nama ?: '(tanpa nama)') . ' (provinsi tidak dikenali)'; continue; }
        if ($tab === 'MWW') $sid = $provId;
        else {
            $f = cariMwd($provId, $kab, $kids, $byProvId);
            if (isset($f['galat'])) { $report['struktur_tak_dikenali'][] = "$label -- " . ($nama ?: '(tanpa nama)') . " ({$f['galat']})"; continue; }
            $sid = $f['id']; $cat = $f['catatan'] ?? null;
            if ($cat) $report['nama_struktur_beda'][] = "$label -- $cat";
        }
        if ($nama === '') {
            $report['tanpa_nama']++;
            if (isset($termOf[$sid])) $kosong[] = ['label' => $label, 'structure' => $sid, 'term' => $termOf[$sid], 'ket' => $ket];
            continue;
        }
        if (!isset($termOf[$sid])) { $report['tanpa_periode'][] = "$label -- $nama"; continue; }
        $hp = hpFromSheet($r[$col['hp']] ?? '');
        if ($hp === null || !preg_match('/^628\d{8,11}$/', $hp)) $report['hp_mencurigakan'][] = "$label -- $nama (HP di sheet: \"" . ($r[$col['hp']] ?? '') . '")';
        $rows[] = ['tab' => $tab, 'label' => $label, 'structure' => $sid, 'term' => $termOf[$sid], 'nama' => $nama, 'hp' => $hp, 'ket' => $ket];
    }
    say("Berkas $tab: $n baris.");
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
// "Dihri" ~ "K.M Dihri". Hanya dipakai bila HP sama dan orangnya tercatat di periode itu.
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

$stat = ['sama' => 0, 'diganti' => 0, 'ditambah' => 0];
$report += ['mirip' => [], 'kembar' => [], 'ditambah' => [], 'ragu' => [], 'tak_ditemukan' => [], 'bentrok' => [], 'rangkap' => [], 'sisa_polos' => [], 'plt' => []];
$sheetPerson = [];                   // "term|person" => true : orang yang dimaksud sheet sebagai Ketua struktur itu
$applied = [];                       // id struktur => true : struktur yang Ketua MWW/MWD-nya BERHASIL dipasang

$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE placements SET position_id = :p WHERE id = :id");
$ins = $pdo->prepare("INSERT INTO placements (uuid, person_id, term_id, structure_id, structure_unit_id, position_id, is_primary, source, notes)
                      VALUES (:uuid, :pid, :term, :str, NULL, :p, 0, 'manual', :notes)");

foreach ($rows as $r) {
    if (preg_match('/\bplt\b/i', $r['ket'])) $report['plt'][] = "{$r['label']} -- {$r['nama']} (sheet: \"{$r['ket']}\"; dicatat sebagai Ketua)";
    $m = matchPerson($r, $people, $byKey, $byPhone, $inTerm);
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
    if ($twinDone !== null) {                                 // kembarannya sudah Ketua MWW/MWD (hasil jalan sebelumnya)
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
                   ':notes' => 'Ketua menurut sheet ' . $r['tab'] . ' (Data MWW-MWD Nov 2026)']);
    $newId = (int) $pdo->lastInsertId();
    audit($audit, $newId, 'create', null, ['person_id' => $pid, 'term_id' => $term, 'position_id' => $want], $sid);
    $live[$term][$pid][$newId] = ['id' => $newId, 'person_id' => $pid, 'term_id' => $term, 'structure_id' => $sid, 'structure_unit_id' => null, 'position_id' => $want, 'source' => 'manual'];
    $inTerm[$term][$pid] = true;
    $stat['ditambah']++; $applied[$sid] = true;
    $report['ditambah'][] = "{$r['label']} -- {$people[$pid]['full_name']} (id $pid, cocok lewat {$m['how']})";
}

// Bentrok: Ketua MWW/MWD yang sudah ada di struktur itu, dipegang orang yang bukan Ketua menurut sheet.
// Sisa polos: penempatan "Ketua" polos di tingkat struktur pada struktur yang diproses (data migrasi lama; tidak diubah).
$done = []; foreach ($rows as $r) $done[$r['structure']] = $r;
$q = $pdo->query("SELECT pl.id, pl.term_id, pl.structure_id, pl.person_id, pl.position_id FROM placements pl
                  WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL AND pl.position_id IN (" . implode(',', [$posId['ketua'], $posId['ketua_mww'], $posId['ketua_mwd']]) . ")
                    AND pl.structure_id IN (" . implode(',', array_keys($done) ?: [0]) . ")");
$plain = 0; $plainStruct = [];
foreach ($q as $pl) {
    $term = (int) $pl['term_id']; $pid = (int) $pl['person_id'];
    if ((int) $pl['position_id'] === $posId['ketua']) {
        if (!isset($sheetPerson[$term . '|' . $pid])) { $plain++; $plainStruct[(int) $pl['structure_id']][] = $people[$pid]['full_name']; }
    } elseif ($term === $done[(int) $pl['structure_id']]['term'] && !isset($sheetPerson[$term . '|' . $pid])) {
        $report['bentrok'][] = $sname[(int) $pl['structure_id']] . ' -- ' . $people[$pid]['full_name'] . ' memegang ' . $jabatanNama[(int) $pl['position_id']] . ' tetapi bukan Ketua menurut sheet';
    }
}

// Baris sheet tanpa nama Ketua ("Tidak Aktif" dan sejenisnya) pada struktur yang dikenal: dilaporkan bila databasenya justru punya Ketua di sana.
if ($kosong) {
    $kTerms = implode(',', array_unique(array_column($kosong, 'term')));
    $holders = [];
    foreach ($pdo->query("SELECT pl.structure_id, pl.person_id FROM placements pl WHERE pl.deleted_at IS NULL AND pl.structure_unit_id IS NULL
                          AND pl.term_id IN ($kTerms) AND pl.position_id IN (" . implode(',', [$posId['ketua'], $posId['ketua_mww'], $posId['ketua_mwd']]) . ")") as $h) {
        $holders[(int) $h['structure_id']][] = $people[(int) $h['person_id']]['full_name'] ?? ('id ' . $h['person_id']);
    }
    foreach ($kosong as $k) {
        if (!empty($holders[$k['structure']])) $report['kosong_tapi_ada'][] = "{$k['label']} -- sheet tanpa nama" . ($k['ket'] !== '' ? " (\"{$k['ket']}\")" : '') . '; database mencatat: ' . implode('; ', array_unique($holders[$k['structure']]));
    }
}

// Simpan (atau batalkan) SEBELUM mencetak laporan. Laporannya panjang, dan keluaran yang terputus di tengah (mis. `| head`)
// bisa mematikan proses; perubahan yang sudah dihitung tidak boleh ikut hilang karenanya.
if ($commit) $pdo->commit(); else $pdo->rollBack();

// ---------- Laporan ----------
say();
say("HASIL: sudah sesuai {$stat['sama']} | jabatan diganti {$stat['diganti']} | penempatan ditambah {$stat['ditambah']}");
say('Struktur yang Ketua-nya terpasang: ' . count($applied) . ' (MWW ' . count(array_filter(array_keys($applied), fn($s) => strpos($sname[$s], 'MWW ') === 0)) . ', MWD ' . count(array_filter(array_keys($applied), fn($s) => strpos($sname[$s], 'MWD ') === 0)) . ')');
say("Baris tanpa nama Ketua (dilewati): {$report['tanpa_nama']}");
foreach ($plainStruct as $sid => $names) {
    $sheetNames = array_map(fn($x) => $x['nama'], array_filter($rows, fn($x) => $x['structure'] === $sid));
    $report['sisa_polos'][] = $sname[$sid] . ' -- Ketua menurut sheet: ' . implode(', ', $sheetNames) . ' | "Ketua" polos lain: ' . implode('; ', $names);
}
$sections = ['struktur_tak_dikenali' => 'STRUKTUR TAK DIKENALI (cek penulisan nama di sheet)',
             'nama_struktur_beda'    => 'STRUKTUR DICOCOKKAN MESKI TULISAN / WILAYAHNYA BERBEDA (awalan Kab./Kota, atau di bawah MWW lain) -- mohon dicek',
             'tanpa_periode'         => 'STRUKTUR TANPA PERIODE BERJALAN (tidak diproses)',
             'hp_mencurigakan'       => 'NOMOR HP DI SHEET TAK LAZIM (bukan 08xx / 628xx; pencocokan lewat HP tidak berlaku, hanya lewat nama)',
             'plt'                   => 'KETERANGAN "PLT" DI SHEET (Pelaksana Tugas; dicatat sebagai Ketua)',
             'tak_ditemukan'         => 'ORANG TAK DITEMUKAN DI DATABASE (tidak dibuat otomatis)',
             'ragu'                  => 'RAGU-RAGU (kandidat ada tetapi tidak pasti; tidak diubah)',
             'mirip'                 => 'DICOCOKKAN MESKI TULISAN NAMA BERBEDA (HP sama, orangnya tercatat di periode itu) -- mohon dicek',
             'kembar'                => 'DATA ORANG GANDA (nama sama dua baris; jabatan diganti pada penempatan yang sudah ada, tidak menambah baris)',
             'ditambah'              => 'PENEMPATAN KETUA YANG DITAMBAH (orang itu belum berjabatan "Ketua" polos di struktur tsb)',
             'rangkap'               => 'RANGKAP JABATAN DI STRUKTUR YANG SAMA (Ketua ditambahkan)',
             'bentrok'               => 'BENTROK (pemegang Ketua MWW/MWD yang tak ada di sheet; tidak diubah)',
             'kosong_tapi_ada'       => 'SHEET TANPA NAMA KETUA, TETAPI DATABASE PUNYA KETUA DI STRUKTUR ITU (tidak diubah)',
             'sisa_polos'            => 'MASIH ADA "KETUA" POLOS LAIN DI STRUKTUR INI (tidak diubah; bisa Ketua lama atau data migrasi salah petak)'];
foreach ($sections as $k => $title) {
    say();
    say("== $title: " . count($report[$k]));
    foreach ($report[$k] as $line) say("  - $line");
}
say();
say("Sisa penempatan \"Ketua\" polos di tingkat struktur pada " . count($plainStruct) . " struktur yang diproses, dipegang orang yang bukan Ketua menurut sheet: $plain (tidak diubah).");

say();
say($commit ? 'TERSIMPAN.' : 'DRY-RUN: dibatalkan (tidak ada yang disimpan). Tambahkan --commit untuk menyimpan.');
