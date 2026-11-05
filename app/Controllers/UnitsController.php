<?php
// PATH LOKASI FILE: app/Controllers/UnitsController.php
// SKUO: katalog unit bersama, Bidang per periode, unit per periode, dan pengelompokan unit ke Bidang per struktur.

class UnitsController extends Controller {
    const UNIT_KINDS = ['departemen', 'lembaga', 'biro', 'badan', 'pusat', 'divisi', 'lainnya'];

    // Katalog nama unit yang dipakai bersama lintas struktur: Departemen, Lembaga, Biro, Badan,
    // Pusat, Divisi. Bidang TIDAK di sini -- ia hidup per struktur per periode (units/grouping).
    public function catalog() {
        $this->requireLogin();
        $units = $this->model('UnitModel');

        $kind    = in_array($_GET['kind'] ?? '', self::UNIT_KINDS, true) ? $_GET['kind'] : '';
        $keyword = trim($_GET['q'] ?? '');
        $urut    = ($_GET['urut'] ?? '') === 'skuo';   // satu daftar menurut urutan resmi SKUO, bukan per jenis
        $wing    = isset(UnitModel::WINGS[$_GET['w'] ?? '']) ? $_GET['w'] : '';   // katalog sayap Umum / Muslimah; kosong = keduanya

        // Unit yang baru ditambahkan (?baru=id): halaman menawarkan langsung memasangnya ke struktur, karena entri katalog saja belum
        // muncul di struktur mana pun. Hanya bila belum terpasang di mana pun dan admin boleh mengubah.
        $baru = null;
        if (Auth::canEdit() && !empty($_GET['baru'])) {
            $baru = $units->catalogFindById((int) $_GET['baru']);
            if ($baru && $units->unitUsage((int) $baru['id']) > 0) $baru = null;
        }

        $this->view('admin/units/catalog', [
            'title'   => 'Unit Organisasi',
            'baru'    => $baru,
            'targets' => Auth::canEdit() ? $units->installTargets() : [],
            'rows'    => $units->catalogList($kind, $keyword, $urut, $wing),
            'counts'  => $units->catalogCountsByKind($wing),
            'wing'    => $wing,
            'wingCounts' => $units->catalogCountsByWing(),
            'kind'    => $kind,
            'keyword' => $keyword,
            'urut'    => $urut,
            'inti'    => $units->intiInfo(),
            'nextOrder' => $units->catalogNextOrder(),
        ]);
    }

    // Kembali ke katalog dengan saringan yang sedang dipakai masih utuh: setelah mengubah satu unit
    // di antara 105 baris, kembali ke daftar penuh berarti kehilangan tempat.
    // $baru: id entri yang baru dibuat, supaya halaman katalog menawarkan memasangnya ke struktur.
    private function backToCatalog($baru = 0) {
        $kind = in_array($_POST['kind_filter'] ?? '', self::UNIT_KINDS, true) ? $_POST['kind_filter'] : '';
        $q    = trim($_POST['q_filter'] ?? '');
        $w    = isset(UnitModel::WINGS[$_POST['w_filter'] ?? '']) ? $_POST['w_filter'] : '';
        $p    = array_filter(['w' => $w, 'kind' => $kind, 'q' => $q, 'urut' => ($_POST['urut_filter'] ?? '') === 'skuo' ? 'skuo' : '', 'baru' => $baru ?: ''], function ($v) { return $v !== ''; });
        $this->redirect('units/catalog' . ($p ? '?' . http_build_query($p) : ''));
    }

    public function catalog_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/catalog');

        $units = $this->model('UnitModel');
        try {
            $units->catalogDelete((int) ($_POST['id'] ?? 0));
            Flash::set('success', 'Unit dihapus dari katalog.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->backToCatalog();
    }

    public function catalog_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/catalog');

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'name'         => trim($_POST['name'] ?? ''),
            'abbreviation' => trim($_POST['abbreviation'] ?? ''),
            'kind'         => in_array($_POST['kind'] ?? '', self::UNIT_KINDS, true) ? $_POST['kind'] : 'lainnya',
            'wing'         => isset(UnitModel::WINGS[$_POST['wing'] ?? '']) ? $_POST['wing'] : null,   // sayap katalog; kosong: entri baru 'umum', entri lama tak berubah
            'is_active'    => !empty($_POST['is_active']),
            'sort_order'   => trim((string) ($_POST['sort_order'] ?? '')),   // kosong: entri baru ke urutan terakhir, entri lama tetap
        ];

        if ($data['name'] === '') {
            Flash::set('error', 'Nama unit wajib diisi.');
            $this->backToCatalog();
        }
        // Entri baru wajib menyebut sayapnya: sayap yang terlewat (dulu bawaan Umum) membuat unit Muslimah tak pernah muncul sebagai saran di MWP.
        if (!$id && $data['wing'] === null) {
            Flash::set('error', 'Pilih sayap unit ini: Umum (DPP, DPW, DPD, ...) atau Muslimah (MWP, MWW, MWD, ...).');
            $this->backToCatalog();
        }

        $baru = 0;
        try {
            $saved = $units->catalogSave($id, $data);
            if (!$id) $baru = (int) $saved;
            Flash::set('success', $id ? 'Katalog unit disimpan.' : '"' . $data['name'] . '" masuk ke katalog ' . UnitModel::WINGS[$data['wing']] . '. Belum terpasang di struktur mana pun.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_unit_catalog_name') !== false) {
                Flash::set('error', 'Nama unit itu sudah ada di katalog sayap tersebut. Katalog dipakai bersama lintas struktur sesayap, jadi cukup pakai yang sudah ada.');
            } else {
                error_log('Simpan katalog unit gagal: ' . $e->getMessage());
                Flash::set('error', 'Unit tidak bisa disimpan.');
            }
        }
        $this->backToCatalog($baru);
    }

    // Tampilan halaman Pengelompokan Unit memuat 500+ struktur, hampir semuanya masih kosong (belum punya Bidang maupun unit). Karena itu
    // daftarnya disaring: jenjang (?lv=), status (?st=disusun|belum|semua) dan nama (?q=). Tanpa ?st= yang dipakai "Sudah disusun", kecuali
    // belum ada yang disusun sama sekali (mis. admin satu DPD baru): saat itu "Semua". Baris yang baru disimpan (?open=) selalu ikut tampil.
    const GROUPING_STATUS = ['disusun' => 'Sudah disusun', 'belum' => 'Belum disusun', 'semua' => 'Semua'];
    const GROUPING_MAX_ROWS = 60;

    // Pengelompokan unit per struktur: untuk tiap struktur dalam lingkup yang punya periode berjalan, Bidang-nya dan unit di
    // bawah tiap Bidang. Struktur tanpa periode berjalan dikumpulkan terpisah (Bidang dan unit terikat periode, jadi belum ada).
    public function grouping() {
        $this->requireLogin();
        $structures = $this->model('StructureModel')->allInScope();
        $terms = $this->model('TermModel')->allInScope();
        $units = $this->model('UnitModel');
        $counts = $units->termCounts();

        $currentOf = [];
        foreach ($terms as $t) {
            if (!empty($t['is_current'])) $currentOf[(int) $t['structure_id']] = $t;
        }

        // Sayap umum dulu, lalu Muslimah; di dalamnya dari tingkat Pusat ke Ranting, lalu menurut nama.
        usort($structures, function ($a, $b) {
            return [$a['wing'] === 'muslimah', (int) $a['tier'], $a['name']] <=> [$b['wing'] === 'muslimah', (int) $b['tier'], $b['name']];
        });

        // Satu daftar ringan dulu (tanpa Bidang dan unit): saringan dan angka chip dihitung dari sini, isi rinci hanya dimuat untuk baris yang tampil.
        $all = []; $without = [];
        foreach ($structures as $s) {
            $t = $currentOf[(int) $s['id']] ?? null;
            if (!$t) { $without[] = $s; continue; }
            $c = $counts[(int) $t['id']] ?? ['groups' => 0, 'units' => 0];
            $all[] = ['structure' => $s, 'term' => $t, 'disusun' => ($c['groups'] + $c['units']) > 0];
        }

        // Parameter bisa datang sebagai larik (?st[]=x): hanya teks yang dipakai, selebihnya dianggap kosong.
        $param = function ($key) { $v = $_GET[$key] ?? ''; return is_string($v) ? $v : ''; };
        $keyword = mb_substr(trim($param('q')), 0, 100);
        $matchName = function ($s) use ($keyword) {
            return $keyword === '' || mb_stripos($s['level'] . ' ' . $s['name'], $keyword) !== false;
        };
        $nSusun = count(array_filter($all, function ($e) { return $e['disusun']; }));
        $st = $param('st');
        if (!isset(self::GROUPING_STATUS[$st])) $st = $nSusun > 0 ? 'disusun' : 'semua';

        // Angka chip: jenjang dihitung menurut status + nama yang sedang dipilih, status menurut jenjang + nama.
        $lv = $param('lv');
        $okStatus = function ($e) use ($st) { return $st === 'semua' || ($st === 'disusun') === $e['disusun']; };
        $levelCounts = []; $statusCounts = ['disusun' => 0, 'belum' => 0, 'semua' => 0];
        foreach ($all as $e) {
            if (!$matchName($e['structure'])) continue;
            if ($okStatus($e)) $levelCounts[$e['structure']['level']] = ($levelCounts[$e['structure']['level']] ?? 0) + 1;
            if ($lv === '' || $lv === $e['structure']['level']) {
                $statusCounts[$e['disusun'] ? 'disusun' : 'belum']++;
                $statusCounts['semua']++;
            }
        }
        $allLevels = array_unique(array_map(function ($e) { return $e['structure']['level']; }, $all));
        if (!in_array($lv, $allLevels, true)) $lv = '';

        $open = (int) $param('open');
        $match = array_values(array_filter($all, function ($e) use ($lv, $okStatus, $matchName, $open) {
            if ((int) $e['term']['id'] === $open) return true;
            return ($lv === '' || $e['structure']['level'] === $lv) && $matchName($e['structure']) && $okStatus($e);
        }));
        $matched = count($match);
        $shown = array_slice($match, 0, self::GROUPING_MAX_ROWS);
        foreach ($match as $e) {   // baris yang baru dikerjakan tetap tampil walau di luar batas
            if ((int) $e['term']['id'] === $open && !in_array($e, $shown, true)) $shown[] = $e;
        }

        $entries = [];
        foreach ($shown as $e) {
            $tid = (int) $e['term']['id'];
            $entries[] = $e + ['groups' => $units->groupsByTerm($tid), 'units' => $units->unitsByTerm($tid),
                               'coordinators' => $units->coordinatorCandidates($tid)];
        }

        $this->view('admin/units/grouping', [
            'title'    => 'Pengelompokan Unit',
            'entries'  => $entries,
            'without'  => $st === 'disusun' ? [] : array_values(array_filter($without, function ($s) use ($lv, $matchName) {
                return ($lv === '' || $s['level'] === $lv) && $matchName($s);
            })),
            'openTerm' => $open,
            'catalog'  => Auth::canEdit() ? $units->catalogAll(true) : [],   // saran nama pada isian "Tambah unit" (tiap entri membawa wing: saran dipilah per sayap struktur)
            'lv'       => $lv,
            'st'       => $st,
            'q'        => $keyword,
            'levelCounts'  => $levelCounts,
            'statusCounts' => $statusCounts,
            'totalAll' => count($all),
            'matched'  => $matched,
            'maxRows'  => self::GROUPING_MAX_ROWS,
        ]);
    }

    // Aksi-aksi simpan/hapus di bawah dipakai halaman Bidang & Unit sebuah periode DAN halaman Pengelompokan Unit; yang kedua menandai
    // asalnya dengan return=grouping supaya pengguna kembali ke strukturnya di sana (terbuka), bukan ke halaman Bidang & Unit. Saringan
    // yang sedang dipakai di halaman itu (f_lv, f_st, f_q) ikut dibawa pulang.
    private function back($termId) {
        $termId = (int) $termId;
        if (($_POST['return'] ?? '') === 'grouping') {
            $p = ['open' => $termId];
            $lv = is_string($_POST['f_lv'] ?? null) ? $_POST['f_lv'] : '';
            $st = is_string($_POST['f_st'] ?? null) ? $_POST['f_st'] : '';
            $q  = is_string($_POST['f_q'] ?? null) ? mb_substr(trim($_POST['f_q']), 0, 100) : '';
            if (preg_match('/^[A-Za-z]{2,5}$/', $lv)) $p['lv'] = $lv;
            if (isset(self::GROUPING_STATUS[$st])) $p['st'] = $st;
            if ($q !== '') $p['q'] = $q;
            $this->redirect('units/grouping?' . http_build_query($p) . '#t' . $termId);
        }
        $this->redirect('units/term/' . $termId);
    }

    // Isian angka urutan: kolomnya SMALLINT UNSIGNED, jadi nilai di luar rentang ditahan di ujungnya, bukan dibiarkan jadi galat basis data.
    private function sortOrder($value) {
        return max(0, min(65535, (int) $value));
    }

    // Menyimpan pengelompokan satu periode: tiap unit ke Bidang yang dipilih di halaman Pengelompokan Unit.
    public function grouping_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('units/grouping');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $term = $this->model('TermModel')->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $moves = is_array($_POST['group'] ?? null) ? $_POST['group'] : [];
        try {
            $n = $this->model('UnitModel')->regroup($termId, $moves);
            Flash::set('success', $n > 0 ? $n . ' unit dipindahkan.' : 'Tidak ada perubahan.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Simpan pengelompokan gagal: ' . $e->getMessage());
            Flash::set('error', 'Pengelompokan tidak bisa disimpan.');
        }
        $_POST['return'] = 'grouping';   // formulirnya hanya ada di halaman Pengelompokan Unit; back() membawa pulang saringannya
        $this->back($termId);
    }

    // Bidang & unit milik satu periode.
    public function term($termId) {
        $this->requireLogin();

        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');

        $this->view('admin/units/term', [
            'title'   => 'Bidang & Unit: ' . $term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name'],
            'term'    => $term,
            'groups'  => $units->groupsByTerm($termId),
            'rows'    => $units->unitsByTerm($termId),
            'catalog' => $units->catalogAll(true, $units->wingOfStructure($term['structure_id'])),   // hanya katalog sayap struktur ini
        ]);
    }

    public function group_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Periode di atas sudah diperiksa lingkupnya; Bidang yang diubah harus benar-benar milik periode itu, bukan sekadar id yang dikirim.
        if ($id) {
            $existing = $units->groupFindById($id);
            if (!$existing || (int) $existing['term_id'] !== $termId) $this->notFound('Bidang tidak ditemukan di periode ini.');
        }

        $data = [
            'structure_id' => $term['structure_id'],
            'term_id'      => $termId,
            'name'         => trim($_POST['name'] ?? ''),
            'kind'         => in_array($_POST['kind'] ?? '', ['bidang','subbidang','grup'], true) ? $_POST['kind'] : 'bidang',
            'sort_order'   => $this->sortOrder($_POST['sort_order'] ?? 500),
        ];

        if ($data['name'] === '' || mb_strlen($data['name']) > 200) {
            Flash::set('error', 'Nama Bidang wajib diisi, paling banyak 200 huruf.');
            $this->back($termId);
        }

        // Ketua Bidang hanya diubah bila formulirnya mengirim isian itu (halaman Bidang & Unit lama tidak mengirimnya). Id yang
        // dikirim wajib salah satu calon sah periode ini; kelompok biasa (Biro-Biro, dst.) tidak punya Ketua Bidang.
        if ($id && array_key_exists('coordinator_placement_id', $_POST)) {
            $coord = !empty($_POST['coordinator_placement_id']) ? (int) $_POST['coordinator_placement_id'] : null;
            if ($coord !== null && ($data['kind'] === 'grup' || !$units->isCoordinatorCandidate($termId, $coord))) {
                Flash::set('error', 'Ketua Bidang harus Ketua atau Wakil Ketua di Pengurus Inti periode ini.');
                $this->back($termId);
            }
            $data['coordinator_placement_id'] = $coord;
        }

        try {
            $units->groupSave($id, $data);
            Flash::set('success', 'Bidang disimpan.');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_unit_groups_term_name') !== false) {
                Flash::set('error', 'Bidang dengan nama itu sudah ada di periode ini.');
            } else {
                error_log('Simpan bidang gagal: ' . $e->getMessage());
                Flash::set('error', 'Bidang tidak bisa disimpan.');
            }
        }
        $this->back($termId);
    }

    public function group_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $units = $this->model('UnitModel');
        $group = $units->groupFindById((int) ($_POST['id'] ?? 0));
        if (!$group) $this->notFound('Bidang tidak ditemukan.');
        $this->requireScope($group['structure_id']);

        try {
            $units->groupDelete($group['id']);
            Flash::set('success', 'Bidang dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->back($group['term_id']);
    }

    public function unit_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $termId = (int) ($_POST['term_id'] ?? 0);
        $terms = $this->model('TermModel');
        $term = $terms->findById($termId);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $units = $this->model('UnitModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Unit yang diubah harus benar-benar milik periode yang lingkupnya diperiksa di atas, bukan sekadar id yang dikirim.
        $existing = null;
        if ($id) {
            $existing = $units->unitFindById($id);
            if (!$existing || (int) $existing['term_id'] !== $termId) $this->notFound('Unit tidak ditemukan di periode ini.');
        }

        // Bidang yang dipilih harus milik periode ini. Database juga menolaknya lewat composite FK,
        // tapi dicek di sini supaya pesannya bisa dibaca. Mengubah unit tanpa mengirim unit_group_id (mis. hanya nama tampil
        // dan urutan) membiarkan Bidangnya apa adanya.
        // Tujuan unit BARU datang dari unit_group_id (formulir di dalam sebuah kelompok) atau dari "masuk" (formulir tingkat struktur dan
        // "Pasang" di katalog): angka = kelompok itu, 'none' / kosong = tanpa Bidang, 'auto' = kelompok menurut jenis unit. 'auto' baru bisa
        // diputuskan setelah katalognya beres di bawah, sebab jenis unit dari nama yang diketik baru ketahuan di situ.
        $auto = false;
        if (array_key_exists('unit_group_id', $_POST) || !$existing) {
            $pick = array_key_exists('unit_group_id', $_POST) ? $_POST['unit_group_id'] : ($_POST['masuk'] ?? '');
            $pick = is_scalar($pick) ? (string) $pick : '';
            $auto = !$existing && $pick === 'auto';
            $groupId = ctype_digit($pick) && (int) $pick > 0 ? (int) $pick : null;
        } else {
            $groupId = $existing['unit_group_id'] !== null ? (int) $existing['unit_group_id'] : null;
        }
        if ($groupId !== null) {
            $group = $units->groupFindById($groupId);
            if (!$group || (int) $group['term_id'] !== $termId) {
                Flash::set('error', 'Bidang yang dipilih bukan milik periode ini.');
                $this->back($termId);
            }
        }

        $displayName = trim($_POST['display_name'] ?? '');
        if (mb_strlen($displayName) > 200) {
            Flash::set('error', 'Nama tampil paling banyak 200 huruf.');
            $this->back($termId);
        }

        // Menambah unit: pilih dari katalog (halaman Bidang & Unit), atau ketik namanya (halaman Pengelompokan Unit): nama yang
        // sudah ada di katalog dipakai ulang, yang belum ada dibuat dengan jenis yang dipilih.
        $catalogId = (int) ($_POST['unit_catalog_id'] ?? 0);
        $createdInCatalog = false;
        if (!$id && !$catalogId) {
            $newName = trim($_POST['new_name'] ?? '');
            if ($newName === '' || mb_strlen($newName) > 200) {
                Flash::set('error', 'Pilih unit dari katalog, atau ketik nama unit (paling banyak 200 huruf).');
                $this->back($termId);
            }
            $kind = in_array($_POST['new_kind'] ?? '', self::UNIT_KINDS, true) ? $_POST['new_kind'] : 'lainnya';
            // Nama dicari / dibuat di katalog SAYAP struktur ini (unit Muslimah tak memakai entri DPP, walau namanya sama).
            [$catalogId, $createdInCatalog] = $units->catalogEnsure($newName, $kind, $units->wingOfStructure($term['structure_id']));
        }

        // Unit baru: tolak yang mustahil SEBELUM kelompok otomatis dibuat, supaya penolakan tak meninggalkan kelompok kosong.
        $groupNote = '';
        if (!$existing) {
            try {
                $units->assertCatalogFitsStructure($catalogId, $term['structure_id']);
            } catch (RuntimeException $e) {
                Flash::set('error', $e->getMessage());
                $this->back($termId);
            }
            if ($units->unitInTerm($termId, $catalogId)) {
                Flash::set('error', 'Unit itu sudah terdaftar di periode ini.');
                $this->back($termId);
            }
            if ($auto) {
                [$groupId, $groupNote] = $units->groupForKind($term['structure_id'], $termId, (string) ($units->catalogFindById($catalogId)['kind'] ?? 'lainnya'));
            }
        }

        $data = [
            'structure_id'    => $term['structure_id'],
            'term_id'         => $termId,
            'unit_catalog_id' => $existing ? (int) $existing['unit_catalog_id'] : $catalogId,
            'unit_group_id'   => $groupId,
            'display_name'    => $displayName,
            // Unit baru yang urutannya tak diisi mengikuti urutan resmi SKUO di katalog (500 bila entri itu belum diurutkan).
            'sort_order'      => $this->sortOrder(($_POST['sort_order'] ?? '') !== '' ? $_POST['sort_order']
                                  : ($units->catalogFindById($existing ? (int) $existing['unit_catalog_id'] : $catalogId)['sort_order'] ?? 500)),
        ];

        try {
            $units->unitSave($id, $data);
            if ($id) {
                Flash::set('success', 'Unit disimpan.');
            } else {
                // Pesan menyebut TEMPAT unit itu mendarat, termasuk kelompok yang dibuat otomatis: di situlah pengguna akan mencarinya.
                if ($groupNote === '' && $groupId) $groupNote = 'kelompok ' . ($units->groupFindById($groupId)['name'] ?? '');
                $unitName = $units->catalogFindById($catalogId)['name'] ?? 'Unit';
                Flash::set('success', ($createdInCatalog ? 'Unit baru dibuat di katalog. ' : '') . '"' . $unitName . '" ditambahkan ke ' . $term['level'] . ' ' . $term['structure_name']
                    . ($groupNote !== '' ? ', masuk ' . $groupNote : ', tanpa Bidang (pilih Bidangnya di daftar bawah, lalu Simpan pengelompokan)') . '.');
            }
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_structure_units_term_unit') !== false) {
                Flash::set('error', 'Unit itu sudah terdaftar di periode ini.');
            } else {
                error_log('Simpan unit gagal: ' . $e->getMessage());
                Flash::set('error', 'Unit tidak bisa disimpan.');
            }
        }
        $this->back($termId);
    }

    public function unit_delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $units = $this->model('UnitModel');
        $unit = $units->unitFindById((int) ($_POST['id'] ?? 0));
        if (!$unit) $this->notFound('Unit tidak ditemukan.');
        $this->requireScope($unit['structure_id']);

        try {
            $units->unitDelete($unit['id']);
            Flash::set('success', 'Unit dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->back($unit['term_id']);
    }
}
