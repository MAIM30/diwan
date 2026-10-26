<?php
// PATH LOKASI FILE: app/Controllers/PengurusController.php
// Portal Pengurus: pengurus melihat data kepengurusannya sendiri.
//
// Alur masuk: nomor WA → (bila WA Gateway aktif) OTP via WhatsApp → verifikasi → sesi.
// Bila gateway belum dikonfigurasi, masuk langsung seperti sebelumnya (tanpa OTP).

class PengurusController extends Controller {

    private function noStore() {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
    }

    // ---------- Masuk / keluar ----------

    public function login() {
        $this->noStore();
        if (Member::check()) {
            $this->redirect('pengurus');
        }
        $this->view('pengurus/login', ['title' => 'Masuk Pengurus', 'otpEnabled' => Whatsapp::isConfigured()]);
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

        // Bila WA Gateway aktif, kirim OTP dan alihkan ke halaman verifikasi.
        if (Whatsapp::isConfigured()) {
            $phone = $person['phone'];
            $code  = Whatsapp::sendOtp($person['id'], $phone);
            if ($code === false) {
                Flash::set('error', 'Gagal mengirim kode OTP. Silakan coba lagi.');
                $this->redirect('pengurus/login');
            }
            $_SESSION['otp_pending'] = [
                'person_id' => (int) $person['id'],
                'phone'     => $phone,
                'sent_at'   => time(),
            ];
            $this->redirect('pengurus/otp');
            return;
        }

        // Fallback: tanpa WA Gateway, masuk langsung.
        Member::login($person);
        $members->recordLogin($person['id']);
        $this->redirect('pengurus');
    }

    public function otp() {
        $this->noStore();
        if (Member::check()) $this->redirect('pengurus');
        if (empty($_SESSION['otp_pending'])) $this->redirect('pengurus/login');

        $phone = $_SESSION['otp_pending']['phone'] ?? '';
        $masked = Text::maskPhone($phone);
        $this->view('pengurus/otp', ['title' => 'Verifikasi OTP', 'maskedPhone' => $masked]);
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

        $pending = $_SESSION['otp_pending'];
        $code    = trim((string) ($_POST['code'] ?? ''));

        // OTP berlaku 5 menit sejak dikirim.
        if (time() - (int) $pending['sent_at'] > 300) {
            unset($_SESSION['otp_pending']);
            Flash::set('error', 'Kode OTP sudah kedaluwarsa. Silakan masuk ulang.');
            $this->redirect('pengurus/login');
        }

        $personId = Whatsapp::verifyOtp($pending['phone'], $code);
        if ($personId === false || $personId !== (int) $pending['person_id']) {
            $throttle->recordFailure();
            Flash::set('error', 'Kode OTP tidak valid. Periksa kembali kode yang dikirim ke WhatsApp Anda.');
            $this->redirect('pengurus/otp');
        }

        unset($_SESSION['otp_pending']);
        $members = $this->model('MemberModel');
        $person  = $members->findEligibleById($personId);
        if (!$person) {
            Flash::set('error', 'Akses tidak lagi tersedia.');
            $this->redirect('pengurus/login');
        }

        Member::login($person);
        $members->recordLogin($person['id']);
        $this->redirect('pengurus');
    }

    public function otp_resend() {
        $this->noStore();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('pengurus/login');
        if (empty($_SESSION['otp_pending'])) $this->redirect('pengurus/login');

        $pending = $_SESSION['otp_pending'];

        // Batas kirim ulang: minimal 60 detik sejak OTP sebelumnya.
        if (time() - (int) $pending['sent_at'] < 60) {
            Flash::set('info', 'Tunggu sebentar sebelum mengirim ulang kode.');
            $this->redirect('pengurus/otp');
        }

        $code = Whatsapp::sendOtp($pending['person_id'], $pending['phone']);
        if ($code === false) {
            Flash::set('error', 'Gagal mengirim ulang kode OTP.');
            $this->redirect('pengurus/otp');
        }

        $_SESSION['otp_pending']['sent_at'] = time();
        Flash::set('success', 'Kode OTP baru telah dikirim ke WhatsApp Anda.');
        $this->redirect('pengurus/otp');
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
        return [$members, $person];
    }

    public function index() {
        [$members, $person] = $this->guard();

        $history = $members->history($person['id']);
        $current = $members->currentOf($history);

        // Rantai struktur di-cache per struktur supaya orang yang rangkap di struktur sama tidak
        // mengulang query. Progres masa jabatan dihitung per jabatan aktif.
        $chains = [];
        $lives = [];
        foreach ($current as $i => $c) {
            $sid = (int) $c['structure_id'];
            if (!isset($chains[$sid])) $chains[$sid] = $members->structureChain($sid);
            $lives[$i] = MemberModel::life($c);
        }

        $this->view('pengurus/index', [
            'title'   => 'Beranda',
            'person'  => $person,
            'history' => $history,
            'current' => $current,
            'chains'  => $chains,
            'lives'   => $lives,
            'missing' => $members->missingFields($person),
            'logins'  => $members->loginHistory($person['id'], 6),
            'biodata' => $this->model('BiodataModel')->findByPersonId($person['id']),
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

        // Berjalan = periode berjalan DAN belum berakhir -- definisi yang sama dengan beranda.
        $isNow = function ($h) use ($today) {
            return !empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= $today);
        };
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
            'driveReady' => GoogleDrive::isConfigured(),
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
        $result = $model->validate($_POST);
        $retry = array_merge($_POST, ['commitments' => array_map('intval', $_POST['commitments'] ?? [])]);

        if ($result['errors']) {
            $this->biodataForm($members, $person, $retry, $result['errors']);
            return;
        }

        // Foto itu opsional dan disimpan di Google Drive, bukan di server -- di database hanya ID berkasnya.
        // Diproses dan diunggah SEBELUM apa pun disimpan, supaya foto yang gagal tidak meninggalkan
        // biodata setengah jadi. Foto profil (people.photo_path) tidak disentuh.
        $previous = $model->findByPersonId($person['id']);
        $photoBytes = null;
        $photoDriveId = null;
        $photoFile = $_FILES['photo'] ?? null;
        if ($photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if (!GoogleDrive::isConfigured()) {
                $this->biodataForm($members, $person, $retry, ['photo' => 'Unggah foto belum tersedia: Google Drive belum dihubungkan.']);
                return;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'foto');
            $err = Photo::fromUpload($photoFile, $tmp);
            $photoBytes = $err === null ? file_get_contents($tmp) : null;
            @unlink($tmp);
            if ($err !== null) {
                $this->biodataForm($members, $person, $retry, ['photo' => Photo::message($err)]);
                return;
            }
            $up = GoogleDrive::upload($photoBytes, 'Foto_' . $person['name'] . '_' . $person['uuid'] . '.jpg', 'image/jpeg');
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
                Flash::set('success', 'Biodata disimpan dan terkirim ke Google Drive.');
            } else {
                Flash::set('warn', 'Biodata disimpan, tapi gagal dikirim ke Google Drive: ' . $up['error'] . ' Anda tetap bisa mengunduh PDF-nya.');
            }
        } else {
            Flash::set('success', 'Biodata disimpan.');
        }

        $this->redirect('pengurus/biodata');
    }

    // PDF dibangkitkan ULANG di sini, tidak pernah dibaca dari berkas tersimpan -- lihat BiodataPdf.php.
    public function biodata_pdf() {
        [$members, $person] = $this->guard();
        $row = $this->model('BiodataModel')->findByPersonId($person['id']);
        if (!$row) $this->notFound('Biodata belum diisi.');

        $pdfBytes = BiodataPdf::render($person, $this->activePlacement($members, $person['id']), $row);

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
