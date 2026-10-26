<?php
// PATH LOKASI FILE: app/Controllers/BroadcastController.php
// Broadcast WhatsApp ke pengurus: cari penerima → pratinjau → kirim satu-satu dengan progres.

class BroadcastController extends Controller {

    public function index() {
        $this->requireLogin('super_admin');

        if (!Whatsapp::isConfigured()) {
            Flash::set('error', 'WhatsApp belum dikonfigurasi. Atur di Pengaturan WhatsApp terlebih dahulu.');
            $this->redirect('settings/whatsapp');
        }

        $this->view('admin/broadcast/index', [
            'title' => 'Broadcast WhatsApp',
        ]);
    }

    // JSON: pencarian struktur / unit / orang untuk isian autocomplete.
    public function search() {
        $this->requireLogin('super_admin');
        header('Content-Type: application/json; charset=utf-8');

        $mode = $_GET['mode'] ?? '';
        $q    = trim($_GET['q'] ?? '');
        $db   = Database::getInstance()->getConnection();
        $rows = [];

        if ($q === '' && $mode !== '') {
            echo json_encode([]);
            exit;
        }

        $like = '%' . $q . '%';

        if ($mode === 'structure') {
            $stmt = $db->prepare("
                SELECT s.id, CONCAT(s.level, ' ', s.name) AS label,
                       IF(s.wing='muslimah','Muslimah','Umum') AS hint,
                       (SELECT COUNT(DISTINCT pl.person_id) FROM placements pl
                        WHERE pl.structure_id = s.id AND pl.deleted_at IS NULL) AS cnt
                FROM structures s
                WHERE s.deleted_at IS NULL
                  AND (s.name LIKE :q OR s.level LIKE :q2)
                ORDER BY s.tier, s.name
                LIMIT 20
            ");
            $stmt->execute([':q' => $like, ':q2' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['id' => 's:' . $r['id'], 'label' => $r['label'], 'hint' => $r['hint'] . ' · ' . $r['cnt'] . ' pengurus', 'type' => 'structure'];
            }
        } elseif ($mode === 'unit') {
            $stmt = $db->prepare("
                SELECT uc.id, uc.name AS label, uc.kind,
                       (SELECT COUNT(DISTINCT pl.person_id) FROM structure_units su
                        JOIN placements pl ON pl.structure_unit_id = su.id AND pl.deleted_at IS NULL
                        WHERE su.unit_catalog_id = uc.id AND su.deleted_at IS NULL) AS cnt
                FROM unit_catalog uc
                WHERE uc.name LIKE :q OR uc.abbreviation LIKE :q2
                ORDER BY uc.name
                LIMIT 20
            ");
            $stmt->execute([':q' => $like, ':q2' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['id' => 'u:' . $r['id'], 'label' => $r['label'], 'hint' => ucfirst($r['kind']) . ' · ' . $r['cnt'] . ' pengurus', 'type' => 'unit'];
            }
        } elseif ($mode === 'person') {
            $stmt = $db->prepare("
                SELECT p.id, p.name AS label, p.phone,
                       p.title_prefix, p.title_suffix
                FROM people p
                WHERE p.deleted_at IS NULL AND p.status = 'active'
                  AND p.phone IS NOT NULL AND p.phone != ''
                  AND (p.name LIKE :q OR p.phone LIKE :q2)
                ORDER BY p.name
                LIMIT 20
            ");
            $stmt->execute([':q' => $like, ':q2' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim(($r['title_prefix'] ? $r['title_prefix'] . ' ' : '') . $r['label'] . ($r['title_suffix'] ? ', ' . $r['title_suffix'] : ''));
                $rows[] = ['id' => 'p:' . $r['id'], 'label' => $name, 'hint' => Text::maskPhone($r['phone']), 'type' => 'person'];
            }
        }

        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function preview() {
        $this->requireLogin('super_admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('broadcast');

        $message  = trim($_POST['message'] ?? '');
        $targets  = trim($_POST['targets'] ?? '');
        $scope    = $_POST['scope'] ?? 'all';

        if ($message === '') {
            Flash::set('error', 'Pesan tidak boleh kosong.');
            $this->redirect('broadcast');
        }

        $db = Database::getInstance()->getConnection();
        $recipients = $this->resolveTargets($db, $targets, $scope);

        $waSettings = Whatsapp::isConfigured() ? (new SettingModel())->getMany(['wa_broadcast_delay_min', 'wa_broadcast_delay_max']) : [];

        $this->view('admin/broadcast/preview', [
            'title'      => 'Pratinjau Broadcast',
            'recipients' => $recipients,
            'message'    => $message,
            'targets'    => $targets,
            'scope'      => $scope,
            'delayMin'   => (int) ($waSettings['wa_broadcast_delay_min'] ?? 8),
            'delayMax'   => (int) ($waSettings['wa_broadcast_delay_max'] ?? 15),
        ]);
    }

    // AJAX: kirim satu pesan, dipanggil dari JS satu per satu untuk menampilkan progres.
    public function send_one() {
        $this->requireLogin('super_admin');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            exit;
        }

        if (!Whatsapp::isConfigured()) {
            echo json_encode(['ok' => false, 'error' => 'WhatsApp belum dikonfigurasi']);
            exit;
        }

        $personId = (int) ($_POST['person_id'] ?? 0);
        $message  = trim($_POST['message'] ?? '');

        if (!$personId || $message === '') {
            echo json_encode(['ok' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, name, phone FROM people WHERE id = :id AND phone IS NOT NULL AND phone != ''");
        $stmt->execute([':id' => $personId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$person) {
            echo json_encode(['ok' => false, 'error' => 'Orang tidak ditemukan']);
            exit;
        }

        $phone = Text::normalizePhone($person['phone']);
        if (!$phone) {
            echo json_encode(['ok' => false, 'error' => 'Nomor tidak valid']);
            exit;
        }

        $personalMsg = str_replace('{nama}', $person['name'], $message);
        $personalMsg = self::varyMessage($personalMsg);

        $result = Whatsapp::sendBroadcast($phone, $personalMsg);

        echo json_encode([
            'ok'      => $result['ok'],
            'name'    => $person['name'],
            'error'   => $result['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ubah daftar target (s:123, u:45, p:678) jadi daftar orang unik.
    private function resolveTargets(PDO $db, $targets, $scope) {
        $items = array_filter(array_map('trim', explode(',', $targets)));
        if (empty($items)) {
            return $this->allActivePeople($db, $scope);
        }

        $structureIds = [];
        $unitIds      = [];
        $personIds    = [];

        foreach ($items as $item) {
            [$type, $id] = explode(':', $item, 2) + [1 => '0'];
            $id = (int) $id;
            if ($type === 's') $structureIds[] = $id;
            elseif ($type === 'u') $unitIds[] = $id;
            elseif ($type === 'p') $personIds[] = $id;
        }

        $allPersonIds = [];

        if (!empty($structureIds)) {
            $ph = implode(',', array_fill(0, count($structureIds), '?'));
            $coreJoin = $scope === 'inti' ? 'AND pos.is_core = 1' : '';
            $stmt = $db->prepare("
                SELECT DISTINCT pl.person_id FROM placements pl
                JOIN positions pos ON pos.id = pl.position_id
                WHERE pl.structure_id IN ($ph) AND pl.deleted_at IS NULL $coreJoin
            ");
            $stmt->execute($structureIds);
            $allPersonIds = array_merge($allPersonIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!empty($unitIds)) {
            $ph = implode(',', array_fill(0, count($unitIds), '?'));
            $stmt = $db->prepare("
                SELECT DISTINCT pl.person_id FROM placements pl
                JOIN structure_units su ON su.id = pl.structure_unit_id AND su.deleted_at IS NULL
                WHERE su.unit_catalog_id IN ($ph) AND pl.deleted_at IS NULL
            ");
            $stmt->execute($unitIds);
            $allPersonIds = array_merge($allPersonIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!empty($personIds)) {
            $allPersonIds = array_merge($allPersonIds, $personIds);
        }

        $allPersonIds = array_unique(array_map('intval', $allPersonIds));
        if (empty($allPersonIds)) return [];

        $ph = implode(',', array_fill(0, count($allPersonIds), '?'));
        $stmt = $db->prepare("
            SELECT DISTINCT p.id, p.name, p.phone, p.title_prefix, p.title_suffix
            FROM people p
            WHERE p.id IN ($ph) AND p.deleted_at IS NULL AND p.status = 'active'
              AND p.phone IS NOT NULL AND p.phone != ''
            ORDER BY p.name
        ");
        $stmt->execute(array_values($allPersonIds));
        return $this->dedupe($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function allActivePeople(PDO $db, $scope) {
        $coreJoin = $scope === 'inti'
            ? "JOIN placements pl ON pl.person_id = p.id AND pl.deleted_at IS NULL
               JOIN positions pos ON pos.id = pl.position_id AND pos.is_core = 1"
            : '';
        $existsCheck = $scope !== 'inti'
            ? "AND EXISTS (SELECT 1 FROM placements plx WHERE plx.person_id = p.id AND plx.deleted_at IS NULL)"
            : '';
        $stmt = $db->query("
            SELECT DISTINCT p.id, p.name, p.phone, p.title_prefix, p.title_suffix
            FROM people p $coreJoin
            WHERE p.deleted_at IS NULL AND p.status = 'active'
              AND p.phone IS NOT NULL AND p.phone != '' $existsCheck
            ORDER BY p.name
        ");
        return $this->dedupe($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function varyMessage($msg) {
        $greetings = ['Assalamu\'alaikum', 'Assalamualaikum', 'Assalamu\'alaikum Wr. Wb.'];
        $closings  = ['Jazakumullah khairan.', 'Jazakallah khairan.', 'Barakallahu fiikum.'];

        $lines = explode("\n", $msg);
        $first = trim($lines[0] ?? '');
        $last  = trim(end($lines));

        if (stripos($first, 'assalamu') === 0) {
            $lines[0] = $greetings[array_rand($greetings)];
        }
        if (stripos($last, 'jazak') === 0 || stripos($last, 'barak') === 0) {
            $lines[count($lines) - 1] = $closings[array_rand($closings)];
        }

        $zwsp = ["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"];
        $words = explode(' ', implode("\n", $lines));
        $pos = array_rand($words);
        $words[$pos] .= $zwsp[array_rand($zwsp)];

        return implode(' ', $words);
    }

    private function dedupe(array $rows) {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $phone = Text::normalizePhone($r['phone']);
            if ($phone && !isset($seen[$phone])) {
                $seen[$phone] = true;
                $out[] = $r;
            }
        }
        return $out;
    }
}
