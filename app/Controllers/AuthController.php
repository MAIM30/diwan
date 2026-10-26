<?php
// PATH LOKASI FILE: app/Controllers/AuthController.php

class AuthController extends Controller {
    public function index() {
        $this->login();
    }

    public function login() {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        $this->view('auth/login', ['title' => 'Masuk']);
    }

    public function login_process() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('auth/login');
        }

        require_once ROOT_DIR . '/app/Core/LoginThrottle.php';
        $throttle = new LoginThrottle();

        // Nama pengguna: email (mengandung '@') atau nomor WhatsApp. Kolom formulir bernama 'username';
        // 'phone' tetap diterima supaya klien/tes lama tidak patah.
        $ident = trim((string) ($_POST['username'] ?? $_POST['phone'] ?? ''));
        $password = $_POST['password'] ?? '';
        $accounts = $this->model('AccountModel');

        $isEmail = strpos($ident, '@') !== false;
        if ($isEmail) {
            $email = mb_strtolower($ident);
            // Kunci pembatas: login_attempts.phone hanya 20 karakter, sedangkan email bisa lebih panjang.
            $key = 'e:' . substr(sha1($email), 0, 18);
            $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        } else {
            $key = Text::normalizePhone($ident);
            $valid = $key !== null;
        }

        if (!$valid || $password === '') {
            Flash::set('error', 'Email atau nomor WhatsApp, dan kata sandi, wajib diisi dengan benar.');
            $this->redirect('auth/login');
        }

        if ($throttle->isLocked($key)) {
            Flash::set('error', 'Terlalu banyak percobaan gagal. Coba lagi dalam '
                . LoginThrottle::WINDOW_MINUTES . ' menit.');
            $this->redirect('auth/login');
        }

        $account = $isEmail ? $accounts->findByEmail($email) : $accounts->findByPhone($key);

        // Pesan gagal sengaja SAMA untuk nama pengguna tidak terdaftar, kata sandi salah, dan akun
        // ditangguhkan: perbedaan pesan akan jadi cara menguji siapa yang punya akun di sini.
        if (!$account || !$accounts->verifyPassword($account, $password) || $account['status'] !== 'active') {
            $throttle->record($key, false);
            $sisa = $throttle->remaining($key);
            Flash::set('error', 'Nama pengguna atau kata sandi tidak cocok.'
                . ($sisa > 0 && $sisa <= 2 ? ' Sisa ' . $sisa . ' percobaan sebelum terkunci.' : ''));
            $this->redirect('auth/login');
        }

        $roles = $accounts->rolesOf($account['id']);
        if (empty($roles)) {
            $throttle->record($key, false);
            Flash::set('error', 'Akun Anda belum diberi peran. Hubungi administrator.');
            $this->redirect('auth/login');
        }

        $throttle->record($key, true);
        Auth::login($account, $roles);
        $accounts->touchLogin($account['id']);
        Audit::log('accounts', $account['id'], 'update', null, ['event' => 'login']);

        // Kata sandi sementara: langsung ke halaman ganti kata sandi.
        $this->redirect(Auth::mustChangePassword() ? 'sandi' : 'dashboard');
    }

    // POST saja (dan karena itu melewati verifikasi CSRF di App). Logout lewat GET bisa dipicu
    // orang lain lewat <img src> atau tautan, dan membuat pengguna keluar tanpa pernah memintanya.
    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('dashboard');
        }
        if (Auth::check()) {
            Audit::log('accounts', Auth::id(), 'update', null, ['event' => 'logout']);
        }
        Auth::logout(); // ID sesi sudah diganti: pesan di bawah dibawa oleh sesi baru itu
        Flash::set('success', 'Anda sudah keluar.');
        $this->redirect('auth/login');
    }
}
