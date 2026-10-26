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
    const MAX_BYTES  = 3145728;    // 3 MB

    // Dari entri $_FILES: periksa unggahannya, lalu process(). Kode galat process() ditambah:
    //   'none'    tidak ada berkas dipilih
    //   'size'    lebih dari MAX_BYTES (atau melampaui batas PHP)
    //   'upload'  unggahan gagal / bukan hasil unggahan HTTP
    public static function fromUpload($f, $destAbs) {
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return 'none';
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > self::MAX_BYTES) return 'size';
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'upload';
        return self::process($f['tmp_name'], $destAbs);
    }

    public static function message($code) {
        return [
            'none'   => 'Pilih berkas foto terlebih dahulu.',
            'size'   => 'Ukuran foto terlalu besar. Maksimal 3 MB.',
            'upload' => 'Unggahan gagal. Silakan coba lagi.',
            'type'   => 'Berkas bukan gambar yang didukung. Gunakan JPG, PNG, atau WebP.',
            'small'  => 'Gambar terlalu kecil. Minimal 64 x 64 piksel.',
            'large'  => 'Dimensi gambar terlalu besar. Kecilkan hingga sekitar 4000 x 4000 piksel.',
            'decode' => 'Gambar tidak bisa dibaca. Coba berkas lain.',
            'write'  => 'Foto tidak bisa disimpan di server.',
        ][$code] ?? 'Unggahan gagal. Silakan coba lagi.';
    }

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
