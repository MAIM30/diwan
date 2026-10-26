<?php
// PATH LOKASI FILE: app/Controllers/LokasiController.php
// Daftar wilayah (JSON) untuk isian domisili bertingkat di formulir admin dan Portal Pengurus. Hanya-baca, hanya GET.
// Datanya publik, tetapi endpoint ini bukan proksi terbuka: wajib sesi admin (bukan yang masih berkata sandi sementara) atau sesi pengurus.
// Seluruh logika pengambilan, validasi kode, dan salinan ada di Lokasi.

class LokasiController extends Controller {

    public function provinsi() { $this->reply('provinsi', ''); }
    public function kabupaten($code = '') { $this->reply('kabupaten', $code); }
    public function kecamatan($code = '') { $this->reply('kecamatan', $code); }
    public function kelurahan($code = '') { $this->reply('kelurahan', $code); }

    private function reply($level, $code) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        if (!((Auth::check() && !Auth::mustChangePassword()) || Member::check())) {
            http_response_code(401);
            header('Cache-Control: no-store');
            echo json_encode(['error' => 'Silakan masuk terlebih dahulu.']);
            return;
        }

        $r = Lokasi::get($level, (string) $code);
        if ($r['status'] !== 200) {
            http_response_code($r['status']);
            header('Cache-Control: no-store');
            echo json_encode(['error' => [
                400 => 'Kode wilayah tidak valid.',
                404 => 'Kode wilayah tidak ditemukan.',
                503 => 'Data wilayah belum bisa dimuat. Isi manual untuk sementara.',
            ][$r['status']] ?? 'Gagal.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Daftar jarang berubah; peramban boleh menyimpannya sehari. "private": sesi-nya bukan milik cache bersama.
        header('Cache-Control: private, max-age=86400');
        echo json_encode(['data' => $r['data'], 'stale' => $r['stale']], JSON_UNESCAPED_UNICODE);
    }
}
