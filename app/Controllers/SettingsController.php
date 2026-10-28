<?php
// PATH LOKASI FILE: app/Controllers/SettingsController.php
// Pengaturan aplikasi: WA Gateway (Baileys), dan kelak pengaturan lainnya.

class SettingsController extends Controller {

    public function index() {
        $this->requireLogin('super_admin');
        $this->redirect('settings/whatsapp');
    }

    public function whatsapp() {
        $this->requireLogin('super_admin');
        $settings = $this->model('SettingModel');
        $pairingCode = null;
        $pairingPhone = null;
        if (!empty($_SESSION['wa_pairing_code']) && (int) ($_SESSION['wa_pairing_expires'] ?? 0) > time()) {
            $pairingCode = $_SESSION['wa_pairing_code'];
            $pairingPhone = $_SESSION['wa_pairing_phone'] ?? null;
        }
        unset($_SESSION['wa_pairing_code'], $_SESSION['wa_pairing_phone'], $_SESSION['wa_pairing_expires']);

        $this->view('admin/settings/whatsapp', [
            'title'    => 'Pengaturan WhatsApp',
            'settings' => $settings->getMany(Whatsapp::SETTINGS_KEYS),
            'pairingCode' => $pairingCode,
            'pairingPhone' => $pairingPhone,
        ]);
    }

    public function whatsapp_save() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/whatsapp');

        $settings = $this->model('SettingModel');
        $delayMin = max(3, (int) ($_POST['wa_broadcast_delay_min'] ?? 8));
        $delayMax = max($delayMin + 1, (int) ($_POST['wa_broadcast_delay_max'] ?? 15));

        $pairs = [
            'wa_gateway_url'          => rtrim(trim($_POST['wa_gateway_url'] ?? ''), '/'),
            'wa_gateway_key'          => trim($_POST['wa_gateway_key'] ?? ''),
            'wa_broadcast_delay_min'  => (string) $delayMin,
            'wa_broadcast_delay_max'  => (string) $delayMax,
            'wa_qr_display_minutes'   => (string) min(10, max(1, (int) ($_POST['wa_qr_display_minutes'] ?? 5))),
        ];

        $settings->setMany($pairs, Auth::id());
        Whatsapp::$settings = null;

        Audit::log('settings', 0, 'update', null, ['keys' => array_keys($pairs)]);
        Flash::set('success', 'Pengaturan WhatsApp disimpan.');
        $this->redirect('settings/whatsapp');
    }

    public function whatsapp_pair() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/whatsapp');
        if (!Whatsapp::isConfigured()) {
            Flash::set('error', 'URL Gateway dan API Key harus diisi dulu.');
            $this->redirect('settings/whatsapp');
        }
        $phone = Text::normalizePhone($_POST['pair_phone'] ?? '');
        if ($phone === null) {
            Flash::set('error', 'Masukkan nomor WhatsApp dengan kode negara, misalnya 62812xxxxxxx.');
            $this->redirect('settings/whatsapp');
        }
        $result = Whatsapp::requestPairingCode($phone);
        if (empty($result['ok']) || empty($result['data']['code'])) {
            Flash::set('error', 'Kode pasangan tidak bisa dibuat: ' . ($result['error'] ?? 'Gateway tidak memberikan kode.'));
            $this->redirect('settings/whatsapp');
        }
        $_SESSION['wa_pairing_code'] = (string) $result['data']['code'];
        $_SESSION['wa_pairing_phone'] = $phone;
        $_SESSION['wa_pairing_expires'] = time() + 180;
        Flash::set('success', 'Kode pasangan berhasil dibuat. Masukkan kode ini di WhatsApp pada ponsel yang nomornya dipilih.');
        $this->redirect('settings/whatsapp');
    }

    public function whatsapp_test() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/whatsapp');

        if (!Whatsapp::isConfigured()) {
            Flash::set('error', 'URL Gateway dan API Key harus diisi dulu.');
            $this->redirect('settings/whatsapp');
        }

        $result = Whatsapp::testConnection();
        if ($result['ok']) {
            $status = $result['data']['status'] ?? 'unknown';
            $label  = $status === 'open' ? 'Terhubung ke WhatsApp' : 'Gateway aktif, tapi WhatsApp belum tersambung (status: ' . $status . ')';
            Flash::set($status === 'open' ? 'success' : 'warn', $label);
        } else {
            Flash::set('error', 'Koneksi gagal: ' . ($result['error'] ?? 'Tidak ada respons'));
        }
        $this->redirect('settings/whatsapp');
    }

    public function gdrive() {
        $this->requireLogin('super_admin');
        $settings = $this->model('SettingModel');

        $this->view('admin/settings/gdrive', [
            'title'    => 'Pengaturan Google Drive',
            'settings' => $settings->getMany(GoogleDrive::SETTINGS_KEYS),
        ]);
    }

    public function gdrive_save() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/gdrive');

        $settings = $this->model('SettingModel');
        $pairs = ['gdrive_client_id' => trim($_POST['gdrive_client_id'] ?? '')];
        // Rahasia klien tidak pernah ditampilkan ulang di form; kolom kosong = pertahankan yang lama.
        $secret = trim($_POST['gdrive_client_secret'] ?? '');
        if ($secret !== '') $pairs['gdrive_client_secret'] = $secret;
        $settings->setMany($pairs, Auth::id());
        GoogleDrive::$settings = null;

        Audit::log('settings', 0, 'update', null, ['keys' => array_keys($pairs)]);
        Flash::set('success', 'Client ID Google disimpan. Lanjut tekan "Hubungkan Google Drive".');
        $this->redirect('settings/gdrive');
    }

    public function gdrive_connect() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/gdrive');

        if (!GoogleDrive::hasClient()) {
            Flash::set('error', 'Isi dan simpan Client ID + Client Secret dulu.');
            $this->redirect('settings/gdrive');
        }

        $_SESSION['gdrive_oauth_state'] = bin2hex(random_bytes(16));
        header('Location: ' . GoogleDrive::authUrl($_SESSION['gdrive_oauth_state']));
        exit;
    }

    public function gdrive_callback() {
        $this->requireLogin('super_admin');

        $state = $_SESSION['gdrive_oauth_state'] ?? '';
        unset($_SESSION['gdrive_oauth_state']);
        if ($state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
            Flash::set('error', 'Permintaan tidak sah atau kedaluwarsa. Ulangi dari tombol "Hubungkan Google Drive".');
            $this->redirect('settings/gdrive');
        }
        if (!empty($_GET['error']) || empty($_GET['code'])) {
            Flash::set('error', 'Persetujuan Google dibatalkan atau gagal (' . ($_GET['error'] ?? 'tanpa kode') . ').');
            $this->redirect('settings/gdrive');
        }

        $exchange = GoogleDrive::exchangeCode($_GET['code']);
        if (!$exchange['ok']) {
            Flash::set('error', 'Gagal menukar kode dari Google: ' . $exchange['error']);
            $this->redirect('settings/gdrive');
        }
        $refresh = $exchange['data']['refresh_token'] ?? '';
        if ($refresh === '') {
            Flash::set('error', 'Google tidak mengirim refresh token. Cabut akses DIWAN di myaccount.google.com/permissions lalu ulangi.');
            $this->redirect('settings/gdrive');
        }

        $settings = $this->model('SettingModel');
        $access   = $exchange['data']['access_token'];
        $folderId = (string) $settings->get('gdrive_folder_id', '');

        // Hubungkan ulang (mis. akses sempat dicabut): pakai lagi folder lama selama masih ada, agar
        // berkas yang sudah terkumpul tidak tercecer di dua folder.
        if ($folderId === '' || !GoogleDrive::folderExists($access, $folderId)) {
            $folder = GoogleDrive::createFolder($access);
            if (!$folder['ok']) {
                Flash::set('error', 'Terhubung, tapi gagal membuat folder di Drive: ' . $folder['error']);
                $this->redirect('settings/gdrive');
            }
            $folderId = $folder['data']['id'];
        }

        $settings->setMany(['gdrive_refresh_token' => $refresh, 'gdrive_folder_id' => $folderId], Auth::id());
        $settings->delete('gdrive_service_account_json');
        GoogleDrive::$settings = null;

        Audit::log('settings', 0, 'update', null, ['event' => 'gdrive_connected']);
        Flash::set('success', 'Google Drive terhubung. Folder "' . GoogleDrive::FOLDER_NAME . '" siap menerima PDF biodata.');
        $this->redirect('settings/gdrive');
    }

    public function gdrive_test() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('settings/gdrive');

        $result = GoogleDrive::testConnection();
        if ($result['ok']) {
            Flash::set('success', 'Terhubung -- akses ke Google dan folder tujuan berfungsi.');
        } else {
            Flash::set('error', 'Gagal: ' . $result['error']);
        }
        $this->redirect('settings/gdrive');
    }

    public function whatsapp_log() {
        $this->requireLogin('super_admin');

        $db = Database::getInstance()->getConnection();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $typeFilter = in_array($_GET['type'] ?? '', ['otp', 'notification', 'broadcast'], true) ? $_GET['type'] : '';

        $where = '';
        $params = [];
        if ($typeFilter !== '') {
            $where = 'WHERE type = :tp';
            $params[':tp'] = $typeFilter;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM whatsapp_log $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT * FROM whatsapp_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/settings/whatsapp_log', [
            'title'   => 'Log WhatsApp',
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'type'    => $typeFilter,
        ]);
    }
}
