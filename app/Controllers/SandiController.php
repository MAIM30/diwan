<?php
// PATH LOKASI FILE: app/Controllers/SandiController.php
// Ganti kata sandi sendiri (admin). Sengaja TIDAK memakai requireLogin(): halaman inilah satu-satunya
// yang boleh dibuka selama kata sandi masih sementara (akun baru, atau kata sandi yang ditetapkan admin).

class SandiController extends Controller {
    private function guard() {
        if (!Auth::check()) {
            Flash::set('error', 'Silakan masuk terlebih dahulu.');
            $this->redirect('auth/login');
        }
    }

    public function index() {
        $this->guard();
        $this->view('auth/sandi', [
            'title'  => 'Ganti Kata Sandi',
            'forced' => Auth::mustChangePassword(),
        ]);
    }

    public function simpan() {
        $this->guard();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('sandi');

        $accounts = $this->model('AccountModel');
        $account = $accounts->findById(Auth::id());
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        // Kata sandi sekarang diminta SELALU, juga saat kata sandi sementara: sesi yang ditinggalkan terbuka
        // di komputer bersama tidak boleh cukup untuk mengunci pemilik akun dari akunnya sendiri.
        if (!$account || !$accounts->verifyPassword($account, $current)) {
            Flash::set('error', 'Kata sandi sekarang tidak cocok.');
            $this->redirect('sandi');
        }
        $err = $accounts->checkNewPassword($account, $current, $new, $confirm);
        if ($err !== null) {
            Flash::set('error', $err);
            $this->redirect('sandi');
        }

        $accounts->changePassword($account['id'], $new);
        Auth::clearMustChange();
        session_regenerate_id(true);   // ID sesi baru setelah kredensial berubah
        Flash::set('success', 'Kata sandi diganti.');
        $this->redirect('dashboard');
    }
}
