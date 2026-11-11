<?php
// PATH LOKASI FILE: tests/placement_form_test.php
// Uji form penempatan: orang selalu tampil, "Anulir" tidak lagi bersarang di form simpan, pencarian orang, dan pesan simpan yang jujur
// (tanpa perubahan bukan "berhasil"; berhasil menyebut tempat tampil yang baru). Menjalankan PlacementsController SUNGGUHAN terhadap
// database lokal di dalam SATU transaksi yang selalu di-rollback. Syarat: penempatan 5904 (Anggota tanpa unit di DPP, bukan inti) ada.
//
//   php tests/placement_form_test.php

define('ROOT_DIR', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });

require ROOT_DIR . '/config/config.php';
foreach (['Database', 'Controller', 'Audit', 'Text', 'Uuid', 'Csrf', 'Auth', 'Ui', 'Flash', 'AdminScope', 'Susunan', 'PeopleOverview'] as $c) require_once ROOT_DIR . "/app/Core/$c.php";
foreach (['WaNotify'] as $c) { $f = ROOT_DIR . "/app/Core/$c.php"; if (is_file($f)) require_once $f; }
require_once ROOT_DIR . '/app/Controllers/PlacementsController.php';
require_once ROOT_DIR . '/app/Models/PlacementModel.php';

$_SESSION = ['auth' => ['account_id' => 1, 'roles' => ['super_admin']], 'scope' => ['all' => true, 'root_ids' => [], 'structure_ids' => [], 'is_national' => true, 'label' => 'Nasional']];
$_SERVER['REQUEST_METHOD'] = 'GET';

class Redirected extends RuntimeException {}
class TPF extends PlacementsController {
    public $html = '';
    protected function redirect($path = '') { throw new Redirected($path); }
    protected function requireLogin($roles = null) {}
    protected function view($v, $d = []) {
        if (strpos($v, 'layouts/') === 0) return;
        extract($d); ob_start(); require ROOT_DIR . "/app/Views/$v.php"; $this->html .= ob_get_clean();
    }
}

$pass = 0; $fail = 0;
function ok($cond, $label, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++; echo "GAGAL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function flashText(array $f) { return implode(' | ', array_map(function ($x) { return $x['type'] . ': ' . $x['message']; }, $f)); }
function run($method, array $args = [], array $post = [], array $get = []) {
    $text = function ($a) { return array_map(function ($v) { return is_array($v) ? $v : (string) $v; }, $a); };
    $_POST = $text($post); $_GET = $text($get); $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    unset($_SESSION['_flash']);
    $c = new TPF; $redir = null; $out = '';
    ob_start();
    try { $c->$method(...$args); } catch (Redirected $r) { $redir = $r->getMessage(); }
    $out = ob_get_clean();
    return [$redir, $_SESSION['_flash'] ?? [], $c, $out];
}

$pdo = Database::getInstance()->getConnection();
$row = $pdo->query("SELECT pl.*, p.full_name FROM placements pl JOIN people p ON p.id = pl.person_id WHERE pl.id = 5904 AND pl.deleted_at IS NULL")->fetch();
if (!$row || $row['structure_unit_id'] !== null) { echo "LEWAT: penempatan 5904 (Anggota tanpa unit) tidak ada di database lokal.\n"; exit(0); }
$pdo->beginTransaction();
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });

$termId = (int) $row['term_id']; $personId = (int) $row['person_id'];
$pm = new PlacementModel();
$unit = $pdo->query("SELECT su.id FROM structure_units su JOIN unit_catalog c ON c.id = su.unit_catalog_id WHERE su.term_id = $termId AND su.deleted_at IS NULL AND c.name = 'Badan Anggaran'")->fetchColumn();
$core = (int) $pdo->query("SELECT id FROM positions WHERE code = 'wakil_sekretaris_jenderal'")->fetchColumn();
$timPakar = (int) $pdo->query("SELECT id FROM positions WHERE name = 'Tim Pakar'")->fetchColumn();
$base = ['id' => '5904', 'base_term_id' => $termId, 'target_term_id' => $termId, 'person_id' => $personId, 'gender' => 'L',
         'structure_unit_id' => '', 'position_id' => (string) $row['position_id'], 'is_primary' => '', 'start_date' => '', 'end_date' => '',
         'end_reason' => '', 'sk_reference' => '', 'notes' => ''];

// ---------- Tempat tampil ----------
ok($pm->landingLabel(null, $row['position_id']) === 'Pimpinan Lainnya', 'tanpa unit + jabatan non-inti = Pimpinan Lainnya');
ok($pm->landingLabel(null, $core) === 'Pengurus Inti', 'tanpa unit + jabatan inti = Pengurus Inti');
ok($unit && strpos($pm->landingLabel($unit, $row['position_id']), 'Badan-Badan › Badan Anggaran') === 0, 'di dalam unit = kelompok › unit', (string) ($unit ? $pm->landingLabel($unit, $row['position_id']) : 'unit tak ada'));

// ---------- Tampilan form ----------
[, , $c] = run('form', [$termId, 5904]);
$h = $c->html;
ok(strpos($h, 'Masriadi') !== false, 'form ubah: nama orang tampil (dulu kolom Orang kosong untuk orang di luar 2.000 nama pertama)');
ok(strpos($h, '<select id="person_id"') === false, 'form ubah: tak ada lagi daftar pilihan 2.000 nama');
$mainEnd = strpos($h, '</form>'); $side = strpos($h, 'class="pf-side"');
ok($mainEnd !== false && $side !== false && $mainEnd < $side && substr_count(substr($h, 0, $mainEnd), '<form') === 1, 'penempatan lain ada DI LUAR form simpan (form tak bersarang)');
ok(strpos($h, 'Sekarang tampil di: <b>Pimpinan Lainnya</b>') !== false, 'form ubah: menyebut tempat tampil sekarang');
ok(strpos($h, '<option value="">Pengurus Inti</option>') !== false && strpos($h, 'Tanpa unit (langsung di tingkat struktur)') === false && strpos($h, 'PENGURUS INTI (tingkat struktur') === false, 'pilihan tingkat struktur (tanpa unit) bernama "Pengurus Inti"');
ok(substr_count(file_get_contents(ROOT_DIR . '/app/Views/admin/placements/form.php'), '<option value="">Pengurus Inti</option>') === 2, 'nama pilihan sama di tampilan awal dan di JS yang memuat ulang pilihan struktur');
ok(strpos($h, 'name="gender"') !== false && strpos($h, 'id="structure_level"') === false, 'isian Jenis Kelamin / Tingkat Struktur dari pengguna dihapus (jenis kelamin ikut orangnya)');
ok(preg_match('/data-core="1"/', $h) === 1 && preg_match('/data-core="0"/', $h) === 1, 'jabatan membawa penanda inti (untuk pratinjau)');

[, , $c] = run('form', [$termId], [], ['person_id' => $personId]);
ok(strpos($c->html, 'Masriadi') !== false && strpos($c->html, 'value="' . $personId . '"') !== false, 'form tambah dengan ?person_id=: orang tampil walau di luar 2.000 nama pertama');
[, , $c] = run('form', [$termId]);
ok(strpos($c->html, 'id="pf-q"') !== false && strpos($c->html, 'name="person_id" id="pf-person-id" value="0"') !== false, 'form tambah tanpa orang: kolom pencarian tampil');

// ---------- Pencarian orang ----------
[, , , $json] = run('orang', [], [], ['q' => 'masriadi']);
$list = json_decode($json, true);
ok(is_array($list) && count($list) >= 1 && count($list) <= 15, 'orang?q=masriadi: ada hasil, paling banyak 15', $json);
$hit = $list ? array_values(array_filter($list, function ($x) use ($personId) { return $x['id'] === $personId; })) : [];
ok($hit && isset($hit[0]['name'], $hit[0]['gender'], $hit[0]['sub'], $hit[0]['initial'], $hit[0]['hue']), 'hasil membawa id, nama, jenis kelamin, jabatan terkini, inisial, warna');
[, , , $json] = run('orang', [], [], ['q' => 'm']);
ok($json === '[]', 'orang: kurang dari 2 huruf = kosong');
[, , , $json] = run('orang', [], [], ['q' => ['x']]);
ok($json === '[]', 'orang: parameter larik tak menjatuhkan');

// ---------- Simpan ----------
$before = $pdo->query("SELECT * FROM placements WHERE id = 5904")->fetch();
[$redir, $fl] = run('save', [], $base);
$t = flashText($fl);
ok(strpos($t, 'warning') === 0 && strpos($t, 'Tidak ada yang diubah') !== false, 'simpan tanpa perubahan: peringatan, bukan "berhasil"', $t);
ok(strpos($t, 'Pimpinan Lainnya') !== false && strpos($t, 'Pengurus Inti bila jabatannya inti') !== false, 'peringatan menyebut tempat tampil dan aturannya', $t);
ok($redir === 'placements/form/' . $termId . '/5904', 'tanpa perubahan: kembali ke form yang sama', (string) $redir);
$after = $pdo->query("SELECT * FROM placements WHERE id = 5904")->fetch();
ok($before == $after, 'tanpa perubahan: baris tak disentuh');
ok($pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'placements' AND entity_id = 5904 AND action = 'update' AND created_at >= NOW() - INTERVAL 1 MINUTE")->fetchColumn() == 0, 'tanpa perubahan: tak ada catatan audit');

[$redir, $fl] = run('save', [], ['structure_unit_id' => (string) $unit] + $base);
$t = flashText($fl);
ok(strpos($t, 'success') === 0 && strpos($t, 'Dipindah dari Pimpinan Lainnya ke Badan-Badan › Badan Anggaran') !== false, 'pindah ke unit: pesan menyebut asal dan tujuan', $t);
ok($redir === 'terms/detail/' . $termId, 'berhasil: ke susunan periode', (string) $redir);
ok((int) $pdo->query("SELECT structure_unit_id FROM placements WHERE id = 5904")->fetchColumn() === (int) $unit, 'pindah ke unit: tersimpan');

[, $fl] = run('save', [], ['structure_unit_id' => '', 'position_id' => (string) $core] + $base);
ok(strpos(flashText($fl), 'Dipindah dari') !== false || strpos(flashText($fl), 'Penempatan diperbarui') !== false, 'ke jabatan inti: tersimpan');
// Mulai lagi dari keadaan awal untuk dua uji terakhir (di dalam transaksi uji).
$pdo->exec("UPDATE placements SET structure_unit_id = NULL, position_id = " . (int) $row['position_id'] . " WHERE id = 5904");
[, $fl] = run('save', [], ['position_id' => (string) $core] + $base);
ok(strpos(flashText($fl), 'Dipindah dari Pimpinan Lainnya ke Pengurus Inti') !== false, 'jabatan inti tanpa unit: dari Pimpinan Lainnya ke Pengurus Inti', flashText($fl));
$pdo->exec("UPDATE placements SET position_id = " . (int) $row['position_id'] . " WHERE id = 5904");
[, $fl] = run('save', [], ['position_id' => (string) $timPakar] + $base);
ok(strpos(flashText($fl), 'Tampil di: Pimpinan Lainnya') !== false && strpos(flashText($fl), 'Dipindah dari') === false, 'sesama non-inti: tetap Pimpinan Lainnya, tak mengaku dipindah', flashText($fl));

// ---------- "Penempatan lain": membawa penanda inti ----------
$tid = $pdo->query("SELECT governance_period_id FROM terms WHERE id = $termId")->fetchColumn();
$others = $pm->byGovernancePeriod($tid, $personId);
ok($others && array_key_exists('is_core', $others[0]), 'byGovernancePeriod membawa is_core');

$pdo->rollBack();
echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
