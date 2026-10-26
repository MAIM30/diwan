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

        $this->view('admin/settings/whatsapp', [
            'title'    => 'Pengaturan WhatsApp',
            'settings' => $settings->getMany(Whatsapp::SETTINGS_KEYS),
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
        ];

        $settings->setMany($pairs, Auth::id());
        Whatsapp::$settings = null;

        Audit::log('settings', 0, 'update', null, ['keys' => array_keys($pairs)]);
        Flash::set('success', 'Pengaturan WhatsApp disimpan.');
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
