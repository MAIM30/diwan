<?php
// PATH LOKASI FILE: tests/term_period_edit_test.php
// Uji pembetulan periode yang keliru:
//   (1) Ubah Periode: acuan periode sebuah periode struktur bisa DIGANTI (nama + tanggal mengikuti acuan baru, penempatan tak disentuh), dengan
//       penolakan yang jelas bila struktur itu sudah memakai acuan/nama tersebut;
//   (2) Acuan periode (nama, tanggal) bisa DIUBAH, dan salinannya di setiap periode struktur pemakai ikut berubah dalam satu transaksi; ditolak bila
//       identik dengan acuan lain, bentrok nama per struktur, atau mendahului akhir aktual; dan terkunci untuk admin yang lingkupnya tak mencakup semua pemakai.
//
// Menulis baris uji sungguhan (struktur "UJI-ACUAN-<pid>-*", acuan "UJI-ACUAN-<pid>-*" bertanggal 2031-2040, periodenya) dan menghapusnya di akhir,
// juga lewat shutdown bila uji terhenti. Tak ada penempatan yang dibuat atau diubah.
//
//   php tests/term_period_edit_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
ob_start();

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'PeopleOverview', 'StructureLevels'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
require_once ROOT_DIR . '/app/Controllers/TermsController.php';
foreach (['TermModel', 'StructureModel'] as $m) require_once ROOT_DIR . "/app/Models/$m.php";

class Redirected extends Exception {}
class Refused extends Exception {}
class TTc extends TermsController {
    protected function requireLogin($roles = null) {}
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function denied($message = '') { throw new Refused('ditolak'); }
    protected function notFound($message = '') { throw new Refused('tidak ada'); }
    protected function view($v, $d = []) { if (strpos($v, 'layouts/') === 0) return; extract($d); require ROOT_DIR . "/app/Views/$v.php"; }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function has($h, $n) { return strpos($h, $n) !== false; }
function national() {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
}
function scoped($root) {
    $_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['admin_struktur']], 'scope' => ['all' => false, 'root_ids' => [$root],
                 'structure_ids' => (new StructureModel())->descendantIdsOfMany([$root]), 'is_national' => false, 'label' => 'Uji']];
}
function flashText() { return implode(' | ', array_map(function ($x) { return $x['message']; }, $_SESSION['_flash'] ?? [])); }
// Jalankan aksi controller: hasilnya 'redirect:<tujuan>', 'ditolak', atau teks HTML keluarannya.
function act($method, array $post = null, ...$args) {
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST'; $_POST = $post ?? []; unset($_SESSION['_flash']);
    ob_start();
    try { (new TTc)->$method(...$args); $r = ob_get_clean(); return $r; }
    catch (Redirected $e) { ob_end_clean(); return 'redirect:' . $e->getMessage(); }
    catch (Refused $e) { ob_end_clean(); return $e->getMessage(); }
}
function rejects(callable $fn, $needle) {
    try { $fn(); return false; } catch (RuntimeException $e) { return stripos($e->getMessage(), $needle) !== false ? true : $e->getMessage(); }
}

$pdo = Database::getInstance()->getConnection();
$one = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
$row = function ($sql) use ($pdo) { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); };
$dpp = $row("SELECT id, path, depth FROM structures WHERE level = 'DPP' AND deleted_at IS NULL LIMIT 1");
if (!$dpp || !$one("SELECT COUNT(*) FROM structure_levels WHERE code = 'DPLN'")) { echo "LEWAT: butuh DPP dan tingkat DPLN di database lokal.\n"; exit(0); }

$tag = 'UJI-ACUAN-' . getmypid();
$auditFrom = (int) $one("SELECT COALESCE(MAX(id), 0) FROM audit_log");
$cleanup = function () use ($pdo, $tag, $auditFrom) {
    $sids = $pdo->query("SELECT id FROM structures WHERE name LIKE '" . $tag . "%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($sids) $pdo->exec("DELETE FROM terms WHERE structure_id IN (" . implode(',', array_map('intval', $sids)) . ")");
    $pdo->exec("DELETE FROM governance_periods WHERE name LIKE '" . $tag . "%'");
    if ($sids) $pdo->exec("DELETE FROM structures WHERE id IN (" . implode(',', array_map('intval', $sids)) . ")");
    $pdo->exec("DELETE FROM audit_log WHERE id > " . (int) $auditFrom . " AND entity IN ('terms', 'governance_periods', 'structures')");
};
register_shutdown_function($cleanup);
$cleanup();

$mkStructure = function ($suffix) use ($pdo, $dpp, $tag) {
    $pdo->prepare("INSERT INTO structures (uuid, level, wing, tier, name, parent_id, is_active) VALUES (?, 'DPLN', 'umum', 2, ?, ?, 1)")->execute([Uuid::v4(), $tag . '-S' . $suffix, $dpp['id']]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE structures SET path = ?, depth = ? WHERE id = ?")->execute([$dpp['path'] . $id . '/', $dpp['depth'] + 1, $id]);
    return $id;
};
national();
$terms = new TermModel();
$mkPeriod = function ($suffix, $start, $end) use ($terms, $tag) { return $terms->createGovernancePeriod(['name' => $tag . '-' . $suffix, 'start_date' => $start, 'end_date_planned' => $end]); };
$mkTerm = function ($structureId, $periodId, $current = false, $extra = []) use ($terms) {
    return $terms->create($extra + ['structure_id' => $structureId, 'governance_period_id' => $periodId, 'end_date_actual' => '', 'status' => $current ? 'active' : 'draft',
        'is_current' => $current, 'sk_number' => '', 'sk_date' => '', 'notes' => '']);
};
$payload = function ($termId, array $over = []) use ($terms) {   // isian formulir Ubah Periode apa adanya
    $t = $terms->findById($termId);
    return $over + ['governance_period_id' => $t['governance_period_id'], 'end_date_actual' => $t['end_date_actual'] ?: '', 'status' => $t['status'], 'is_current' => (bool) $t['is_current'],
                    'sk_number' => (string) $t['sk_number'], 'sk_date' => $t['sk_date'] ?: '', 'notes' => (string) $t['notes']];
};

$sA = $mkStructure('A'); $sB = $mkStructure('B'); $sC = $mkStructure('C');
$g1 = $mkPeriod('1', '2031-01-01', '2035-12-31');   // acuan yang keliru
$g2 = $mkPeriod('2', '2036-01-01', '2040-12-31');   // acuan yang benar
$tA = $mkTerm($sA, $g1, true);
$tB = $mkTerm($sB, $g1);
$tC = $mkTerm($sC, $g1);
$n1 = $tag . '-1'; $n2 = $tag . '-2';

// ---------- 1. Mengganti acuan pada satu periode ----------
$before = $row("SELECT * FROM terms WHERE id = $tA");
$terms->update($tA, $payload($tA, ['governance_period_id' => $g2]));
$after = $row("SELECT * FROM terms WHERE id = $tA");
ok((int) $after['governance_period_id'] === $g2 && $after['name'] === $n2 && $after['start_date'] === '2036-01-01' && $after['end_date_planned'] === '2040-12-31', '1: acuan diganti: nama dan tanggal periode mengikuti acuan baru', json_encode($after));
ok((int) $after['is_current'] === 1 && $after['status'] === $before['status'] && (int) $after['structure_id'] === $sA, '1: tanda berjalan, status, dan struktur tak berubah');
ok($one("SELECT COUNT(*) FROM audit_log WHERE entity = 'terms' AND entity_id = $tA AND action = 'update' AND id > $auditFrom") >= 1, '1: perubahan tercatat di audit');
$terms->update($tA, ['end_date_actual' => '', 'status' => 'active', 'is_current' => true, 'sk_number' => 'SK-UJI', 'sk_date' => '', 'notes' => '']);   // pemanggil yang tak mengirim acuan
$a2 = $row("SELECT governance_period_id, sk_number FROM terms WHERE id = $tA");
ok((int) $a2['governance_period_id'] === $g2 && $a2['sk_number'] === 'SK-UJI', '1: pemanggil yang tak mengirim acuan tak mengubahnya (kompatibel)');
$r = rejects(function () use ($terms, $tB, $payload) { $terms->update($tB, $payload($tB, ['governance_period_id' => 999999999])); }, 'tidak ditemukan');
ok($r === true && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tB") === $g1, '1: acuan yang tak ada ditolak, periode tak berubah', is_string($r) ? $r : 'tidak ditolak');

// struktur B sudah punya periode pada acuan g2 -> tak boleh dobel
$tB2 = $mkTerm($sB, $g2);
$r = rejects(function () use ($terms, $tB, $payload, $g2) { $terms->update($tB, $payload($tB, ['governance_period_id' => $g2])); }, 'sudah memakai periode');
ok($r === true && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tB") === $g1, '1: struktur yang sudah punya periode pada acuan itu ditolak, tak ada yang berubah', is_string($r) ? $r : 'tidak ditolak');
// nama yang sama dari acuan lain (tanggal beda) juga bentrok
$g5 = $terms->createGovernancePeriod(['name' => $n2, 'start_date' => '2037-01-01', 'end_date_planned' => '2041-12-31']);
$r = rejects(function () use ($terms, $tB, $payload, $g5) { $terms->update($tB, $payload($tB, ['governance_period_id' => $g5])); }, 'sudah memakai periode');
ok($r === true, '1: nama periode yang sama (acuan lain bernama sama) ditolak', is_string($r) ? $r : 'tidak ditolak');
// periode yang sudah dihapus tetap menahan kuncinya: pesan terpisah yang jujur
$terms->softDelete($tB2);
$r = rejects(function () use ($terms, $tB, $payload, $g2) { $terms->update($tB, $payload($tB, ['governance_period_id' => $g2])); }, 'sudah dihapus');
ok($r === true, '1: periode yang sudah dihapus tetap menahan acuannya, dan pesannya menyebut itu', is_string($r) ? $r : 'tidak ditolak');

// ---------- 2. Lewat formulir dan controller ----------
national();
$h = act('form', null, $tC);
ok(has($h, '<select id="governance_period_id" name="governance_period_id" required data-original="' . $g1 . '">') && has($h, '>' . $n2 . ' · 2036-01-01 s.d. 2040-12-31<'), '2: formulir Ubah Periode: acuan bisa dipilih (tidak lagi terkunci), daftar memuat acuan lain');
ok(!has($h, 'dikunci setelah susunan struktur dibuat') && has($h, 'penempatan') && has($h, 'daftar acuan periode'), '2: teks bantuan menjelaskan akibatnya dan menunjuk ke daftar acuan');
ok(has($h, 'id="structure_id" name="structure_id" required disabled'), '2: struktur periode tetap terkunci');
$post = ['id' => (string) $tC, 'structure_id' => (string) $sC, 'governance_period_id' => (string) $g2, 'end_date_actual' => '', 'status' => 'draft', 'sk_number' => '', 'sk_date' => '', 'notes' => ''];
$res = act('save', array_merge($post, ['end_date_actual' => '2030-01-01']));
ok($res === 'redirect:terms/form/' . $tC && has(flashText(), 'tidak boleh lebih awal') && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tC") === $g1, '2: akhir aktual dibandingkan dengan tanggal mulai ACUAN BARU, bukan yang lama', $res . ' | ' . flashText());
$res = act('save', $post);
$t = $row("SELECT governance_period_id, name, start_date FROM terms WHERE id = $tC");
ok($res === 'redirect:terms/detail/' . $tC && (int) $t['governance_period_id'] === $g2 && $t['name'] === $n2 && $t['start_date'] === '2036-01-01', '2: simpan lewat controller menggantikan acuan lalu ke halaman susunan', $res . ' | ' . json_encode($t) . ' | ' . flashText());
// struktur C kini memakai g2; menggantinya kembali ke g1 (yang bebas) bekerja, ke acuan lain yang dipakai struktur itu ditolak
$res = act('save', array_merge($post, ['governance_period_id' => (string) $g1]));
ok($res === 'redirect:terms/detail/' . $tC && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tC") === $g1, '2: bisa dikembalikan ke acuan semula');
$noPeriod = $post; unset($noPeriod['governance_period_id']);   // permintaan yang tak membawa pilihan acuan (bukan dari formulir ini)
$res = act('save', array_merge($noPeriod, ['notes' => 'tanpa acuan']));
ok($res === 'redirect:terms/detail/' . $tC && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tC") === $g1 && $one("SELECT notes FROM terms WHERE id = $tC") === 'tanpa acuan', '2: periode yang sudah ada disimpan tanpa pilihan acuan: acuan dibiarkan, bukan ditolak', $res . ' | ' . flashText());
$tC2 = $mkTerm($sC, $g2);
$res = act('save', array_merge($post, ['governance_period_id' => (string) $g2]));
ok($res === 'redirect:terms/form/' . $tC && has(flashText(), 'sudah memakai periode') && (int) $one("SELECT governance_period_id FROM terms WHERE id = $tC") === $g1, '2: bentrok ditolak dengan pesan lalu kembali ke formulir', $res . ' | ' . flashText());
$terms->softDelete($tC2);

// ---------- 3. Mengubah acuan yang keliru ----------
// g1 dipakai B dan C (A sudah pindah ke g2).
$users = (int) $one("SELECT COUNT(*) FROM terms WHERE governance_period_id = $g1");
$tsBefore = $one("SELECT updated_at FROM terms WHERE id = $tB");
usleep(20000);
$changed = $terms->updateGovernancePeriod($g1, ['name' => $tag . '-1b', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']);
ok($changed === $users && $users === 2, '3: ubah acuan: jumlah periode yang ikut berubah dikembalikan', $changed . '/' . $users);
$gp = $row("SELECT name, start_date, end_date_planned FROM governance_periods WHERE id = $g1");
$tb = $row("SELECT name, start_date, end_date_planned, updated_at FROM terms WHERE id = $tB");
$tc = $row("SELECT name, start_date, end_date_planned FROM terms WHERE id = $tC");
ok($gp['name'] === $tag . '-1b' && $gp['start_date'] === '2032-02-02' && $gp['end_date_planned'] === '2036-02-01', '3: acuan tersimpan', json_encode($gp));
ok($tb['name'] === $tag . '-1b' && $tb['start_date'] === '2032-02-02' && $tb['end_date_planned'] === '2036-02-01' && $tc['name'] === $tag . '-1b' && $tc['start_date'] === '2032-02-02', '3: salinan di setiap periode struktur pemakai ikut berubah', json_encode([$tb, $tc]));
ok($tb['updated_at'] > $tsBefore, '3: updated_at periode bergerak, jadi API mengirim ulang');
ok((int) $one("SELECT COUNT(*) FROM terms t JOIN governance_periods gp ON gp.id = t.governance_period_id WHERE gp.name LIKE '" . $tag . "%' AND (t.name <> gp.name OR t.start_date <> gp.start_date OR NOT (t.end_date_planned <=> gp.end_date_planned))") === 0, '3: tak ada salinan yang tertinggal berbeda dari acuannya');
ok($one("SELECT COUNT(*) FROM audit_log WHERE entity = 'governance_periods' AND entity_id = $g1 AND action = 'update' AND id > $auditFrom") == 1, '3: tercatat di audit');
ok($terms->updateGovernancePeriod($g1, ['name' => $tag . '-1b', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']) === 0, '3: tanpa perubahan mengembalikan 0');
$terms->updateGovernancePeriod($g1, ['name' => $tag . '-1b', 'start_date' => '2032-02-02', 'end_date_planned' => '']);
ok($one("SELECT end_date_planned FROM governance_periods WHERE id = $g1") === null && $one("SELECT end_date_planned FROM terms WHERE id = $tB") === null, '3: akhir rencana boleh dikosongkan, salinannya ikut');
$terms->updateGovernancePeriod($g1, ['name' => $tag . '-1b', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']);

$upd = function (array $over) use ($terms, $g1, $tag) {
    return function () use ($terms, $g1, $tag, $over) { $terms->updateGovernancePeriod($g1, $over + ['name' => $tag . '-1b', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']); };
};
foreach ([['name' => '', 'k' => 'Nama periode wajib'], ['name' => str_repeat('x', 51), 'k' => 'Nama periode wajib'], ['start_date' => '', 'k' => 'Tanggal mulai'], ['start_date' => '2032-13-40', 'k' => 'Tanggal mulai'],
          ['end_date_planned' => 'bukan-tanggal', 'k' => 'tidak sah'], ['end_date_planned' => '2031-01-01', 'k' => 'lebih awal']] as $c) {
    $k = $c['k']; unset($c['k']);
    $r = rejects($upd($c), $k);
    ok($r === true, '3: isian tak sah ditolak: ' . json_encode($c), is_string($r) ? $r : 'tidak ditolak');
}
// identik dengan acuan lain
$r = rejects(function () use ($terms, $g1, $n2) { $terms->updateGovernancePeriod($g1, ['name' => $n2, 'start_date' => '2036-01-01', 'end_date_planned' => '2040-12-31']); }, 'Sudah ada acuan');
ok($r === true && $one("SELECT name FROM governance_periods WHERE id = $g1") === $tag . '-1b', '3: acuan yang akan identik dengan acuan lain ditolak (arahkan ke Ubah Periode)', is_string($r) ? $r : 'tidak ditolak');
// bentrok nama per struktur: B punya periode (terhapus) bernama n2 pada g2
$r = rejects(function () use ($terms, $g1, $n2) { $terms->updateGovernancePeriod($g1, ['name' => $n2, 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']); }, 'sudah punya periode lain bernama');
ok($r === true && $one("SELECT name FROM governance_periods WHERE id = $g1") === $tag . '-1b' && $one("SELECT name FROM terms WHERE id = $tB") === $tag . '-1b', '3: nama yang sudah dipakai periode lain di struktur pemakai ditolak, tak ada yang berubah', is_string($r) ? $r : 'tidak ditolak');
// akhir aktual mendahului tanggal mulai yang baru
$terms->update($tC, $payload($tC, ['end_date_actual' => '2033-06-01']));
$r = rejects(function () use ($terms, $g1, $tag) { $terms->updateGovernancePeriod($g1, ['name' => $tag . '-1b', 'start_date' => '2034-01-01', 'end_date_planned' => '2036-02-01']); }, 'akhir aktualnya lebih awal');
ok($r === true && $one("SELECT start_date FROM governance_periods WHERE id = $g1") === '2032-02-02', '3: tanggal mulai yang melewati akhir aktual sebuah periode ditolak', is_string($r) ? $r : 'tidak ditolak');
$r = rejects(function () use ($terms) { $terms->updateGovernancePeriod(999999999, ['name' => 'x', 'start_date' => '2032-01-01', 'end_date_planned' => '']); }, 'tidak ditemukan');
ok($r === true, '3: acuan yang tak ada ditolak', is_string($r) ? $r : 'tidak ditolak');

// ---------- 4. Siapa yang boleh ----------
ok($terms->governancePeriodEditable($g1) === true, '4: Super Admin: boleh mengubah acuan yang dipakai banyak struktur');
scoped($sB);   // lingkup hanya struktur B; g1 juga dipakai C
ok($terms->governancePeriodEditable($g1) === false, '4: admin yang lingkupnya hanya sebagian pemakai: tak boleh mengubah acuan bersama');
$r = rejects(function () use ($terms, $g1, $tag) { $terms->updateGovernancePeriod($g1, ['name' => $tag . '-1c', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']); }, 'di luar lingkup');
ok($r === true && $one("SELECT name FROM governance_periods WHERE id = $g1") === $tag . '-1b', '4: model menolaknya juga bila dipanggil langsung', is_string($r) ? $r : 'tidak ditolak');
$h = act('period_catalog');
ok(!has($h, 'terms/period_form/' . $g1 . '"'), '4: daftar acuan: tombol Ubah tak muncul untuk acuan itu');
ok(act('period_form', null, $g1) === 'ditolak', '4: membuka formulir ubah acuan itu ditolak');
$res = act('period_save', ['id' => (string) $g1, 'name' => $tag . '-1c', 'start_date' => '2032-02-02', 'end_date_planned' => '2036-02-01']);
ok($res === 'redirect:terms/period_form/' . $g1 && $one("SELECT name FROM governance_periods WHERE id = $g1") === $tag . '-1b', '4: menyimpannya lewat controller ditolak dengan pesan', $res . ' | ' . flashText());
$only = $mkPeriod('only', '2041-01-01', '2045-12-31');
$mkTerm($sB, $only);   // dipakai hanya struktur B: di lingkup
ok($terms->governancePeriodEditable($only) === true, '4: acuan yang seluruh pemakainya ada di lingkup admin: boleh');
ok(has(act('period_catalog'), 'terms/period_form/' . $only . '"'), '4: ... dan tombol Ubah muncul di daftar acuan');

// ---------- 5. Halaman dan alur controller (Super Admin) ----------
national();
$h = act('period_catalog');
ok(has($h, 'terms/period_form/' . $g1 . '"') && has($h, 'Ubah Periode'), '5: daftar acuan: tombol Ubah per acuan, catatan menunjuk ke Ubah Periode untuk satu struktur');
$h = act('period_form', null, $g1);
ok(has($h, 'name="id" value="' . $g1 . '"') && has($h, 'value="' . $tag . '-1b"') && has($h, 'value="2032-02-02"') && has($h, 'dipakai bersama') && has($h, 'Simpan Perubahan'), '5: formulir ubah acuan terisi dan menjelaskan akibatnya');
ok(has(act('period_form'), 'Buat Acuan Periode Kepengurusan') && !has(act('period_form'), 'name="id"'), '5: formulir buat tetap seperti semula');
$h = act('period_detail', null, $g1);
ok(has($h, 'terms/period_form/' . $g1 . '"') && has($h, 'Ubah acuan'), '5: rekap acuan: tombol Ubah acuan');
$res = act('period_save', ['id' => (string) $g1, 'name' => $tag . '-1c', 'start_date' => '2032-03-03', 'end_date_planned' => '2036-03-02']);
ok($res === 'redirect:terms/period_detail/' . $g1 && has(flashText(), '2 periode struktur') && $one("SELECT name FROM terms WHERE id = $tB") === $tag . '-1c', '5: simpan lewat controller: salinan ikut, pesan menyebut jumlahnya', $res . ' | ' . flashText());
$res = act('period_save', ['id' => (string) $g1, 'name' => $tag . '-1c', 'start_date' => '2032-03-03', 'end_date_planned' => '2030-01-01']);
ok($res === 'redirect:terms/period_form/' . $g1 && has(flashText(), 'lebih awal'), '5: isian salah kembali ke formulir ubah (bukan formulir buat)', $res . ' | ' . flashText());
$res = act('period_save', ['id' => '999999999', 'name' => 'x', 'start_date' => '2032-03-03', 'end_date_planned' => '']);
ok($res === 'tidak ada', '5: acuan yang tak ada: tidak ditemukan');
$res = act('period_save', ['name' => $tag . '-new', 'start_date' => '2046-01-01', 'end_date_planned' => '2050-12-31']);
ok($res === 'redirect:terms/period_catalog' && $one("SELECT COUNT(*) FROM governance_periods WHERE name = '" . $tag . "-new'") == 1, '5: membuat acuan baru tetap bekerja');

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
