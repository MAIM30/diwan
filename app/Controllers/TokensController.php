<?php
// PATH LOKASI FILE: app/Controllers/TokensController.php
// Token aplikasi konsumen API. Hanya Super Admin: token di sini bisa membaca data seluruh
// organisasi, jadi penerbitannya tidak diturunkan ke admin struktur.

class TokensController extends Controller {
    public function index() {
        $this->requireLogin(['super_admin']);
        $clients = $this->model('ApiClientModel');
        $structures = $this->model('StructureModel');

        $this->view('admin/tokens/index', [
            'title'      => 'Token Aplikasi Konsumen',
            'rows'       => $clients->all(),
            'structures' => $structures->allOrdered(),
            'scopeList'  => ApiClientModel::SCOPES,
            // Token utuh hanya tersedia sesaat setelah dibuat/dirotasi, dibawa lewat sesi sekali
            // pakai. Setelah halaman ini ditutup, tidak ada cara menampilkannya lagi.
            'freshToken' => $this->takeFreshToken(),
        ]);
    }

    public function create() {
        $this->requireLogin(['super_admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('tokens');

        $clients = $this->model('ApiClientModel');
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            Flash::set('error', 'Nama aplikasi konsumen wajib diisi.');
            $this->redirect('tokens');
        }

        $result = $clients->create([
            'name'               => $name,
            'scopes'             => (array) ($_POST['scopes'] ?? []),
            'scope_structure_id' => $_POST['scope_structure_id'] ?? null,
            'rate_limit_per_min' => $_POST['rate_limit_per_min'] ?? 60,
            'expires_at'         => $_POST['expires_at'] ?? '',
        ]);

        $_SESSION['_fresh_token'] = ['name' => $name, 'token' => $result['token']];
        Flash::set('success', 'Token dibuat. Salin sekarang -- nilainya tidak bisa ditampilkan lagi setelah halaman ini.');
        $this->redirect('tokens');
    }

    public function update() {
        $this->requireLogin(['super_admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('tokens');

        $clients = $this->model('ApiClientModel');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$clients->findById($id)) $this->notFound('Token tidak ditemukan.');

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            Flash::set('error', 'Nama aplikasi konsumen wajib diisi.');
            $this->redirect('tokens');
        }

        $clients->updateScopes($id, [
            'name'               => $name,
            'scopes'             => (array) ($_POST['scopes'] ?? []),
            'scope_structure_id' => $_POST['scope_structure_id'] ?? null,
            'rate_limit_per_min' => $_POST['rate_limit_per_min'] ?? 60,
            'expires_at'         => $_POST['expires_at'] ?? '',
        ]);
        Flash::set('success', 'Pengaturan token diperbarui.');
        $this->redirect('tokens');
    }

    public function rotate() {
        $this->requireLogin(['super_admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('tokens');

        $clients = $this->model('ApiClientModel');
        $id = (int) ($_POST['id'] ?? 0);
        $client = $clients->findById($id);
        if (!$client) $this->notFound('Token tidak ditemukan.');

        $plain = $clients->rotate($id);
        $_SESSION['_fresh_token'] = ['name' => $client['name'], 'token' => $plain];
        Flash::set('success', 'Token baru diterbitkan. Token lama LANGSUNG tidak berlaku -- '
            . 'pasang yang baru di aplikasi konsumen sekarang.');
        $this->redirect('tokens');
    }

    public function revoke() {
        $this->requireLogin(['super_admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('tokens');

        $clients = $this->model('ApiClientModel');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$clients->findById($id)) $this->notFound('Token tidak ditemukan.');

        $clients->revoke($id);
        Flash::set('success', 'Token dicabut.');
        $this->redirect('tokens');
    }

    public function log($id) {
        $this->requireLogin(['super_admin']);
        $clients = $this->model('ApiClientModel');
        $client = $clients->findById($id);
        if (!$client) $this->notFound('Token tidak ditemukan.');

        $this->view('admin/tokens/log', [
            'title'  => 'Log Pemakaian: ' . $client['name'],
            'client' => $client,
            'rows'   => $clients->recentLog($id, 100),
        ]);
    }

    private function takeFreshToken() {
        $t = $_SESSION['_fresh_token'] ?? null;
        unset($_SESSION['_fresh_token']);
        return $t;
    }
}
