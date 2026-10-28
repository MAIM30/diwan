<?php
// PATH LOKASI FILE: app/Controllers/BiodataController.php
// Sisi ADMIN dari Biodata Pengurus (lihat PengurusController untuk sisi pengisian oleh pengurus
// sendiri). Hanya daftar + unduh ulang + kirim ulang ke Drive -- isiannya sendiri tidak pernah diubah
// admin, itu milik pengurus yang bersangkutan.

class BiodataController extends Controller {
    public function index() {
        $this->requireLogin();
        $model = $this->model('BiodataModel');
        $tab = ($_GET['tab'] ?? 'rekap') === 'status' ? 'status' : 'rekap';
        $queryParam = $_GET['q'] ?? '';
        $rosterQuery = is_string($queryParam) ? trim($queryParam) : '';
        $rows = $tab === 'rekap' ? $model->listInScope() : [];
        $structures = $this->model('StructureModel')->allInScope();
        $terms = $this->model('TermModel')->allInScope();
        $currentOf = [];
        foreach ($terms as $term) {
            if (!empty($term['is_current'])) $currentOf[(int) $term['structure_id']] = $term;
        }
        usort($structures, function ($a, $b) {
            return [$a['wing'] === 'muslimah', (int) $a['tier'], $a['name']] <=> [$b['wing'] === 'muslimah', (int) $b['tier'], $b['name']];
        });

        $unitModel = $this->model('UnitModel');
        $entries = []; $without = [];
        foreach ($structures as $structure) {
            $term = $currentOf[(int) $structure['id']] ?? null;
            if (!$term) { $without[] = $structure; continue; }
            $entries[] = [
                'structure' => $structure,
                'term' => $term,
                'groups' => $unitModel->groupsByTerm($term['id']),
                'units' => $unitModel->unitsByTerm($term['id']),
            ];
        }

        $rosterFilterOptions = [];
        foreach ($entries as $entry) {
            $termId = (int) $entry['term']['id'];
            $structureName = $entry['structure']['level'] . ' ' . $entry['structure']['name'];
            $groupNames = [];
            foreach ($entry['groups'] as $group) {
                $groupNames[(int) $group['id']] = $group['name'];
                $key = 'group:' . $termId . ':' . (int) $group['id'];
                $rosterFilterOptions[$key] = 'Bidang: ' . $group['name'] . ' — ' . $structureName;
            }
            foreach ($entry['units'] as $unit) {
                $key = 'unit:' . $termId . ':' . (int) $unit['id'];
                $unitName = $unit['display_name'] ?: $unit['catalog_name'];
                $groupName = $groupNames[(int) ($unit['unit_group_id'] ?? 0)] ?? '';
                $label = $unitName . ($groupName !== '' ? ' — ' . $groupName : '');
                $rosterFilterOptions[$key] = 'Departemen/Unit: ' . $label . ' — ' . $structureName;
            }
        }
        $filterParam = $_GET['penempatan'] ?? '';
        if (!is_string($filterParam) || !array_key_exists($filterParam, $rosterFilterOptions)
            || !preg_match('/^(group|unit):(\d+):(\d+)$/D', $filterParam, $filterParts)) {
            $filterParam = '';
            $assignmentFilter = [];
        } else {
            $assignmentFilter = ['type' => $filterParts[1], 'term_id' => (int) $filterParts[2], 'id' => (int) $filterParts[3]];
        }
        $rosterStatus = $_GET['status'] ?? 'all';
        if (!is_string($rosterStatus) || !in_array($rosterStatus, ['all', 'done', 'missing'], true)) $rosterStatus = 'all';

        $this->view('admin/biodata/index', [
            'title' => 'Biodata Pengurus',
            'rows'  => $rows,
            'entries' => $entries,
            'without' => $without,
            'recap' => $model->currentGroupingRecap(),
            'tab' => $tab,
            'roster' => $tab === 'status' ? $model->currentStatusRoster($rosterQuery, $rosterStatus, $assignmentFilter) : [],
            'rosterQuery' => $rosterQuery,
            'rosterStatus' => $rosterStatus,
            'rosterFilterOptions' => $rosterFilterOptions,
            'rosterFilter' => $filterParam,
        ]);
    }

    // Data ringkas untuk modal pada simpul Bidang atau unit.
    public function roster($termId, $groupId, $unitId = 0) {
        $this->requireLogin();
        $group = $groupId === 'core' ? 'core' : (int) $groupId;
        $rows = $this->model('BiodataModel')->groupingRoster((int) $termId, $group, (int) $unitId);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
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
