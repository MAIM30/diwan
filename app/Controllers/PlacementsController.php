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

        // Orangnya ditampilkan sebagai kartu: yang punya penempatan ini (mengubah) atau ?person_id= dari profil (menambah); tanpa itu
        // dicari lewat placements/orang. Dulu orang dicari di 2.000 nama pertama menurut abjad, jadi untuk sebagian besar pengurus
        // kolom Orang kosong (dan ?person_id= diam-diam dibuang).
        $personId = $row ? (int) $row['person_id'] : (int) ($_GET['person_id'] ?? 0);
        $person = null;
        // Jangan menerima person_id dari URL jika orang tersebut di luar lingkup akses.
        if ($personId > 0 && $people->isVisible($personId)) $person = $people->findById($personId);

        $this->view('admin/placements/form', [
            'title'     => $id ? 'Ubah Penempatan' : 'Tambah Penempatan',
            'term'      => $term,
            'row'       => $row,
            'person'    => $person,
            'otherPlacements' => $otherPlacements,
            'landingNow' => $row ? $placements->landingLabel($row['structure_unit_id'], $row['position_id']) : null,
            'units'     => $units->unitsByTerm($row['term_id'] ?? $termId),
            'positions' => $positions->all(),
        ]);
    }

    // Pencarian orang untuk formulir Tambah Penempatan (JSON): nama, nomor WA, atau NIK lengkap, di dalam lingkup admin. Pengganti daftar
    // pilihan 2.000 nama yang memotong sebagian besar pengurus. Paling banyak 15 hasil; jabatan terkininya ikut, untuk membedakan nama kembar.
    public function orang() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        header('Content-Type: application/json; charset=utf-8');

        $q = $_GET['q'] ?? '';
        $q = is_string($q) ? trim($q) : '';
        if (mb_strlen($q) < 2) { echo '[]'; return; }

        $out = [];
        foreach ($this->model('PersonModel')->searchInScope($q, 15, 0, []) as $p) {
            $out[] = [
                'id'      => (int) $p['id'],
                'name'    => Ui::fullName($p),
                'gender'  => (string) $p['gender'],
                'sub'     => $p['position_name'] ? $p['position_name'] . ' · ' . $p['structure_label'] : 'Belum menjabat',
                'initial' => mb_strtoupper(mb_substr(trim((string) Ui::nameParts($p)[1]), 0, 1)),   // untuk avatar kartu orang
                'hue'     => abs(crc32((string) ($p['uuid'] ?? $p['id']))) % 6,                    // sama dengan Ui::avatar
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    // Pemilih struktur untuk Tambah Jabatan (JSON): ketik nama, saring menurut jenjang. Pengganti dropdown yang memuat ratusan struktur sekaligus.
    // Parameter: q, lv (jenjang), wing (umum | muslimah), gp (acuan periode). Hanya struktur dalam lingkup admin (TermModel::pickerSearch).
    public function structures() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        header('Content-Type: application/json; charset=utf-8');

        // Parameter bisa datang sebagai larik (?q[]=x): hanya teks yang dipakai.
        $param = function ($key) { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; };
        echo json_encode($this->model('TermModel')->pickerSearch([
            'q'    => $param('q'),
            'lv'   => $param('lv'),
            'wing' => $param('wing'),
            'gp'   => (int) $param('gp'),
        ]), JSON_UNESCAPED_UNICODE);
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

        // Kecocokan jabatan dengan tempatnya (tingkat struktur / di dalam SKUO / jenis unitnya) dijaga PlacementModel, di setiap jalur
        // penulisan, jadi tak bergantung pada pilihan yang difilter di browser. Penolakannya tiba sebagai RuntimeException di bawah.
        // Simpanan tanpa perubahan apa pun jangan dilaporkan "berhasil": pengguna yang mengira sudah memindahkan sesuatu akan mengira
        // sistemnya yang salah. Dikatakan terus terang, berikut tempat penempatan itu tampil sekarang dan apa yang menentukannya.
        if ($id && $termId === $baseTermId) {
            $same = function ($a, $b) { return (string) ($a ?? '') === (string) ($b ?? ''); };
            $unchanged = (int) ($existing['structure_unit_id'] ?? 0) === (int) ($data['structure_unit_id'] ?? 0)
                && (int) $existing['position_id'] === (int) $data['position_id']
                && !empty($existing['is_primary']) === !empty($data['is_primary'])
                && $same($existing['start_date'], $data['start_date']) && $same($existing['end_date'], $data['end_date'])
                && $same($existing['end_reason'], $data['end_reason']) && $same($existing['sk_reference'], $data['sk_reference'])
                && $same($existing['notes'], $data['notes']);
            if ($unchanged) {
                Flash::set('warning', 'Tidak ada yang diubah, jadi tidak ada yang disimpan. Penempatan ini tetap tampil di: '
                    . $placements->landingLabel($existing['structure_unit_id'], $existing['position_id']) . '. '
                    . 'Tempatnya ditentukan oleh Unit Organisasi (di dalam unit: Bidang / Badan / Biro / ...) dan oleh Jabatan (tanpa unit: '
                    . 'Pengurus Inti bila jabatannya inti, selain itu Pimpinan Lainnya).');
                $returnTo();
            }
        }

        try {
            $after = $placements->landingLabel($data['structure_unit_id'], $data['position_id']);
            if ($id) {
                $from = $placements->landingLabel($existing['structure_unit_id'], $existing['position_id']);
                if ($termId !== $baseTermId) $placements->updateWithinGovernancePeriod($id, $data);
                else $placements->update($id, $data);
                $head = $termId !== $baseTermId ? 'Penempatan dipindahkan ke struktur tujuan dalam acuan periode yang sama.' : 'Penempatan diperbarui.';
                Flash::set('success', $head . ($termId === $baseTermId && $from !== $after ? ' Dipindah dari ' . $from . ' ke ' . $after . '.' : ' Tampil di: ' . $after . '.'));
            } else {
                $newId = $placements->create($data);
                WaNotify::placementCreated((int) $data['person_id'], $newId);
                Flash::set('success', 'Penempatan ditambahkan. Tampil di: ' . $after . '.');
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
        $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : date('Y-m-d');

        // Tombol Akhiri dari profil pengurus mengirim return_to_person agar kembali ke profil yang
        // sama; dari susunan periode kembali ke susunan itu.
        $back = !empty($_POST['return_to_person'])
              ? 'people/detail/' . (int) $row['person_id']
              : 'terms/detail/' . $row['term_id'];

        $parsed = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $endDate) {
            Flash::set('error', 'Tanggal berakhir tidak valid.');
            $this->redirect($back);
        }
        $floor = $row['start_date'] ?: $row['term_start'];
        if ($floor && $endDate < $floor) {
            Flash::set('error', 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai (' . $floor . ').');
            $this->redirect($back);
        }

        $placements->end($row['id'], $endDate, $reason);
        Flash::set('success', 'Jabatan ' . $row['position_name'] . ' pada ' . $row['level'] . ' ' . $row['structure_name']
            . ' diakhiri per ' . $endDate . '. Riwayatnya tetap tersimpan.');
        $this->redirect($back);
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
        Flash::set('success', 'Jabatan ' . $row['position_name'] . ' pada ' . $row['level'] . ' ' . $row['structure_name']
            . ' berhasil dilepas. Data orang dan penempatan lainnya tetap tersimpan.');

        // Tombol Hapus dari profil pengurus mengirim return_to_person_id agar kembali ke profil
        // yang sama; dari susunan periode kembali ke daftar Tanpa Unit Organisasi.
        $returnPersonId = (int) ($_POST['return_to_person_id'] ?? 0);
        if ($returnPersonId > 0) $this->redirect('people/detail/' . $returnPersonId);
        $this->redirect('people?filter=tanpa_unit');
    }
}
