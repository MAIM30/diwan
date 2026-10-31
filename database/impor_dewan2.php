<?php
// PATH LOKASI FILE: database/impor_dewan2.php
// Import data sheet DEWAN2: 171 orang di DSO (Dewan Syura), DSY (Dewan Syariah), DPK.
//
// Sumber: database/data_dewan2.csv (unduhan dari Google Sheet DEWAN2)
// Kolom CSV: No, Nama Pengurus, NO HP, Jabatan, Bagian / Komisi, Lembaga
//
// Logika:
//   - Cocokkan orang lewat nomor HP (normalisasi E.164); bila tidak ada/tidak cocok → cocokkan nama;
//     bila tetap tidak ada → buat baru di tabel people.
//   - "Pengurus Harian" DSO dan "Komisi Tetap (Pengurus Harian)" DSY → penempatan di level struktur
//     (structure_unit_id = NULL).
//   - "Pengurus" DPK → struktur datar, semua di level struktur.
//   - Selain itu → buat unit (kind='komisi') di unit_catalog + structure_units, lalu tempatkan di situ.
//   - Satu orang bisa punya banyak penempatan (rangkap jabatan + lintas lembaga).
//   - Idempoten: penempatan yang sudah ada (person_id + structure_id + term_id + position_id +
//     structure_unit_id) tidak dibuat ulang.
//
// Jalankan:
//   php -I database/impor_dewan2.php --db=db_pengurus           (sungguhan)
//   php -I database/impor_dewan2.php --db=db_pengurus --dry-run (simulasi)

if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only.'); }

$opts  = getopt('', ['db::', 'host::', 'user::', 'pass::', 'dry-run']);
$host  = $opts['host'] ?? 'localhost';
$user  = $opts['user'] ?? 'root';
$pass  = $opts['pass'] ?? '';
$db    = $opts['db']   ?? 'db_pengurus';
$dry   = array_key_exists('dry-run', $opts);

if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) { fwrite(STDERR, "Nama DB tidak valid.\n"); exit(1); }

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

if ($dry) echo "[DRY RUN — tidak ada yang diubah]\n\n";

// ─── Konstanta dari migrasi 017 ───────────────────────────────────────────────
$STRUCT = ['DSO' => 1143, 'DSY' => 1144, 'DPK' => 1145];
$TERM   = ['DSO' => 523,  'DSY' => 524,  'DPK' => 525];

// ─── Jabatan → position_id ────────────────────────────────────────────────────
$POS = [
    'ketua'            => 7,
    'wakil ketua'      => 8,
    'sekretaris'       => 9,
    'wakil sekretaris' => 10,
    'bendahara'        => 11,
    'wakil bendahara'  => 12,
    'anggota'          => 19,
];

function mapJabatan(string $j): int {
    global $POS;
    $k = strtolower(trim($j));
    return $POS[$k] ?? 19; // default Anggota
}

// ─── Deteksi gender dari nama + konteks ──────────────────────────────────────
function guessGender(string $name, bool $muslimaContext): string {
    if ($muslimaContext) return 'P';
    $name = strtolower($name);
    // Gelar/sapaan perempuan
    foreach (['hj.', 'dra.', 'dr.hj', 'ny.', 'ustazah'] as $g) {
        if (str_contains($name, $g)) return 'P';
    }
    // Penggalan nama khas perempuan (tanpa trigger false positive umum)
    $femTokens = ['emelia','nurhayati','salwa','ibrawati','syamsi asni','hamna',
                  'kasmawati','devi','jismaniah','rahma arsyad','nurhidayati',
                  'jenny ana','nasrah','hawariah','kurnaemi','sartini','fitriani',
                  'andi indra puteri','armida','asria'];
    foreach ($femTokens as $t) {
        if (str_contains($name, $t)) return 'P';
    }
    return 'L';
}

// ─── Normalisasi nomor HP → format E.164 tanpa '+' ───────────────────────────
function normPhone(string $p): ?string {
    $p = preg_replace('/\D+/', '', $p);
    if ($p === '' || strlen($p) < 8) return null;
    if (str_starts_with($p, '0'))  $p = '62' . substr($p, 1);
    if (!str_starts_with($p, '62')) $p = '62' . $p;
    return $p;
}

// ─── Normalisasi nama untuk perbandingan + name_normalized (ikuti Text::normalizeBase) ───
function normName(string $n): string {
    // Potong di koma pertama (gelar belakang), hilangkan gelar depan umum, huruf kecil
    $s = trim((string) $n);
    $koma = strpos($s, ',');
    if ($koma !== false) $s = substr($s, 0, $koma);
    $s = mb_strtolower($s);
    $s = preg_replace('/\b(dr|drg|prof|ir|hj|h|kh|k\.h|ns|apt)\b\.?/u', '', $s);
    $s = preg_replace('/[^\p{L}\s]/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ─── Cari atau buat unit (komisi/lainnya) ─────────────────────────────────────
$unitCache = []; // "structureId|unitName" → structure_unit_id

function findOrCreateUnit(PDO $pdo, int $structureId, int $termId, string $unitName, string $kind, bool $dry): int {
    global $unitCache;
    $key = "$structureId|$unitName";
    if (isset($unitCache[$key])) return $unitCache[$key];

    // Cari di unit_catalog
    $ucId = $pdo->prepare("SELECT id FROM unit_catalog WHERE name = :n LIMIT 1");
    $ucId->execute([':n' => $unitName]);
    $ucId = $ucId->fetchColumn();
    if (!$ucId) {
        if (!$dry) {
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
            $pdo->prepare("INSERT INTO unit_catalog (uuid, name, kind) VALUES (:u,:n,:k)")
                ->execute([':u'=>$uuid,':n'=>$unitName,':k'=>$kind]);
            $ucId = (int) $pdo->lastInsertId();
        } else {
            $ucId = 0; // placeholder untuk dry-run
        }
        echo "  + unit_catalog: \"$unitName\" (kind=$kind)\n";
    }

    // Cari di structure_units
    $suId = $pdo->prepare("SELECT id FROM structure_units
        WHERE structure_id=:s AND term_id=:t AND unit_catalog_id=:u AND deleted_at IS NULL LIMIT 1");
    $suId->execute([':s'=>$structureId,':t'=>$termId,':u'=>$ucId]);
    $suId = $suId->fetchColumn();
    if (!$suId) {
        if (!$dry && $ucId) {
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
            $pdo->prepare("INSERT INTO structure_units (uuid, structure_id, term_id, unit_catalog_id) VALUES (:u,:s,:t,:c)")
                ->execute([':u'=>$uuid,':s'=>$structureId,':t'=>$termId,':c'=>$ucId]);
            $suId = (int) $pdo->lastInsertId();
        } else {
            $suId = 0;
        }
        echo "  + structure_units: \"$unitName\" @ struktur $structureId\n";
    }

    $unitCache[$key] = (int) $suId;
    return (int) $suId;
}

// ─── Cari atau buat orang ─────────────────────────────────────────────────────
$personCache = []; // phone → person_id

function findOrCreatePerson(PDO $pdo, string $name, string $rawPhone, string $gender, bool $dry): int {
    global $personCache;

    $phone = normPhone($rawPhone);

    // Coba cache dulu
    if ($phone && isset($personCache[$phone])) return $personCache[$phone];

    // Cari lewat HP
    if ($phone) {
        $row = $pdo->prepare("SELECT id FROM people WHERE phone = :p AND deleted_at IS NULL LIMIT 1");
        $row->execute([':p' => $phone]);
        if ($id = $row->fetchColumn()) {
            $personCache[$phone] = (int) $id;
            return (int) $id;
        }
    }

    // Cari lewat nama (normalisasi)
    $normN = normName($name);
    $all = $pdo->query("SELECT id, name FROM people WHERE deleted_at IS NULL")->fetchAll();
    foreach ($all as $r) {
        if (normName($r['name']) === $normN) {
            $id = (int) $r['id'];
            if ($phone) $personCache[$phone] = $id;
            return $id;
        }
    }

    // Buat baru
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $id = 0;
    if (!$dry) {
        $pdo->prepare("INSERT INTO people (uuid, name, name_normalized, gender, phone, status, portal_enabled)
            VALUES (:u, :n, :nn, :g, :p, 'active', 0)")
            ->execute([':u'=>$uuid, ':n'=>$name, ':nn'=>normName($name), ':g'=>$gender, ':p'=>$phone]);
        $id = (int) $pdo->lastInsertId();
        if ($phone) $personCache[$phone] = $id;
    }
    echo "  + orang baru: \"$name\" ($gender, HP: " . ($phone ?? '-') . ")\n";
    return $id;
}

// ─── Buat penempatan (idempoten) ──────────────────────────────────────────────
function placeIfNew(PDO $pdo, int $personId, int $structureId, int $termId,
                    int $posId, ?int $unitId, string $label, bool $dry): void {
    if (!$personId) { echo "  ! penempatan dilewati (person_id=0, dry-run)\n"; return; }

    $check = $pdo->prepare("SELECT id FROM placements
        WHERE person_id=:p AND structure_id=:s AND term_id=:t
          AND position_id=:po AND (structure_unit_id <=> :u) AND deleted_at IS NULL LIMIT 1");
    $check->execute([':p'=>$personId,':s'=>$structureId,':t'=>$termId,
                     ':po'=>$posId,':u'=>$unitId]);
    if ($check->fetchColumn()) {
        echo "  = sudah ada: $label\n";
        return;
    }

    if (!$dry) {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
        $pdo->prepare("INSERT INTO placements
            (uuid, person_id, structure_id, term_id, position_id, structure_unit_id, source)
            VALUES (:uu,:p,:s,:t,:po,:u,'sk')")
            ->execute([':uu'=>$uuid,':p'=>$personId,':s'=>$structureId,':t'=>$termId,
                       ':po'=>$posId,':u'=>$unitId]);
    }
    echo "  + penempatan: $label\n";
}

// ─── Baca CSV ─────────────────────────────────────────────────────────────────
$csvPath = __DIR__ . '/data_dewan2.csv';
if (!file_exists($csvPath)) { fwrite(STDERR, "CSV tidak ditemukan: $csvPath\n"); exit(1); }

$fh   = fopen($csvPath, 'r');
$head = fgetcsv($fh); // header: No, Nama Pengurus, NO HP, Jabatan, Bagian / Komisi, Lembaga
$rows = [];
while ($row = fgetcsv($fh)) {
    if (count($row) < 6) continue;
    $rows[] = [
        'no'      => trim($row[0]),
        'nama'    => trim($row[1]),
        'hp'      => trim($row[2]),
        'jabatan' => trim($row[3]),
        'bagian'  => trim($row[4]),
        'lembaga' => trim($row[5]),
    ];
}
fclose($fh);

// ─── Pemetaan Lembaga → kode ──────────────────────────────────────────────────
$lemMap = [
    'Dewan Syura'                     => 'DSO',
    'Dewan Syariah'                   => 'DSY',
    'Dewan Pengawas Keuangan (DPK)'   => 'DPK',
    'Dewan Pengawas Keuangan'         => 'DPK',
];

// Bagian/Komisi yang ditempatkan di level struktur (bukan unit)
$strLevelBagian = [
    'Pengurus Harian',                   // DSO
    'Komisi Tetap (Pengurus Harian)',    // DSY
    'Pengurus',                          // DPK
];

// ─── Proses baris ─────────────────────────────────────────────────────────────
$counters = ['orang_baru' => 0, 'unit_baru' => 0, 'penempatan_baru' => 0, 'penempatan_ada' => 0];

if (!$dry) $pdo->beginTransaction();

foreach ($rows as $r) {
    $lemKode = null;
    foreach ($lemMap as $k => $v) {
        if (stripos($r['lembaga'], $k) !== false) { $lemKode = $v; break; }
    }
    if (!$lemKode) {
        echo "! Baris {$r['no']}: lembaga tidak dikenal \"{$r['lembaga']}\"\n";
        continue;
    }

    $structureId = $STRUCT[$lemKode];
    $termId      = $TERM[$lemKode];
    $posId       = mapJabatan($r['jabatan']);

    // Konteks muslimah: Komisi VIII DSO, Komisi Muslimah DSY
    $muslimaCtx = str_contains($r['bagian'], 'Muslimah')
               || str_contains($r['bagian'], 'muslimah');

    echo "\n[{$r['no']}] {$r['nama']} | {$r['jabatan']} | {$r['bagian']} | $lemKode\n";

    $gender    = guessGender($r['nama'], $muslimaCtx);
    $personId  = findOrCreatePerson($pdo, $r['nama'], $r['hp'], $gender, $dry);

    // Tentukan unit
    $unitId = null;
    $inStrLevel = false;
    foreach ($strLevelBagian as $sl) {
        if (stripos($r['bagian'], $sl) !== false) { $inStrLevel = true; break; }
    }
    // "Anggota Komisi Tetap" → tetap level struktur (bagian dari Komisi Tetap Pengurus Harian)
    if (stripos($r['bagian'], 'Anggota Komisi Tetap') !== false) $inStrLevel = true;

    if (!$inStrLevel) {
        // Tentukan kind: semua Komisi → 'komisi'; Anggota Pleno → 'lainnya'
        $kind = (stripos($r['bagian'], 'Komisi') !== false) ? 'komisi' : 'lainnya';
        $unitId = findOrCreateUnit($pdo, $structureId, $termId, $r['bagian'], $kind, $dry);
    }

    $label = "{$r['nama']} sebagai {$r['jabatan']} di {$r['bagian']} ($lemKode)";
    placeIfNew($pdo, $personId, $structureId, $termId, $posId, $unitId ?: null, $label, $dry);
}

if (!$dry) $pdo->commit();

echo "\n--- Selesai ---\n";
echo "Dry-run: " . ($dry ? 'YA' : 'tidak') . "\n";
