<?php
// PATH LOKASI FILE: app/Controllers/PositionsController.php
// Master Data > Jabatan: katalog jabatan struktural dan unit yang dipakai penempatan.

class PositionsController extends Controller {

    public function index() {
        $this->requireLogin();
        $pos = $this->model('PositionModel');

        $appliesTo = in_array($_GET['at'] ?? '', PositionModel::APPLIES_TO, true) ? $_GET['at'] : '';
        $keyword   = trim($_GET['q'] ?? '');

        $this->view('admin/positions/index', [
            'title'    => 'Jabatan',
            'rows'     => $pos->adminList($appliesTo, $keyword),
            'counts'   => $pos->countsByAppliesTo(),
            'appliesTo' => $appliesTo,
            'keyword'  => $keyword,
        ]);
    }

    private function backToList() {
        $at = in_array($_POST['at_filter'] ?? '', PositionModel::APPLIES_TO, true) ? $_POST['at_filter'] : '';
        $q  = trim($_POST['q_filter'] ?? '');
        $p  = array_filter(['at' => $at, 'q' => $q], function ($v) { return $v !== ''; });
        $this->redirect('positions' . ($p ? '?' . http_build_query($p) : ''));
    }

    public function save() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('positions');

        $pos = $this->model('PositionModel');
        $id  = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $code = trim($_POST['code'] ?? '');
        if ($code === '' && !$id) {
            $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($_POST['name'] ?? '')));
            $code = trim($code, '_');
        }

        $data = [
            'name'           => trim($_POST['name'] ?? ''),
            'code'           => $code,
            'applies_to'     => in_array($_POST['applies_to'] ?? '', PositionModel::APPLIES_TO, true)
                                    ? $_POST['applies_to'] : 'both',
            'is_core'        => !empty($_POST['is_core']),
            'core_from_tier' => ($_POST['core_from_tier'] ?? '') !== '' ? (int) $_POST['core_from_tier'] : null,
            'sort_order'     => max(0, min(65535, (int) ($_POST['sort_order'] ?? 500))),
            'is_active'      => !empty($_POST['is_active']),
        ];

        if ($data['name'] === '') {
            Flash::set('error', 'Nama jabatan wajib diisi.');
            $this->backToList();
        }
        if ($data['code'] === '') {
            Flash::set('error', 'Kode jabatan wajib diisi.');
            $this->backToList();
        }

        try {
            $pos->save($id, $data);
            Flash::set('success', 'Jabatan disimpan.');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_positions_code') !== false) {
                Flash::set('error', 'Kode "' . e($data['code']) . '" sudah dipakai jabatan lain.');
            } else {
                error_log('Simpan jabatan gagal: ' . $e->getMessage());
                Flash::set('error', 'Jabatan tidak bisa disimpan.');
            }
        }
        $this->backToList();
    }

    public function delete() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('positions');

        $pos = $this->model('PositionModel');
        try {
            $pos->delete((int) ($_POST['id'] ?? 0));
            Flash::set('success', 'Jabatan dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->backToList();
    }
}
