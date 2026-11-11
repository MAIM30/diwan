<?php
// PATH LOKASI FILE: app/Controllers/PositionsController.php
// Master Data > Jabatan: katalog jabatan struktural dan unit yang dipakai penempatan.

class PositionsController extends Controller {

    public function index() {
        $this->requireLogin();
        $pos = $this->model('PositionModel');

        $appliesTo = in_array($_GET['at'] ?? '', PositionModel::APPLIES_TO, true) ? $_GET['at'] : '';
        $keyword   = trim($_GET['q'] ?? '');
        // Saringan struktur (?st=DPP&lv=DPW): pilihan yang tak sah diabaikan, bukan galat.
        $structure = $pos->resolveStructureFilter($_GET['st'] ?? '', $_GET['lv'] ?? '');

        $this->view('admin/positions/index', [
            'title'    => 'Jabatan',
            'rows'     => $pos->adminList($appliesTo, $keyword, $structure),
            'counts'   => $pos->countsByAppliesTo(),
            'appliesTo' => $appliesTo,
            'keyword'  => $keyword,
            'structure'  => $structure,
            'structureFilters' => $pos->structureFilters(),
        ]);
    }

    const PER_PAGE_OPTIONS = [25, 50, 100];

    // Tombol "Lihat": siapa saja yang memegang jabatan ini. Server-side karena "Anggota" saja punya ribuan penempatan.
    // Hanya penempatan di dalam lingkup akses admin yang ditampilkan; sisanya dihitung dan diberitahukan.
    public function members($id) {
        $this->requireLogin();
        $positions = $this->model('PositionModel');
        $position = $positions->findById((int) $id);
        if (!$position) $this->notFound('Jabatan tidak ditemukan.');

        $keyword = trim($_GET['q'] ?? '');
        $perPage = in_array((int) ($_GET['per_page'] ?? 0), self::PER_PAGE_OPTIONS, true) ? (int) $_GET['per_page'] : 25;
        // Saringan struktur dari halaman Jabatan ikut terbawa, supaya daftar ini sama dengan angka "Dipakai" yang sedang dilihat.
        $structure = $positions->resolveStructureFilter($_GET['st'] ?? '', $_GET['lv'] ?? '');

        $total = $positions->holdersCount($position['id'], $keyword, true, $structure);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        $this->view('admin/positions/members', [
            'title'    => $position['name'],
            'position' => $position,
            'rows'     => $positions->holders($position['id'], $keyword, $perPage, ($page - 1) * $perPage, $structure),
            'total'    => $total,
            'allCount' => $positions->holdersCount($position['id'], '', false, $structure),
            'inScope'  => $positions->holdersCount($position['id'], '', true, $structure),
            'structure' => $structure,
            'keyword'  => $keyword,
            'page'     => $page,
            'pages'    => $pages,
            'perPage'  => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    private function backToList() {
        $at = in_array($_POST['at_filter'] ?? '', PositionModel::APPLIES_TO, true) ? $_POST['at_filter'] : '';
        $q  = trim($_POST['q_filter'] ?? '');
        // Saringan struktur ikut dipertahankan setelah simpan / hapus (dibakukan ulang: hidden field dari formulir tak dipercaya begitu saja).
        $st = $this->model('PositionModel')->resolveStructureFilter($_POST['st_filter'] ?? '', $_POST['lv_filter'] ?? '');
        $p  = array_filter(['at' => $at, 'q' => $q, 'st' => $st['abbr'] ?? '', 'lv' => $st['level'] ?? ''], function ($v) { return $v !== ''; });
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
            // Jenis unit tempat jabatan ini berlaku (kosong = semua); PositionModel membuang yang tak sah dan mengosongkannya
            // untuk jabatan yang hanya berlaku di tingkat struktur.
            'unit_kinds'     => array_values(array_filter((array) ($_POST['unit_kinds'] ?? []), 'is_string')),
            // Tanda inti (is_core) sengaja TIDAK dibaca dari formulir: ia tak lagi diatur dari halaman ini. Jabatan baru tidak inti, dan
            // yang sudah ada tetap seperti semula (PositionModel::save hanya mengubahnya bila kuncinya ada).
            'sort_order'    => max(0, min(65535, (int) ($_POST['sort_order'] ?? 500))),
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
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
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
