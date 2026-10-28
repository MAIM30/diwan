<?php
// PATH LOKASI FILE: app/Controllers/ImportsController.php
// Panel Super Admin untuk meninjau catatan impor yang membutuhkan keputusan manusia.

class ImportsController extends Controller {
    public function index() {
        $this->requireLogin('super_admin');
        $resolution = $_GET['status'] ?? 'pending';
        $allowed = ['pending','confirmed','split','corrected','ignored','all'];
        if (!in_array($resolution, $allowed, true)) $resolution = 'pending';

        $model = $this->model('ImportReviewModel');
        $this->view('admin/imports/index', [
            'title' => 'Tinjauan Impor',
            'rows' => $model->listItems($resolution),
            'counts' => $model->counts(),
            'status' => $resolution,
        ]);
    }

    public function resolve() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('imports');

        try {
            $ok = $this->model('ImportReviewModel')->updateResolution(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['resolution'] ?? ''),
                (string) ($_POST['resolution_note'] ?? '')
            );
            if (!$ok) $this->notFound('Butir tinjauan tidak ditemukan.');
            Flash::set('success', 'Keputusan tinjauan disimpan. Data hasil impor tidak berubah otomatis.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $backStatus = is_string($_POST['back_status'] ?? null) ? $_POST['back_status'] : 'pending';
        if (!in_array($backStatus, ['pending','confirmed','split','corrected','ignored','all'], true)) $backStatus = 'pending';
        $this->redirect('imports?status=' . $backStatus);
    }
}
