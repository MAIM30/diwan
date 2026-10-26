<?php
// PATH LOKASI FILE: app/Controllers/UnitsController.php
// SKUO: katalog unit bersama, Bidang per periode, unit per periode, dan pengelompokan unit ke Bidang per struktur.

class UnitsController extends Controller {
    const UNIT_KINDS = ['departemen', 'lembaga', 'biro', 'badan', 'pusat', 'divisi', 'lainnya'];

    // Katalog nama unit yang dipakai bersama lintas struktur: Departemen, Lembaga, Biro, Badan,
    // Pusat, Divisi. Bidang TIDAK di sini -- ia hidup per struktur per periode (units/grouping).
    public function catalog() {
        $this->requireLogin();
        $units = $this->model('UnitModel');

        $kind    = in_array($_GET['kind'] ?? '', self::UNIT_KINDS, true) ? $_GET['kind'] : '';
        $keyword = trim($_GET['q'] ?? '');

        $this->view('admin/units/catalog', [
            'title'   => 'Unit Organisasi',
            'rows'    => $units->catalogList($kind, $keyword),
            'counts'  => $units->catalogCountsByKind(),
            'kind'    => $kind,
            'keyword' => $keyword,
        ]);
    }

    // Kembali ke katalog dengan saringan yang sedang dipakai masih utuh: setelah mengubah satu unit
    // di antara 105 baris, kembali ke daftar penuh berarti kehilangan tempat.
    private function backToCatalog() {
        $kind = in_array($_POST['kind_filter'] ?? '', self::UNIT_KINDS, true) ? $_POST['kind_filter'] : '';
        $q    = trim($_POST['q_filter'] ?? '');
        $p    = array_filter(['kind' => $kind, 'q' => $q], function ($v) { return $v !== ''; });
        $this->redirect('units/catalog' . ($p ? '?' . http_build_query($p) : ''));
    }

    public function catalog_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/catalog');

        $units = $this->model('UnitModel');
        try {
            $units->catalogDelete((int) ($_POST['id'] ?? 0));
            Flash::set('success', 'Unit dihapus dari katalog.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->backToCatalog();
    }

    public function catalog_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/catalog');

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'name'         => trim($_POST['name'] ?? ''),
            'abbreviation' => trim($_POST['abbreviation'] ?? ''),
            'kind'         => in_array($_POST['kind'] ?? '', self::UNIT_KINDS, true) ? $_POST['kind'] : 'lainnya',
            'is_active'    => !empty($_POST['is_active']),
        ];

        if ($data['name'] === '') {
            Flash::set('error', 'Nama unit wajib diisi.');
            $this->backToCatalog();
        }

        try {
            $units->catalogSave($id, $data);
            Flash::set('success', 'Katalog unit disimpan.');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_unit_catalog_name') !== false) {
                Flash::set('error', 'Nama unit itu sudah ada di katalog. Katalog dipakai bersama lintas struktur, jadi cukup pakai yang sudah ada.');
            } else {
                error_log('Simpan katalog unit gagal: ' . $e->getMessage());
                Flash::set('error', 'Unit tidak bisa disimpan.');
            }
        }
        $this->backToCatalog();
    }

    // Pengelompokan unit per struktur: untuk tiap struktur dalam lingkup yang punya periode berjalan, Bidang-nya dan unit di
    // bawah tiap Bidang. Struktur tanpa periode berjalan dikumpulkan terpisah (Bidang dan unit terikat periode, jadi belum ada).
    public function grouping() {
        $this->requireLogin();
        $structures = $this->model('StructureModel')->allInScope();
        $terms = $this->model('TermModel')->allInScope();
        $units = $this->model('UnitModel');

        $currentOf = [];
        foreach ($terms as $t) {
            if (!empty($t['is_current'])) $currentOf[(int) $t['structure_id']] = $t;
        }

        // Sayap umum dulu, lalu Muslimah; di dalamnya dari tingkat Pusat ke Ranting, lalu menurut nama.
        usort($structures, function ($a, $b) {
            return [$a['wing'] === 'muslimah', (int) $a['tier'], $a['name']] <=> [$b['wing'] === 'muslimah', (int) $b['tier'], $b['name']];
        });

        $entries = []; $without = [];
        foreach ($structures as $s) {
            $t = $currentOf[(int) $s['id']] ?? null;
            if (!$t) { $without[] = $s; continue; }
            $entries[] = ['structure' => $s, 'term' => $t, 'groups' => $units->groupsByTerm($t['id']), 'units' => $units->unitsByTerm($t['id'])];
        }

        $this->view('admin/units/grouping', [
            'title'    => 'Pengelompokan Unit',
            'entries'  => $entries,
            'without'  => $without,
            'openTerm' => (int) ($_GET['open'] ?? 0),
            'catalog'  => Auth::canEdit() ? $units->catalogAll(true) : [],   // saran nama pada isian "Tambah unit"
        ]);
    }

    // Aksi-aksi simpan/hapus di bawah dipakai halaman Bidang & Unit sebuah periode DAN halaman Pengelompokan Unit; yang kedua menandai
    // asalnya dengan return=grouping supaya pengguna kembali ke strukturnya di sana (terbuka), bukan ke halaman Bidang & Unit.
    private function back($termId) {
        $termId = (int) $termId;
        if (($_POST['return'] ?? '') === 'grouping') $this->redirect('units/grouping?open=' . $termId . '#t' . $termId);
        $this->redirect('units/term/' . $termId);
    }

    // Isian angka urutan: kolomnya SMALLINT UNSIGNED, jadi nilai di luar rentang ditahan di ujungnya, bukan dibiarkan jadi galat basis data.
    private function sortOrder($value) {
        return max(0, min(65535, (int) $value));
    }

    // Menyimpan pengelompokan satu periode: tiap unit ke Bidang yang dipilih di halaman Pengelompokan Unit.
    public function grouping_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/grouping');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $term = $this->model('TermModel')->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $moves = is_array($_POST['group'] ?? null) ? $_POST['group'] : [];
        try {
            $n = $this->model('UnitModel')->regroup($termId, $moves);
            Flash::set('success', $n > 0 ? $n . ' unit dipindahkan.' : 'Tidak ada perubahan.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Simpan pengelompokan gagal: ' . $e->getMessage());
            Flash::set('error', 'Pengelompokan tidak bisa disimpan.');
        }
        $this->redirect('units/grouping?open=' . $termId . '#t' . $termId);
    }

    // Bidang & unit milik satu periode.
    public function term($termId) {
        $this->requireLogin();

        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');

        $this->view('admin/units/term', [
            'title'   => 'Bidang & Unit: ' . $term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name'],
            'term'    => $term,
            'groups'  => $units->groupsByTerm($termId),
            'rows'    => $units->unitsByTerm($termId),
            'catalog' => $units->catalogAll(true),
        ]);
    }

    public function group_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Periode di atas sudah diperiksa lingkupnya; Bidang yang diubah harus benar-benar milik periode itu, bukan sekadar id yang dikirim.
        if ($id) {
            $existing = $units->groupFindById($id);
            if (!$existing || (int) $existing['term_id'] !== $termId) $this->notFound('Bidang tidak ditemukan di periode ini.');
        }

        $data = [
            'structure_id' => $term['structure_id'],
            'term_id'      => $termId,
            'name'         => trim($_POST['name'] ?? ''),
            'kind'         => in_array($_POST['kind'] ?? '', ['bidang','subbidang','grup'], true) ? $_POST['kind'] : 'bidang',
            'sort_order'   => $this->sortOrder($_POST['sort_order'] ?? 500),
        ];

        if ($data['name'] === '' || mb_strlen($data['name']) > 200) {
            Flash::set('error', 'Nama Bidang wajib diisi, paling banyak 200 huruf.');
            $this->back($termId);
        }

        try {
            $units->groupSave($id, $data);
            Flash::set('success', 'Bidang disimpan.');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_unit_groups_term_name') !== false) {
                Flash::set('error', 'Bidang dengan nama itu sudah ada di periode ini.');
            } else {
                error_log('Simpan bidang gagal: ' . $e->getMessage());
                Flash::set('error', 'Bidang tidak bisa disimpan.');
            }
        }
        $this->back($termId);
    }

    public function group_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $units = $this->model('UnitModel');
        $group = $units->groupFindById((int) ($_POST['id'] ?? 0));
        if (!$group) $this->notFound('Bidang tidak ditemukan.');
        $this->requireScope($group['structure_id']);

        try {
            $units->groupDelete($group['id']);
            Flash::set('success', 'Bidang dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->back($group['term_id']);
    }

    public function unit_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Unit yang diubah harus benar-benar milik periode yang lingkupnya diperiksa di atas, bukan sekadar id yang dikirim.
        $existing = null;
        if ($id) {
            $existing = $units->unitFindById($id);
            if (!$existing || (int) $existing['term_id'] !== $termId) $this->notFound('Unit tidak ditemukan di periode ini.');
        }

        // Bidang yang dipilih harus milik periode ini. Database juga menolaknya lewat composite FK,
        // tapi dicek di sini supaya pesannya bisa dibaca. Mengubah unit tanpa mengirim unit_group_id (mis. hanya nama tampil
        // dan urutan) membiarkan Bidangnya apa adanya.
        if (array_key_exists('unit_group_id', $_POST) || !$existing) {
            $groupId = !empty($_POST['unit_group_id']) ? (int) $_POST['unit_group_id'] : null;
        } else {
            $groupId = $existing['unit_group_id'] !== null ? (int) $existing['unit_group_id'] : null;
        }
        if ($groupId !== null) {
            $group = $units->groupFindById($groupId);
            if (!$group || (int) $group['term_id'] !== $termId) {
                Flash::set('error', 'Bidang yang dipilih bukan milik periode ini.');
                $this->back($termId);
            }
        }

        $displayName = trim($_POST['display_name'] ?? '');
        if (mb_strlen($displayName) > 200) {
            Flash::set('error', 'Nama tampil paling banyak 200 huruf.');
            $this->back($termId);
        }

        // Menambah unit: pilih dari katalog (halaman Bidang & Unit), atau ketik namanya (halaman Pengelompokan Unit): nama yang
        // sudah ada di katalog dipakai ulang, yang belum ada dibuat dengan jenis yang dipilih.
        $catalogId = (int) ($_POST['unit_catalog_id'] ?? 0);
        $createdInCatalog = false;
        if (!$id && !$catalogId) {
            $newName = trim($_POST['new_name'] ?? '');
            if ($newName === '' || mb_strlen($newName) > 200) {
                Flash::set('error', 'Pilih unit dari katalog, atau ketik nama unit (paling banyak 200 huruf).');
                $this->back($termId);
            }
            $kind = in_array($_POST['new_kind'] ?? '', self::UNIT_KINDS, true) ? $_POST['new_kind'] : 'lainnya';
            [$catalogId, $createdInCatalog] = $units->catalogEnsure($newName, $kind);
        }

        $data = [
            'structure_id'    => $term['structure_id'],
            'term_id'         => $termId,
            'unit_catalog_id' => $existing ? (int) $existing['unit_catalog_id'] : $catalogId,
            'unit_group_id'   => $groupId,
            'display_name'    => $displayName,
            'sort_order'      => $this->sortOrder($_POST['sort_order'] ?? 500),
        ];

        try {
            $units->unitSave($id, $data);
            Flash::set('success', $createdInCatalog ? 'Unit baru dibuat di katalog dan ditambahkan.' : 'Unit disimpan.');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_structure_units_term_unit') !== false) {
                Flash::set('error', 'Unit itu sudah terdaftar di periode ini.');
            } else {
                error_log('Simpan unit gagal: ' . $e->getMessage());
                Flash::set('error', 'Unit tidak bisa disimpan.');
            }
        }
        $this->back($termId);
    }

    public function unit_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $units = $this->model('UnitModel');
        $unit = $units->unitFindById((int) ($_POST['id'] ?? 0));
        if (!$unit) $this->notFound('Unit tidak ditemukan.');
        $this->requireScope($unit['structure_id']);

        try {
            $units->unitDelete($unit['id']);
            Flash::set('success', 'Unit dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->back($unit['term_id']);
    }
}
