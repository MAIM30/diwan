<?php
// PATH LOKASI FILE: app/Controllers/HomeController.php
// Halaman awal (alamat "/"). Hanya mengarahkan: admin ke dasbor admin, pengurus ke berandanya, dan
// pengunjung baru ke halaman masuk PENGURUS -- pengguna terbanyak aplikasi ini. Pengelola data
// masuk lewat /auth/login, yang ditautkan dari halaman masuk pengurus.

class HomeController extends Controller {
    public function index() {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        if (Member::check()) {
            $this->redirect('pengurus');
        }
        $this->redirect('pengurus/login');
    }
}
