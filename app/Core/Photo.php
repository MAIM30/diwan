<?php
// PATH LOKASI FILE: app/Core/Photo.php
// Pemrosesan foto profil, dipakai unggahan di halaman profil DAN impor biodata -- satu pipeline
// sanitasi, jadi foto dari mana pun diperlakukan sama.
//
// Berkas masukan TIDAK PERNAH disimpan apa adanya. Gambar didekode GD lalu ditulis ulang sebagai JPEG
// 512x512 persegi: itu membuang EXIF (lokasi GPS dsb.), metadata, dan muatan apa pun yang disembunyikan
// di dalam berkas, dan memastikan yang tersimpan benar-benar gambar. Jenis berkas ditentukan dari
// ISI (finfo + getimagesize), bukan dari nama atau Content-Type yang bisa dipalsukan.

class Photo {
    const MAX_PIXELS = 16000000;   // 16 MP: dekode GD ~4 byte/piksel, jaga memori
    const MIN_SIDE   = 64;
    const OUT_SIDE   = 512;

    // Mengembalikan null bila berhasil menulis $destAbs, atau satu kode galat:
    //   'type'   bukan JPG/PNG/WebP (atau isi tak cocok dengan jenisnya)
    //   'small'  sisi terpendek < MIN_SIDE
    //   'large'  terlalu banyak piksel
    //   'decode' GD gagal membaca
    //   'write'  tidak bisa menulis ke tujuan
    public static function process($srcPath, $destAbs) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($srcPath);
        $info = @getimagesize($srcPath);
        if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info['mime'] !== $mime) {
            return 'type';
        }
        [$w, $h] = $info;
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) return 'small';
        if ($w * $h > self::MAX_PIXELS) return 'large';

        $src = null;
        if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($srcPath);
        elseif ($mime === 'image/png') $src = @imagecreatefrompng($srcPath);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($srcPath);
        if (!$src) return 'decode';

        // Putar sesuai orientasi EXIF (foto ponsel), baru EXIF-nya dibuang lewat penulisan ulang.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($srcPath);
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rot) {
                $rotated = imagerotate($src, $rot, 0);
                if ($rotated) { imagedestroy($src); $src = $rotated; }
            }
        }

        // Potong persegi dari tengah, lalu perkecil.
        $sw = imagesx($src); $sh = imagesy($src);
        $side = min($sw, $sh);
        $dst = imagecreatetruecolor(self::OUT_SIDE, self::OUT_SIDE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // latar putih untuk PNG transparan
        imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), self::OUT_SIDE, self::OUT_SIDE, $side, $side);
        imagedestroy($src);

        $dir = dirname($destAbs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            imagedestroy($dst);
            error_log('Tidak bisa membuat folder unggahan: ' . $dir);
            return 'write';
        }
        $ok = imagejpeg($dst, $destAbs, 86);
        imagedestroy($dst);
        return $ok ? null : 'write';
    }
}
