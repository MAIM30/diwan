<?php
// PATH LOKASI FILE: database/import_biodata.php
// Mengimpor "Biodata / Profil Singkat Pengurus" (hasil database/tools/biodata_pdf_to_json.py) ke orang
// yang SUDAH ADA, dan menyalurkan yang belum dikenali lewat alur pendaftaran. CLI:
//
//   php database/import_biodata.php --db=db_pengurus --json=<folder>/biodata.json [--commit] [--no-photos]
//
// Tanpa --commit semuanya dicoba lalu di-rollback dan hanya laporan yang dicetak. Aman dijalankan
// ulang: hasil yang sama tidak menggandakan apa pun.
//
// ATURAN (semuanya mengikuti prinsip "lebih baik tidak tahu daripada salah"):
//
//  1. Formulir tidak punya NIK, jadi mencocokkan orang hanya bisa lewat nama. Karena itu pencocokan
//     berlapis, dan nama saja baru cukup bila TEPAT SATU orang yang cocok dan gelarnya tidak bertentangan:
//       nama+gelar     nama asli sama dan gelar belakang sama
//       nama           nama asli sama, salah satu sisi tak mencantumkan gelar, kandidat tunggal
//       nama+unit      nama sama tetapi gelar berbeda/ambigu; unit di formulir ada di penempatan orang itu
//       mirip          selisih ejaan (kemiripan >= 90%) DAN gelar sama atau unit cocok, kandidat tunggal
//     Selain itu TIDAK dicocokkan.
//  2. Orang yang cocok: biodata hanya MENGISI yang kosong. Isian yang sudah ada tidak ditimpa
//     (aturan yang sama dengan aktivasi pendaftaran); yang bertentangan dicatat sebagai butir tinjau.
//     Nama & gelar yang tercatat (dari SK) tidak diubah.
//  3. Jabatan DIBACA dari teks bebas dan dibandingkan dengan penempatan yang sudah ada. Penempatan
//     TIDAK pernah diubah/ditambah dari formulir: yang berbeda dicatat sebagai butir tinjau.
//  4. Orang yang tidak ditemukan / ambigu dikirim sebagai PENDAFTARAN (status Diajukan) ke DPP dengan
//     unit & jabatan terbaca sebagai usulan -- bukan langsung jadi pengurus. Verifikator memutuskan.
//     Jenis kelamin tidak ada di formulir; diduga dari nama-nama yang sudah ada di basis data (hanya
//     bila meyakinkan), dan pengajuan yang tak bisa diduga ditahan.
//  5. Foto pas dipasang hanya bila orangnya belum punya foto, lewat Photo::process (pipeline sama
//     dengan unggahan: EXIF dibuang, JPEG 512x512).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/Uuid.php';
require_once ROOT_DIR . '/app/Core/Photo.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';
require_once __DIR__ . '/005_data_diri.php';

$opts = getopt('', ['db::', 'host::', 'user::', 'pass::', 'json:', 'gender::', 'commit', 'no-photos', 'verbose']);
$host = $opts['host'] ?? 'localhost'; $user = $opts['user'] ?? 'root'; $pass = $opts['pass'] ?? '';
$dbName = $opts['db'] ?? 'db_pengurus';
$jsonPath = $opts['json'] ?? null;
$commit = isset($opts['commit']);
$withPhotos = !isset($opts['no-photos']);
$verbose = isset($opts['verbose']);
// Jawaban pasti dari pengelola untuk jenis kelamin yang tak bisa diduga: {"Nama persis di formulir": "L"|"P"}.
$genderOverride = [];
if (!empty($opts['gender'])) {
    $gj = is_file($opts['gender']) ? json_decode(file_get_contents($opts['gender']), true) : null;
    if (!is_array($gj)) { fwrite(STDERR, "BATAL: berkas --gender tidak ditemukan atau bukan JSON.
"); exit(1); }
    foreach ($gj as $k => $v) if (in_array($v, ['L', 'P'], true)) $genderOverride[BiodataMatcher::norm($k)] = $v;
}

function say($m = '') { echo $m . "\n"; }
function fail($m) { fwrite(STDERR, "BATAL: $m\n"); exit(1); }

if (!$jsonPath || !is_file($jsonPath)) fail('Berkas --json tidak ditemukan. Hasilkan dengan database/tools/biodata_pdf_to_json.py.');
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) fail('Nama database hanya boleh huruf, angka, dan garis bawah.');
$baseDir = dirname(realpath($jsonPath));
$records = json_decode(file_get_contents($jsonPath), true);
if (!is_array($records) || !$records) fail('Isi --json kosong atau bukan JSON yang sah.');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) { fail('Tidak bisa terhubung ke basis data: ' . $e->getMessage()); }

// Migrasi 005 (dua kolom nullable) idempoten; DDL tidak bisa di-rollback, jadi diterapkan di depan.
migrate_005_data_diri($pdo, function ($m) { say('  ' . $m); });
say();

function q(PDO $pdo, $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one(PDO $pdo, $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

// =====================================================================================================
// 1. KELOMPOKKAN FORMULIR PER ORANG, PILIH VERSI TERBARU
// =====================================================================================================
$monthEn = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
$rank = function (array $r, $i) use ($monthEn) {
    $signed = '0000-00-00';
    if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/u', (string) $r['ttd_tempat_tanggal'], $m) && isset(BiodataMatcher::MONTHS[mb_strtolower($m[2])])) {
        $signed = sprintf('%04d-%02d-%02d', $m[3], BiodataMatcher::MONTHS[mb_strtolower($m[2])], $m[1]);
    }
    $mod = '0000-00-00';
    if (preg_match('/^([A-Za-z]{3})\s+(\d{1,2})$/', (string) ($r['modified'] ?? ''), $m) && isset($monthEn[strtolower($m[1])])) {
        $mod = sprintf('2026-%02d-%02d', $monthEn[strtolower($m[1])], $m[2]);
    }
    $filled = 0; foreach (['ttl', 'domisili', 'hp', 'pekerjaan', 'hp_keluarga', 'jabatan', 'bidang'] as $k) if (BiodataMatcher::clean($r[$k] ?? null) !== null) $filled++;
    return sprintf('%s|%s|%d|%05d', $signed, $mod, $filled, $i);
};

$junk = [];   // formulir yang namanya jelas bukan nama orang
$byBase = [];
foreach ($records as $i => $r) {
    $nm = BiodataMatcher::clean($r['nama'] ?? null);
    if ($nm === null || !BiodataMatcher::looksLikeName($nm)) { $junk[] = $r; continue; }
    $r['_np'] = BiodataMatcher::nameParts($nm);
    $r['_rank'] = $rank($r, $i);
    $byBase[implode(' ', $r['_np']['base'])][] = $r;
}
$isSub = function ($a, $b) {   // huruf-huruf $a termuat di $b (gelar yang kurang lengkap = orang yang sama)
    $ca = count_chars($a, 1); $cb = count_chars($b, 1);
    foreach ($ca as $ch => $n) if (($cb[$ch] ?? 0) < $n) return false;
    return true;
};
$groups = [];   // daftar kelompok: ['rows' => [...], 'rep' => rekaman terbaru]
foreach ($byBase as $base => $rows) {
    $byG = [];
    foreach ($rows as $r) $byG[$r['_np']['gelar']][] = $r;
    // gelar yang kurang lengkap (himpunan bagian) menyatu ke yang lebih lengkap bila superset-nya tunggal
    $keys = array_keys($byG);
    foreach ($keys as $g) {
        if (!isset($byG[$g])) continue;
        $supers = array_values(array_filter(array_keys($byG), function ($h) use ($g, $isSub) { return $h !== $g && $isSub($g, $h); }));
        if (count($supers) === 1) { $byG[$supers[0]] = array_merge($byG[$supers[0]], $byG[$g]); unset($byG[$g]); }
    }
    foreach ($byG as $g => $rs) {
        usort($rs, function ($a, $b) { return strcmp($b['_rank'], $a['_rank']); });
        $groups[] = ['rows' => $rs, 'rep' => $rs[0]];
    }
}

// =====================================================================================================
// 2. DATA YANG SUDAH ADA
// =====================================================================================================
$people = [];
foreach (q($pdo, "SELECT id, uuid, full_name, gender, phone, family_phone, occupation, birth_date, birth_place, city, address_text, photo_path, home_structure_id, title_prefix
                  FROM people WHERE deleted_at IS NULL") as $p) {
    $p['np'] = BiodataMatcher::nameParts($p['full_name']);
    $p['units'] = []; $p['groups'] = []; $p['pucuk'] = []; $p['pl'] = [];
    $people[(int) $p['id']] = $p;
}
foreach (q($pdo, "SELECT pl.person_id, po.name pos, uc.name unit, g.name grp, s.level
                  FROM placements pl JOIN terms t ON t.id = pl.term_id AND t.is_current = 1 AND t.deleted_at IS NULL
                  JOIN positions po ON po.id = pl.position_id JOIN structures s ON s.id = pl.structure_id
                  LEFT JOIN structure_units su ON su.id = pl.structure_unit_id LEFT JOIN unit_catalog uc ON uc.id = su.unit_catalog_id
                  LEFT JOIN unit_groups g ON g.id = su.unit_group_id
                  WHERE pl.deleted_at IS NULL AND (pl.end_date IS NULL OR pl.end_date >= CURDATE())") as $r) {
    $id = (int) $r['person_id']; if (!isset($people[$id])) continue;
    $people[$id]['pl'][] = [$r['pos'], $r['unit']];
    if ($r['unit'] !== null) { $people[$id]['units'][$r['unit']] = true; if ($r['grp'] !== null) $people[$id]['groups'][$r['grp']] = true; }
    else $people[$id]['pucuk'][$r['pos']] = true;
}
$byBaseDb = [];
foreach ($people as $id => $p) $byBaseDb[implode(' ', $p['np']['base'])][] = $id;
$phoneIdx = [];
foreach ($people as $id => $p) if ($p['phone']) $phoneIdx[$p['phone']][] = $id;

// Dugaan jenis kelamin dari nama-nama yang SUDAH ada (hanya bila meyakinkan).
$gStat = [];
foreach ($people as $p) { $t = $p['np']['base'][0] ?? null; if ($t === null) continue; $gStat[$t][$p['gender']] = ($gStat[$t][$p['gender']] ?? 0) + 1; }
// Dugaan jenis kelamin untuk orang yang TIDAK ada di data. Urutan bukti, dari yang terkuat:
//   1. orang dengan nama asli yang sama persis di data kita (semuanya sejenis kelamin);
//   2. satu-satunya orang yang namanya sangat mirip (>= 85%, unggul jelas dari yang kedua);
//   3. kamus nama depan dari data kita (>= 2 orang sepakat 100%, atau >= 3 orang sepakat >= 90%);
//   4. awalan "Hj."/"Ustadzah".
// Hasilnya selalu DUGAAN: pengajuannya ditandai gender_dugaan dan dikonfirmasi verifikator saat mengaktifkan.
$guessGender = function (array $np, $prefix) use ($gStat, &$people, &$byBaseDb) {
    if (preg_match('/\bhj\b|hajjah|ustadzah|ustz/i', (string) $prefix)) return 'P';
    $same = $byBaseDb[implode(' ', $np['base'])] ?? [];
    if ($same) { $gs = array_unique(array_map(function ($i) use ($people) { return $people[$i]['gender']; }, $same)); if (count($gs) === 1) return $gs[0]; }
    if (count($np['base']) >= 2) {
        $best = []; foreach ($people as $id => $p) $best[$id] = BiodataMatcher::similarity($np['base'], $p['np']['base']);
        arsort($best); $sc = array_values(array_slice($best, 0, 2, true)); $ids = array_keys(array_slice($best, 0, 2, true));
        if ($sc && $sc[0] >= 85 && (count($sc) < 2 || $sc[0] - $sc[1] >= 4)) return $people[$ids[0]]['gender'];
    }
    foreach (array_slice($np['base'], 0, 2) as $t) {
        $c = $gStat[$t] ?? null; if (!$c) continue;
        $n = array_sum($c); arsort($c); $top = reset($c);
        if (($n >= 2 && $top === $n) || ($n >= 3 && $top / $n >= 0.9)) return array_key_first($c);
    }
    return null;
};

// "Kabupaten Bone" -> kota; alamat jalan/perumahan/panjang -> alamat. Dipakai orang cocok DAN pendaftaran.
$domSplit = function ($dom) {
    if ($dom === null) return [null, null];
    if (mb_strlen($dom) <= 60 && !preg_match('/\d|\b(jl|jalan|btn|perum|blok|rt|rw)\b/i', $dom)) return [$dom, null];
    return [null, mb_substr($dom, 0, 255)];
};

// =====================================================================================================
// 3. PENCOCOKAN
// =====================================================================================================
$formInfo = function (array $g) {
    $units = []; $groups = []; $pos = [];
    foreach ($g['rows'] as $r) {
        $x = BiodataMatcher::parseRole($r['jabatan'] ?? '', $r['bidang'] ?? '');
        foreach ($x['units'] as $u) $units[$u] = true;
        foreach ($x['groups'] as $u) $groups[$u] = true;
        foreach ($x['positions'] as $u) $pos[$u] = true;
    }
    return [array_keys($units), array_keys($groups), array_keys($pos)];
};
$corroborated = function ($pid, array $fi) use (&$people) {
    $P = $people[$pid];
    if (array_intersect($fi[0], array_keys($P['units']))) return true;
    if (array_intersect($fi[1], array_keys($P['groups']))) return true;
    if (array_intersect($fi[2], array_keys($P['pucuk']))) return true;
    return false;
};

$decisions = [];
foreach ($groups as $gi => $g) {
    $np = $g['rep']['_np']; $baseKey = implode(' ', $np['base']);
    $fi = $formInfo($g);
    $phones = array_values(array_filter(array_map(function ($r) { return BiodataMatcher::phone($r['hp'] ?? null); }, $g['rows'])));
    $multiToken = count($np['base']) >= 2;
    $d = ['status' => 'none'];

    // Satu kelompok yang berisi dua nomor WA DAN dua tanggal lahir berbeda hampir pasti dua orang yang
    // kebetulan senama (bukan salah ketik): ditahan, tidak dicocokkan dan tidak didaftarkan.
    $ph = array_unique(array_filter(array_map(function ($r) { return BiodataMatcher::phone($r['hp'] ?? null); }, $g['rows'])));
    $bd = array_unique(array_filter(array_map(function ($r) { return BiodataMatcher::parseTtl($r['ttl'] ?? null)['date']; }, $g['rows'])));
    if (count($ph) >= 2 && count($bd) >= 2) { $decisions[$gi] = ['status' => 'conflict', 'fi' => $fi]; continue; }

    $cands = $byBaseDb[$baseKey] ?? [];
    // Gelar kompatibel = salah satu sisi tak mencantumkan gelar, atau satu himpunan bagian dari yang lain
    // ("S.Pd." vs "S.Pd., M.Pd." = orang yang sama dengan gelar lebih lengkap; "S.Si." vs "S.Pd." = bukan).
    $compat = function ($id) use ($people, $np, $isSub) {
        $a = $np['gelar']; $b = $people[$id]['np']['gelar'];
        return $a === '' || $b === '' || $a === $b || $isSub($a, $b) || $isSub($b, $a);
    };
    if ($cands) {
        $same = array_values(array_filter($cands, function ($id) use ($people, $np) { return $people[$id]['np']['gelar'] === $np['gelar']; }));
        // Nama satu kata ("Abdullah") sama persis pun belum cukup: terlalu banyak orang bernama itu.
        if (count($same) === 1 && !$multiToken && !$corroborated($same[0], $fi)) $d = ['status' => 'none', 'ids' => $same, 'why' => 'nama satu kata tanpa bukti kedua'];
        elseif (count($same) === 1) $d = ['status' => 'match', 'id' => $same[0], 'how' => 'nama+gelar'];
        elseif (count($same) > 1) {
            $uc = array_values(array_filter($same, function ($id) use ($corroborated, $fi) { return $corroborated($id, $fi); }));
            $d = count($uc) === 1 ? ['status' => 'match', 'id' => $uc[0], 'how' => 'nama+unit'] : ['status' => 'ambiguous', 'ids' => $same];
        } else {
            // Tak ada gelar yang persis sama. Nama TUNGGAL (satu kata) terlalu umum untuk dicocokkan tanpa
            // gelar yang sama, jadi baru dipertimbangkan bila kandidatnya satu-satunya.
            $ok = array_values(array_filter($cands, $compat));
            $uc = array_values(array_filter($ok, function ($id) use ($corroborated, $fi) { return $corroborated($id, $fi); }));
            if ($multiToken && count($ok) === 1 && count($cands) === 1 && ($np['gelar'] === '' || $people[$ok[0]]['np']['gelar'] === '')) $d = ['status' => 'match', 'id' => $ok[0], 'how' => 'nama'];
            elseif (count($uc) === 1 && ($multiToken || count($cands) === 1)) $d = ['status' => 'match', 'id' => $uc[0], 'how' => 'nama+unit'];
            else $d = ['status' => count($cands) > 1 ? 'ambiguous' : 'none', 'ids' => $cands, 'why' => 'gelar bertentangan atau unit tidak menguatkan'];
        }
    }
    if ($d['status'] === 'none') {
        // salah eja: calon dengan kemiripan >= 90%, wajib kandidat tunggal yang unggul jelas, dan ada bukti kedua
        $best = []; foreach ($people as $id => $p) $best[$id] = BiodataMatcher::similarity($np['base'], $p['np']['base']);
        arsort($best); $top = array_slice($best, 0, 2, true); $ids = array_keys($top); $sc = array_values($top);
        if ($sc && $sc[0] >= 90 && (count($sc) < 2 || $sc[0] - $sc[1] >= 4) && $multiToken) {
            $id = $ids[0];
            $gelarOk = $compat($id);
            $phoneOk = $phones && $people[$id]['phone'] && in_array($people[$id]['phone'], $phones, true);
            // bukti kedua: gelar tak bertentangan DAN tercantum (bukan kosong), atau unit cocok, atau nomor WA sama -- dan gelar tak boleh bertentangan
            if ($gelarOk && (($np['gelar'] !== '' && $people[$id]['np']['gelar'] !== '') || $corroborated($id, $fi) || $phoneOk)) $d = ['status' => 'match', 'id' => $id, 'how' => 'mirip'];
        }
    }
    $d['fi'] = $fi; $decisions[$gi] = $d;
}
// Dua kelompok formulir yang jatuh ke orang yang sama = salah satunya keliru -> keduanya ditahan.
$claimed = [];
foreach ($decisions as $gi => $d) if ($d['status'] === 'match') $claimed[$d['id']][] = $gi;
foreach ($claimed as $pid => $gis) if (count($gis) > 1) foreach ($gis as $gi) $decisions[$gi] = ['status' => 'ambiguous', 'ids' => [$pid], 'why' => 'dua formulir berbeda cocok dengan orang yang sama', 'fi' => $decisions[$gi]['fi']];

// =====================================================================================================
// 4. TERAPKAN
// =====================================================================================================
$posId = []; foreach (q($pdo, "SELECT id, name FROM positions") as $r) $posId[mb_strtolower($r['name'])] = (int) $r['id'];
$dpp = q($pdo, "SELECT s.id, t.id term_id FROM structures s JOIN terms t ON t.structure_id = s.id AND t.is_current = 1 WHERE s.level = 'DPP' AND s.parent_id IS NULL LIMIT 1")[0] ?? null;
$dppUnit = [];
if ($dpp) foreach (q($pdo, "SELECT su.id, uc.name FROM structure_units su JOIN unit_catalog uc ON uc.id = su.unit_catalog_id WHERE su.term_id = ? AND su.deleted_at IS NULL", [$dpp['term_id']]) as $r) $dppUnit[$r['name']] = (int) $r['id'];

$review = [];   // [raw_name, raw_context, reason, candidate_person_id, note]
$S = ['match' => [], 'ambiguous' => 0, 'none' => 0, 'reg_new' => 0, 'reg_exists' => 0, 'held' => 0, 'junk' => count($junk),
      'birth' => 0, 'birth_doubt' => 0, 'city' => 0, 'phone_set' => 0, 'phone_same' => 0, 'phone_conflict' => 0, 'occ' => 0, 'fam' => 0, 'photo' => 0, 'photo_skip' => 0, 'photo_fail' => 0, 'photo_repair' => 0,
      'people_changed' => 0, 'role_ok' => 0, 'role_pos' => 0, 'role_unit' => 0, 'role_none' => 0, 'versions' => 0];
$heldList = []; $regList = []; $matchLog = [];

$mask = function ($s) { return mb_strlen($s) > 1 ? mb_substr($s, 0, 1) . str_repeat('*', max(1, mb_strlen($s) - 1)) : $s; };
$pdo->beginTransaction();
try {
    $batch = null;
    $ensureBatch = function () use (&$batch, $pdo) {
        if ($batch === null) {
            $pdo->prepare("INSERT INTO import_batches (uuid, label, source, status) VALUES (?, ?, 'lainnya', 'running')")
                ->execute([Uuid::v4(), 'Biodata pengurus (PDF) ' . date('Y-m-d'), ]);
            $batch = (int) $pdo->lastInsertId();
        }
        return $batch;
    };
    $photoJobs = [];

    foreach ($groups as $gi => $g) {
        $rep = $g['rep']; $d = $decisions[$gi];
        if (count($g['rows']) > 1) $S['versions']++;
        $ctx = mb_substr(trim(($rep['jabatan'] ?? '') . ' / ' . ($rep['bidang'] ?? '')), 0, 250);
        if ($d['status'] === 'conflict') {
            $S['held']++; $heldList[] = [$rep['nama'], 'formulir senama tampak milik orang berbeda (nomor WA dan tanggal lahir berbeda)'];
            $review[] = [$rep['nama'], $ctx, 'ambiguous', null, 'Beberapa formulir dengan nama ini memuat nomor WA DAN tanggal lahir berbeda -- kemungkinan dua orang senama. Tidak diproses; pisahkan secara manual.'];
            continue;
        }
        $ttl = BiodataMatcher::parseTtl($rep['ttl'] ?? null);
        $dom = BiodataMatcher::clean($rep['domisili'] ?? null);
        $hp = BiodataMatcher::phone($rep['hp'] ?? null);
        $fam = BiodataMatcher::phone($rep['hp_keluarga'] ?? null);
        $occ = BiodataMatcher::clean($rep['pekerjaan'] ?? null);

        if ($d['status'] === 'match') {
            $pid = $d['id']; $P = $people[$pid];
            $S['match'][$d['how']] = ($S['match'][$d['how']] ?? 0) + 1;
            if ($verbose && $d['how'] !== 'nama+gelar') $matchLog[] = sprintf('[%s] %s  =>  %s', $d['how'], $rep['nama'], $P['full_name']);
            $set = []; $before = [];

            if ($ttl['date']) { if (!$P['birth_date']) { $set['birth_date'] = $ttl['date']; $S['birth']++; } }
            elseif ($ttl['doubt']) { $S['birth_doubt']++; $review[] = [$rep['nama'], $ctx, 'lainnya', $pid, 'Tanggal lahir di formulir tak terbaca pasti: "' . $ttl['doubt'] . '". Tidak diisi.']; }
            if ($ttl['place'] && !$P['birth_place']) $set['birth_place'] = mb_substr($ttl['place'], 0, 100);
            if ($dom !== null && !$P['city'] && !$P['address_text']) {
                [$cty, $adr] = $domSplit($dom);
                if ($cty !== null) $set['city'] = $cty; else $set['address_text'] = $adr;
                $S['city']++;
            }
            if ($hp !== null) {
                if (!$P['phone']) { $set['phone'] = $hp; $S['phone_set']++; }
                elseif ($P['phone'] === $hp) $S['phone_same']++;
                else { $S['phone_conflict']++; $review[] = [$rep['nama'], $ctx, 'lainnya', $pid, 'Nomor WA di formulir berbeda dari yang tercatat (formulir: ' . Text::maskPhone($hp) . ', tercatat: ' . Text::maskPhone($P['phone']) . '). Yang tercatat dipertahankan.']; }
            }
            // Kontak darurat yang sama dengan nomor orangnya sendiri tak berguna sebagai kontak keluarga: tidak disimpan.
            if ($fam !== null && !$P['family_phone'] && $fam !== $hp && $fam !== ($set['phone'] ?? $P['phone'])) { $set['family_phone'] = $fam; $S['fam']++; }
            if ($occ !== null && !$P['occupation']) { $set['occupation'] = mb_substr($occ, 0, 150); $S['occ']++; }

            if ($set) {
                $cols = []; $vals = [];
                foreach ($set as $c => $v) { $cols[] = "$c = ?"; $vals[] = $v; $before[$c] = $P[$c] ?? null; }
                $vals[] = $pid;
                $pdo->prepare('UPDATE people SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($vals);
                $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, before_data, after_data, structure_id) VALUES ('people', ?, 'update', ?, ?, ?)")
                    ->execute([$pid, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($set + ['event' => 'import_biodata'], JSON_UNESCAPED_UNICODE), $P['home_structure_id']]);
                $S['people_changed']++;
            }

            // ---- foto ----
            if ($withPhotos && !empty($rep['photo'])) {
                if ($P['photo_path']) {
                    // Menyembuhkan diri: photo_path terisi tetapi BERKASNYA hilang dari disk (terhapus, salah salin) -> dibuat ulang dari
                    // sumbernya lewat pipeline yang sama, tanpa menyentuh basis data (jalurnya tidak berubah).
                    if (is_file(ROOT_DIR . '/public/' . $P['photo_path'])) $S['photo_skip']++;
                    else $photoJobs[] = [$pid, $P['uuid'], $baseDir . '/' . $rep['photo'], true];
                } else $photoJobs[] = [$pid, $P['uuid'], $baseDir . '/' . $rep['photo'], false];
            }

            // ---- jabatan: bandingkan, jangan ubah ----
            $role = BiodataMatcher::parseRole($rep['jabatan'] ?? '', $rep['bidang'] ?? '');
            $ours = []; foreach ($P['pl'] as [$pp, $uu]) $ours[] = $pp . ($uu ? ' · ' . $uu : ' · (pucuk)');
            sort($ours);   // urutan basis data tak dijamin; catatan harus identik antar jalan supaya idempoten
            $oursTxt = $ours ? implode('; ', $ours) : 'tidak ada penempatan';
            if (!$role['pairs']) { $S['role_none']++; continue; }
            $bad = [];
            foreach ($role['pairs'] as [$pp, $uu]) {
                $hit = false; $unitHit = false;
                if ($uu === null && !in_array($pp, BiodataMatcher::PUCUK_POSITIONS, true)) {
                    // jabatan tanpa unit ("Anggota", "Ketua"): tak bisa dinilai -- bukan selisih. Cukup dicatat bila
                    // orang ini memang tak punya jabatan itu di unit mana pun.
                    continue;
                }
                foreach ($P['pl'] as [$op, $ou]) {
                    if ($uu === null) { if ($ou === null && $op === $pp) $hit = true; }
                    else { if ($ou === $uu) { $unitHit = true; if ($pp === null || $op === $pp) $hit = true; } }
                }
                if (!$hit) $bad[] = ($pp ?? '?') . ($uu ? ' · ' . $uu : ' (pucuk pimpinan)') . ($unitHit ? ' (jabatan beda)' : ' (unit tak tercatat)');
            }
            sort($bad);
            if (!$bad) $S['role_ok']++;
            else {
                $S[count($bad) && strpos(implode('', $bad), 'jabatan beda') !== false ? 'role_pos' : 'role_unit']++;
                $review[] = [$rep['nama'], $ctx, 'lainnya', $pid, 'Jabatan di formulir tidak sama dengan data kita. Formulir dibaca: ' . implode('; ', $bad) . '. Data kita: ' . $oursTxt . '. Data kita tidak diubah.'];
            }
            continue;
        }

        // ---------- tidak cocok / ambigu -> pendaftaran ----------
        $isAmb = $d['status'] === 'ambiguous';
        $isAmb ? $S['ambiguous']++ : $S['none']++;
        [$pre, $name, $suf] = Text::normalizeNameParts('', $rep['nama'], '');
        // Jawaban pasti dari pengelola (--gender) menang; selain itu dugaan, dan dugaan ditandai.
        $gender = $genderOverride[BiodataMatcher::norm($rep['nama'])] ?? null;
        $genderGuess = $gender === null;
        if ($gender === null && $isAmb) { $gs = array_unique(array_map(function ($i) use ($people) { return $people[$i]['gender']; }, $d['ids'])); if (count($gs) === 1) $gender = $gs[0]; }
        if ($gender === null) $gender = $guessGender($rep['_np'], $pre);
        $why = null;
        if ($hp === null) $why = 'nomor WA tidak ada/tak sah (wajib untuk pendaftaran)';
        elseif ($gender === null) $why = 'jenis kelamin tidak bisa dipastikan dari nama';
        if ($why !== null) {
            $S['held']++; $heldList[] = [$rep['nama'], $why];
            $review[] = [$rep['nama'], $ctx, $isAmb ? 'ambiguous' : 'lainnya', $isAmb ? ($d['ids'][0] ?? null) : null, 'Tidak diajukan sebagai pendaftar: ' . $why . '. Formulir: tempat/tanggal lahir "' . ($rep['ttl'] ?? '') . '".'];
            continue;
        }

        $exists = (int) one($pdo, "SELECT COUNT(*) FROM registration_requests WHERE name = ? AND phone = ? AND status IN ('submitted','verified')", [$name, $hp]);
        if ($exists) { $S['reg_exists']++; continue; }

        $role = BiodataMatcher::parseRole($rep['jabatan'] ?? '', $rep['bidang'] ?? '');
        $unitNames = array_values(array_filter($role['units'], function ($u) use ($dppUnit) { return isset($dppUnit[$u]); }));
        $target = $role['pairs'][0] ?? [null, null];
        $targetUnit = (count($unitNames) === 1 && isset($dppUnit[$unitNames[0]])) ? $dppUnit[$unitNames[0]] : null;
        $targetPos = $target[0] !== null ? ($posId[mb_strtolower($target[0])] ?? null) : null;
        $cand = $isAmb ? $d['ids'] : [];
        [$cty, $adr] = $domSplit($dom);
        // 'profil' disalin ke data orang saat diaktifkan (RegistrationModel::activate); 'formulir' hanya konteks bagi verifikator.
        $extra = [
            'sumber' => 'Biodata Pengurus (PDF) ' . date('Y-m-d'),
            'catatan' => 'Dari formulir biodata; belum ada di data kepengurusan kita.' . ($genderGuess ? ' Jenis kelamin DIDUGA dari nama (formulir tidak memuatnya) -- konfirmasi saat mengaktifkan.' : ''),
            'gender_dugaan' => $genderGuess,
            'profil' => array_filter([
                'birth_place' => $ttl['place'] !== null ? mb_substr($ttl['place'], 0, 100) : null, 'city' => $cty, 'address_text' => $adr,
                'occupation' => $occ !== null ? mb_substr($occ, 0, 150) : null, 'family_phone' => $fam !== $hp ? $fam : null,
            ], function ($v) { return $v !== null; }),
            'formulir' => [
                'ttl' => $rep['ttl'] ?? null, 'domisili' => $dom, 'jabatan' => $rep['jabatan'] ?? null, 'bidang' => $rep['bidang'] ?? null,
                'jabatan_terbaca' => array_map(function ($p) { return ['jabatan' => $p[0], 'unit' => $p[1]]; }, $role['pairs']),
            ],
        ];
        $pdo->prepare("INSERT INTO registration_requests
                (uuid, title_prefix, name, title_suffix, gender, phone, birth_date, extra_data,
                 target_structure_id, target_term_id, target_unit_id, target_position_id, status, matched_person_id, match_confidence)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'submitted', ?, ?)")
            ->execute([Uuid::v4(), $pre, $name, $suf, $gender, $hp, $ttl['date'], json_encode($extra, JSON_UNESCAPED_UNICODE),
                       $dpp['id'], $dpp['term_id'], $targetUnit, $targetPos, count($cand) === 1 ? $cand[0] : null, $isAmb ? 'ambiguous' : 'none']);
        $rid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, after_data, structure_id) VALUES ('registration_requests', ?, 'create', ?, ?)")
            ->execute([$rid, json_encode(['full_name' => Text::joinName($pre, $name, $suf), 'target_structure_id' => (int) $dpp['id'], 'event' => 'import_biodata'], JSON_UNESCAPED_UNICODE), $dpp['id']]);
        $S['reg_new']++; $regList[] = [$rep['nama'], $isAmb ? 'ambigu' : 'baru', $targetUnit ? 'unit terbaca' : 'unit belum terbaca'];
        if ($isAmb) $review[] = [$rep['nama'], $ctx, 'ambiguous', $cand[0] ?? null, 'Cocok dengan beberapa orang (' . implode(', ', array_map(function ($i) use ($people) { return $people[$i]['full_name']; }, $d['ids'])) . ')' . (isset($d['why']) ? ' -- ' . $d['why'] : '') . '. Diajukan sebagai pendaftar; verifikator memilih orangnya.'];
    }

    foreach ($junk as $r) $review[] = [(string) ($r['nama'] ?? ''), mb_substr(trim(($r['jabatan'] ?? '') . ' / ' . ($r['bidang'] ?? '')), 0, 250), 'lainnya', null, 'Nama pada formulir tidak tampak seperti nama orang (alamat/uji coba); dilewati.'];

    // ---- daftar tinjau ----
    // Idempoten: butir dengan nama + catatan yang sama (belum pernah dibuat) saja yang ditulis; batch baru
    // hanya dibuat bila memang ada butir baru.
    $seen = $pdo->prepare("SELECT COUNT(*) FROM import_review_items WHERE raw_name = ? AND note = ?");
    $fresh = [];
    foreach ($review as $r) {
        $seen->execute([mb_substr($r[0], 0, 200), $r[4]]);
        if (!(int) $seen->fetchColumn()) $fresh[] = $r;
    }
    $S['review_new'] = count($fresh);
    if ($fresh) {
        $b = $ensureBatch();
        $ins = $pdo->prepare("INSERT INTO import_review_items (batch_id, raw_name, raw_context, reason, candidate_person_id, note) VALUES (?,?,?,?,?,?)");
        foreach ($fresh as $r) $ins->execute([$b, mb_substr($r[0], 0, 200), mb_substr($r[1], 0, 255), $r[2], $r[3], $r[4]]);
    }
    if ($batch !== null) {
        $pdo->prepare("UPDATE import_batches SET status = 'completed', stat_new = ?, stat_matched = ?, stat_needs_review = ?, finished_at = NOW(3) WHERE id = ?")
            ->execute([$S['reg_new'], array_sum($S['match']), count($fresh), $batch]);
    }

    // ---- foto (terakhir: berkas tak bisa di-rollback) ----
    foreach ($photoJobs as [$pid, $uuid, $src, $repair]) {
        if (!is_file($src)) { $S['photo_fail']++; continue; }
        $rel = 'uploads/people/' . $uuid . '.jpg';
        $dest = $commit ? ROOT_DIR . '/public/' . $rel : sys_get_temp_dir() . '/pg_dry_' . $uuid . '.jpg';
        $err = Photo::process($src, $dest);
        if ($err !== null) { $S['photo_fail']++; continue; }
        if ($repair) { $S['photo_repair']++; if (!$commit) @unlink($dest); continue; }
        if ($commit) {
            $pdo->prepare("UPDATE people SET photo_path = ? WHERE id = ? AND photo_path IS NULL")->execute([$rel, $pid]);
            $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, before_data, after_data, structure_id) VALUES ('people', ?, 'update', ?, ?, ?)")
                ->execute([$pid, json_encode(['photo_path' => null]), json_encode(['photo_path' => $rel, 'event' => 'import_biodata']), $people[$pid]['home_structure_id']]);
        } else @unlink($dest);
        $S['photo']++;
    }

    // =================================================================================================
    // LAPORAN
    // =================================================================================================
    $nMatch = array_sum($S['match']);
    say('== Formulir ==');
    say(sprintf('%d formulir -> %d orang (%d punya lebih dari satu versi; versi terbaru dipakai); %d berfoto; %d dilewati (nama bukan nama orang)',
        count($records), count($groups), $S['versions'], count(array_filter($records, function ($r) { return !empty($r['photo']); })), $S['junk']));
    say();
    say('== Pencocokan dengan orang yang sudah ada ==');
    say(sprintf('Cocok     : %d  (%s)', $nMatch, implode(', ', array_map(function ($k, $v) { return "$k $v"; }, array_keys($S['match']), $S['match']))));
    say(sprintf('Ambigu    : %d', $S['ambiguous']));
    say(sprintf('Tak ditemukan: %d', $S['none']));
    say();
    say('== Data diri yang diisi pada orang yang cocok (hanya yang kosong) ==');
    say(sprintf('%d orang diperbarui | tanggal lahir %d (ragu/tak diisi %d) | domisili %d | pekerjaan %d | HP keluarga %d', $S['people_changed'], $S['birth'], $S['birth_doubt'], $S['city'], $S['occ'], $S['fam']));
    say(sprintf('Nomor WA: diisi %d, sama %d, BERTENTANGAN %d (yang tercatat dipertahankan)', $S['phone_set'], $S['phone_same'], $S['phone_conflict']));
    say(sprintf('Foto     : %d dipasang, %d dilewati (sudah punya foto), %d DIPULIHKAN (berkas hilang dari disk), %d gagal', $S['photo'], $S['photo_skip'], $S['photo_repair'], $S['photo_fail']));
    say();
    say('== Jabatan di formulir vs penempatan kita (penempatan TIDAK diubah) ==');
    say(sprintf('Sama %d | jabatan beda pada unit yang sama %d | unit tak tercatat %d | teks tak terbaca sebagai jabatan %d', $S['role_ok'], $S['role_pos'], $S['role_unit'], $S['role_none']));
    say();
    say('== Pendaftaran (status Diajukan) untuk yang belum dikenali ==');
    say(sprintf('Dibuat %d, sudah ada %d, ditahan %d', $S['reg_new'], $S['reg_exists'], $S['held']));
    foreach ($regList as $x) say('  + ' . implode(' | ', $x));
    foreach ($heldList as $x) say('  ! ditahan: ' . $x[0] . ' -- ' . $x[1]);
    say();
    if ($verbose && $matchLog) { say('== Kecocokan selain nama+gelar (periksa) =='); foreach ($matchLog as $l) say('  ' . $l); say(); }
    say(sprintf('Daftar tinjau: %d butir terbaca, %d baru ditulis ke import_review_items.', count($review), $S['review_new']));
    if ($verbose) foreach ($review as $r) say('  - ' . $r[0] . ' :: ' . $r[4]);
    say();

    if ($commit) { $pdo->commit(); say('SELESAI: perubahan DISIMPAN ke `' . $dbName . '`.'); }
    else { $pdo->rollBack(); say('DRY-RUN: tidak ada yang disimpan. Ulangi dengan --commit setelah laporan di atas Anda setujui.'); }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'GAGAL, semua perubahan basis data dibatalkan: ' . $e->getMessage() . "\n");
    exit(1);
}
