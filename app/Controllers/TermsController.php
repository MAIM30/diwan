<?php
// PATH LOKASI FILE: app/Controllers/TermsController.php
// Periode kepengurusan. Halaman ini yang membuat riwayat antar periode bisa dilihat utuh.

class TermsController extends Controller {
    public function index() {
        $this->requireLogin();
        $terms = $this->model('TermModel');

        $this->view('admin/terms/index', [
            'title' => 'Periode Kepengurusan',
            'rows'  => $terms->allInScope(),
        ]);
    }

    public function period_catalog() {
        $this->requireLogin();
        $this->view('admin/terms/period_catalog', [
            'title' => 'Acuan Periode Kepengurusan',
            'rows' => $this->model('TermModel')->governancePeriodsInScope(),
        ]);
    }

    public function period_detail($id) {
        $this->requireLogin();
        $terms = $this->model('TermModel');
        $period = $terms->governancePeriodFindById($id);
        $rows = $period ? $terms->termsForGovernancePeriod($id) : [];
        if (!$period) $this->notFound('Acuan periode tidak ditemukan.');
        $this->view('admin/terms/period_detail', [
            'title' => 'Rekap Periode ' . $period['name'],
            'period' => $period,
            'rows' => $rows,
            'availableStructures' => $terms->structuresNotUsingGovernancePeriod($id),
            'placementCount' => array_sum(array_column($rows, 'placement_count')),
        ]);
    }

    public function attach_structures() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms/period_catalog');

        $periodId = (int) ($_POST['governance_period_id'] ?? 0);
        $terms = $this->model('TermModel');
        $period = $terms->governancePeriodFindById($periodId);
        if (!$period) $this->notFound('Acuan periode tidak ditemukan.');

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['structure_ids'] ?? [])))));
        $structures = $this->model('StructureModel');
        foreach ($ids as $structureId) {
            $structure = $structures->findById($structureId);
            if (!$structure || empty($structure['is_active'])) $this->notFound('Struktur aktif tidak ditemukan.');
            $this->requireScope($structureId);
        }

        try {
            $count = $terms->attachGovernancePeriodToStructures($periodId, $ids, !empty($_POST['mark_current']));
            Flash::set('success', $count
                ? $count . ' struktur ditautkan ke periode ' . $period['name'] . '. Susunan unit dan SK dapat dilengkapi pada setiap struktur.'
                : 'Tidak ada perubahan; struktur yang dipilih sudah memakai periode ini.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Tautkan periode ke struktur gagal: ' . $e->getMessage());
            Flash::set('error', 'Periode tidak dapat ditautkan ke struktur yang dipilih.');
        }
        $this->redirect('terms/period_detail/' . $periodId);
    }

    public function period_form() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        $this->view('admin/terms/period_form', ['title' => 'Buat Acuan Periode Kepengurusan']);
    }

    public function period_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms/period_catalog');

        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date_planned' => $_POST['end_date_planned'] ?? '',
        ];
        if ($data['name'] === '' || $data['start_date'] === '') {
            Flash::set('error', 'Nama periode dan tanggal mulai wajib diisi.');
            $this->redirect('terms/period_form');
        }
        if ($data['end_date_planned'] !== '' && $data['end_date_planned'] < $data['start_date']) {
            Flash::set('error', 'Tanggal akhir rencana tidak boleh lebih awal dari tanggal mulai.');
            $this->redirect('terms/period_form');
        }

        try {
            $this->model('TermModel')->createGovernancePeriod($data);
            Flash::set('success', 'Acuan periode dibuat. Sekarang pilih periode ini saat menambahkan periode pada setiap struktur.');
        } catch (PDOException $e) {
            Flash::set('error', 'Acuan dengan nama dan rentang tanggal tersebut sudah tersedia.');
        }
        $this->redirect('terms/period_catalog');
    }

    // Susunan pengurus satu periode, dikelompokkan per kategori (Pengurus Inti, Bidang beserta Departemen dan Lembaganya, dst.).
    public function detail($id) {
        $this->requireLogin();

        $terms = $this->model('TermModel');
        $term = $terms->findById($id);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $placements = $this->model('PlacementModel');
        $units = $this->model('UnitModel');
        $rows = $placements->byTerm($id);
        $unitRows = $units->unitsByTerm($id);
        $groups = $units->groupsByTerm($id);

        $this->view('admin/terms/detail', [
            'title'      => 'Susunan ' . $term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name'],
            'term'       => $term,
            'rows'       => $rows,
            'tree'       => Susunan::arrange($rows, $groups, $unitRows),
            'units'      => $unitRows,
            'groups'     => $groups,
            'otherTerms' => $terms->byStructure($term['structure_id']),
            'skCoverage' => $terms->skCoverage($id),
        ]);
    }

    // Mengunduh/membuka berkas SK periode ini. Harus login DAN periodenya berada di lingkup admin. Berkas ada di
    // storage/ (di luar folder publik), jadi inilah satu-satunya jalan ke sana.
    public function sk($id = 0) {
        $this->requireLogin();
        $terms = $this->model('TermModel');
        $term = $terms->findById($id);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $abs = SkFile::path($term['sk_file_path'] ?? null);
        if (!$abs || !is_file($abs)) $this->notFound('Berkas SK periode ini belum dilampirkan.');

        $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($term['sk_number'] ?: ('periode-' . $term['id'])));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="SK-' . trim($label, '-') . '.pdf"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');       // skrip di dalam PDF tak berjalan dalam konteks aplikasi
        header('Cache-Control: private, no-store, max-age=0');
        readfile($abs);
        exit;
    }

    // Menangani kolom berkas SK pada formulir periode. Mengembalikan pesan galat, atau null bila beres/tak ada berkas.
    private function handleSkUpload($termId, $terms) {
        $term = $terms->findById($termId);
        if (!empty($_POST['remove_sk_file']) && !empty($term['sk_file_path'])) {
            $terms->setSkFile($termId, null);
            return null;
        }
        $f = $_FILES['sk_file'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > SkFile::MAX_BYTES) {
            return 'Berkas SK terlalu besar (maksimal 20 MB; batas unggah PHP di server ini ' . ini_get('upload_max_filesize') . ').';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'Unggahan berkas SK gagal. Silakan coba lagi.';
        }
        $r = SkFile::store($f['tmp_name'], true);
        if (isset($r['error'])) {
            return ['type' => 'Berkas SK harus berupa PDF yang sah.', 'size' => 'Berkas SK kosong atau terlalu besar (maks 20 MB).',
                    'write' => 'Berkas SK tidak bisa disimpan di server.'][$r['error']];
        }
        $terms->setSkFile($termId, $r['path']);
        return null;
    }

    // $structureId diteruskan saat datang dari halaman struktur yang baru dibuat.
    public function form($id = 0, $structureId = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $terms = $this->model('TermModel');
        $structures = $this->model('StructureModel');
        $row = null;

        if ($id) {
            $row = $terms->findById($id);
            if (!$row) $this->notFound('Periode tidak ditemukan.');
            $this->requireScope($row['structure_id']);
            $structureId = $row['structure_id'];
        } elseif ($structureId) {
            $this->requireScope($structureId);
        }

        $this->view('admin/terms/form', [
            'title'       => $id ? 'Ubah Periode' : 'Tambah Periode',
            'row'         => $row,
            'structures'  => $structures->allInScope(),
            'governancePeriods' => $terms->governancePeriodsInScope(),
            'governancePeriodId' => (int) ($_GET['governance_period_id'] ?? 0),
            'structureId' => $structureId,
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $terms = $this->model('TermModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $structureId = (int) ($_POST['structure_id'] ?? 0);
        if ($id) {
            $existingTerm = $terms->findById($id);
            if (!$existingTerm) $this->notFound('Periode tidak ditemukan.');
            $this->requireScope($existingTerm['structure_id']);
            if ($structureId !== (int) $existingTerm['structure_id']) $this->notFound('Struktur periode tidak dapat diganti.');
        } else {
            $this->requireScope($structureId);
        }

        $data = [
            'structure_id'     => $structureId,
            'governance_period_id' => (int) ($_POST['governance_period_id'] ?? 0),
            'end_date_actual'  => $_POST['end_date_actual'] ?? '',
            'status'           => in_array($_POST['status'] ?? '', ['draft','active','ended'], true) ? $_POST['status'] : 'draft',
            'is_current'       => !empty($_POST['is_current']),
            'sk_number'        => trim($_POST['sk_number'] ?? ''),
            'sk_date'          => $_POST['sk_date'] ?? '',
            'notes'            => trim($_POST['notes'] ?? ''),
        ];

        if (!$id && !$terms->governancePeriodFindById($data['governance_period_id'])) {
            Flash::set('error', 'Pilih acuan Periode Kepengurusan yang tersedia.');
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }
        $dateAnchor = $id ? $terms->findById($id) : $terms->governancePeriodFindById($data['governance_period_id']);
        if (!empty($data['end_date_actual']) && $data['end_date_actual'] < $dateAnchor['start_date']) {
            Flash::set('error', 'Tanggal akhir aktual tidak boleh lebih awal dari tanggal mulai periode.');
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }

        try {
            if ($id) {
                $terms->update($id, $data);
                $skErr = $this->handleSkUpload($id, $terms);
                Flash::set($skErr ? 'error' : 'success', $skErr ? 'Periode diperbarui, tetapi berkas SK ditolak: ' . $skErr : 'Periode diperbarui.');
                $this->redirect('terms/detail/' . $id);
            } else {
                $newId = $terms->create($data);
                $skErr = $this->handleSkUpload($newId, $terms);
                Flash::set($skErr ? 'error' : 'success', $skErr ? 'Periode dibuat, tetapi berkas SK ditolak: ' . $skErr
                    : 'Periode dibuat. Langkah berikutnya: susun Bidang & unit, atau salin dari periode sebelumnya.');
                $this->redirect('terms/detail/' . $newId);
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_terms_structure_name') !== false) {
                Flash::set('error', 'Periode dengan nama itu sudah ada di struktur ini.');
            } else {
                error_log('Simpan periode gagal: ' . $e->getMessage());
                Flash::set('error', 'Periode tidak bisa disimpan.');
            }
            $this->redirect('terms');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }
    }

    // Menyalin susunan Bidang & unit dari periode lain pada struktur yang sama. Dibutuhkan karena
    // unit terikat periode: tanpa ini, tiap periode baru harus disusun ulang dari nol.
    public function clone_units() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $targetId = (int) ($_POST['target_term_id'] ?? 0);
        $sourceId = (int) ($_POST['source_term_id'] ?? 0);

        $terms = $this->model('TermModel');
        $target = $terms->findById($targetId);
        if (!$target) $this->notFound('Periode tujuan tidak ditemukan.');
        $this->requireScope($target['structure_id']);

        try {
            $result = $terms->cloneUnitsFrom($sourceId, $targetId);
            Flash::set('success', 'Disalin: ' . $result['groups'] . ' Bidang dan ' . $result['units']
                . ' unit. Penempatan pengurus tidak ikut disalin.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Clone unit gagal: ' . $e->getMessage());
            Flash::set('error', 'Penyalinan gagal. Pastikan periode tujuan masih kosong dari Bidang bernama sama.');
        }

        $this->redirect('terms/detail/' . $targetId);
    }

    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $id = (int) ($_POST['id'] ?? 0);
        $terms = $this->model('TermModel');
        $row = $terms->findById($id);
        if (!$row) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($row['structure_id']);

        try {
            $terms->softDelete($id);
            Flash::set('success', 'Periode dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('terms');
    }
}
