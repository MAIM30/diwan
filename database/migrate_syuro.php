<?php
// PATH LOKASI FILE: database/migrate_syuro.php
// Migrasi satu kali dari basis data syuro (db_syuro) ke pengurus.wahdah.or.id, dijalankan dari CLI:
//
//   php database/migrate_syuro.php --db=db_pengurus [--source=db_syuro] [--commit]
//
// Tanpa --commit skrip hanya MENCOBA (semua perubahan di-rollback) dan mencetak laporan, jadi hasilnya
// bisa ditinjau dulu. Target harus basis data yang baru dipasang (php database/install.php ...) dan
// belum berisi orang -- skrip menolak berjalan dua kali atau di atas data lain.
//
// Pemetaan:
//   organizations        -> structures        (disatukan dengan akar DPP + MWP bawaan install.php)
//   master_departments   -> unit_catalog      (jenis unit ditebak dari kata pertama namanya)
//   organization_units   -> structure_units   (+ unit_groups dari teks `bidang`) pada periode berjalan
//   users                -> people            (nama dipisah gelar depan | nama | gelar belakang)
//   user_placements      -> placements        (source = 'migrasi_syuro')
//   users.masa_amanah_*  -> terms             (periode jadi entitas; lihat aturan di bawah)
//
// ATURAN PERIODE. Di syuro masa amanah dua kolom di `users`, bukan milik struktur. Di sini:
//   * DPW dan MWW: SELALU periode 2021-2026 (1 Jan 2021 - 31 Des 2026), apa pun isi kolom di syuro --
//     keputusan pengelola data. Syuro hanya menyimpan tanggalnya untuk satu pengurus DPW; 250
//     penempatan DPW/MWW lainnya kosong.
//   * Tingkat lain: tanggal dari pengurusnya. Penempatan yang tanggalnya kosong dimasukkan ke periode
//     yang paling banyak dipakai struktur itu DAN dicatat di import_review_items untuk dikonfirmasi.
//   * Per struktur, periode dengan tanggal mulai terbaru yang sudah lewat = berjalan; yang lebih tua
//     = arsip (dipotong sehari sebelum periode berikutnya bila tumpang tindih); yang belum mulai = draft.
//
// YANG SENGAJA TIDAK DIBAWA:
//   * Akun & peran syuro (role/scope_organization_id). Peran di syuro tidak bisa dipetakan 1:1 ke
//     peran di sini (di syuro 'Pimpinan'/'Sekretaris' berlingkup akar = nasional), dan memberi hak
//     admin lewat migrasi diam-diam adalah keputusan akses, bukan keputusan data. Beri peran lewat
//     menu Akun. Daftar pemegang peran di syuro dicetak di akhir laporan.
//   * Email palsu hasil impor syuro (*.local) dan pengguna tanpa penempatan (mis. Super Admin syuro).
//   * NIK: syuro tidak menyimpannya. Orang hasil migrasi baru bisa masuk Portal Pengurus setelah NIK-nya diisi.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Skrip ini hanya boleh dijalankan dari command line.');
}

require_once __DIR__ . '/../app/Core/Text.php';
require_once __DIR__ . '/../app/Core/Uuid.php';

// ---------- Keputusan pengelola data (ubah di sini bila berubah) ----------
const FIXED_TERMS = [
    'DPW' => ['start' => '2021-01-01', 'end' => '2026-12-31'],
    'MWW' => ['start' => '2021-01-01', 'end' => '2026-12-31'],
];
// Kode wilayah pengait DPW<->MWW (structures.region_code, VARCHAR(20)).
const REGION_PAIR = ['pattern' => '/Sulawesi Tenggara/i', 'code' => 'SULTRA', 'levels' => ['DPW', 'MWW']];

$opts = getopt('', ['source::', 'db::', 'host::', 'user::', 'pass::', 'commit']);
$host   = $opts['host'] ?? 'localhost';
$user   = $opts['user'] ?? 'root';
$pass   = $opts['pass'] ?? '';
$src    = $opts['source'] ?? 'db_syuro';
$dst    = $opts['db'] ?? 'db_pengurus';
$commit = isset($opts['commit']);

foreach ([$src, $dst] as $n) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $n)) {
        fwrite(STDERR, "Nama database hanya boleh huruf, angka, dan garis bawah.\n");
        exit(1);
    }
}
if ($src === $dst) {
    fwrite(STDERR, "Sumber dan tujuan tidak boleh basis data yang sama.\n");
    exit(1);
}

function say($m = '') { echo $m . "\n"; }
function fail($m) { fwrite(STDERR, "BATAL: $m\n"); exit(1); }

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fail('Tidak bisa terhubung ke MySQL: ' . $e->getMessage());
}

$exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
foreach ([$src => 'sumber', $dst => 'tujuan'] as $n => $label) {
    $exists->execute([$n]);
    if (!(int) $exists->fetchColumn()) fail("Basis data $label `$n` tidak ada." . ($label === 'tujuan' ? " Pasang dulu: php database/install.php --db=$n ..." : ''));
}

$S = "`$src`";
$D = "`$dst`";
$pdo->exec("USE $D");

// ---------- Prasyarat ----------
if ((int) $pdo->query("SELECT COUNT(*) FROM people")->fetchColumn() > 0
    || (int) $pdo->query("SELECT COUNT(*) FROM placements")->fetchColumn() > 0) {
    fail("`$dst` sudah berisi orang/penempatan. Migrasi hanya untuk basis data yang baru dipasang:\n"
       . "  php database/install.php --db=$dst --fresh --admin-phone=... --admin-password=...");
}

$today = date('Y-m-d');
$positions = [];
foreach ($pdo->query("SELECT id, name FROM positions")->fetchAll() as $r) $positions[mb_strtolower($r['name'])] = (int) $r['id'];
if (!$positions) fail('Katalog jabatan kosong. Jalankan install.php lebih dulu.');

// ---------- Baca sumber ----------
$orgs   = $pdo->query("SELECT id, level, name, parent_id FROM $S.organizations ORDER BY id")->fetchAll();
$users  = $pdo->query("SELECT * FROM $S.users ORDER BY id")->fetchAll();
$pls    = $pdo->query("SELECT * FROM $S.user_placements ORDER BY id")->fetchAll();
$depts  = $pdo->query("SELECT * FROM $S.master_departments ORDER BY id")->fetchAll();
$ounits = $pdo->query("SELECT * FROM $S.organization_units ORDER BY id")->fetchAll();

$orgById = []; foreach ($orgs as $o) $orgById[(int) $o['id']] = $o;
$userById = []; foreach ($users as $u) $userById[(int) $u['id']] = $u;
$unitById = []; foreach ($ounits as $u) $unitById[(int) $u['id']] = $u;
$deptById = []; foreach ($depts as $d) $deptById[(int) $d['id']] = $d;

say("Sumber `$src`: " . count($orgs) . ' struktur, ' . count($users) . ' pengguna, ' . count($pls) . ' penempatan, '
    . count($depts) . ' unit katalog, ' . count($ounits) . ' unit struktur.');
say('Tujuan  `' . $dst . '`: ' . ($commit ? 'AKAN DISIMPAN (--commit)' : 'dry-run, semuanya di-rollback') . '.');
say();

$review = [];   // [raw_name, raw_context, reason, note]
$stat = ['people' => 0, 'placements' => 0, 'skipped_people' => 0, 'skipped_placements' => 0, 'name_changed' => 0];
$uuid = function () { return Uuid::v4(); };

$pdo->beginTransaction();
try {
    // =================================================================================================
    // 1. STRUKTUR
    // =================================================================================================
    $struct = [];  // id syuro -> id tujuan
    $pending = $orgs;
    $guard = 0;
    while ($pending && $guard++ < 20) {
        $rest = [];
        foreach ($pending as $o) {
            $pid = $o['parent_id'] === null ? null : ($struct[(int) $o['parent_id']] ?? false);
            if ($pid === false) { $rest[] = $o; continue; }   // induk belum dibuat: tunda

            $region = (in_array($o['level'], REGION_PAIR['levels'], true) && preg_match(REGION_PAIR['pattern'], $o['name']))
                ? REGION_PAIR['code'] : null;

            $q = $pdo->prepare("SELECT id, parent_id FROM structures WHERE level = ? AND name = ? LIMIT 1");
            $q->execute([$o['level'], $o['name']]);
            if ($row = $q->fetch()) {
                if ((int) $row['parent_id'] !== (int) $pid) fail("Struktur {$o['level']} {$o['name']} sudah ada di tujuan tetapi induknya berbeda.");
                $id = (int) $row['id'];
                if ($region !== null) $pdo->prepare("UPDATE structures SET region_code = ? WHERE id = ?")->execute([$region, $id]);
            } else {
                $pdo->prepare("INSERT INTO structures (uuid, level, name, parent_id, region_code) VALUES (?,?,?,?,?)")
                    ->execute([$uuid(), $o['level'], $o['name'], $pid, $region]);
                $id = (int) $pdo->lastInsertId();
                $pp = '/'; $depth = 0;
                if ($pid !== null) {
                    $r = $pdo->prepare("SELECT path, depth FROM structures WHERE id = ?");
                    $r->execute([$pid]);
                    $prow = $r->fetch();
                    $pp = $prow['path']; $depth = (int) $prow['depth'] + 1;
                }
                $pdo->prepare("UPDATE structures SET path = ?, depth = ? WHERE id = ?")->execute([$pp . $id . '/', $depth, $id]);
            }
            $struct[(int) $o['id']] = $id;
        }
        $pending = $rest;
    }
    if ($pending) fail('Pohon struktur sumber memuat induk yang tidak ada / berputar.');

    // =================================================================================================
    // 2. PERIODE -- tentukan periode tiap penempatan, lalu buat periodenya
    // =================================================================================================
    // Periode paling umum per struktur (dari yang bertanggal), untuk menampung penempatan tanpa tanggal.
    $freq = [];
    foreach ($pls as $p) {
        $o = $orgById[(int) $p['organization_id']]; $u = $userById[(int) $p['user_id']];
        if (isset(FIXED_TERMS[$o['level']]) || !$u['masa_amanah_start']) continue;
        $k = $u['masa_amanah_start'] . '|' . ($u['masa_amanah_end'] ?? '');
        $freq[(int) $o['id']][$k] = ($freq[(int) $o['id']][$k] ?? 0) + 1;
    }
    $dominant = [];
    foreach ($freq as $oid => $m) { arsort($m); $dominant[$oid] = array_key_first($m); }

    $plTermKey = [];   // id penempatan syuro -> kunci periode "orgId|mulai|akhir"
    $plNoDate = [];    // id penempatan syuro -> true bila memakai periode dominan
    $specs = [];       // orgId -> kunci -> ['start','end','fixed']
    foreach ($pls as $p) {
        $oid = (int) $p['organization_id']; $o = $orgById[$oid]; $u = $userById[(int) $p['user_id']];
        $fixed = false;
        if (isset(FIXED_TERMS[$o['level']])) {
            $s = FIXED_TERMS[$o['level']]['start']; $e = FIXED_TERMS[$o['level']]['end']; $fixed = true;
        } elseif ($u['masa_amanah_start']) {
            $s = $u['masa_amanah_start']; $e = $u['masa_amanah_end'] ?: '';
        } elseif (isset($dominant[$oid])) {
            [$s, $e] = explode('|', $dominant[$oid]); $plNoDate[(int) $p['id']] = true;
        } else {
            $plTermKey[(int) $p['id']] = null;   // tak ada acuan sama sekali -> dilewati & dilaporkan
            continue;
        }
        $key = $oid . '|' . $s . '|' . $e;
        $plTermKey[(int) $p['id']] = $key;
        $specs[$oid][$key] = ['start' => $s, 'end' => $e === '' ? null : $e, 'fixed' => $fixed];
    }

    $termId = [];        // kunci -> id periode tujuan
    $termInfo = [];      // kunci -> [orgId, nama, status, is_current, end_actual, start, end]
    $currentTermOf = []; // orgId -> kunci periode berjalan (atau terbaru)
    $periodIdByIdentity = [];
    $findPeriod = $pdo->prepare("SELECT id FROM governance_periods
        WHERE name = ? AND start_date = ? AND end_date_planned <=> ? LIMIT 1");
    $insPeriod = $pdo->prepare("INSERT INTO governance_periods (uuid, name, start_date, end_date_planned) VALUES (?,?,?,?)");
    $insTerm = $pdo->prepare("INSERT INTO terms (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, end_date_actual, status, is_current, notes)
                              VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($specs as $oid => $list) {
        uasort($list, function ($a, $b) { return strcmp($a['start'], $b['start']); });
        $keys = array_keys($list);
        $cur = null;
        foreach ($keys as $i => $k) if ($list[$k]['start'] <= $today) $cur = $i;
        foreach ($keys as $i => $k) {
            $t = $list[$k];
            if ($cur === null || $i > $cur) { $status = 'draft'; $isCur = 0; $actual = null; }
            elseif ($i === $cur) { $status = 'active'; $isCur = 1; $actual = null; }
            else {
                $status = 'ended'; $isCur = 0;
                $cut = date('Y-m-d', strtotime($list[$keys[$i + 1]]['start'] . ' -1 day'));
                $actual = ($t['end'] !== null && $t['end'] < $cut) ? $t['end'] : $cut;
            }
            $name = substr($t['start'], 0, 4) . '-' . ($t['end'] ? substr($t['end'], 0, 4) : '...');
            $periodIdentity = $name . '|' . $t['start'] . '|' . ($t['end'] ?? '');
            if (!isset($periodIdByIdentity[$periodIdentity])) {
                $findPeriod->execute([$name, $t['start'], $t['end']]);
                $periodId = $findPeriod->fetchColumn();
                if (!$periodId) {
                    $insPeriod->execute([$uuid(), $name, $t['start'], $t['end']]);
                    $periodId = (int) $pdo->lastInsertId();
                }
                $periodIdByIdentity[$periodIdentity] = (int) $periodId;
            }
            $periodId = $periodIdByIdentity[$periodIdentity];
            $note = $t['fixed']
                ? 'Periode ditetapkan pengelola data saat migrasi dari syuro: syuro tidak menyimpan tanggal masa amanah untuk hampir seluruh penempatan tingkat ini.'
                : null;
            $insTerm->execute([$uuid(), $periodId, $struct[$oid], $name, $t['start'], $t['end'], $actual, $status, $isCur, $note]);
            $termId[$k] = (int) $pdo->lastInsertId();
            $termInfo[$k] = ['org' => $oid, 'name' => $name, 'status' => $status, 'cur' => $isCur, 'actual' => $actual, 'start' => $t['start'], 'end' => $t['end']];
            if ($i === $cur || ($cur === null && $i === count($keys) - 1)) $currentTermOf[$oid] = $k;
        }
    }

    // =================================================================================================
    // 3. KATALOG UNIT
    // =================================================================================================
    $kindOf = function ($name) {
        $first = mb_strtolower((string) strtok($name, ' '));
        $m = ['departemen' => 'departemen', 'lembaga' => 'lembaga', 'biro' => 'biro', 'badan' => 'badan', 'pusat' => 'pusat', 'divisi' => 'divisi'];
        return $m[$first] ?? 'lainnya';
    };
    $headCode = function ($kind) { return $kind === 'pusat' ? 'kepala' : ($kind === 'divisi' ? 'koordinator' : 'ketua'); };
    $posByCode = [];
    foreach ($pdo->query("SELECT id, code FROM positions")->fetchAll() as $r) $posByCode[$r['code']] = (int) $r['id'];

    $catalog = [];   // id master_departments -> id unit_catalog
    $insCat = $pdo->prepare("INSERT INTO unit_catalog (uuid, name, abbreviation, kind, default_head_position_id) VALUES (?,?,?,?,?)");
    foreach ($depts as $d) {
        $kind = $kindOf($d['name']);
        $insCat->execute([$uuid(), $d['name'], $d['abbreviation'] !== null && $d['abbreviation'] !== '' ? $d['abbreviation'] : null,
                          $kind, $posByCode[$headCode($kind)] ?? null]);
        $catalog[(int) $d['id']] = (int) $pdo->lastInsertId();
    }

    // =================================================================================================
    // 4. UNIT PER STRUKTUR + PERIODE, DAN BIDANG PENAUNGNYA
    // =================================================================================================
    // Unit di syuro tak punya periode. Ia dibuat di periode berjalan struktur itu, ditambah periode lain
    // mana pun yang dirujuk sebuah penempatan (supaya riwayat lama tetap menunjuk unit yang sah).
    $unitTerms = [];   // id unit syuro -> set kunci periode
    foreach ($ounits as $u) {
        $oid = (int) $u['organization_id'];
        if (isset($currentTermOf[$oid])) $unitTerms[(int) $u['id']][$currentTermOf[$oid]] = true;
    }
    foreach ($pls as $p) {
        if ($p['organization_unit_id'] && !empty($plTermKey[(int) $p['id']])) $unitTerms[(int) $p['organization_unit_id']][$plTermKey[(int) $p['id']]] = true;
    }

    // Urutan Bidang = urutan kemunculan pertama; kelompok longgar ("Biro-Biro", dst.) diurutkan di akhir.
    $groupKind = function ($n) { return preg_match('/^Bidang\b/i', $n) ? 'bidang' : (preg_match('/^Subbidang\b/i', $n) ? 'subbidang' : 'grup'); };
    $groupSort = [];   // orgId -> nama -> urutan
    foreach ($ounits as $u) {
        $b = trim((string) $u['bidang']);
        if ($b === '') continue;
        $oid = (int) $u['organization_id'];
        if (!isset($groupSort[$oid][$b])) {
            $n = count($groupSort[$oid] ?? []);
            $groupSort[$oid][$b] = ($groupKind($b) === 'grup' ? 800 : 10) + $n * 10;
        }
    }

    $groupId = [];   // "termId|nama" -> id unit_groups
    $suId = [];      // "kunciPeriode|idUnitSyuro" -> id structure_units
    $insGrp = $pdo->prepare("INSERT INTO unit_groups (uuid, structure_id, term_id, name, kind, sort_order) VALUES (?,?,?,?,?,?)");
    $insSu = $pdo->prepare("INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id, unit_group_id) VALUES (?,?,?,?,?)");
    $stat['units'] = 0; $stat['groups'] = 0;
    foreach ($ounits as $u) {
        $uid = (int) $u['id'];
        if (empty($unitTerms[$uid])) { $review[] = [$deptById[(int) $u['master_department_id']]['name'], 'unit tanpa periode di struktur tujuan', 'unmapped_unit', 'Unit ini tidak dibuat karena strukturnya belum punya periode.']; continue; }
        $oid = (int) $u['organization_id'];
        foreach (array_keys($unitTerms[$uid]) as $k) {
            $gid = null;
            $b = trim((string) $u['bidang']);
            if ($b !== '') {
                $gk = $termId[$k] . '|' . $b;
                if (!isset($groupId[$gk])) {
                    $insGrp->execute([$uuid(), $struct[$oid], $termId[$k], $b, $groupKind($b), $groupSort[$oid][$b]]);
                    $groupId[$gk] = (int) $pdo->lastInsertId();
                    $stat['groups']++;
                }
                $gid = $groupId[$gk];
            }
            $insSu->execute([$uuid(), $struct[$oid], $termId[$k], $catalog[(int) $u['master_department_id']], $gid]);
            $suId[$k . '|' . $uid] = (int) $pdo->lastInsertId();
            $stat['units']++;
        }
    }

    // =================================================================================================
    // 5. ORANG
    // =================================================================================================
    $plByUser = [];
    foreach ($pls as $p) $plByUser[(int) $p['user_id']][] = $p;

    $person = [];   // id user syuro -> id orang tujuan
    $insPerson = $pdo->prepare("INSERT INTO people (uuid, title_prefix, name, title_suffix, name_normalized, gender, phone, email, status, created_at, home_structure_id)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $nameSamples = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        if (empty($plByUser[$uid])) { $stat['skipped_people']++; continue; }   // bukan pengurus (mis. Super Admin syuro)
        if ($u['gender'] !== 'Laki-laki' && $u['gender'] !== 'Perempuan') {
            $review[] = [$u['name'], 'users.id=' . $uid, 'lainnya', 'Jenis kelamin kosong di syuro; orang & penempatannya TIDAK dimigrasikan. Isi lalu tambahkan manual.'];
            $stat['skipped_people']++; $stat['skipped_placements'] += count($plByUser[$uid]);
            foreach ($plByUser[$uid] as $p) $plTermKey[(int) $p['id']] = null;
            continue;
        }
        $parts = Text::splitName($u['name']);
        $joined = Text::joinName($parts['title_prefix'], $parts['name'], $parts['title_suffix']);
        $raw = trim(preg_replace('/\s+/u', ' ', $u['name']));
        if ($joined !== $raw) { $stat['name_changed']++; if (count($nameSamples) < 5) $nameSamples[] = "$raw  =>  $joined"; }

        $email = trim((string) $u['email']);
        if ($email === '' || preg_match('/\.local$/i', $email)) $email = null;

        // Struktur asal: penempatan utama bila ada, kalau tidak yang pertama.
        $first = $plByUser[$uid][0];
        foreach ($plByUser[$uid] as $p) if ((int) $p['is_primary'] === 1) { $first = $p; break; }
        $home = $struct[(int) $first['organization_id']] ?? null;

        $insPerson->execute([
            $uuid(), $parts['title_prefix'], $parts['name'], $parts['title_suffix'], Text::normalizeBase($parts['name']),
            $u['gender'] === 'Laki-laki' ? 'L' : 'P',
            Text::normalizePhone($u['phone']), $email,
            $u['status'] === 'Active' ? 'active' : 'inactive',
            $u['created_at'] ?: date('Y-m-d H:i:s'), $home,
        ]);
        $person[$uid] = (int) $pdo->lastInsertId();
        $stat['people']++;
    }

    // =================================================================================================
    // 6. PENEMPATAN
    // =================================================================================================
    $insPl = $pdo->prepare("INSERT INTO placements (uuid, person_id, term_id, structure_id, structure_unit_id, position_id, is_primary, source, created_at)
                            VALUES (?,?,?,?,?,?,?,'migrasi_syuro',?)");
    $perTerm = [];
    foreach ($pls as $p) {
        $pid = (int) $p['id']; $uid = (int) $p['user_id']; $u = $userById[$uid]; $o = $orgById[(int) $p['organization_id']];
        $ctx = $p['position_name'] . ' · ' . $o['level'] . ' ' . $o['name'];
        if (!isset($person[$uid])) continue;   // sudah dicatat saat orangnya dilewati
        $k = $plTermKey[$pid] ?? null;
        if ($k === null) { $review[] = [$u['name'], $ctx, 'lainnya', 'Tidak ada periode acuan untuk struktur ini; penempatan TIDAK dimigrasikan.']; $stat['skipped_placements']++; continue; }
        $posId = $positions[mb_strtolower(trim($p['position_name']))] ?? null;
        if ($posId === null) { $review[] = [$u['name'], $ctx, 'unmapped_position', 'Jabatan "' . $p['position_name'] . '" tidak ada di katalog; penempatan TIDAK dimigrasikan.']; $stat['skipped_placements']++; continue; }
        $suKey = $p['organization_unit_id'] ? ($k . '|' . (int) $p['organization_unit_id']) : null;
        if ($suKey !== null && !isset($suId[$suKey])) { $review[] = [$u['name'], $ctx, 'unmapped_unit', 'Unit tidak ditemukan pada periode ini; penempatan TIDAK dimigrasikan.']; $stat['skipped_placements']++; continue; }

        try {
            $insPl->execute([$uuid(), $person[$uid], $termId[$k], $struct[(int) $p['organization_id']], $suKey ? $suId[$suKey] : null, $posId,
                             (int) $p['is_primary'], $p['created_at'] ?: date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $review[] = [$u['name'], $ctx, 'lainnya', 'Penempatan ganda (orang, periode, unit, jabatan sama); hanya satu yang dimigrasikan.'];
            $stat['skipped_placements']++; continue;
        }
        $stat['placements']++;
        $perTerm[$k] = ($perTerm[$k] ?? 0) + 1;

        if (!empty($plNoDate[$pid])) {
            $review[] = [$u['name'], $ctx, 'lainnya', 'Tanpa masa amanah di syuro; ditempatkan di periode ' . $termInfo[$k]['name'] . ' (yang paling banyak dipakai struktur ini). Mohon konfirmasi.'];
        } elseif ($termInfo[$k]['status'] === 'ended') {
            $review[] = [$u['name'], $ctx, 'lainnya', 'Masa amanah di syuro (' . $termInfo[$k]['start'] . ' s.d. ' . ($termInfo[$k]['end'] ?: '-') . ') menghasilkan periode arsip '
                . $termInfo[$k]['name'] . ' yang dipotong ' . $termInfo[$k]['actual'] . ' karena periode berikutnya sudah berjalan. Apakah penempatan ini seharusnya di periode berjalan?'];
        }
    }

    // =================================================================================================
    // 7. CATATAN BATCH + DAFTAR TINJAU
    // =================================================================================================
    $pdo->prepare("INSERT INTO import_batches (uuid, label, source, status, stat_new, stat_matched, stat_needs_review, finished_at)
                   VALUES (?,?, 'syuro', 'completed', ?, 0, ?, NOW(3))")
        ->execute([$uuid(), "Migrasi dari `$src`", $stat['people'], count($review)]);
    $batch = (int) $pdo->lastInsertId();
    $insRev = $pdo->prepare("INSERT INTO import_review_items (batch_id, raw_name, raw_context, reason, note) VALUES (?,?,?,?,?)");
    foreach ($review as $r) $insRev->execute([$batch, mb_substr($r[0], 0, 200), mb_substr($r[1], 0, 255), $r[2], $r[3]]);

    // =================================================================================================
    // 8. PEMERIKSAAN SEBELUM DISIMPAN
    // =================================================================================================
    $problems = [];
    $c = function ($sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };
    if ($c("SELECT COUNT(*) FROM placements WHERE source='migrasi_syuro'") !== $stat['placements']) $problems[] = 'jumlah penempatan di tujuan tidak sama dengan yang dihitung skrip';
    if ($c("SELECT COUNT(*) FROM people") !== $stat['people']) $problems[] = 'jumlah orang di tujuan tidak sama dengan yang dihitung skrip';
    if ($c("SELECT COUNT(*) FROM people p WHERE NOT EXISTS (SELECT 1 FROM placements pl WHERE pl.person_id = p.id)") > 0) $problems[] = 'ada orang tanpa penempatan';
    if ($c("SELECT COUNT(*) FROM (SELECT structure_id FROM terms WHERE is_current=1 GROUP BY structure_id HAVING COUNT(*)>1) x") > 0) $problems[] = 'ada struktur dengan lebih dari satu periode berjalan';
    if ($c("SELECT COUNT(*) FROM placements pl JOIN terms t ON t.id=pl.term_id WHERE t.structure_id <> pl.structure_id") > 0) $problems[] = 'ada penempatan yang strukturnya tidak sama dengan struktur periodenya';
    if ($c("SELECT COUNT(*) FROM people WHERE full_name = '' OR name = ''") > 0) $problems[] = 'ada nama kosong';
    // DPW/MWW: tidak boleh ada satu pun penempatan di luar periode yang diputuskan pengelola.
    $off = $c("SELECT COUNT(*) FROM placements pl JOIN structures s ON s.id=pl.structure_id JOIN terms t ON t.id=pl.term_id
               WHERE s.level IN ('DPW','MWW') AND (t.start_date <> '" . FIXED_TERMS['DPW']['start'] . "' OR t.end_date_planned <> '" . FIXED_TERMS['DPW']['end'] . "')");
    if ($off > 0) $problems[] = "$off penempatan DPW/MWW berada di luar periode 2021-2026";

    if ($problems) throw new RuntimeException('Pemeriksaan gagal: ' . implode('; ', $problems));

    // =================================================================================================
    // LAPORAN
    // =================================================================================================
    say('== Hasil ==');
    say(sprintf('Orang      : %d dimigrasikan, %d dilewati (tanpa penempatan / tanpa jenis kelamin)', $stat['people'], $stat['skipped_people']));
    say(sprintf('Penempatan : %d dimigrasikan, %d dilewati', $stat['placements'], $stat['skipped_placements']));
    say(sprintf('Unit       : %d unit struktur, %d bidang/kelompok, %d unit katalog', $stat['units'], $stat['groups'], count($catalog)));
    say(sprintf('Nama       : %d nama disusun ulang saat dipisah gelar (spasi/koma dirapikan)', $stat['name_changed']));
    foreach ($nameSamples as $s) say('             ' . $s);
    say();
    say('== Periode ==');
    say(sprintf('%-6s %-48s %-10s %-9s %-25s %s', 'Tingkat', 'Struktur', 'Periode', 'Status', 'Rentang', 'Penempatan'));
    foreach ($termInfo as $k => $t) {
        $o = $orgById[$t['org']];
        say(sprintf('%-6s %-48s %-10s %-9s %-25s %d', $o['level'], mb_substr($o['name'], 0, 46), $t['name'], $t['status'] . ($t['cur'] ? '*' : ''),
            $t['start'] . ' - ' . ($t['end'] ?: '...') . ($t['actual'] ? ' (dipotong ' . $t['actual'] . ')' : ''), $perTerm[$k] ?? 0));
    }
    say('  * = periode berjalan');
    say();
    $byReason = [];
    foreach ($review as $r) { $key = $r[2] . ': ' . (strpos($r[3], 'Tanpa masa amanah') === 0 ? 'tanpa tanggal di syuro -> periode dominan' : (strpos($r[3], 'periode arsip') !== false ? 'periode arsip tumpang tindih' : $r[3])); $byReason[$key] = ($byReason[$key] ?? 0) + 1; }
    say('== Perlu ditinjau manusia (' . count($review) . ' butir, tersimpan di import_review_items) ==');
    foreach ($byReason as $k => $n) say(sprintf('  %4d  %s', $n, $k));
    say();
    say('== Akun & peran syuro yang TIDAK dibawa ==');
    foreach ($users as $u) {
        if ($u['role'] === 'Pengurus') continue;
        say(sprintf('  %-6s %-42s peran syuro: %-12s lingkup: %s  telepon: %s', '#' . $u['id'], mb_substr($u['name'], 0, 40), $u['role'],
            $u['scope_organization_id'] ? ($orgById[(int) $u['scope_organization_id']]['level'] ?? '?') : '-', $u['phone'] ? 'ada' : 'tidak ada'));
    }
    say('  Beri peran lewat menu Akun setelah migrasi; orangnya sudah ada di daftar Orang.');
    say();

    if ($commit) {
        $pdo->commit();
        say('SELESAI: perubahan DISIMPAN ke `' . $dst . '`.');
    } else {
        $pdo->rollBack();
        say('DRY-RUN: tidak ada yang disimpan. Ulangi dengan --commit setelah laporan di atas Anda setujui.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "GAGAL, semua perubahan dibatalkan: " . $e->getMessage() . "\n");
    exit(1);
}
