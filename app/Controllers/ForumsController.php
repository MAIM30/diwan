<?php
// PATH LOKASI FILE: app/Controllers/ForumsController.php
// Kelola forum permusyawaratan: buat, ubah, kunci, tetapkan peserta, riwayat, ekspor CSV.

class ForumsController extends Controller {

    public function index() {
        $this->requireLogin();
        $forums = $this->model('ForumModel');
        $this->view('admin/forums/index', [
            'title'  => 'Forum Permusyawaratan',
            'forums' => $forums->all(),
        ]);
    }

    public function form($id = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $forums = $this->model('ForumModel');
        $row = null;
        if ($id) {
            $row = $forums->findById($id);
            if (!$row) $this->notFound('Forum tidak ditemukan.');
            $this->requireScope($row['organizer_structure_id']);
            if ($forums->isFinished($row)) {
                Flash::set('error', 'Forum yang sudah selesai tidak bisa diubah.');
                $this->redirect('forums/detail/' . $id);
            }
        }

        $structures = $this->model('StructureModel');
        $this->view('admin/forums/form', [
            'title'      => $id ? 'Ubah Forum' : 'Buat Forum',
            'row'        => $row,
            'types'      => ForumModel::TYPES,
            'structures' => $structures->allInScope(),
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forums = $this->model('ForumModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'name'                   => $_POST['name'] ?? '',
            'type'                   => $_POST['type'] ?? 'lainnya',
            'organizer_structure_id' => (int) ($_POST['organizer_structure_id'] ?? 0),
            'start_date'             => $_POST['start_date'] ?? '',
            'end_date'               => $_POST['end_date'] ?? '',
            'decision_ref'           => $_POST['decision_ref'] ?? '',
        ];

        if (trim($data['name']) === '') {
            Flash::set('error', 'Nama forum wajib diisi.');
            $this->redirect($id ? 'forums/form/' . $id : 'forums/form');
        }
        if (!isset(ForumModel::TYPES[$data['type']])) {
            Flash::set('error', 'Jenis forum tidak valid.');
            $this->redirect($id ? 'forums/form/' . $id : 'forums/form');
        }
        if ($data['organizer_structure_id'] === 0) {
            Flash::set('error', 'Penyelenggara wajib dipilih.');
            $this->redirect($id ? 'forums/form/' . $id : 'forums/form');
        }
        $this->requireScope($data['organizer_structure_id']);

        if ($id) {
            $existing = $forums->findById($id);
            if (!$existing) $this->notFound('Forum tidak ditemukan.');
            $this->requireScope($existing['organizer_structure_id']);
            if ($forums->isFinished($existing)) {
                Flash::set('error', 'Forum yang sudah selesai tidak bisa diubah.');
                $this->redirect('forums/detail/' . $id);
            }
            $forums->update($id, $data);
            Flash::set('success', 'Forum diperbarui.');
        } else {
            $id = $forums->create($data);
            Flash::set('success', 'Forum dibuat.');
        }

        $this->redirect('forums/detail/' . $id);
    }

    public function detail($id) {
        $this->requireLogin();
        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        $participants = $this->model('ForumParticipantModel');
        $warnings = [];
        if ($forum['status'] === 'terbuka') {
            $warnings = $forums->warningsBeforeLock($id);
        }

        $this->view('admin/forums/detail', [
            'title'        => $forum['name'],
            'forum'        => $forum,
            'participants' => $participants->byForum($id),
            'counts'       => $participants->categoryCounts($id),
            'activeCount'  => $participants->countActive($id),
            'warnings'     => $warnings,
            'canEdit'      => Auth::canEdit() && AdminScope::allows($forum['organizer_structure_id']),
        ]);
    }

    public function assign($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if ($forums->isFinished($forum)) {
            Flash::set('error', 'Forum yang sudah selesai tidak bisa diubah.');
            $this->redirect('forums/detail/' . $id);
        }

        $participants = $this->model('ForumParticipantModel');
        $existingPids = array_map('intval', $participants->activePersonIds($id));

        $people = $this->model('PersonModel');
        $structures = $this->model('StructureModel');
        $placements = $this->model('PlacementModel');

        $filterStruct = (int) ($_GET['structure'] ?? 0);
        $filterCore   = !empty($_GET['core']);
        $filterSearch = trim($_GET['q'] ?? '');

        $candidates = $this->searchCandidates($people, $placements, $filterStruct, $filterCore, $filterSearch);

        $this->view('admin/forums/assign', [
            'title'        => 'Tetapkan Peserta — ' . $forum['name'],
            'forum'        => $forum,
            'candidates'   => $candidates,
            'existingPids' => $existingPids,
            'structures'   => $structures->allInScope(),
            'filterStruct' => $filterStruct,
            'filterCore'   => $filterCore,
            'filterSearch' => $filterSearch,
            'isLocked'     => $forums->isLocked($forum),
        ]);
    }

    private function searchCandidates($people, $placements, $structureId, $coreOnly, $search) {
        $sql = "
            SELECT DISTINCT p.id, p.full_name, p.title_prefix, p.name, p.title_suffix,
                   p.phone, p.gender, p.uuid AS person_uuid,
                   GROUP_CONCAT(DISTINCT CONCAT(po.name, ' — ', s.level, ' ', s.name) ORDER BY pl.is_primary DESC SEPARATOR '; ') AS jabatan_list,
                   MIN(CASE WHEN pl.is_primary = 1 THEN pl.id END) AS primary_placement_id,
                   MIN(CASE WHEN pl.is_primary = 1 THEN pl.structure_id END) AS primary_structure_id
            FROM people p
            JOIN placements pl ON pl.person_id = p.id AND pl.deleted_at IS NULL
            JOIN terms t ON t.id = pl.term_id AND t.is_current = 1
            JOIN structures s ON s.id = pl.structure_id
            JOIN positions po ON po.id = pl.position_id
            WHERE p.deleted_at IS NULL AND " . AdminScope::sqlFilter('pl.structure_id');

        $params = [];
        if ($structureId > 0) {
            require_once ROOT_DIR . '/app/Models/StructureModel.php';
            $sm = new StructureModel();
            $ids = $sm->descendantIds($structureId);
            if (empty($ids)) $ids = [0];
            $sql .= " AND pl.structure_id IN (" . implode(',', array_map('intval', $ids)) . ")";
        }
        if ($coreOnly) {
            require_once ROOT_DIR . '/app/Models/PositionModel.php';
            $sql .= " AND pl.structure_unit_id IS NULL AND " . PositionModel::CORE_SQL;
        }
        if ($search !== '') {
            $sql .= " AND (p.full_name LIKE :q OR p.phone LIKE :q2)";
            $params[':q'] = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
        }

        $sql .= " GROUP BY p.id ORDER BY p.name LIMIT 500";

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function assign_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forumId = (int) ($_POST['forum_id'] ?? 0);
        $forums = $this->model('ForumModel');
        $forum = $forums->findById($forumId);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if ($forums->isFinished($forum)) {
            Flash::set('error', 'Forum yang sudah selesai tidak bisa diubah.');
            $this->redirect('forums/detail/' . $forumId);
        }

        $personIds = array_map('intval', (array) ($_POST['person_ids'] ?? []));
        if (empty($personIds)) {
            Flash::set('error', 'Belum ada orang yang dipilih.');
            $this->redirect('forums/assign/' . $forumId);
        }

        $category = $_POST['category'] ?? 'peserta_penuh';
        $reason   = trim($_POST['reason'] ?? '');

        if ($forums->isLocked($forum) && $reason === '') {
            Flash::set('error', 'Forum sudah dikunci. Alasan perubahan wajib diisi.');
            $this->redirect('forums/assign/' . $forumId);
        }

        $participants = $this->model('ForumParticipantModel');
        $existing = array_map('intval', $participants->activePersonIds($forumId));
        $added = 0;
        $skipped = 0;

        foreach ($personIds as $pid) {
            $pid = (int) $pid;
            if (in_array($pid, $existing, true)) { $skipped++; continue; }

            $data = [
                'person_id'                  => $pid,
                'category'                   => $category,
                'decision_ref'               => $_POST['decision_ref'] ?? '',
                'reason'                     => $reason,
                'basis_placement_id'         => !empty($_POST['primary_placement_id_' . $pid]) ? (int) $_POST['primary_placement_id_' . $pid] : null,
                'representing_structure_id'  => !empty($_POST['primary_structure_id_' . $pid]) ? (int) $_POST['primary_structure_id_' . $pid] : null,
            ];

            try {
                $participants->assign($forumId, $data, $forum);
                $added++;
            } catch (\RuntimeException $e) {
                $skipped++;
            }
        }

        Flash::set('success', $added . ' peserta ditambahkan'
            . ($skipped > 0 ? ', ' . $skipped . ' dilewati (sudah ada)' : '') . '.');
        $this->redirect('forums/detail/' . $forumId);
    }

    public function participant_update($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $participants = $this->model('ForumParticipantModel');
        $row = $participants->findById($id);
        if (!$row) $this->notFound('Peserta tidak ditemukan.');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($row['forum_id']);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if ($forums->isFinished($forum)) {
            Flash::set('error', 'Forum yang sudah selesai tidak bisa diubah.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        $reason = trim($_POST['reason'] ?? '');
        if ($forums->isLocked($forum) && $reason === '') {
            Flash::set('error', 'Forum sudah dikunci. Alasan perubahan wajib diisi.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        $participants->updateParticipant($id, [
            'category'                   => $_POST['category'] ?? $row['category'],
            'representing_structure_id'  => $_POST['representing_structure_id'] ?? null,
            'basis_placement_id'         => $_POST['basis_placement_id'] ?? null,
            'decision_ref'               => $_POST['decision_ref'] ?? '',
            'notes'                      => $_POST['notes'] ?? '',
            'reason'                     => $reason,
        ], $forum);

        Flash::set('success', 'Data peserta diperbarui.');
        $this->redirect('forums/detail/' . $forum['id']);
    }

    public function revoke($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $participants = $this->model('ForumParticipantModel');
        $row = $participants->findById($id);
        if (!$row) $this->notFound('Peserta tidak ditemukan.');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($row['forum_id']);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        $reason = trim($_POST['reason'] ?? '');
        if ($forums->isLocked($forum) && $reason === '') {
            Flash::set('error', 'Forum sudah dikunci. Alasan pencabutan wajib diisi.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        $participants->revoke($id, $reason, $forum);
        Flash::set('success', 'Peserta dicabut.');
        $this->redirect('forums/detail/' . $forum['id']);
    }

    public function replace($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $participants = $this->model('ForumParticipantModel');
        $row = $participants->findById($id);
        if (!$row) $this->notFound('Peserta tidak ditemukan.');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($row['forum_id']);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        $newPersonId = (int) ($_POST['new_person_id'] ?? 0);
        if ($newPersonId === 0) {
            Flash::set('error', 'Pengganti wajib dipilih.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        $reason = trim($_POST['reason'] ?? '');
        if ($forums->isLocked($forum) && $reason === '') {
            Flash::set('error', 'Forum sudah dikunci. Alasan penggantian wajib diisi.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        try {
            $participants->replace($id, [
                'person_id'                  => $newPersonId,
                'category'                   => $row['category'],
                'representing_structure_id'  => $row['representing_structure_id'],
                'reason'                     => $reason,
            ], $forum);
            Flash::set('success', 'Peserta berhasil diganti.');
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('forums/detail/' . $forum['id']);
    }

    public function remove($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $participants = $this->model('ForumParticipantModel');
        $row = $participants->findById($id);
        if (!$row) $this->notFound('Peserta tidak ditemukan.');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($row['forum_id']);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if ($forums->isLocked($forum)) {
            Flash::set('error', 'Peserta tidak bisa dihapus setelah forum dikunci. Gunakan Cabut atau Ganti.');
            $this->redirect('forums/detail/' . $forum['id']);
        }

        $participants->remove($id, $forum);
        Flash::set('success', 'Peserta dihapus.');
        $this->redirect('forums/detail/' . $forum['id']);
    }

    public function lock($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if (!$forums->advanceStatus($id, 'dikunci')) {
            Flash::set('error', 'Status forum tidak bisa dimajukan ke "dikunci".');
            $this->redirect('forums/detail/' . $id);
        }

        Flash::set('success', 'Forum dikunci. Perubahan peserta masih dimungkinkan dengan alasan.');
        $this->redirect('forums/detail/' . $id);
    }

    public function open($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if (!$forums->advanceStatus($id, 'terbuka')) {
            Flash::set('error', 'Status forum tidak bisa dimajukan ke "terbuka".');
            $this->redirect('forums/detail/' . $id);
        }

        Flash::set('success', 'Forum dibuka untuk penetapan peserta.');
        $this->redirect('forums/detail/' . $id);
    }

    public function finish($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if (!$forums->advanceStatus($id, 'selesai')) {
            Flash::set('error', 'Status forum tidak bisa dimajukan ke "selesai".');
            $this->redirect('forums/detail/' . $id);
        }

        Flash::set('success', 'Forum ditandai selesai. Data menjadi baca-saja.');
        $this->redirect('forums/detail/' . $id);
    }

    public function delete($id) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('forums');

        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        if (!$forums->softDelete($id)) {
            Flash::set('error', 'Hanya forum berstatus Draft yang bisa dihapus.');
            $this->redirect('forums/detail/' . $id);
        }

        Flash::set('success', 'Forum dihapus.');
        $this->redirect('forums');
    }

    public function history($id) {
        $this->requireLogin();
        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        $this->view('admin/forums/history', [
            'title'   => 'Riwayat — ' . $forum['name'],
            'forum'   => $forum,
            'history' => $forums->history($id),
        ]);
    }

    public function export($id) {
        $this->requireLogin();
        $forums = $this->model('ForumModel');
        $forum = $forums->findById($id);
        if (!$forum) $this->notFound('Forum tidak ditemukan.');
        $this->requireScope($forum['organizer_structure_id']);

        $participants = $this->model('ForumParticipantModel');
        $rows = $participants->byForum($id);

        $categoryLabel = ['peserta_penuh' => 'Peserta Penuh', 'undangan' => 'Undangan', 'peninjau' => 'Peninjau'];
        $statusLabel = ['ditetapkan' => 'Ditetapkan', 'dicabut' => 'Dicabut', 'diganti' => 'Diganti'];

        $filename = 'peserta-' . preg_replace('/[^a-z0-9_-]/i', '_', $forum['name']) . '-' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['No', 'Nama', 'Kategori', 'Utusan', 'Jabatan Dasar', 'Status', 'No HP', 'SK', 'Setelah Kunci']);

        $no = 0;
        foreach ($rows as $r) {
            $no++;
            $utusan = $r['repr_level'] ? trim($r['repr_level'] . ' ' . $r['repr_structure_name']) : '';
            $basis  = $r['position_name'] ? ($r['position_name'] . ' — ' . trim($r['basis_level'] . ' ' . $r['basis_structure_name'])) : '';
            fputcsv($fp, [
                $no,
                $r['full_name'],
                $categoryLabel[$r['category']] ?? $r['category'],
                $utusan,
                $basis,
                $statusLabel[$r['status']] ?? $r['status'],
                $r['phone'] ?? '',
                $r['decision_ref'] ?? '',
                $r['changed_after_lock'] ? 'Ya' : '',
            ]);
        }
        fclose($fp);

        Audit::log('forums', $id, 'export', null, ['count' => $no, 'format' => 'csv']);
        exit;
    }
}
