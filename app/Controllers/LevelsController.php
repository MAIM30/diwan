<?php
// PATH LOKASI FILE: app/Controllers/LevelsController.php
// Master Data > Tingkat Struktur: daftar tingkat (DPP, DPW, DPLN, DPD, ..., MWP, MWW, MWLN, ..., DSO, DSY, DPK) yang dikelola, bukan dipatri di
// kode. Hanya super admin, seperti katalog Jabatan: daftar ini menentukan pilihan Tingkat di formulir Struktur, urutan dan pengelompokan
// jenjang di seluruh halaman, dan nilai `level`, `wing`, `tier` di API. Singkatan (kode) tiap tingkat boleh diganti: structures.level ikut.

require_once ROOT_DIR . '/app/Core/StructureLevels.php';

class LevelsController extends Controller {
    public function index() {
        $this->requireLogin('super_admin');
        $this->view('admin/levels/index', [
            'title' => 'Tingkat Struktur',
            'rows'  => $this->model('StructureLevelModel')->levelList(),
        ]);
    }

    public function save() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('levels');

        $code = trim((string) ($_POST['code'] ?? ''));
        $isNew = empty($_POST['edit']);
        try {
            $levels = $this->model('StructureLevelModel');
            $saved = $levels->save($isNew ? null : $code, [
                // Tingkat baru: singkatannya. Tingkat yang diubah: singkatan BARU (isian new_code; kosong atau sama = tak diganti), sedangkan
                // `code` di formulir adalah singkatan lama yang menunjuk tingkatnya.
                'code'          => $isNew ? $code : trim((string) ($_POST['new_code'] ?? '')),
                'name'          => $_POST['name'] ?? '',
                'grp'           => $_POST['grp'] ?? '',
                'tier'          => $_POST['tier'] ?? '',
                'parent_code'   => $_POST['parent_code'] ?? '',
                'parent_strict' => !empty($_POST['parent_strict']),
                'short_label'   => $_POST['short_label'] ?? '',
                'sort_order'    => trim((string) ($_POST['sort_order'] ?? '')),
                'is_active'     => !empty($_POST['is_active']),
            ]);
            $r = $levels->renamed;
            Flash::set('success', $isNew ? 'Tingkat ' . $saved . ' dibuat. Sekarang bisa dipilih pada formulir Struktur.'
                : ($r ? 'Singkatan ' . $r['from'] . ' diganti menjadi ' . $r['to'] . '; ' . $r['structures'] . ' struktur ikut berganti (nilai level di API juga berubah). Tingkat disimpan.'
                      : 'Tingkat ' . $saved . ' disimpan.'));
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('levels');
    }

    public function delete() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('levels');

        try {
            $code = trim((string) ($_POST['code'] ?? ''));
            $this->model('StructureLevelModel')->delete($code);
            Flash::set('success', 'Tingkat ' . $code . ' dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('levels');
    }
}
