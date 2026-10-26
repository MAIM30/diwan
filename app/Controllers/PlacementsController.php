<?php
// PATH LOKASI FILE: app/Controllers/PlacementsController.php
// Penempatan jabatan. Perhatikan bahwa tidak ada aksi "pindahkan ke periode baru": memulai periode
// baru selalu berarti MENAMBAH baris di periode itu, bukan mengubah baris periode sebelumnya --
// itulah yang menjaga riwayat tetap utuh.

class PlacementsController extends Controller {
    public function form($termId, $id = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $placements = $this->model('PlacementModel');
        $row = null;
        if ($id) {
            $row = $placements->findById($id);
            if (!$row || (int) $row['term_id'] !== (int) $termId) {
                $this->notFound('Penempatan tidak ditemukan di periode ini.');
            }
        }

        $units = $this->model('UnitModel');
        $positions = $this->model('PositionModel');
        $people = $this->model('PersonModel');

        $this->view('admin/placements/form', [
            'title'     => $id ? 'Ubah Penempatan' : 'Tambah Penempatan',
            'term'      => $term,
            'row'       => $row,
            'units'     => $units->unitsByTerm($termId),
            'positions' => $positions->all(),
            'people'    => $people->optionsInScope(),
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $placements = $this->model('PlacementModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'person_id'         => (int) ($_POST['person_id'] ?? 0),
            'term_id'           => $termId,
            // structure_id diambil dari PERIODE, bukan dari input form. Composite FK di database
            // akan menolak kalau keduanya tidak cocok, tapi mengambilnya dari sumber yang benar
            // sejak awal berarti pengguna tidak pernah bisa salah memilihnya.
            'structure_id'      => (int) $term['structure_id'],
            'structure_unit_id' => !empty($_POST['structure_unit_id']) ? (int) $_POST['structure_unit_id'] : null,
            'position_id'       => (int) ($_POST['position_id'] ?? 0),
            'is_primary'        => !empty($_POST['is_primary']),
            'start_date'        => $_POST['start_date'] ?? '',
            'end_date'          => $_POST['end_date'] ?? '',
            'end_reason'        => $_POST['end_reason'] ?? '',
            'source'            => 'manual',
            'sk_reference'      => trim($_POST['sk_reference'] ?? ''),
            'notes'             => trim($_POST['notes'] ?? ''),
        ];

        if ($data['person_id'] === 0 || $data['position_id'] === 0) {
            Flash::set('error', 'Orang dan jabatan wajib dipilih.');
            $this->redirect('placements/form/' . $termId . ($id ? '/' . $id : ''));
        }

        if ($data['end_date'] !== '' && $data['start_date'] !== '' && $data['end_date'] < $data['start_date']) {
            Flash::set('error', 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.');
            $this->redirect('placements/form/' . $termId . ($id ? '/' . $id : ''));
        }

        try {
            if ($id) {
                $placements->update($id, $data);
                Flash::set('success', 'Penempatan diperbarui.');
            } else {
                $newId = $placements->create($data);
                WaNotify::placementCreated((int) $data['person_id'], $newId);
                Flash::set('success', 'Penempatan ditambahkan.');
            }
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }

        $this->redirect('terms/detail/' . $termId);
    }

    // Mengakhiri penempatan tanpa menghapusnya: yang bersangkutan memang pernah menjabat.
    public function end() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $placements = $this->model('PlacementModel');
        $row = $placements->findById((int) ($_POST['id'] ?? 0));
        if (!$row) $this->notFound('Penempatan tidak ditemukan.');
        $this->requireScope($row['structure_id']);

        $reason = in_array($_POST['end_reason'] ?? '', ['selesai_periode','mengundurkan_diri','mutasi','wafat','diberhentikan','lainnya'], true)
                  ? $_POST['end_reason'] : 'lainnya';
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');

        $placements->end($row['id'], $endDate, $reason);
        Flash::set('success', 'Penempatan diakhiri per ' . $endDate . '. Riwayatnya tetap tersimpan.');
        $this->redirect('terms/detail/' . $row['term_id']);
    }

    // Hanya untuk kekeliruan entri. Pengurus yang selesai menjabat diakhiri lewat end(), bukan ini.
    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $placements = $this->model('PlacementModel');
        $row = $placements->findById((int) ($_POST['id'] ?? 0));
        if (!$row) $this->notFound('Penempatan tidak ditemukan.');
        $this->requireScope($row['structure_id']);

        $placements->softDelete($row['id']);
        Flash::set('success', 'Penempatan dihapus.');
        $this->redirect('terms/detail/' . $row['term_id']);
    }
}
