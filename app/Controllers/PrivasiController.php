<?php
// PATH LOKASI FILE: app/Controllers/PrivasiController.php
// Kebijakan privasi (alamat "/privasi"), publik tanpa login. Google mewajibkan URL ini untuk
// layar persetujuan OAuth saat DIWAN meminta izin Google Drive (lihat /settings/gdrive).

class PrivasiController extends Controller {
    public function index() {
        $this->view('privasi', ['title' => 'Kebijakan Privasi']);
    }
}
