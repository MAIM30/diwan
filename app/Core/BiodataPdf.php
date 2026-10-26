<?php
// PATH LOKASI FILE: app/Core/BiodataPdf.php
// Membangkitkan PDF "Biodata / Profil Singkat Pengurus", meniru tata letak formulir fisik yang
// sebelumnya diisi manual (Word/Canva) lalu diunggah ke Drive satu per satu.
//
// SENGAJA tidak pernah ditulis ke disk server: render() mengembalikan isi PDF sebagai string biner
// (FPDF Output('S')) langsung di memori. Dipanggil ulang setiap kali dibutuhkan -- saat kirim ke
// Google Drive, dan saat pengurus/admin mengunduhnya -- dari data people+placements+biodata_submissions
// yang sudah tersimpan, bukan dari berkas PDF yang disimpan.

require_once __DIR__ . '/Vendor/Fpdf/fpdf.php';

class BiodataPdf {
    // Teks 8 pernyataan komitmen, URUTAN TETAP 1-8 -- persis salinan dari formulir aslinya. Index
    // array (1-based) inilah yang disimpan di biodata_submissions.commitments.
    const COMMITMENTS = [
        1 => "Saya siap menerima amanah kepengurusan sebagai bentuk tanggung jawab kepada Allah, jalan perjuangan seorang muslim, dengan menjaga keikhlasan, integritas, dan keteladanan dalam menjalankan tugas.",
        2 => "Saya siap menjadikan nilai-nilai Islam dan prinsip organisasi sebagai pedoman dalam bersikap, mengambil keputusan, dan menjalankan amanah kepengurusan.",
        3 => "Saya siap membangun hubungan yang baik, bekerja sama, menjaga ukhuwah, serta menghargai pengurus, anggota, dan bersinergi dengan pihak lain dalam menjalankan tugas organisasi.",
        4 => "Saya siap menerima nasihat, kritik, evaluasi, dan perbedaan pendapat dengan sikap terbuka serta mengutamakan kepentingan organisasi di atas kepentingan pribadi atau kelompok.",
        5 => "Saya siap menggunakan anugerah nikmat berupa bakat, kompetensi, pengalaman, dan pemikiran yang saya miliki secara optimal untuk memberikan kontribusi bagi pencapaian tujuan organisasi dan kejayaan islam.",
        6 => "Saya siap terus belajar, bertumbuh, meningkatkan kompetensi, dan menyelesaikan persoalan secara bertanggung jawab sesuai dengan amanah yang diberikan.",
        7 => "Saya siap menyediakan waktu, tenaga, perhatian, dan kehadiran yang diperlukan untuk menjalankan amanah kepengurusan.",
        8 => "Saya siap menjalankan tugas secara disiplin, konsisten, dan tuntas serta mempertanggungjawabkan proses dan hasilnya.",
    ];

    const PERNYATAAN_AKHIR = "Dengan memilih pernyataan di atas, saya menyatakan bahwa saya memahami kepengurusan sebagai amanah yang membutuhkan kesiapan diri untuk berkontribusi secara utuh. Saya bersedia memberikan kontribusi terbaik sesuai kemampuan dan amanah yang diberikan.";

    const GREEN = [20, 92, 55];
    const TEXT  = [23, 50, 59];
    const MUTED = [85, 116, 128];
    const LINE  = [219, 232, 236];

    const PHOTO_MAX_W = 30;   // mm; kotak foto 3x4 di pojok kanan atas
    const PHOTO_MAX_H = 40;

    // $photoBytes: JPEG foto yang baru diunggah, supaya tak perlu diunduh lagi dari Drive. Null = pakai foto
    // biodata yang tersimpan di Drive, dan bila tak ada, foto profil di server.
    public static function render(array $person, ?array $placement, array $biodata, ?string $photoBytes = null) {
        $photoBytes = $photoBytes ?? self::drivePhoto($biodata);
        $photoFile = null;
        if ($photoBytes !== null) {
            // FPDF hanya membaca gambar dari berkas; ini sementara dan dibuang begitu PDF selesai.
            $photoFile = tempnam(sys_get_temp_dir(), 'bio');
            file_put_contents($photoFile, $photoBytes);
        }

        try {
            $pdf = new FPDF();
            $pdf->AliasNbPages();
            $pdf->SetMargins(18, 16, 18);
            $pdf->SetAutoPageBreak(true, 18);
            $pdf->AddPage();

            self::header($pdf, $person, $photoFile);
            self::sectionA($pdf, $person);
            self::sectionB($pdf, $placement, $biodata);
            self::commitments($pdf, $biodata);
            self::closing($pdf, $person, $biodata);

            return $pdf->Output('S');
        } finally {
            if ($photoFile !== null) @unlink($photoFile);
        }
    }

    private static function drivePhoto(array $biodata) {
        $id = $biodata['photo_gdrive_file_id'] ?? '';
        if ($id === '' || !GoogleDrive::isConfigured()) return null;
        return GoogleDrive::download($id);
    }

    private static function txt($s) {
        // Core font FPDF (Helvetica) cuma Latin-1 -- teks Bahasa Indonesia standar aman dikonversi.
        $s = (string) $s;
        return function_exists('iconv') ? (iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s) : $s;
    }

    // Foto dimuat UTUH ke kotak PHOTO_MAX_W x PHOTO_MAX_H (mm) dengan proporsi aslinya -- tidak dipotong, tidak
    // melar. Mengembalikan [lebar, tinggi] dalam mm, atau null bila berkasnya tak terbaca sebagai gambar.
    private static function photoSize($file) {
        $info = @getimagesize($file);
        if (!$info || $info[0] < 1 || $info[1] < 1) return null;
        $scale = min(self::PHOTO_MAX_W / $info[0], self::PHOTO_MAX_H / $info[1]);
        return [$info[0] * $scale, $info[1] * $scale];
    }

    private static function header($pdf, array $person, $photoFile) {
        $logo = ROOT_DIR . '/public/assets/img/logo-wahdah-png.png';
        $pageW = $pdf->GetPageWidth();
        $top = $pdf->GetY();

        if (is_file($logo)) {
            $pdf->Image($logo, $pageW / 2 - 9, $top, 18, 18);
            $pdf->SetY($top + 20);
        }

        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor(...self::TEXT);
        $pdf->Cell(0, 7, self::txt('BIODATA / PROFIL SINGKAT PENGURUS'), 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell(0, 6, self::txt('WAHDAH ISLAMIYAH'), 0, 1, 'C');
        $bottom = $pdf->GetY();

        // Foto di pojok kanan atas, rata dengan tepi atas logo dan tepi kanan garis. Foto biodata dari Drive
        // didahulukan; bila tak ada, foto profil di server.
        $file = $photoFile;
        if ($file === null && !empty($person['photo_path']) && is_file(ROOT_DIR . '/public/' . $person['photo_path'])) {
            $file = ROOT_DIR . '/public/' . $person['photo_path'];
        }
        $size = $file !== null ? self::photoSize($file) : null;
        if ($size) {
            $x = $pageW - 18 - $size[0];
            $pdf->Image($file, $x, $top, $size[0], $size[1], 'JPG');
            $pdf->SetDrawColor(...self::LINE);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $top, $size[0], $size[1]);
            $bottom = max($bottom, $top + $size[1]);
        }

        $pdf->SetY($bottom);
        $pdf->SetDrawColor(...self::GREEN);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(18, $bottom + 3, $pageW - 18, $bottom + 3);
        $pdf->Ln(9);
    }

    private static function sectionHeading($pdf, $text) {
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::TEXT);
        $pdf->Cell(0, 7, self::txt($text), 0, 1);
        $pdf->SetDrawColor(...self::LINE);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetPageWidth() - 18, $pdf->GetY());
        $pdf->Ln(3);
    }

    // Baris "label : nilai" -- label lebar tetap, nilai melebar & bisa lipat baris (MultiCell).
    private static function row($pdf, $no, $label, $value) {
        $labelW = 62;

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(...self::TEXT);
        $pdf->Cell(8, 6, $no . '.', 0, 0);
        $pdf->Cell($labelW - 8, 6, self::txt($label), 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(4, 6, ':', 0, 0);

        $pdf->MultiCell(0, 6, self::txt($value !== '' && $value !== null ? $value : '-'), 0, 'L');
        $yAfter = $pdf->GetY();

        $pdf->SetDrawColor(...self::LINE);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(18, $yAfter + 1, $pdf->GetPageWidth() - 18, $yAfter + 1);
        $pdf->SetY($yAfter + 3);
    }

    private static function sectionA($pdf, array $person) {
        self::sectionHeading($pdf, 'A. Identitas Diri');

        $nama = trim(($person['title_prefix'] ?? '') . ' ' . $person['name'] . ' ' . ($person['title_suffix'] ?? ''));
        $ttl = trim(($person['birth_place'] ?? '') . ($person['birth_date'] ? ', ' . Ui::date($person['birth_date']) : ''));

        self::row($pdf, 1, 'Nama Lengkap dan Gelar', $nama);
        self::row($pdf, 2, 'Tempat, Tanggal Lahir', $ttl !== ',' ? $ttl : '');
        self::row($pdf, 3, 'Domisili', $person['city'] ?? '');
        self::row($pdf, 4, 'Nomor HP/WhatsApp', $person['phone'] ?? '');
        self::row($pdf, 5, 'Pekerjaan/Profesi', $person['occupation'] ?? '');
        self::row($pdf, 6, 'No. HP Keluarga', $person['family_phone'] ?? '');
        $pdf->Ln(4);
    }

    private static function sectionB($pdf, ?array $placement, array $biodata) {
        self::sectionHeading($pdf, 'B. Profil Keorganisasian');

        $jabatan = $biodata['jabatan_text'] ?? ($placement['position_name'] ?? '');
        $bidangDefault = $placement ? ($placement['unit_name'] ?? trim(($placement['level'] ?? '') . ' ' . ($placement['structure_name'] ?? ''))) : '';
        $bidang = $biodata['bidang_text'] ?? $bidangDefault;

        self::row($pdf, 7, 'Jabatan/Amanah', $jabatan);
        self::row($pdf, 8, 'Bidang/Departemen/Lembaga', $bidang);
        self::row($pdf, 9, 'Pengalaman Organisasi', $biodata['pengalaman_organisasi'] ?? '');
        self::row($pdf, 10, 'Kompetensi/Keahlian', $biodata['kompetensi'] ?? '');
        self::row($pdf, 11, 'Karya/Kontribusi', $biodata['karya_kontribusi'] ?? '');
        $pdf->Ln(4);
    }

    // Jumlah baris yang akan dipakai MultiCell untuk $text pada lebar $width, supaya satu pernyataan tidak
    // terpotong di pergantian halaman (kotaknya di halaman lama, teksnya di halaman baru). Font harus
    // sudah dipilih. 2 mm = dua kali cMargin bawaan FPDF.
    private static function lineCount($pdf, $text, $width) {
        $max = $width - 2.0;
        $space = $pdf->GetStringWidth(' ');
        $lines = 1;
        $cur = 0;
        foreach (explode(' ', self::txt($text)) as $word) {
            $w = $pdf->GetStringWidth($word);
            if ($cur > 0 && $cur + $space + $w > $max) { $lines++; $cur = $w; }
            else $cur += ($cur > 0 ? $space : 0) + $w;
        }
        return $lines;
    }

    // Satu baris per pernyataan: kotak di kiri, teks di kolom tersendiri di kanannya. Baris lanjutan teks
    // menjorok sejajar dengan baris pertamanya, tidak kembali ke bawah kotak.
    private static function commitments($pdf, array $biodata) {
        $checked = is_array($biodata['commitments'] ?? null) ? $biodata['commitments'] : [];
        $left = 18; $box = 4; $gap = 3.5; $lineH = 5;
        $textX = $left + $box + $gap;
        $textW = $pdf->GetPageWidth() - 18 - $textX;
        $bottom = $pdf->GetPageHeight() - 18;

        // Delapan pernyataan dijaga satu halaman: kalau sisa halaman tak cukup untuk semuanya, mulai di halaman baru.
        $pdf->SetFont('Helvetica', '', 9.5);
        $blockH = 15;
        foreach (self::COMMITMENTS as $text) $blockH += self::lineCount($pdf, $text, $textW) * $lineH + 2.5;
        if ($pdf->GetY() + $blockH > $bottom) $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::GREEN);
        $pdf->Cell(0, 7, self::txt('Komitmen Kesiapan Menjadi Pengurus'), 0, 1);

        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell(0, 6, self::txt('Kotak yang bertanda centang adalah pernyataan yang dipilih.'), 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...self::TEXT);
        foreach (self::COMMITMENTS as $n => $text) {
            $isChecked = in_array($n, $checked, false) || in_array((string) $n, $checked, true);
            $y = $pdf->GetY();

            $pdf->SetDrawColor(...self::TEXT);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect($left, $y + 0.5, $box, $box);
            if ($isChecked) {
                $pdf->SetLineWidth(0.5);
                $pdf->Line($left + 0.8, $y + 2.7, $left + 1.7, $y + 3.8);
                $pdf->Line($left + 1.7, $y + 3.8, $left + 3.3, $y + 1.3);
            }

            $pdf->SetLeftMargin($textX);
            $pdf->SetX($textX);
            $pdf->MultiCell(0, $lineH, self::txt($text), 0, 'L');
            $pdf->SetLeftMargin($left);
            $pdf->Ln(2.5);
        }
        $pdf->Ln(2);
    }

    private static function closing($pdf, array $person, array $biodata) {
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->SetTextColor(...self::GREEN);
        $pdf->Cell(0, 6, self::txt('Pernyataan Akhir'), 0, 1);

        $pdf->SetFont('Helvetica', 'I', 9.5);
        $pdf->SetTextColor(...self::TEXT);
        $pdf->MultiCell(0, 5, self::txt(self::PERNYATAAN_AKHIR), 0, 'L');
        $pdf->Ln(6);

        $pdf->SetFont('Helvetica', '', 10);
        $tempat = $biodata['signed_place'] ?? '';
        $tanggal = Ui::date(!empty($biodata['signed_date']) ? $biodata['signed_date'] : time());
        $pdf->Cell(0, 6, self::txt('Tempat, Tanggal: ' . trim($tempat . ', ' . $tanggal, ', ')), 0, 1);
        $pdf->Ln(4);

        $nama = trim(($person['title_prefix'] ?? '') . ' ' . $person['name'] . ' ' . ($person['title_suffix'] ?? ''));
        $pdf->Cell(0, 6, self::txt('Nama: ' . $nama), 0, 1);
        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', 'I', 8.5);
        $pdf->SetTextColor(...self::MUTED);
        $waktu = !empty($biodata['updated_at'])
            ? Ui::date($biodata['updated_at']) . ' pukul ' . date('H:i', strtotime($biodata['updated_at'])) . ' WITA'
            : '-';
        $pdf->MultiCell(0, 5, self::txt('Dikirim secara elektronik melalui Portal Pengurus DIWAN oleh pemilik akun pada ' . $waktu . '.'));
    }
}
