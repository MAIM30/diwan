<?php
// PATH LOKASI FILE: tests/scan_placeholders.php
// Penjaga regresi: placeholder SQL bernama yang muncul lebih dari sekali di dalam SATU string.
//
//   php tests/scan_placeholders.php          (keluar 0 = bersih, 1 = ada yang ganda)
//
// Mengapa ada: Database.php memakai ATTR_EMULATE_PREPARES=false, dan dalam mode itu MySQL menolak satu
// placeholder bernama yang dipakai dua kali (SQLSTATE HY093). Kesalahannya baru muncul di jalur yang
// jarang dilewati -- pencarian dengan kata kunci dan paginasi kursor API -- sehingga lolos dari uji
// asap yang hanya membuka halaman tanpa parameter. Pindai ini dijalankan sebelum deploy.
//
// Memakai tokenizer PHP, bukan grep: SQL ber-interpolasi ("{$alias}.col > :x OR ... = :x") terpecah
// menjadi beberapa token, dan potongan klausa tanpa kata SELECT pun tetap harus terperiksa.

$root = $argv[1] ?? dirname(__DIR__) . '/app';
$found = 0;
$files = 0;

function scanString($s, $file, $line, &$found) {
    if (!preg_match('/\b(SELECT|UPDATE|INSERT|DELETE|WHERE|AND|OR|LIKE|SET|VALUES|JOIN|ORDER|LIMIT)\b/', $s)) return;
    if (!preg_match_all('/(?<![:\w]):([a-z_][a-z0-9_]*)/i', $s, $m)) return;
    foreach (array_count_values($m[1]) as $name => $n) {
        if ($n > 1) {
            $found++;
            printf("GANDA  :%s x%d  %s:%d\n", $name, $n, $file, $line);
        }
    }
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $files++;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(dirname($root)) + 1));
    $buf = null;
    $startLine = 0;
    foreach (token_get_all(file_get_contents($f->getPathname())) as $t) {
        if ($t === '"') {
            if ($buf === null) { $buf = ''; $startLine = 0; }
            else { scanString($buf, $rel, $startLine, $found); $buf = null; }
            continue;
        }
        if ($buf !== null) {
            if (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) { $buf .= $t[1]; if (!$startLine) $startLine = $t[2]; }
            else $buf .= '#';
            continue;
        }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) scanString($t[1], $rel, $t[2], $found);
    }
}
echo ($found ? "\n" : '') . "$files berkas dipindai, $found placeholder ganda\n";
exit($found ? 1 : 0);
