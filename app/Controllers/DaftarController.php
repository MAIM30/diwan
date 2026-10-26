<?php
// PATH LOKASI FILE: app/Controllers/DaftarController.php
// Formulir pendaftaran publik (tanpa login).
//
// Formulir ini TIDAK terhubung ke akses aplikasi mana pun. Yang dihasilkannya hanya satu baris
// pengajuan yang menunggu verifikasi manusia -- tidak ada akun yang dibuat, tidak ada data master
// yang berubah, dan tidak ada apa pun yang dikirim ke syuro.

class DaftarController extends Controller {
    public function index() {
        $structures = $this->model('StructureModel');

        $this->view('public/daftar', [
            'title'      => 'Pendaftaran Data Pengurus',
            'structures' => $structures->allOrdered(),
        ]);
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
            $registrations->submit([
                'title_prefix'        => $_POST['title_prefix'] ?? '',
                'name'                => $_POST['name'] ?? '',
                'title_suffix'        => $_POST['title_suffix'] ?? '',
                'gender'              => ($_POST['gender'] ?? '') === 'P' ? 'P' : 'L',
                'phone'               => $phone,
                'email'               => '',
                'birth_date'          => '',
                'nik'                 => '',
                'notes'               => '',
                'target_structure_id' => $structureId,
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

        $this->view('public/daftar_sukses', ['title' => 'Pendaftaran Terkirim']);
    }
}
