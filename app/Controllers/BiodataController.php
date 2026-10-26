<?php
// PATH LOKASI FILE: app/Controllers/BiodataController.php
// Sisi ADMIN dari Biodata Pengurus (lihat PengurusController untuk sisi pengisian oleh pengurus
// sendiri). Hanya daftar + unduh ulang + kirim ulang ke Drive -- isiannya sendiri tidak pernah diubah
// admin, itu milik pengurus yang bersangkutan.

class BiodataController extends Controller {
    public function index() {
        $this->requireLogin();
        $rows = $this->model('BiodataModel')->listInScope();

        $this->view('admin/biodata/index', [
            'title' => 'Biodata Pengurus',
            'rows'  => $rows,
        ]);
    }

    // PDF dibangkitkan ulang di tempat, sama seperti PengurusController::biodata_pdf() -- tidak pernah
    // ada berkas tersimpan untuk dibaca.
    public function pdf($uuid) {
        $this->requireLogin();
        $biodata = $this->model('BiodataModel');
        $row = $biodata->findByUuid($uuid);
        if (!$row) $this->notFound('Biodata tidak ditemukan.');

        $people = $this->model('PersonModel');
        $person = $people->findById((int) $row["person_id"]);
        if (!$person) $this->notFound('Biodata tidak ditemukan.');
        $this->requireScope($this->structureOfPerson($person['id']));

        $placement = $this->currentPlacementOf($person['id']);
        $pdfBytes = BiodataPdf::render($person, $placement, $row);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Biodata_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $person['name']) . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
        exit;
    }

    public function resend() {
        $this->requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('biodata');

        $biodata = $this->model('BiodataModel');
        $row = $biodata->findByUuid($_POST['uuid'] ?? '');
        if (!$row) $this->notFound('Biodata tidak ditemukan.');

        $people = $this->model('PersonModel');
        $person = $people->findById((int) $row["person_id"]);
        if (!$person) $this->notFound('Biodata tidak ditemukan.');
        $this->requireScope($this->structureOfPerson($person['id']));

        if (!GoogleDrive::isConfigured()) {
            Flash::set('error', 'Google Drive belum dikonfigurasi.');
            $this->redirect('biodata');
        }

        $placement = $this->currentPlacementOf($person['id']);
        $pdfBytes = BiodataPdf::render($person, $placement, $row);
        $filename = 'Biodata_' . $person['name'] . '_' . $row['uuid'] . '.pdf';
        $up = GoogleDrive::upload($pdfBytes, $filename);

        if ($up['ok']) {
            $biodata->markDriveUpload($row['id'], $up['file_id'], $up['url']);
            Flash::set('success', 'Berhasil dikirim ulang ke Google Drive.');
        } else {
            Flash::set('error', 'Gagal: ' . $up['error']);
        }
        $this->redirect('biodata');
    }

    // Struktur penempatan aktif (untuk requireScope) -- cukup satu, lingkup dicek terhadap salah
    // satu strukturnya; kalau tidak menjabat di mana pun, dianggap di luar lingkup siapa pun.
    private function structureOfPerson($personId) {
        $p = $this->currentPlacementOf($personId);
        return $p['structure_id'] ?? 0;
    }

    private function currentPlacementOf($personId) {
        $history = $this->model('PlacementModel')->historyOfPerson($personId);
        foreach ($history as $h) {
            if (!empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= date('Y-m-d'))) return $h;
        }
        return null;
    }
}
