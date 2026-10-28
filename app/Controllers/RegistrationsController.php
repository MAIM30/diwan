<?php
// PATH LOKASI FILE: app/Controllers/RegistrationsController.php
// Verifikasi pendaftar: Diajukan -> Diverifikasi -> Aktif.

class RegistrationsController extends Controller {
    public function index() {
        $this->requireLogin();
        $registrations = $this->model('RegistrationModel');

        $status = $_GET['status'] ?? 'submitted';
        $this->view('admin/registrations/index', [
            'title'  => 'Pendaftaran Pengurus',
            'rows'   => $registrations->listInScope($status !== 'all' ? $status : null),
            'status' => $status,
        ]);
    }

    public function review($id) {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();

        $registrations = $this->model('RegistrationModel');
        $reg = $registrations->findById($id);
        if (!$reg) $this->notFound('Pengajuan tidak ditemukan.');
        $this->requireScope($reg['target_structure_id']);

        $terms = $this->model('TermModel');
        $units = $this->model('UnitModel');
        $positions = $this->model('PositionModel');

        // Calon duplikat dicari di SELURUH basis data (satu orang, satu baris), tapi biodata orang di luar
        // lingkup verifikator tidak ditampilkan -- hanya namanya.
        $match = $registrations->matchesFor($reg);
        $match['candidates'] = $this->model('PersonModel')->redactOutOfScope($match['candidates']);

        $termList = $terms->byStructure($reg['target_structure_id']);
        $defaultTermId = $reg['target_term_id'] ?: ($terms->currentOfStructure($reg['target_structure_id'])['id'] ?? null);

        $this->view('admin/registrations/review', [
            'title'     => 'Verifikasi: ' . $reg['full_name'],
            'reg'       => $reg,
            // Calon duplikat dihitung ULANG saat ditinjau, bukan dipakai dari hasil saat pengajuan
            // masuk: data bisa berubah di antara keduanya.
            'match'     => $match,
            'terms'     => $termList,
            'defaultTermId' => $defaultTermId,
            'units'     => $defaultTermId ? $units->unitsByTerm($defaultTermId) : [],
            'positions' => $positions->all(),
        ]);
    }

    public function verify() {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('registrations');

        $registrations = $this->model('RegistrationModel');
        $id = (int) ($_POST['id'] ?? 0);
        $reg = $registrations->findById($id);
        if (!$reg) $this->notFound('Pengajuan tidak ditemukan.');
        $this->requireScope($reg['target_structure_id']);

        if ($registrations->markVerified($id, trim($_POST['review_note'] ?? ''))) {
            Flash::set('success', 'Pengajuan diverifikasi. Langkah berikutnya: tentukan penempatannya untuk mengaktifkan.');
            $wa = WaNotify::registrationDecision($reg, 'verified');
            if (empty($wa['ok'])) {
                Flash::set('warn', !empty($wa['skipped'])
                    ? 'Status berubah, tetapi pesan WhatsApp tidak dikirim. Periksa konfigurasi WA Gateway dan nomor pendaftar.'
                    : 'Status berubah, tetapi pengiriman WhatsApp gagal. Periksa Log WhatsApp.');
            }
        } else {
            Flash::set('error', 'Pengajuan ini tidak lagi berstatus Diajukan.');
        }
        $this->redirect('registrations/review/' . $id);
    }

    public function reject() {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('registrations');

        $registrations = $this->model('RegistrationModel');
        $id = (int) ($_POST['id'] ?? 0);
        $reg = $registrations->findById($id);
        if (!$reg) $this->notFound('Pengajuan tidak ditemukan.');
        $this->requireScope($reg['target_structure_id']);

        $note = trim($_POST['review_note'] ?? '');
        if ($note === '') {
            Flash::set('error', 'Alasan penolakan wajib diisi, supaya keputusannya bisa dipertanggungjawabkan.');
            $this->redirect('registrations/review/' . $id);
        }

        if ($registrations->reject($id, $note)) {
            Flash::set('success', 'Pengajuan ditolak.');
            $wa = WaNotify::registrationDecision($reg, 'rejected', $note);
            if (empty($wa['ok'])) {
                Flash::set('warn', !empty($wa['skipped'])
                    ? 'Status berubah, tetapi pesan WhatsApp tidak dikirim. Periksa konfigurasi WA Gateway dan nomor pendaftar.'
                    : 'Status berubah, tetapi pengiriman WhatsApp gagal. Periksa Log WhatsApp.');
            }
        } else {
            Flash::set('error', 'Status pengajuan sudah berubah; tidak ada pesan yang dikirim.');
        }
        $this->redirect('registrations');
    }

    // Aktivasi: pengajuan berubah menjadi data master + satu baris penempatan.
    public function activate() {
        $this->requireLogin();
        if (!Auth::canVerify()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('registrations');

        $registrations = $this->model('RegistrationModel');
        $id = (int) ($_POST['id'] ?? 0);
        $reg = $registrations->findById($id);
        if (!$reg) $this->notFound('Pengajuan tidak ditemukan.');
        $this->requireScope($reg['target_structure_id']);

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term || (int) $term['structure_id'] !== (int) $reg['target_structure_id']) {
            Flash::set('error', 'Periode yang dipilih bukan milik struktur tujuan pendaftaran ini.');
            $this->redirect('registrations/review/' . $id);
        }

        // 'existing' = verifikator menyatakan pendaftar ini orang yang SUDAH ada di database.
        // Keputusan ini tidak pernah diambil otomatis oleh sistem, hanya diusulkan.
        $personId = null;
        if (($_POST['person_mode'] ?? 'new') === 'existing') {
            $personId = (int) ($_POST['existing_person_id'] ?? 0);
            if ($personId === 0) {
                Flash::set('error', 'Pilih orang yang dimaksud, atau pilih "daftarkan sebagai orang baru".');
                $this->redirect('registrations/review/' . $id);
            }
        }

        try {
            $result = $registrations->activate($id, $personId, [
                'term_id'           => $termId,
                'structure_id'      => (int) $reg['target_structure_id'],
                'structure_unit_id' => !empty($_POST['structure_unit_id']) ? (int) $_POST['structure_unit_id'] : null,
                'position_id'       => (int) ($_POST['position_id'] ?? 0),
                'is_primary'        => !empty($_POST['is_primary']),
            ], $_POST['gender'] ?? null);
            Flash::set('success', 'Pendaftar diaktifkan. Catatan: status Aktif di sini TIDAK otomatis '
                . 'membuatkan akun di syuro atau aplikasi lain -- itu langkah terpisah.');
            $this->redirect('people/detail/' . $result['person_id']);
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('registrations/review/' . $id);
        }
    }
}
