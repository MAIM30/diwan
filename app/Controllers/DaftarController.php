<?php
// PATH LOKASI FILE: app/Controllers/DaftarController.php
// Formulir pendaftaran publik (tanpa login).
//
// Formulir ini TIDAK terhubung ke akses aplikasi mana pun: tidak ada akun yang dibuat dan tidak ada apa pun yang dikirim ke syuro.
// Bawaannya (verval aktif) ia hanya menghasilkan satu baris pengajuan yang menunggu verifikasi manusia, tanpa data master yang berubah.
// Bila admin menonaktifkan verval (RegistrationModel::vervalActive), pengajuan yang jelas langsung diterima: orangnya tercatat, tanpa penempatan.

class DaftarController extends Controller {
    public function index() {
        $structures = $this->model('StructureModel');

        $this->view('public/daftar', [
            'title'      => 'Pendaftaran Data Pengurus',
            'structures' => $structures->allOrdered(),
            'unitsByWing' => $this->unitsByWing(),
            'vervalActive' => $this->model('RegistrationModel')->vervalActive(),
        ]);
    }

    // Katalog Unit Organisasi yang aktif, per sayap lalu per jenis (Departemen, Lembaga, Biro, ...), untuk pilihan di formulir.
    // Sayap mengikuti struktur yang dipilih: unit Muslimah hanya dipasang pada struktur Muslimah, dan sebaliknya.
    private function unitsByWing() {
        $out = ['umum' => [], 'muslimah' => []];
        foreach ($this->model('UnitModel')->catalogAll(true) as $u) {
            $out[$u['wing']][$u['kind']][] = ['id' => (int) $u['id'], 'name' => $u['name']];
        }
        $groups = [];
        foreach ($out as $wing => $byKind) {
            $groups[$wing] = [];
            foreach (UnitKinds::labels() as $kind => $label) {
                if (!empty($byKind[$kind])) $groups[$wing][] = ['label' => $label, 'items' => $byKind[$kind]];
            }
        }
        return $groups;
    }

    public function submit() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('daftar');
        }

        $structures = $this->model('StructureModel');
        $structureId = (int) ($_POST['target_structure_id'] ?? 0);
        $structure = $structures->findById($structureId);

        if (!$structure) {
            Flash::set('error', 'Struktur tujuan tidak valid.');
            $this->redirect('daftar');
        }

        [$pre, $name] = Text::normalizeNameParts($_POST['title_prefix'] ?? '', $_POST['name'] ?? '', $_POST['title_suffix'] ?? '');
        $phone = Text::normalizePhone($_POST['phone'] ?? '');

        if ($name === '' || $phone === null) {
            Flash::set('error', 'Nama dan nomor WhatsApp wajib diisi dengan benar.');
            $this->redirect('daftar');
        }

        $registrations = $this->model('RegistrationModel');

        try {
            $requestId = $registrations->submit([
                'title_prefix'        => $_POST['title_prefix'] ?? '',
                'name'                => $_POST['name'] ?? '',
                'title_suffix'        => $_POST['title_suffix'] ?? '',
                'gender'              => ($_POST['gender'] ?? '') === 'P' ? 'P' : 'L',
                'phone'               => $phone,
                'selected_person_id'  => (int) ($_POST['existing_person_id'] ?? 0),
                'email'               => '',
                'birth_date'          => '',
                'nik'                 => '',
                'notes'               => '',
                'target_structure_id' => $structureId,
                'unit_catalog_id'     => (int) ($_POST['unit_catalog_id'] ?? 0),
                'target_term_id'      => null,
                'target_unit_id'      => null,
                'target_position_id'  => null,
            ]);
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('daftar');
        } catch (PDOException $e) {
            error_log('Pendaftaran gagal: ' . $e->getMessage());
            Flash::set('error', 'Pendaftaran tidak bisa disimpan. Silakan coba lagi.');
            $this->redirect('daftar');
        }

        // Verval nonaktif: pengajuan yang jelas langsung diterima. Yang tak memenuhi syarat (nama mirip data lama, jenis kelamin tak sesuai
        // sayap) atau gagal diproses tetap menunggu verifikator, jadi pendaftar tak pernah kehilangan pengajuannya.
        $outcome = 'review';
        if (!$registrations->vervalActive()) {
            $outcome = $registrations->acceptWithoutReview($requestId)['accepted'] ? 'accepted' : 'review_auto';
        }

        $this->view('public/daftar_sukses', ['title' => 'Pendaftaran Terkirim', 'outcome' => $outcome]);
    }

    // Direktori nama publik membantu pendaftar memastikan data yang sudah ada.
    // Nilai yang dipilih tetap diverifikasi ulang saat submit.
    public function cariNama() {
        header('Content-Type: application/json; charset=utf-8');
        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) < 3) {
            echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
            return;
        }
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        echo json_encode($this->model('RegistrationModel')->peopleByName($query, $offset), JSON_UNESCAPED_UNICODE);
    }
}
