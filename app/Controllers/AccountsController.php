<?php
// PATH LOKASI FILE: app/Controllers/AccountsController.php
// Manajemen akun pengelola data + peran/lingkupnya.

class AccountsController extends Controller {
    public function index() {
        $this->requireLogin(['super_admin', 'admin_struktur']);
        $accounts = $this->model('AccountModel');

        $this->view('admin/accounts/index', [
            'title' => 'Akun Pengelola',
            'rows'  => $accounts->listManageable(),
        ]);
    }

    public function form($id = null) {
        $this->requireLogin(['super_admin', 'admin_struktur']);

        $accounts = $this->model('AccountModel');
        $structures = $this->model('StructureModel');
        $row = null;
        $roles = [];
        $suggestName = '';

        // Datang dari tombol "Buat admin" di halaman Struktur: peran dan lingkup sudah terisi. Hanya struktur di
        // dalam lingkup pemanggil yang diterima -- id lain diabaikan, bukan ditolak, supaya tautan lama tak galat.
        if (!$id && isset($_GET['scope'])) {
            $s = $structures->findById((int) $_GET['scope']);
            if ($s && AdminScope::allows($s['id'])) {
                $roles = [['role' => 'admin_struktur', 'scope_structure_id' => (int) $s['id']]];
                $suggestName = AdminNaming::displayName($s['level'], $s['name']);
            }
        }

        if ($id) {
            $row = $accounts->findById($id);
            if (!$row) $this->notFound('Akun tidak ditemukan.');
            $roles = $accounts->rolesOf($id);

            // Admin non-super tidak boleh membuka akun yang lingkupnya di luar lingkupnya sendiri.
            if (!AdminScope::isUnbounded()) {
                foreach ($roles as $r) {
                    if (empty($r['scope_structure_id']) || !AdminScope::allows($r['scope_structure_id'])) {
                        $this->denied('Akun ini punya lingkup di luar lingkup akses Anda.');
                    }
                }
            }
        }

        $this->view('admin/accounts/form', [
            'people'     => $this->model('PersonModel')->optionsInScope(),
            'title'      => $id ? 'Ubah Akun' : 'Tambah Akun',
            'row'        => $row,
            'roles'      => $roles,
            'structures' => $structures->allInScope(),
            'canGrantSuper' => AdminScope::isUnbounded(),
            'suggestName' => $suggestName,
        ]);
    }

    public function save() {
        $this->requireLogin(['super_admin', 'admin_struktur']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('accounts');

        $accounts = $this->model('AccountModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        if ($id && !AdminScope::isUnbounded()) {
            foreach ($accounts->rolesOf($id) as $r) {
                if (empty($r['scope_structure_id']) || !AdminScope::allows($r['scope_structure_id'])) {
                    $this->denied('Akun ini punya lingkup di luar lingkup akses Anda.');
                }
            }
        }

        $data = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'phone'        => trim($_POST['phone'] ?? ''),
            'email'        => trim($_POST['email'] ?? ''),
            'password'     => $_POST['password'] ?? '',
            'person_id'    => $_POST['person_id'] ?? null,
            'status'       => ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
            'must_change_password' => !empty($_POST['must_change_password']),
        ];

        if ($data['display_name'] === '') {
            Flash::set('error', 'Nama tampilan wajib diisi.');
            $this->redirect('accounts/form' . ($id ? '/' . $id : ''));
        }
        if ($data['password'] !== '' && strlen($data['password']) < 10) {
            Flash::set('error', 'Kata sandi minimal 10 karakter.');
            $this->redirect('accounts/form' . ($id ? '/' . $id : ''));
        }

        // Susun daftar peran dari input. Penjagaan eskalasi haknya ada di AccountModel::setRoles(),
        // supaya aturannya hanya hidup di satu tempat.
        $roles = [];
        foreach ((array) ($_POST['role'] ?? []) as $i => $role) {
            if (!in_array($role, ['super_admin','admin_struktur','verifikator','viewer'], true)) continue;
            $scope = $_POST['scope_structure_id'][$i] ?? null;
            $roles[] = ['role' => $role, 'scope_structure_id' => $scope];
        }

        if (empty($roles)) {
            Flash::set('error', 'Akun harus punya setidaknya satu peran, kalau tidak ia tidak akan bisa masuk.');
            $this->redirect('accounts/form' . ($id ? '/' . $id : ''));
        }

        try {
            $accounts->saveWithRoles($id, $data, $roles);
            Flash::set('success', 'Akun disimpan.');
            $this->redirect('accounts');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('accounts/form' . ($id ? '/' . $id : ''));
        }
    }
}
