<?php
// PATH LOKASI FILE: app/Controllers/StructuresController.php

class StructuresController extends Controller {
    public function index() {
        $this->requireLogin();
        $structures = $this->model('StructureModel');

        $rows = $structures->allInScope();
        // Admin tiap struktur hanya ditampilkan kepada yang boleh mengelola akun.
        $admins = [];
        if (Auth::canEdit()) {
            $admins = $this->model('AccountModel')->adminsByStructure(array_column($rows, 'id'));
        }

        // Pohon: baris sudah urut mengikuti path, jadi urutan saudara dipertahankan. Struktur yang induknya
        // di luar lingkup (atau tak ada) menjadi puncak -- induk di luar lingkup tidak pernah ikut tampil.
        $inScope = [];
        foreach ($rows as $r) $inScope[(int) $r['id']] = true;
        $kids = [];
        $tops = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] === null ? 0 : (int) $r['parent_id'];
            if ($pid && isset($inScope[$pid])) $kids[$pid][] = $r;
            else $tops[] = $r;
        }

        $this->view('admin/structures/index', [
            'title'  => 'Struktur Organisasi',
            'tops'   => $tops,
            'kids'   => $kids,
            'admins' => $admins,
        ]);
    }

    public function form($id = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $structures = $this->model('StructureModel');
        $row = null;

        if ($id) {
            $row = $structures->findById($id);
            if (!$row) $this->notFound('Struktur tidak ditemukan.');
            $this->requireScope($row['id']);
        }

        $this->view('admin/structures/form', [
            'title'   => $id ? 'Ubah Struktur' : 'Tambah Struktur',
            'row'     => $row,
            'parents' => $structures->allInScope(),
            'levels'  => $structures->levelsInScope(),
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('structures');

        $structures = $this->model('StructureModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'level'       => $_POST['level'] ?? '',
            'name'        => $_POST['name'] ?? '',
            'parent_id'   => $_POST['parent_id'] ?? null,
            'region_code' => trim($_POST['region_code'] ?? ''),
            'is_active'   => !empty($_POST['is_active']),
        ];

        if ($data['name'] === '' || !in_array($data['level'], $structures->levelsInScope(), true)) {
            Flash::set('error', 'Nama dan tingkat struktur wajib diisi dengan benar.');
            $this->redirect('structures/form' . ($id ? '/' . $id : ''));
        }

        // Induk harus berada di dalam lingkup admin -- kalau tidak, admin DPW bisa menggantungkan
        // struktur baru di bawah DPW lain.
        if (!empty($data['parent_id'])) {
            $this->requireScope($data['parent_id']);
        } elseif (!AdminScope::isUnbounded()) {
            Flash::set('error', 'Hanya Super Admin yang boleh membuat struktur tanpa induk (akar).');
            $this->redirect('structures');
        }

        if ($id) {
            $existing = $structures->findById($id);
            if (!$existing) $this->notFound('Struktur tidak ditemukan.');
            $this->requireScope($id);
        }

        try {
            if ($id) {
                $structures->update($id, $data);
                AdminScope::refresh();
                Flash::set('success', 'Struktur diperbarui.');
            } else {
                $newId = $structures->create($data);
                AdminScope::refresh();
                Flash::set('success', 'Struktur ditambahkan. Langkah berikutnya: buat periode kepengurusannya.');
                $this->redirect('terms/form/0/' . $newId);
            }
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Simpan struktur gagal: ' . $e->getMessage());
            Flash::set('error', 'Struktur tidak bisa disimpan.');
        }

        $this->redirect('structures');
    }

    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('structures');

        $id = (int) ($_POST['id'] ?? 0);
        $structures = $this->model('StructureModel');
        $row = $structures->findById($id);
        if (!$row) $this->notFound('Struktur tidak ditemukan.');
        $this->requireScope($id);

        try {
            $structures->softDelete($id);
            Flash::set('success', 'Struktur dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('structures');
    }
}
