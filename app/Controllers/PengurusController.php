<?php
// PATH LOKASI FILE: app/Controllers/PengurusController.php
// Portal Pengurus: pengurus melihat data kepengurusannya sendiri.
//
// Alur masuk: nomor WA → OTP → sesi. Gateway WhatsApp aktif (Whatsapp::isActive): kode dikirim ke WhatsApp pengurus.
// Gateway TIDAK aktif: pengurus diarahkan mengirim chat ke admin (PORTAL_FALLBACK_WA), yang memberinya kode umum (PORTAL_FALLBACK_CODE);
// kode umum hanya diterima selama gateway tidak aktif. Tak ada jalan masuk tanpa kode.

class PengurusController extends Controller {

    private function noStore() {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
    }

    // Gateway WhatsApp aktif? Menentukan cara kode diterima (dikirim ke WhatsApp, atau minta ke admin) dan kapan kode umum berlaku.
    // Dipisah supaya uji bisa mengaturnya.
    protected function gatewayActive() {
        return Whatsapp::isActive();
    }

    // Mengirim kode ke WhatsApp orang itu. Dipisah supaya uji bisa mengganti pengirimnya.
    protected function sendOtp(array $person, $code) {
        return Whatsapp::sendOtp($person['phone'] ?? '', $person['name'] ?? '', $person['gender'] ?? 'L', $code);
    }

    // Membuka sesi portal bagi pengurus yang sudah lolos semua pemeriksaan masuk, termasuk kode OTP. $via: lihat MemberModel::recordLogin.
    private function enter($members, array $person, $via = null) {
        unset($_SESSION['otp_pending']);
        Member::login($person);
        $members->recordLogin($person['id'], $via);
        $this->redirect('pengurus');
    }

    // ---------- Masuk / keluar ----------

    public function login() {
        $this->noStore();
        if (Member::check()) {
            $this->redirect('pengurus');
        }
        $this->view('pengurus/login', ['title' => 'Masuk Pengurus']);
    }

    public function login_process() {
        $this->noStore();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('pengurus/login');
        }

        if (!PORTAL_ENABLED) {
            Flash::set('error', 'Portal Pengurus sedang tidak tersedia. Silakan coba lagi nanti.');
            $this->redirect('pengurus/login');
        }

        $throttle = new MemberThrottle();
        if ($throttle->isLocked()) {
            Flash::set('error', 'Terlalu banyak percobaan gagal. Silakan coba lagi nanti.');
            $this->redirect('pengurus/login');
        }

        $raw = trim((string) ($_POST['wa'] ?? ''));
        $digits = preg_replace('/\D+/', '', $raw);
        $members = $this->model('MemberModel');
        if (strlen($digits) === 16) {
            $person = $members->findEligibleByNik($digits);
        } else {
            if (Text::normalizePhone($raw) === null) {
                Flash::set('error', 'Nomor WhatsApp tidak valid. Tulis seperti 08123456789.');
                $this->redirect('pengurus/login');
            }
            $person = $members->findEligibleByPhone($raw);
        }

        if (!$person) {
            $throttle->recordFailure();
            $_SESSION['daftar_phone'] = $raw;
            Flash::set('info', 'Nomor ini belum tercatat sebagai pengurus. Silakan daftarkan diri Anda terlebih dahulu.');
            $this->redirect('daftar');
        }

        $pending = ['person_id' => (int) $person['id'], 'phone' => (string) ($person['phone'] ?? '')];

        // Tanpa gateway aktif kode tak bisa dikirim: halaman OTP mengarahkan pengurus mengirim chat ke admin, yang memberinya kode umum.
        // Tak ada kode yang diterbitkan atau dikirim; yang berlaku hanya kode umum, dan hanya selama gateway tetap tidak aktif.
        if (!$this->gatewayActive()) {
            $_SESSION['otp_pending'] = $pending + ['issued_at' => time(), 'via_admin' => true];
            $this->redirect('pengurus/otp');
        }

        // Kode yang baru saja dikirim ke orang ini masih berlaku: jangan kirim lagi (pembatas, lihat Otp), pakai yang itu.
        $ago = Otp::lastIssuedAgo($person['id']);
        if ($ago !== null && $ago < Otp::RESEND_AFTER) {
            $_SESSION['otp_pending'] = $pending + ['issued_at' => time() - $ago];
            Flash::set('info', 'Kode sudah dikirim ke WhatsApp Anda. Tunggu semenit sebelum meminta kode baru.');
            $this->redirect('pengurus/otp');
        }
        if (Otp::ipLimited()) {
            Flash::set('error', 'Terlalu banyak permintaan kode dari jaringan ini. Silakan coba lagi nanti.');
            $this->redirect('pengurus/login');
        }

        $code = Otp::issue($person['id'], $person['phone'] ?? '');
        $sent = $this->sendOtp($person, $code);
        if (empty($sent['ok'])) {
            Otp::revoke($person['id']);   // tak ada yang memegang kode ini; jangan biarkan ia menahan permintaan berikutnya
            Flash::set('error', 'Kode OTP gagal dikirim ke WhatsApp. Silakan coba lagi sebentar lagi.');
            $this->redirect('pengurus/login');
        }

        $_SESSION['otp_pending'] = $pending + ['issued_at' => time()];
        $this->redirect('pengurus/otp');
    }

    public function otp() {
        $this->noStore();
        if (Member::check()) $this->redirect('pengurus');
        if (empty($_SESSION['otp_pending'])) $this->redirect('pengurus/login');

        $this->view('pengurus/otp', [
            'title'        => 'Verifikasi OTP',
            'maskedPhone'  => Text::maskPhone($_SESSION['otp_pending']['phone'] ?? ''),
            'viaAdmin'     => !empty($_SESSION['otp_pending']['via_admin']),
            'adminChatUrl' => Otp::adminChatUrl(),
        ]);
    }

    public function otp_verify() {
        $this->noStore();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('pengurus/login');
        if (empty($_SESSION['otp_pending'])) $this->redirect('pengurus/login');

        $throttle = new MemberThrottle();
        if ($throttle->isLocked()) {
            unset($_SESSION['otp_pending']);
            Flash::set('error', 'Terlalu banyak percobaan gagal. Silakan coba lagi nanti.');
            $this->redirect('pengurus/login');
        }

        $pending  = $_SESSION['otp_pending'];
        $code     = trim((string) ($_POST['code'] ?? ''));
        $viaAdmin = !empty($pending['via_admin']);

        // Halaman "minta kode ke admin" hanya punya arti selama gateway mati. Begitu gateway aktif lagi tak ada kode yang dikirim ke
        // orang ini, dan kode umum tak berlaku lagi: suruh masuk ulang supaya kode dikirim lewat WhatsApp.
        if ($viaAdmin && $this->gatewayActive()) {
            unset($_SESSION['otp_pending']);
            Flash::set('info', 'WhatsApp otomatis sudah aktif kembali. Silakan masuk ulang untuk menerima kode OTP lewat WhatsApp.');
            $this->redirect('pengurus/login');
        }

        if (time() - (int) $pending['issued_at'] > ($viaAdmin ? Otp::ADMIN_TTL : Otp::TTL)) {
            unset($_SESSION['otp_pending']);
            Flash::set('error', 'Kode OTP sudah kedaluwarsa. Silakan masuk ulang.');
            $this->redirect('pengurus/login');
        }

        // Kode umum hanya berlaku selama gateway tidak aktif (diperiksa SAAT INI, bukan saat masuk dimulai). Pemeriksaan gateway hanya
        // dijalankan bila kode yang diketik memang kode umum, supaya kode biasa tak menunggu gateway.
        $personId = (int) $pending['person_id'];
        $general  = Otp::isGeneralCode($code) && !$this->gatewayActive();
        if (!$general && !Otp::verify($personId, $code)) {
            $throttle->recordFailure();
            Flash::set('error', $viaAdmin
                ? 'Kode OTP tidak valid. Minta kode kepada admin lewat tombol WhatsApp di halaman ini.'
                : 'Kode OTP tidak valid. Periksa kembali kode yang dikirim ke WhatsApp Anda.');
            $this->redirect('pengurus/otp');
        }

        unset($_SESSION['otp_pending']);
        $members = $this->model('MemberModel');
        $person  = $members->findEligibleById($personId);
        if (!$person) {
            Flash::set('error', 'Akses tidak lagi tersedia.');
            $this->redirect('pengurus/login');
        }

        $this->enter($members, $person, $general ? 'kode_umum' : null);
    }

    // POST + CSRF (diverifikasi App): keluar lewat GET bisa dipicu orang lain lewat <img src>.
    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('pengurus');
        }
        Member::logout(); // sesi sudah diganti ID-nya: pesan di bawah dibawa oleh sesi baru itu
        Flash::set('success', 'Anda sudah keluar.');
        $this->redirect('pengurus/login');
    }

    // ---------- Halaman (semuanya bertumpu pada sesi) ----------

    // Pintu masuk setiap halaman. Memeriksa ULANG kelayakan di basis data pada setiap permintaan:
    // bila admin mencabut akses, menonaktifkan, atau menghapus orang ini, sesi yang masih hidup
    // pun ikut mati saat itu juga -- bukan baru setelah kedaluwarsa.
    private function guard() {
        $this->requireMember();
        $members = $this->model('MemberModel');
        $person = $members->findEligibleById(Member::id());

        if (!$person) {
            Member::logout();
            Flash::set('error', 'Akses Anda ke Portal Pengurus tidak lagi tersedia. Hubungi admin struktur Anda.');
            $this->redirect('pengurus/login');
        }
        Member::touch();
        // Orang yang belum punya foto profil tampil dengan foto biodatanya (lihat Ui::avatar).
        $person['biodata_photo_id'] = $this->model('BiodataModel')->photoFileId($person['id']);
        return [$members, $person];
    }

    // Beranda: jabatan utama dan sisa waktunya, Biodata, jabatan lain, data diri, rekan, perjalanan, dan riwayat masuk.
    // Jabatan utama = yang pertama setelah PortalHome::orderCurrent (Pengurus Inti dulu, lalu periode terbaru).
    public function index() {
        [$members, $person] = $this->guard();
        $pid = (int) $person['id'];

        $history = $members->history($pid);
        $current = PortalHome::orderCurrent($members->currentOf($history));
        $main = $current[0] ?? null;

        $this->view('pengurus/index', [
            'title'           => 'Beranda',
            'person'          => $person,
            'history'         => $history,
            'current'         => $current,
            'lives'           => array_map(function ($c) { return MemberModel::life($c); }, $current),
            'chain'           => $main ? $members->structureChain($main['structure_id']) : [],
            'colleagues'      => $main ? $members->colleagues($main, $pid, PortalHome::COLLEAGUES_MAX) : [],
            'colleaguesTotal' => $main ? $members->colleaguesCount($main, $pid) : 0,
            'data'            => $members->completeness($person),
            'logins'          => $members->loginHistory($pid, 6),
            'biodata'         => $this->model('BiodataModel')->findByPersonId($pid),
        ]);
    }

    // Susunan tempat saya menjabat: pimpinan (kluster) + rekan lainnya. Hanya nama + jabatan.
    public function susunan() {
        [$members, $person] = $this->guard();

        $history = $members->history($person['id']);
        $current = $members->currentOf($history);

        $rosters = [];
        foreach ($current as $c) {
            $rosters[] = $members->colleagues($c, $person['id']);
        }

        $this->view('pengurus/susunan', [
            'title'   => 'Susunan',
            'person'  => $person,
            'current' => $current,
            'rosters' => $rosters,
        ]);
    }

    // Riwayat jabatan: linimasa (Gantt) + daftar. Penyaring lewat parameter ?f= (bukan JavaScript),
    // jadi halaman tetap berfungsi tanpa JS dan tautannya bisa dibagikan.
    public function riwayat() {
        [$members, $person] = $this->guard();
        $history = $members->history($person['id']);
        $today = date('Y-m-d');

        $isNow = function ($h) use ($today) { return MemberModel::isNow($h, $today); };
        $counts = ['semua' => count($history), 'berjalan' => 0, 'arsip' => 0];
        foreach ($history as $h) $counts[$isNow($h) ? 'berjalan' : 'arsip']++;

        $f = $_GET['f'] ?? 'semua';
        if (!isset($counts[$f])) $f = 'semua';
        $shown = array_values(array_filter($history, function ($h) use ($f, $isNow) {
            return $f === 'semua' || ($f === 'berjalan') === $isNow($h);
        }));

        // Baris linimasa: tanggal akhir kosong dianggap "sampai hari ini" supaya batangnya tetap tergambar.
        $rows = [];
        foreach ($shown as $h) {
            [$start, $end] = MemberModel::range($h);
            if (!$start) continue;
            $rows[] = [
                'label'   => $h['position_name'] . ' · ' . $h['level'],   // ringkas di grafik; tingkat membedakan baris
                'title'   => $h['position_name'],                          // penuh di tooltip & tabel
                'sub'     => trim($h['level'] . ' ' . $h['structure_name'] . ($h['unit_name'] ? ' · ' . $h['unit_name'] : '')),
                'start'   => $start,
                'end'     => $end && $end >= $start ? $end : max($start, $today),
                'current' => $isNow($h),
            ];
        }

        $this->view('pengurus/riwayat', [
            'title'    => 'Riwayat Jabatan',
            'person'   => $person,
            'history'  => $shown,
            'counts'   => $counts,
            'filter'   => $f,
            'gantt'    => Chart::gantt($rows, 'Linimasa jabatan: ' . count($rows) . ' penempatan'),
        ]);
    }

    // ---------- Ubah data sendiri ----------

    public function ubah() {
        [, $person] = $this->guard();
        $this->view('pengurus/ubah', ['title' => 'Ubah Data Saya', 'person' => $person, 'errors' => [], 'old' => []]);
    }

    public function ubah_process() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('pengurus/ubah');
        [$members, $person] = $this->guard();

        // Batas kewajaran: kiriman beruntun hampir pasti otomatis atau iseng, bukan orang yang merapikan datanya.
        if ($members->selfEditCountSince($person['id'], 60) >= MemberModel::SELF_EDITS_PER_HOUR) {
            Flash::set('error', 'Terlalu banyak perubahan dalam satu jam terakhir. Coba lagi nanti.');
            $this->redirect('pengurus/ubah');
        }

        $result = $members->updateOwnProfile($person, $_POST);
        if ($result['errors']) {
            // Ditampilkan ulang di halaman yang sama dengan isian yang baru diketik (yang kosong tetap kosong).
            $this->view('pengurus/ubah', ['title' => 'Ubah Data Saya', 'person' => $person, 'errors' => $result['errors'], 'old' => $_POST]);
            return;
        }
        if (!$result['changed']) {
            Flash::set('info', 'Tidak ada yang berubah.');
            $this->redirect('pengurus/ubah');
        }
        Flash::set('success', 'Data diperbarui: ' . implode(', ', array_map('mb_strtolower', array_intersect_key(Ui::PROFILE_LABELS, array_flip($result['changed'])))) . '.');
        $this->redirect('pengurus/data');
    }

    public function data() {
        [$members, $person] = $this->guard();

        $this->view('pengurus/data', [
            'title'   => 'Data Saya',
            'person'  => $person,
            'members' => $members,
        ]);
    }

    // Pengurus hanya dapat melaporkan penempatan yang tampil sebagai jabatan saat ini miliknya.
    public function koreksi() {
        [$members, $person] = $this->guard();
        $current = $members->currentOf($members->history($person['id']));
        $this->view('pengurus/koreksi', [
            'title' => 'Pengajuan Koreksi',
            'person' => $person,
            'current' => $current,
            'requests' => $this->model('PositionCorrectionModel')->listForPerson($person['id']),
        ]);
    }

    public function koreksi_submit() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('pengurus/koreksi');
        [, $person] = $this->guard();

        try {
            $id = $this->model('PositionCorrectionModel')->submitForPerson(
                $person['id'],
                $_POST['placement_id'] ?? 0,
                $_POST['category'] ?? '',
                $_POST['details'] ?? ''
            );
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('pengurus/koreksi');
        } catch (PDOException $e) {
            error_log('Pengajuan koreksi penempatan gagal: ' . $e->getMessage());
            Flash::set('error', 'Pengajuan belum dapat dikirim. Silakan coba lagi.');
            $this->redirect('pengurus/koreksi');
        }

        Audit::log('position_correction_requests', $id, 'create', null, [
            'person_id' => (int) $person['id'],
            'placement_id' => (int) ($_POST['placement_id'] ?? 0),
            'category' => in_array($_POST['category'] ?? '', ['placement', 'position'], true) ? $_POST['category'] : null,
        ]);
        Flash::set('success', 'Pengajuan terkirim. Admin struktur terkait akan memeriksanya.');
        $this->redirect('pengurus/koreksi');
    }

    // ---------- Biodata / Profil Singkat Pengurus ----------

    // Penempatan aktif pertama orang ini (untuk prefill Jabatan/Bidang) -- orang yang rangkap jabatan
    // cukup diwakili satu, sisanya bisa dikoreksi manual lewat jabatan_text/bidang_text.
    private function activePlacement($members, $personId) {
        $current = $members->currentOf($members->history($personId));
        return $current[0] ?? null;
    }

    private function biodataForm($members, $person, $existing, array $errors = []) {
        $this->view('pengurus/biodata', [
            'title'     => 'Biodata Pengurus',
            'person'    => $person,
            'placement' => $this->activePlacement($members, $person['id']),
            'existing'  => $existing,
            'commitmentTexts' => BiodataPdf::COMMITMENTS,
            'errors'    => $errors,
        ]);
    }

    public function biodata() {
        [$members, $person] = $this->guard();
        $this->biodataForm($members, $person, $this->model('BiodataModel')->findByPersonId($person['id']));
    }

    public function biodata_process() {
        [$members, $person] = $this->guard();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('pengurus/biodata');

        $model = $this->model('BiodataModel');
        $previous = $model->findByPersonId($person['id']);
        $result = $model->validate($_POST);
        $retry = array_merge($_POST, [
            'commitments'          => array_map('intval', $_POST['commitments'] ?? []),
            'photo_gdrive_file_id' => $previous['photo_gdrive_file_id'] ?? null,
        ]);

        if ($result['errors']) {
            $this->biodataForm($members, $person, $retry, $result['errors']);
            return;
        }

        // Foto opsional, disimpan di Google Drive dalam dua ukuran:
        //   - Arsip (kualitas tinggi, maks 2400px): disimpan di Drive sebagai foto resmi.
        //   - Kecil (maks 900px): dipakai PDF dan avatar di portal (ringan untuk HP).
        // Di database hanya ID berkas arsip. Saat avatar dilayani (biodata_foto), foto arsip di-download
        // dari Drive lalu dikecilkan on-the-fly dan di-cache peramban 24 jam.
        $photoBytes = null;
        $photoDriveId = null;
        $photoFile = $_FILES['photo'] ?? null;
        if ($photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if (!GoogleDrive::isConfigured()) {
                $this->biodataForm($members, $person, $retry, ['photo' => 'Unggah foto belum tersedia: Google Drive belum dihubungkan.']);
                return;
            }
            $err = Photo::fromUpload($photoFile, $tmpSmall = tempnam(sys_get_temp_dir(), 'fts'), false);
            if ($err !== null) {
                @unlink($tmpSmall);
                $this->biodataForm($members, $person, $retry, ['photo' => Photo::message($err)]);
                return;
            }
            $photoBytes = file_get_contents($tmpSmall);
            @unlink($tmpSmall);

            $errA = Photo::sanitizeForArchive($photoFile['tmp_name'], $tmpBig = tempnam(sys_get_temp_dir(), 'ftb'));
            $archiveBytes = $errA === null ? file_get_contents($tmpBig) : $photoBytes;
            @unlink($tmpBig);

            $up = GoogleDrive::uploadPhoto($archiveBytes, 'Foto_' . $person['name'] . '_' . $person['uuid'] . '.jpg');
            if (!$up['ok']) {
                $this->biodataForm($members, $person, $retry, ['photo' => 'Foto gagal disimpan ke Google Drive: ' . $up['error']]);
                return;
            }
            $photoDriveId = $up['file_id'];
        }

        $row = $model->upsert($person['id'], $result['data']);
        if ($photoDriveId !== null) {
            $model->setPhotoFile($row['id'], $photoDriveId);
            $row['photo_gdrive_file_id'] = $photoDriveId;
            if (!empty($previous['photo_gdrive_file_id'])) GoogleDrive::delete($previous['photo_gdrive_file_id']);
        }
        Audit::log('biodata_submissions', $row['id'], 'update', null,
            ['event' => 'portal_biodata', 'person_id' => $person['id'], 'photo_replaced' => $photoDriveId !== null]);

        if (GoogleDrive::isConfigured()) {
            $pdfBytes = BiodataPdf::render($person, $this->activePlacement($members, $person['id']), $row, $photoBytes);
            $filename = 'Biodata_' . $person['name'] . '_' . $row['uuid'] . '.pdf';
            $up = GoogleDrive::upload($pdfBytes, $filename);
            if ($up['ok']) {
                $model->markDriveUpload($row['id'], $up['file_id'], $up['url']);
                // PDF lama dihapus hanya setelah yang baru berhasil: bila unggahan gagal, berkas lama tetap ada dan statusnya tetap benar.
                if (!empty($previous['gdrive_file_id'])) GoogleDrive::delete($previous['gdrive_file_id']);
                Flash::set('success', 'Biodata disimpan dan terkirim ke Google Drive.');
            } else {
                Flash::set('warn', 'Biodata disimpan, tapi gagal dikirim ke Google Drive: ' . $up['error']);
            }
        } else {
            Flash::set('success', 'Biodata disimpan.');
        }

        $this->redirect('pengurus/biodata');
    }

    // Foto biodata untuk pratinjau di formulir: diambil dari Drive lewat sini, jadi tidak ada salinan di server.
    // URL-nya membawa ID berkas (?v=), yang berganti tiap foto diganti, sehingga aman di-cache peramban.
    public function biodata_foto() {
        [, $person] = $this->guard();
        $row = $this->model('BiodataModel')->findByPersonId($person['id']);
        $bytes = (!empty($row['photo_gdrive_file_id']) && GoogleDrive::isConfigured())
            ? GoogleDrive::download($row['photo_gdrive_file_id']) : null;
        if ($bytes === null) $this->notFound('Foto belum ada.');

        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function biodata_pdf() {
        [$members, $person] = $this->guard();
        $row = $this->model('BiodataModel')->findByPersonId($person['id']);
        if (!$row) $this->notFound('Biodata belum diisi.');

        $pdfBytes = null;
        if (!empty($row['gdrive_file_id']) && GoogleDrive::isConfigured()) {
            $pdfBytes = GoogleDrive::download($row['gdrive_file_id']);
        }
        if ($pdfBytes === null) {
            $pdfBytes = BiodataPdf::render($person, $this->activePlacement($members, $person['id']), $row);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Biodata_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $person['name']) . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
        exit;
    }
}
