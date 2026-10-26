<?php
// PATH LOKASI FILE: app/Core/App.php
// Router sederhana: segmen URL pertama jadi nama Controller, kedua jadi nama method, sisanya
// jadi parameter. Pola yang sama dengan syuro supaya dua aplikasi ini tetap enak dirawat bersama.

class App {
    protected $controller = 'HomeController';
    protected $method = 'index';
    protected $params = [];

    public function __construct() {
        $url = $this->parseURL();

        // Rute API ditangani lebih dulu dan TIDAK melewati proteksi CSRF berbasis sesi: API ini
        // read-only (hanya GET) dan diautentikasi lewat token Bearer per aplikasi konsumen, bukan
        // lewat cookie sesi -- jadi tidak ada permukaan serangan CSRF yang perlu dijaga di sini.
        if (($url[0] ?? '') === 'api') {
            require_once ROOT_DIR . '/app/Core/Api.php';
            require_once ROOT_DIR . '/app/Controllers/ApiController.php';
            $controller = new ApiController();
            $controller->dispatch(array_slice($url, 1));
            return;
        }

        // Proteksi CSRF terpusat: semua request selain GET/HEAD/OPTIONS wajib membawa token sesi valid
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true) && !Csrf::verify()) {
            Csrf::reject();
        }

        // 1. Cek apakah file Controller-nya ada
        if (isset($url[0]) && $url[0] !== '') {
            $controllerName = ucwords($url[0]) . 'Controller';
            if (preg_match('/^[A-Za-z]+Controller$/', $controllerName)
                && file_exists(ROOT_DIR . '/app/Controllers/' . $controllerName . '.php')) {
                $this->controller = $controllerName;
                unset($url[0]);
            }
        }

        // 2. Muat file Controller
        require_once ROOT_DIR . '/app/Controllers/' . $this->controller . '.php';
        $this->controller = new $this->controller;

        // 3. Cek apakah method di dalam Controller tersebut ada DAN memang sebuah aksi rute.
        //    method_exists() saja tidak cukup: ia juga cocok dengan method warisan dari kelas
        //    Controller (view, model, redirect, ...). Tanpa penyaringan ini, URL seperti
        //    /terms/view/auth akan memanggil Controller::view('auth') dan merender file View
        //    sembarang sebagai halaman. Jadi yang boleh dirutekan hanya method publik yang
        //    DIDEKLARASIKAN di kelas controller itu sendiri.
        if (isset($url[1]) && $this->isRoutableAction($this->controller, $url[1])) {
            $this->method = $url[1];
            unset($url[1]);
        }

        // 4. Ambil parameter jika ada
        if (!empty($url)) {
            $this->params = array_values($url);
        }

        // 5. Jalankan. Controller tanpa aksi bawaan `index` (mis. UnitsController, PlacementsController)
        //    yang dibuka tanpa nama aksi -- /units -- bukan galat server: halamannya memang tidak ada.
        //    Tanpa pemeriksaan ini call_user_func_array melempar TypeError (500, dan di server dengan
        //    display_errors menyala menampilkan stack trace berikut path file ke pengunjung).
        //
        //    Aksi yang MEWAJIBKAN parameter (mis. /people/detail/{id}) juga diperiksa di sini. Dibuka
        //    tanpa id -- /people/detail -- itu URL yang memang tidak ada, bukan galat server; tanpa
        //    pemeriksaan arity PHP melempar ArgumentCountError sebelum badan method jalan, sehingga
        //    pemeriksaan login di dalamnya pun tidak pernah sempat dieksekusi dan jejak galat
        //    (berikut path berkas di server) tampil ke pengunjung yang belum masuk sekalipun.
        if (!$this->isRoutableAction($this->controller, $this->method)
            || !$this->hasEnoughParams($this->controller, $this->method, $this->params)) {
            http_response_code(404);
            $message = 'Halaman tidak ditemukan.';
            require ROOT_DIR . '/app/Views/errors/404.php';
            return;
        }
        call_user_func_array([$this->controller, $this->method], $this->params);
    }

    private function hasEnoughParams($controller, $method, array $params) {
        try {
            $ref = new ReflectionMethod($controller, $method);
        } catch (ReflectionException $e) {
            return false;
        }
        return count($params) >= $ref->getNumberOfRequiredParameters();
    }

    private function isRoutableAction($controller, $method) {
        if (!method_exists($controller, $method)) {
            return false;
        }
        try {
            $ref = new ReflectionMethod($controller, $method);
        } catch (ReflectionException $e) {
            return false;
        }
        return $ref->isPublic()
            && !$ref->isStatic()
            && $ref->getDeclaringClass()->getName() !== 'Controller';
    }

    // Memecah dan membersihkan URL
    public function parseURL() {
        if (isset($_GET['url'])) {
            $url = rtrim($_GET['url'], '/');
            $url = filter_var($url, FILTER_SANITIZE_URL);
            return explode('/', $url);
        }
        return [];
    }
}
