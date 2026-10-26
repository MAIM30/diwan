<?php
// PATH LOKASI FILE: tests/biodata_matcher_test.php
// Uji BiodataMatcher dengan isian formulir sungguhan (ejaan salah, spasi hilang, jabatan ganda).
//   php tests/biodata_matcher_test.php        (keluar 1 bila ada yang gagal)

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/app/Core/Text.php';
require_once ROOT_DIR . '/app/Core/BiodataMatcher.php';

$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; return; }
    $fail++;
    echo "GAGAL  $label" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}
function js($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
$B = 'BiodataMatcher';

// ---- tanggal & tempat lahir ----
$t = fn($s) => $B::parseTtl($s, strtotime('2026-10-20'));
ok('format biasa',            $t('Lonrae, 27 Mei 2000') === ['place' => 'Lonrae', 'date' => '2000-05-27', 'doubt' => null], js($t('Lonrae, 27 Mei 2000')));
ok('spasi hilang "30Agustus"', $t('Kepi Mereauke 30Agustus 1976')['date'] === '1976-08-30' && $t('Kepi Mereauke 30Agustus 1976')['place'] === 'Kepi Mereauke', js($t('Kepi Mereauke 30Agustus 1976')));
ok('koma ganda "09, Agustus"', $t('Lakaja, 09, Agustus 2000')['date'] === '2000-08-09' && $t('Lakaja, 09, Agustus 2000')['place'] === 'Lakaja');
ok('bulan tak dikenal -> tanggal null, TIDAK ditebak', $t('Tarakan, 01 Bone 1988')['date'] === null && $t('Tarakan, 01 Bone 1988')['doubt'] === 'Tarakan, 01 Bone 1988' && $t('Tarakan, 01 Bone 1988')['place'] === 'Tarakan');
ok('"5 Menit 1968" -> null',  $t('Kediri, 5 Menit 1968')['date'] === null);
ok('tanggal mustahil 31 Februari -> null', $t('Bone, 31 Februari 1990')['date'] === null);
ok('tahun mustahil (2024) -> null', $t('Bone, 3 Mei 2024')['date'] === null);
ok('numerik hari-bulan-tahun', $t('Makassar, 12-05-1990')['date'] === '1990-05-12');
ok('numerik tahun-bulan-hari', $t('Makassar 1990/05/12')['date'] === '1990-05-12');
ok('singkatan bulan "Agu"/"Okt"', $t('Palu, 4 Okt 1985')['date'] === '1985-10-04' && $t('Palu, 4 Agu 1985')['date'] === '1985-08-04');
ok('tanpa tanggal sama sekali', $t('Bone')['date'] === null && $t('Bone')['place'] === null /* tak ada pola -> tak ada tempat yang dapat dipisahkan */ || $t('Bone')['doubt'] === 'Bone');
ok('kosong "--" -> semuanya null', $t('--') === ['place' => null, 'date' => null, 'doubt' => null]);

// ---- telepon ----
ok('08.. -> 628..',           $B::phone('082250212859') === '6282250212859');
ok('+62 dengan spasi/strip',  $B::phone('+62 812-3456-7890') === '6281234567890');
ok('dua nomor -> yang pertama sah', $B::phone('0812 3456 7890 / 0813 1111 2222') === '6281234567890');
ok('"--" -> null',            $B::phone('--') === null);
ok('terlalu pendek -> null',  $B::phone('0812') === null);

// ---- nama ----
$np = fn($s) => $B::nameParts($s);
ok('tanpa koma "SPd MPd" = dengan koma "S.Pd., M.Pd."', $np('Akhyar Amnur SPd MPd') === $np('Akhyar Amnur, S.Pd., M.Pd.'), js([$np('Akhyar Amnur SPd MPd'), $np('Akhyar Amnur, S.Pd., M.Pd.')]));
ok('urutan gelar tak berpengaruh', $np('Jumli Alam, S.Pd., M.Hum., M.Pd.') === $np('Jumli Alam, S.Pd., M.Pd., M.Hum.'));
ok('gelar berbeda = orang berbeda (Ismail S.Pd vs S.H.)', $np('Ismail, S.Pd.')['base'] === $np('Ismail, S.H.')['base'] && $np('Ismail, S.Pd.')['gelar'] !== $np('Ismail, S.H.')['gelar']);
ok('gelar depan dibuang dari nama asli', $np('Dr. H. Ahmad Fauzi, Lc., M.A.')['base'] === ['ahmad', 'fauzi'], js($np('Dr. H. Ahmad Fauzi, Lc., M.A.')));
ok('"Gr." (guru profesional) di depan nama dibuang', $np('Gr. Bustang, S.Pd., M.Pd')['base'] === ['bustang']);
ok('gelar menempel titik "spt.msi" = "S.Pt., M.Si."', $np('ansyar tambara spt.msi') === $np('Ansyar Tambara, S.Pt., M.Si.'), js([$np('ansyar tambara spt.msi'), $np('Ansyar Tambara, S.Pt., M.Si.')]));
ok('"Drs.Edy" -> nama asli "edy"', $np('Drs.Edy')['base'] === ['edy'] && $np('Abd.Rahman')['base'] === ['abd', 'rahman']);
ok('singkatan satu huruf tidak dipecah ("A.M. Nur")', $np('A.M. Nur Atma')['base'] === ['am', 'nur', 'atma'], js($np('A.M. Nur Atma')));
ok('M./Muh./Muhd di token pertama -> muhammad', $np('M. Rusli')['base'][0] === 'muhammad' && $np('Muh. Rusli')['base'][0] === 'muhammad' && $np('Muhammad Rusli')['base'][0] === 'muhammad');
ok('"M" di tengah nama TIDAK diubah (bisa inisial)', $np('Ahmad M Rusli')['base'] === ['ahmad', 'm', 'rusli']);
ok('Muhammad Adhan tetap dua token', $np('Muhammad Adhan')['base'] === ['muhammad', 'adhan']);
ok('kemiripan ejaan "Abdi" ~ "Abdy" tinggi', $B::similarity(['abdi', 'humam'], ['abdy', 'humam']) >= 85);
ok('kemiripan nama berbeda rendah', $B::similarity(['ahmad', 'fauzi'], ['budi', 'santoso']) < 50);
ok('looksLikeName: alamat/uji coba ditolak', !$B::looksLikeName('BTN ADAL MULA') && !$B::looksLikeName('testing') && !$B::looksLikeName('Jl. Mawar 12') && $B::looksLikeName('Ahmad Fauzi, S.Pd.'));

// ---- jabatan ----
$role = fn($j, $b = '') => $B::parseRole($j, $b);
$pairs = fn($r) => $r['pairs'];
$r = $role('Anggota LPYP', 'LPYP');
ok('"Anggota LPYP"', $pairs($r) === [['Anggota', 'Lembaga Pembinaan Yayasan Pendidikan']], js($pairs($r)));
$r = $role('Anggota Departemen Dakwah dan Pembinaan Masjid');
ok('"dan" di dalam nama unit tidak memecah klausa', $pairs($r) === [['Anggota', 'Departemen Dakwah dan Pembinaan Masjid']], js($pairs($r)));
$r = $role('Sekretaris Biro Aset dan Anggota Deptan', 'Biro Keuangan dan Aset, Departemen Pertanian');
ok('"dan" sebelum kata jabatan memecah: dua pasangan', $pairs($r) === [['Sekretaris', 'Biro Keuangan dan Aset'], ['Anggota', 'Departemen Pertanian']], js($pairs($r)));
$r = $role('Wakil Ketua DUWD');
ok('"Wakil Ketua" bukan "Ketua"', $pairs($r) === [['Wakil Ketua', 'Departemen Urusan Wilayah dan Daerah']] && $r['positions'] === ['Wakil Ketua'], js($r));
$r = $role('Wakil Sekretaris Jenderal');
ok('jabatan pucuk tanpa unit', $pairs($r) === [['Wakil Sekretaris Jenderal', null]], js($pairs($r)));
$r = $role('Ketua LP2KS dan Ketua Yayasan');
ok('dua jabatan Ketua pada dua unit', $pairs($r) === [['Ketua', 'Lembaga Pernikahan dan Pembinaan Keluarga Sakinah'], ['Ketua', 'Lembaga Pembinaan Yayasan Pendidikan']], js($pairs($r)));
$r = $role('Anggota', 'Departemen Pendidikan');
ok('jabatan tanpa unit memakai unit tunggal dari isian Bidang? ("Departemen Pendidikan" ambigu -> tak dipaksa)', $pairs($r) === [['Anggota', null]], js($pairs($r)));
$r = $role('Anggota', 'LP2KS');
ok('jabatan tanpa unit + Bidang berunit tunggal -> dipasangkan', $pairs($r) === [['Anggota', 'Lembaga Pernikahan dan Pembinaan Keluarga Sakinah']], js($pairs($r)));
$long = "Ketua DPD WI Kab sragen, Ketua Departemen DUD DPW WI Jawa Tengah, Anggota Departemen Dakwah, Kaderisasi, & PSDM DPW WI Jawa Tengah, Anggota Departemen Khidmatul Qur'an DPW WI Jawa Tengah, wakil sekretaris LP2Ortom DPP WI & Anggota Departemen DUWD DPP WI";
$r = $role($long);
ok('badan daerah (DPD/DPW) dilewati; hanya DPP yang dipetakan', $pairs($r) === [['Wakil Sekretaris', 'Lembaga Pembinaan dan Pengembangan Organisasi Otonom'], ['Anggota', 'Departemen Urusan Wilayah dan Daerah']] && count($r['regional']) === 4, js($r));
$r = $role('Guru TPA');
ok('pekerjaan bukan jabatan -> tak ada pasangan', $pairs($r) === [] && $r['units'] === [], js($r));
$r = $role('Wahdah islamiyah');
ok('"Wahdah islamiyah" tak dianggap unit apa pun', $pairs($r) === [], js($r));
ok('Bidang disebut sendiri dikenali sebagai kelompok', $role('Anggota', 'Bidang Ketahanan Keluarga')['groups'] === ['Bidang Ketahanan Keluarga']);
ok('semua nama unit di UNIT_PATTERNS unik', count(array_keys($B::UNIT_PATTERNS)) === count(array_unique(array_keys($B::UNIT_PATTERNS))));

echo "\nLULUS: $pass    GAGAL: $fail\n";
exit($fail ? 1 : 0);
