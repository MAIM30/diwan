<?php
// PATH LOKASI FILE: app/Controllers/PositionCorrectionsController.php
// Kotak masuk admin untuk laporan koreksi jabatan/penempatan yang dikirim pengurus.

class PositionCorrectionsController extends Controller {
    public function index() {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();

        $filter = $_GET['status'] ?? 'submitted';
        if (!in_array($filter, ['submitted', 'resolved', 'rejected', 'all'], true)) $filter = 'submitted';
        $model = $this->model('PositionCorrectionModel');
        $this->view('admin/position_corrections/index', [
            'title' => 'Laporan Koreksi',
            'rows' => $model->listInScope($filter === 'all' ? null : $filter),
            'status' => $filter,
        ]);
    }

    public function decide($id) {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('positionCorrections');

        $model = $this->model('PositionCorrectionModel');
        $before = $model->findInScope($id);
        if (!$before) $this->notFound('Laporan koreksi tidak ditemukan.');

        $status = $_POST['decision'] ?? '';
        if (!in_array($status, ['resolved', 'rejected'], true)) {
            Flash::set('error', 'Pilih tindakan yang tersedia.');
            $this->redirect('positionCorrections?status=submitted');
        }
        $note = $_POST['admin_note'] ?? '';
        if (!is_string($note)) $note = '';
        try {
            $changed = $model->decide($id, $status, $note, Auth::id());
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('positionCorrections?status=submitted');
        }
        if (!$changed) {
            Flash::set('info', 'Laporan ini sudah diproses admin lain.');
            $this->redirect('positionCorrections');
        }

        Audit::log('position_correction_requests', (int) $id, 'update',
            ['status' => $before['status']], ['status' => $status, 'reviewed_by' => Auth::id()]);
        Flash::set('success', $status === 'resolved'
            ? 'Laporan ditandai sudah ditangani.'
            : 'Laporan ditandai tidak ditindaklanjuti.');
        $this->redirect('positionCorrections?status=submitted');
    }
}
