<?php
// PATH LOKASI FILE: app/Core/SkFile.php
// Berkas SK (Surat Keputusan) yang menjadi dasar sebuah periode. Disimpan DI LUAR folder publik
// (storage/sk/<uuid>.pdf) dan hanya dilayani lewat TermsController::sk(), yang memeriksa login + lingkup
// -- jadi nama berkas yang bocor atau tertebak tidak membuka apa pun. storage/ juga diblokir di .htaccess.
//
// PDF tidak bisa "disucihamakan" seperti gambar (foto ditulis ulang oleh GD). Yang dilakukan di sini:
// jenis ditentukan dari ISI berkas (finfo + tanda %PDF-), bukan dari nama/Content-Type kiriman; nama
// tersimpan diturunkan dari UUID, bukan nama unggahan; dan saat dilayani diberi `Content-Security-Policy:
// sandbox` + `nosniff` supaya skrip di dalam PDF tak berjalan dalam konteks aplikasi. Hanya admin yang boleh
// mengubah data (canEdit) yang bisa mengunggah.

class SkFile {
    const MAX_BYTES = 20971520;   // 20 MB; SK hasil pindaian 18 halaman di data nyata ±8 MB

    public static function dir() {
        return ROOT_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sk';
    }

    // Jalur relatif yang SAH ('sk/<uuid>.pdf') -> jalur mutlak; selain itu null. Nilai di basis data pun
    // diperiksa bentuknya, jadi baris yang ternoda tak bisa dipakai membaca berkas lain (path traversal).
    public static function path($rel) {
        if (!is_string($rel) || !preg_match('#^sk/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#', $rel)) return null;
        return ROOT_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    // Menyimpan berkas. $uploaded = true untuk berkas unggahan HTTP (move_uploaded_file); false untuk salinan
    // dari CLI. Mengembalikan ['path' => 'sk/<uuid>.pdf'] atau ['error' => 'type'|'size'|'write'].
    public static function store($tmp, $uploaded = true) {
        $size = @filesize($tmp);
        if (!$size || $size > self::MAX_BYTES) return ['error' => 'size'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $head = (string) @file_get_contents($tmp, false, null, 0, 5);
        if ($mime !== 'application/pdf' || $head !== '%PDF-') return ['error' => 'type'];

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { error_log('SK: tidak bisa membuat ' . $dir); return ['error' => 'write']; }
        // Jaring pengaman kedua bila docroot ternyata mencakup storage/ dan .htaccess induk tak berlaku.
        $ht = dirname($dir) . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "# Berkas SK tidak pernah dilayani langsung; lihat TermsController::sk()\nRequire all denied\n");

        $name = Uuid::v4() . '.pdf';
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        $ok = $uploaded ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        return $ok ? ['path' => 'sk/' . $name] : ['error' => 'write'];
    }

    public static function remove($rel) {
        $abs = self::path($rel);
        if ($abs && is_file($abs) && !@unlink($abs)) {
            // Berkas yatim lebih baik terlihat di log daripada diam-diam menumpuk.
            error_log('SK: gagal menghapus ' . $abs . ' (' . (error_get_last()['message'] ?? 'alasan tak diketahui') . ')');
        }
    }
}
