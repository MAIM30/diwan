<?php
// PATH LOKASI FILE: app/Core/Controller.php

// Seluruh method di sini sengaja `protected`: selain App menyaring rute lewat ReflectionMethod,
// tingkat visibilitas ini jadi lapis kedua supaya tidak ada method bawaan yang bisa dipanggil
// sebagai aksi dari URL. Method View yang di-require dari dalam view() tetap berada di dalam
// cakupan kelas, jadi $this->view() untuk partial tetap bekerja.
class Controller {
    protected function view($view, $data = []) {
        extract($data);

        $viewPath = str_replace('/', DIRECTORY_SEPARATOR, $view);
        $fullPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Views'
                  . DIRECTORY_SEPARATOR . $viewPath . '.php';

        if (file_exists($fullPath)) {
            require $fullPath;
        } else {
            http_response_code(500);
            die('Gagal memuat halaman. File tidak ditemukan: <code>' . htmlspecialchars($view) . '</code>');
        }
    }

    protected function model($model) {
        $modelPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models'
                   . DIRECTORY_SEPARATOR . $model . '.php';

        if (file_exists($modelPath)) {
            require_once $modelPath;
            return new $model;
        }
        http_response_code(500);
        die("Model $model tidak ditemukan!");
    }

    // Redirect ke rute aplikasi (relatif terhadap BASEURL)
    protected function redirect($path = '') {
        header('Location: ' . BASEURL . '/' . ltrim($path, '/'));
        exit;
    }

    // Semua halaman admin memanggil ini di awal. Menolak yang belum login, dan -- bila peran
    // tertentu disyaratkan -- menolak yang perannya tidak cukup.
    protected function requireLogin($roles = null) {
        if (!Auth::check()) {
            Flash::set('error', 'Silakan masuk terlebih dahulu.');
            $this->redirect('auth/login');
        }
        // Kata sandi sementara harus diganti DULU. Halaman ganti kata sandi dan "keluar" tidak lewat sini.
        if (Auth::mustChangePassword()) {
            Flash::set('error', 'Kata sandi Anda masih sementara. Ganti dulu sebelum melanjutkan.');
            $this->redirect('sandi');
        }
        if ($roles !== null && !Auth::hasAnyRole((array) $roles)) {
            $this->denied();
        }
    }

    // Halaman Portal Pengurus. Menolak yang belum masuk (atau sesinya habis), dan melarang peramban
    // menyimpan halaman: datanya pribadi dan bisa dibuka di komputer bersama.
    protected function requireMember() {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        if (!Member::check()) {
            $this->redirect('pengurus/login');
        }
    }

    protected function denied($message = 'Anda tidak punya hak akses untuk tindakan ini.') {
        http_response_code(403);
        $this->view('errors/403', ['message' => $message]);
        exit;
    }

    protected function notFound($message = 'Data tidak ditemukan.') {
        http_response_code(404);
        $this->view('errors/404', ['message' => $message]);
        exit;
    }

    // Menolak akses ke struktur di luar lingkup admin yang sedang login. Dipanggil di SETIAP aksi
    // yang menyentuh data milik satu struktur -- satu-satunya tempat keputusan itu dibuat.
    protected function requireScope($structureId) {
        if (!AdminScope::allows($structureId)) {
            $this->denied('Struktur tersebut berada di luar lingkup akses Anda.');
        }
    }
}
