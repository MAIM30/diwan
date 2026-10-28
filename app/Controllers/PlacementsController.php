<?php
// PATH LOKASI FILE: app/Controllers/PlacementsController.php
// Penempatan jabatan terkait ke periode struktur. Perpindahan struktur hanya dibolehkan di dalam
// acuan periode bersama yang sama; perubahan antar acuan tetap menjadi penempatan periode berbeda.

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
        $otherPlacements = [];
        if ($id) {
            $row = $placements->findById($id);
            if (!$row || (int) $row['term_id'] !== (int) $termId) {
                $this->notFound('Penempatan tidak ditemukan di periode ini.');
            }
            $otherPlacements = array_values(array_filter($placements->byGovernancePeriod($term['governance_period_id'], $row['person_id']), function ($placement) use ($row) {
                return (int) $placement['person_id'] === (int) $row['person_id']
                    && (int) $placement['id'] !== (int) $row['id'];
            }));
        }

        $units = $this->model('UnitModel');
        $positions = $this->model('PositionModel');
        $people = $this->model('PersonModel');
        $peopleOptions = $people->optionsInScope();
        $selectedPersonId = !$id ? (int) ($_GET['person_id'] ?? 0) : 0;
        $selectedPerson = null;
        if ($selectedPersonId > 0) {
            foreach ($peopleOptions as $personOption) {
                if ((int) $personOption['id'] === $selectedPersonId) {
                    $selectedPerson = $personOption;
                    break;
                }
            }
            // Jangan menerima person_id dari URL jika orang tersebut di luar lingkup akses.
            if (!$selectedPerson) $selectedPersonId = 0;
        }

        $this->view('admin/placements/form', [
            'title'     => $id ? 'Ubah Penempatan' : 'Tambah Penempatan',
            'term'      => $term,
            'row'       => $row,
            'targetTerms' => $terms->termsForGovernancePeriod($term['governance_period_id']),
            'otherPlacements' => $otherPlacements,
            'units'     => $units->unitsByTerm($row['term_id'] ?? $termId),
            'positions' => $positions->all(),
            'people'    => $peopleOptions,
            'selectedPersonId' => $selectedPersonId,
            'selectedPerson' => $selectedPerson,
        ]);
    }

    // Memuat SKUO dan konteks jabatan ketika struktur tujuan diganti pada formulir.
    public function options($baseTermId, $targetTermId) {
        $this->requireLogin();
        header('Content-Type: application/json; charset=utf-8');
        $terms = $this->model('TermModel');
        $base = $terms->findById($baseTermId);
        $target = $terms->findById($targetTermId);
        if (!$base || !$target || (int) $base['governance_period_id'] !== (int) $target['governance_period_id']) {
            http_response_code(404);
            echo json_encode(['error' => 'Struktur tidak memakai acuan periode yang sama.']);
            return;
        }
        $this->requireScope($base['structure_id']);
        $this->requireScope($target['structure_id']);
        echo json_encode([
            'term' => ['id' => (int) $target['id'], 'level' => $target['level'], 'tier' => (int) $target['tier'], 'structure_name' => $target['structure_name']],
            'units' => $this->model('UnitModel')->unitsByTerm($target['id']),
            'positions' => $this->model('PositionModel')->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $baseTermId = (int) ($_POST['base_term_id'] ?? 0);
        $termId = (int) ($_POST['target_term_id'] ?? 0);
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $returnToPersonDetail = !empty($_POST['return_to_person_detail']);
        $returnPersonId = (int) ($_POST['person_id'] ?? 0);
        $returnTo = function () use ($returnToPersonDetail, $returnPersonId, $baseTermId, &$id) {
            if ($returnToPersonDetail && $returnPersonId > 0) {
                $this->redirect('people/detail/' . $returnPersonId);
            }
            $this->redirect('placements/form/' . $baseTermId . (!empty($id) ? '/' . (int) $id : ''));
        };
        $terms = $this->model('TermModel');
        $baseTerm = $terms->findById($baseTermId);
        $term = $terms->findById($termId);
        if (!$baseTerm || !$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($baseTerm['structure_id']);
        $this->requireScope($term['structure_id']);
        if ((int) $baseTerm['governance_period_id'] !== (int) $term['governance_period_id']) {
            Flash::set('error', 'Struktur tujuan harus menggunakan acuan Periode Kepengurusan yang sama.');
            $this->redirect($returnToPersonDetail && $returnPersonId > 0 ? 'people/detail/' . $returnPersonId : 'placements/form/' . $baseTermId . (!empty($_POST['id']) ? '/' . (int) $_POST['id'] : ''));
        }

        $placements = $this->model('PlacementModel');

        // Saat mengubah, ID penempatan harus benar-benar berasal dari periode yang dikirim.
        // Jangan izinkan POST mengganti struktur/periode penempatan lain hanya dengan mengubah hidden field.
        if ($id) {
            $existing = $placements->findById($id);
            if (!$existing || (int) $existing['term_id'] !== $baseTermId) {
                $this->notFound('Penempatan tidak ditemukan di periode ini.');
            }
            $this->requireScope($existing['structure_id']);
        }

        $data = [
            'person_id'         => (int) ($_POST['person_id'] ?? 0),
            'term_id'           => $termId,
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
            $returnTo();
        }

        $person = $this->model('PersonModel')->findById($data['person_id']);
        if (!$person || !$this->model('PersonModel')->isVisible($data['person_id'])) {
            $this->notFound('Data orang tidak ditemukan.');
        }
        $expectedWing = $person['gender'] === 'P' ? 'muslimah' : 'umum';
        if ($term['wing'] !== $expectedWing || (!empty($_POST['gender']) && $_POST['gender'] !== $person['gender'])) {
            Flash::set('error', 'Jenis kelamin pengurus tidak sesuai dengan sayap struktur yang dipilih.');
            $returnTo();
        }

        if ($data['end_date'] !== '' && $data['start_date'] !== '' && $data['end_date'] < $data['start_date']) {
            Flash::set('error', 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.');
            $returnTo();
        }

        // SKUO harus berasal dari periode dan struktur tujuan.
        if ($data['structure_unit_id']) {
            $unit = $this->model('UnitModel')->unitFindById($data['structure_unit_id']);
            if (!$unit || (int) $unit['term_id'] !== $termId || (int) $unit['structure_id'] !== (int) $term['structure_id']) {
                Flash::set('error', 'Unit Organisasi (SKUO) tidak termasuk dalam struktur dan periode ini.');
                $returnTo();
            }
        }

        // Jabatan tingkat struktur dan jabatan di dalam SKUO dibedakan oleh konteks katalog.
        // Validasi di server tetap diperlukan meskipun pilihan formulir juga difilter di browser.
        $context = $data['structure_unit_id'] ? 'unit' : 'structure';
        $allowedPositionIds = array_map('intval', array_column($this->model('PositionModel')->forContext($context), 'id'));
        if (!in_array($data['position_id'], $allowedPositionIds, true)) {
            Flash::set('error', 'Jabatan tidak sesuai dengan konteks penempatan pada struktur/SKUO yang dipilih.');
            $returnTo();
        }

        try {
            if ($id) {
                if ($termId !== $baseTermId) $placements->updateWithinGovernancePeriod($id, $data);
                else $placements->update($id, $data);
                Flash::set('success', $termId !== $baseTermId ? 'Penempatan dipindahkan ke struktur tujuan dalam acuan periode yang sama.' : 'Penempatan diperbarui.');
            } else {
                $newId = $placements->create($data);
                WaNotify::placementCreated((int) $data['person_id'], $newId);
                Flash::set('success', 'Penempatan ditambahkan.');
            }
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $returnTo();
        }

        if ($returnToPersonDetail && $returnPersonId > 0) $this->redirect('people/detail/' . $returnPersonId);
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
        Flash::set('success', 'Penempatan berhasil dianulir. Data orang tetap tersimpan; bila tidak memiliki penempatan aktif dalam SKUO lain, orang ini muncul pada filter Tanpa Unit Organisasi (SKUO).');
        $this->redirect('people?filter=tanpa_unit');
    }
}
