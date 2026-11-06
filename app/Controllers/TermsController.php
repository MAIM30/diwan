<?php
// PATH LOKASI FILE: app/Controllers/TermsController.php
// Periode kepengurusan. Halaman ini yang membuat riwayat antar periode bisa dilihat utuh.

class TermsController extends Controller {
    const PER_PAGE_OPTIONS = [25, 50, 100];

    // Saringan status (?f=): kunci => label chip. Satu per satu, bukan gabungan.
    const FILTERS = [
        'berjalan' => 'Berjalan',
        'arsip'    => 'Arsip',
        'kosong'   => 'Belum ada pengurus',
        'ada_sk'   => 'Ada SK',
        'tanpa_sk' => 'Tanpa SK',
    ];

    // Daftar periode berhalaman dengan saringan seperti halaman Struktur: baris Jenjang (chip), Acuan periode (chip, bila lebih dari satu),
    // kotak cari, dan chip status. Semuanya disaring di PHP dari satu daftar (TermModel::allInScope), tanpa perubahan database.
    // ?structure=ID (tautan dari Struktur dan dasbor) membatasi pada satu struktur dan ikut terbawa di setiap tautan halaman ini.
    // Angka tiap chip = jumlah baris yang tampil bila chip itu diketuk (mengikuti saringan lain).
    public function index() {
        $this->requireLogin();
        $all = $this->model('TermModel')->allInScope();
        $structures = [];
        foreach ($this->model('StructureModel')->allInScope() as $s) $structures[(int) $s['id']] = $s;   // induk di luar lingkup tak pernah tampil

        // Parameter bisa datang sebagai larik (?lv[]=x): hanya teks yang dipakai, selebihnya dianggap kosong.
        $param = function ($key) { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; };

        $items = [];
        foreach ($all as $r) {
            $p = $r['structure_parent_id'] !== null ? ($structures[(int) $r['structure_parent_id']] ?? null) : null;
            $items[] = $r + [
                'short'        => PeopleOverview::shortName($r['structure_name']),
                'parent_text'  => $p ? $p['level'] . ' ' . PeopleOverview::shortName($p['name']) : '',
                // Induk nasional (DPP, MWP) cukup jenjangnya; "Dewan Pimpinan Pusat" di bawah setiap DPW hanya memanjangkan baris.
                'parent_label' => $p ? ((int) $p['tier'] <= 1 ? $p['level'] : $p['level'] . ' ' . PeopleOverview::shortName($p['name'])) : '',
                'parent_short' => $p ? PeopleOverview::shortName($p['name']) : '',
            ];
        }

        $levelsPresent = array_keys(array_count_values(array_column($items, 'level')));
        $periods = [];   // id => nama
        foreach ($items as $r) $periods[(int) $r['governance_period_id']] = $r['shared_period_name'];
        krsort($periods);   // acuan terbaru lebih dulu

        $lv = $param('lv');
        if (!in_array($lv, $levelsPresent, true)) $lv = '';
        $pr = (int) $param('pr');
        if (!isset($periods[$pr]) || count($periods) < 2) $pr = 0;
        $f = $param('f');
        if (!isset(self::FILTERS[$f])) $f = '';
        $structureId = (int) $param('structure');
        $q = mb_substr($param('q'), 0, 100);
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 4);

        // Pencarian: setiap kata harus ada di jenjang, nama, induk, acuan periode, nama periode, atau nomor SK.
        $matchQ = function (array $r) use ($words) {
            if (!$words) return true;
            $hay = $r['level'] . ' ' . $r['structure_name'] . ' ' . $r['parent_text'] . ' ' . $r['shared_period_name'] . ' ' . $r['name'] . ' ' . $r['sk_number'];
            foreach ($words as $w) if (mb_stripos($hay, $w) === false) return false;
            return true;
        };
        $status = function ($key, array $r) {
            switch ($key) {
                case 'berjalan': return !empty($r['is_current']);
                case 'arsip':    return empty($r['is_current']);
                case 'kosong':   return (int) $r['placement_count'] === 0;
                case 'ada_sk':   return trim((string) $r['sk_number']) !== '';
                case 'tanpa_sk': return trim((string) $r['sk_number']) === '';
            }
            return true;
        };
        $inStructure = function (array $r) use ($structureId) { return !$structureId || (int) $r['structure_id'] === $structureId; };

        // Angka chip: tiap kelompok dihitung menurut SEMUA saringan lain, supaya angkanya = baris yang akan tampil bila diketuk.
        $levelCounts = array_fill_keys($levelsPresent, 0);
        $periodCounts = array_fill_keys(array_keys($periods), 0);
        $filterCounts = array_fill_keys(array_keys(self::FILTERS), 0); $filterCounts[''] = 0;
        foreach ($items as $r) {
            if (!$inStructure($r) || !$matchQ($r)) continue;
            $okLv = $lv === '' || $r['level'] === $lv;
            $okPr = $pr === 0 || (int) $r['governance_period_id'] === $pr;
            $okF = $f === '' || $status($f, $r);
            if ($okPr && $okF) $levelCounts[$r['level']]++;
            if ($okLv && $okF) $periodCounts[(int) $r['governance_period_id']]++;
            if ($okLv && $okPr) {
                $filterCounts['']++;
                foreach (self::FILTERS as $k => $label) if ($status($k, $r)) $filterCounts[$k]++;
            }
        }

        $matched = array_values(array_filter($items, function ($r) use ($lv, $pr, $f, $matchQ, $status, $inStructure) {
            return $inStructure($r) && ($lv === '' || $r['level'] === $lv) && ($pr === 0 || (int) $r['governance_period_id'] === $pr)
                && ($f === '' || $status($f, $r)) && $matchQ($r);
        }));
        $filtered = $lv !== '' || $pr !== 0 || $f !== '' || $q !== '' || $structureId !== 0;

        // Urutan jenjang baku (DPP, DPW, DPLN, DPD, ...), induknya (kabupaten satu provinsi berdampingan), nama, lalu periode terbaru dulu.
        $rank = array_flip(PeopleOverview::orderedLevels($levelsPresent));
        usort($matched, function ($a, $b) use ($rank) {
            return ($rank[$a['level']] <=> $rank[$b['level']]) ?: strnatcasecmp($a['parent_short'], $b['parent_short'])
                ?: strnatcasecmp($a['short'], $b['short']) ?: strcmp($b['start_date'], $a['start_date']);
        });

        $perPage = in_array((int) $param('per_page'), self::PER_PAGE_OPTIONS, true) ? (int) $param('per_page') : 50;
        $page = min(max(1, (int) $param('page')), max(1, (int) ceil(count($matched) / $perPage)));
        $structureLabel = '';
        if ($structureId && isset($structures[$structureId])) $structureLabel = $structures[$structureId]['level'] . ' ' . PeopleOverview::shortName($structures[$structureId]['name']);

        $this->view('admin/terms/index', [
            'title'          => 'Periode Kepengurusan',
            'allCount'       => count($items),
            'total'          => count($matched),
            'rows'           => array_slice($matched, ($page - 1) * $perPage, $perPage),
            'filtered'       => $filtered,
            'levelCounts'    => $levelCounts,
            'periods'        => count($periods) > 1 ? $periods : [],
            'periodCounts'   => $periodCounts,
            'filters'        => self::FILTERS,
            'filterCounts'   => $filterCounts,
            'lv'             => $lv,
            'pr'             => $pr,
            'f'              => $f,
            'q'              => $q,
            'structureId'    => $structureId,
            'structureLabel' => $structureLabel,
            'page'           => $page,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function period_catalog() {
        $this->requireLogin();
        $this->view('admin/terms/period_catalog', [
            'title' => 'Acuan Periode Kepengurusan',
            'rows' => $this->model('TermModel')->governancePeriodsInScope(),
        ]);
    }

    public function period_detail($id) {
        $this->requireLogin();
        $terms = $this->model('TermModel');
        $period = $terms->governancePeriodFindById($id);
        $rows = $period ? $terms->termsForGovernancePeriod($id) : [];
        if (!$period) $this->notFound('Acuan periode tidak ditemukan.');
        $this->view('admin/terms/period_detail', [
            'title' => 'Rekap Periode ' . $period['name'],
            'period' => $period,
            'rows' => $rows,
            'availableStructures' => $terms->structuresNotUsingGovernancePeriod($id),
            'placementCount' => array_sum(array_column($rows, 'placement_count')),
        ]);
    }

    public function attach_structures() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms/period_catalog');

        $periodId = (int) ($_POST['governance_period_id'] ?? 0);
        $terms = $this->model('TermModel');
        $period = $terms->governancePeriodFindById($periodId);
        if (!$period) $this->notFound('Acuan periode tidak ditemukan.');

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['structure_ids'] ?? [])))));
        $structures = $this->model('StructureModel');
        foreach ($ids as $structureId) {
            $structure = $structures->findById($structureId);
            if (!$structure || empty($structure['is_active'])) $this->notFound('Struktur aktif tidak ditemukan.');
            $this->requireScope($structureId);
        }

        try {
            $count = $terms->attachGovernancePeriodToStructures($periodId, $ids, !empty($_POST['mark_current']));
            Flash::set('success', $count
                ? $count . ' struktur ditautkan ke periode ' . $period['name'] . '. Susunan unit dan SK dapat dilengkapi pada setiap struktur.'
                : 'Tidak ada perubahan; struktur yang dipilih sudah memakai periode ini.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Tautkan periode ke struktur gagal: ' . $e->getMessage());
            Flash::set('error', 'Periode tidak dapat ditautkan ke struktur yang dipilih.');
        }
        $this->redirect('terms/period_detail/' . $periodId);
    }

    public function period_form() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        $this->view('admin/terms/period_form', ['title' => 'Buat Acuan Periode Kepengurusan']);
    }

    public function period_save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms/period_catalog');

        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date_planned' => $_POST['end_date_planned'] ?? '',
        ];
        if ($data['name'] === '' || $data['start_date'] === '') {
            Flash::set('error', 'Nama periode dan tanggal mulai wajib diisi.');
            $this->redirect('terms/period_form');
        }
        if ($data['end_date_planned'] !== '' && $data['end_date_planned'] < $data['start_date']) {
            Flash::set('error', 'Tanggal akhir rencana tidak boleh lebih awal dari tanggal mulai.');
            $this->redirect('terms/period_form');
        }

        try {
            $this->model('TermModel')->createGovernancePeriod($data);
            Flash::set('success', 'Acuan periode dibuat. Sekarang pilih periode ini saat menambahkan periode pada setiap struktur.');
        } catch (PDOException $e) {
            Flash::set('error', 'Acuan dengan nama dan rentang tanggal tersebut sudah tersedia.');
        }
        $this->redirect('terms/period_catalog');
    }

    // Susunan pengurus satu periode, dikelompokkan per kategori (Pengurus Inti, Bidang beserta Departemen dan Lembaganya, dst.).
    public function detail($id) {
        $this->requireLogin();

        $terms = $this->model('TermModel');
        $term = $terms->findById($id);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $placements = $this->model('PlacementModel');
        $units = $this->model('UnitModel');
        $rows = $placements->byTerm($id);
        $unitRows = $units->unitsByTerm($id);
        $groups = $units->groupsByTerm($id);

        $this->view('admin/terms/detail', [
            'title'      => 'Susunan ' . $term['level'] . ' ' . $term['structure_name'] . ' ' . $term['name'],
            'term'       => $term,
            'rows'       => $rows,
            'tree'       => Susunan::arrange($rows, $groups, $unitRows),
            'units'      => $unitRows,
            'groups'     => $groups,
            'otherTerms' => $terms->byStructure($term['structure_id']),
            'skCoverage' => $terms->skCoverage($id),
        ]);
    }

    // Mengunduh/membuka berkas SK periode ini. Harus login DAN periodenya berada di lingkup admin. Berkas ada di
    // storage/ (di luar folder publik), jadi inilah satu-satunya jalan ke sana.
    public function sk($id = 0) {
        $this->requireLogin();
        $terms = $this->model('TermModel');
        $term = $terms->findById($id);
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $abs = SkFile::path($term['sk_file_path'] ?? null);
        if (!$abs || !is_file($abs)) $this->notFound('Berkas SK periode ini belum dilampirkan.');

        $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($term['sk_number'] ?: ('periode-' . $term['id'])));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="SK-' . trim($label, '-') . '.pdf"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');       // skrip di dalam PDF tak berjalan dalam konteks aplikasi
        header('Cache-Control: private, no-store, max-age=0');
        readfile($abs);
        exit;
    }

    // Menangani kolom berkas SK pada formulir periode. Mengembalikan pesan galat, atau null bila beres/tak ada berkas.
    private function handleSkUpload($termId, $terms) {
        $term = $terms->findById($termId);
        if (!empty($_POST['remove_sk_file']) && !empty($term['sk_file_path'])) {
            $terms->setSkFile($termId, null);
            return null;
        }
        $f = $_FILES['sk_file'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > SkFile::MAX_BYTES) {
            return 'Berkas SK terlalu besar (maksimal 20 MB; batas unggah PHP di server ini ' . ini_get('upload_max_filesize') . ').';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'Unggahan berkas SK gagal. Silakan coba lagi.';
        }
        $r = SkFile::store($f['tmp_name'], true);
        if (isset($r['error'])) {
            return ['type' => 'Berkas SK harus berupa PDF yang sah.', 'size' => 'Berkas SK kosong atau terlalu besar (maks 20 MB).',
                    'write' => 'Berkas SK tidak bisa disimpan di server.'][$r['error']];
        }
        $terms->setSkFile($termId, $r['path']);
        return null;
    }

    // $structureId diteruskan saat datang dari halaman struktur yang baru dibuat.
    public function form($id = 0, $structureId = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $terms = $this->model('TermModel');
        $structures = $this->model('StructureModel');
        $row = null;

        if ($id) {
            $row = $terms->findById($id);
            if (!$row) $this->notFound('Periode tidak ditemukan.');
            $this->requireScope($row['structure_id']);
            $structureId = $row['structure_id'];
        } elseif ($structureId) {
            $this->requireScope($structureId);
        }

        $this->view('admin/terms/form', [
            'title'       => $id ? 'Ubah Periode' : 'Tambah Periode',
            'row'         => $row,
            // Periode baru untuk struktur yang belum punya periode berjalan: tanda berjalan dicentang dari awal. Dulu tak tercentang, sehingga
            // strukturnya tetap tampil "belum punya periode berjalan" walau periodenya sudah dibuat.
            'markCurrentDefault' => !$id && $structureId && !$terms->hasCurrent($structureId),
            'structures'  => $structures->allInScope(),
            'governancePeriods' => $terms->governancePeriodsInScope(),
            'governancePeriodId' => (int) ($_GET['governance_period_id'] ?? 0),
            'structureId' => $structureId,
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $terms = $this->model('TermModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $structureId = (int) ($_POST['structure_id'] ?? 0);
        if ($id) {
            $existingTerm = $terms->findById($id);
            if (!$existingTerm) $this->notFound('Periode tidak ditemukan.');
            $this->requireScope($existingTerm['structure_id']);
            if ($structureId !== (int) $existingTerm['structure_id']) $this->notFound('Struktur periode tidak dapat diganti.');
        } else {
            $this->requireScope($structureId);
        }

        $data = [
            'structure_id'     => $structureId,
            'governance_period_id' => (int) ($_POST['governance_period_id'] ?? 0),
            'end_date_actual'  => $_POST['end_date_actual'] ?? '',
            'status'           => in_array($_POST['status'] ?? '', ['draft','active','ended'], true) ? $_POST['status'] : 'draft',
            'is_current'       => !empty($_POST['is_current']),
            'sk_number'        => trim($_POST['sk_number'] ?? ''),
            'sk_date'          => $_POST['sk_date'] ?? '',
            'notes'            => trim($_POST['notes'] ?? ''),
        ];

        if (!$id && !$terms->governancePeriodFindById($data['governance_period_id'])) {
            Flash::set('error', 'Pilih acuan Periode Kepengurusan yang tersedia.');
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }
        // Struktur yang sudah memakai acuan itu: bukan galat buntu, melainkan ke periode yang sudah ada (biasanya tinggal ditandai berjalan).
        if (!$id && ($dup = $terms->termForStructureAndPeriod($structureId, $data['governance_period_id']))) {
            Flash::set('warning', $dup['level'] . ' ' . $dup['structure_name'] . ' sudah memakai periode ' . $dup['name'] . ($dup['is_current']
                ? '. Ubah periode itu di sini.'
                : ', hanya belum ditandai berjalan. Centang "Tandai sebagai periode yang sedang berjalan" di sini bila memang berjalan, lalu simpan.'));
            $this->redirect('terms/form/' . (int) $dup['id']);
        }
        // Berjalan = Aktif (sama seperti saat menautkan acuan ke banyak struktur): Draf yang ditandai berjalan menjadi Aktif.
        if ($data['is_current'] && $data['status'] === 'draft') $data['status'] = 'active';
        $dateAnchor = $id ? $terms->findById($id) : $terms->governancePeriodFindById($data['governance_period_id']);
        if (!empty($data['end_date_actual']) && $data['end_date_actual'] < $dateAnchor['start_date']) {
            Flash::set('error', 'Tanggal akhir aktual tidak boleh lebih awal dari tanggal mulai periode.');
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }

        try {
            if ($id) {
                $terms->update($id, $data);
                $skErr = $this->handleSkUpload($id, $terms);
                Flash::set($skErr ? 'error' : 'success', $skErr ? 'Periode diperbarui, tetapi berkas SK ditolak: ' . $skErr : 'Periode diperbarui.');
                if (!$skErr && !$terms->hasCurrent($structureId)) Flash::set('warning', 'Periode diperbarui. Struktur ini belum punya periode berjalan; tandai salah satu periodenya.');
                $this->redirect('terms/detail/' . $id);
            } else {
                $newId = $terms->create($data);
                $skErr = $this->handleSkUpload($newId, $terms);
                Flash::set($skErr ? 'error' : 'success', $skErr ? 'Periode dibuat, tetapi berkas SK ditolak: ' . $skErr
                    : 'Periode dibuat. Langkah berikutnya: susun Bidang & unit, atau salin dari periode sebelumnya.');
                if (!$skErr && !$terms->hasCurrent($structureId)) {
                    Flash::set('warning', 'Periode dibuat, tetapi belum ditandai berjalan, jadi struktur ini masih tampil "belum punya periode berjalan". Tandai lewat Ubah periode, atau tombol "Tandai berjalan" di halaman Struktur.');
                }
                $this->redirect('terms/detail/' . $newId);
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uq_terms_structure_name') !== false) {
                Flash::set('error', 'Periode dengan nama itu sudah ada di struktur ini.');
            } else {
                error_log('Simpan periode gagal: ' . $e->getMessage());
                Flash::set('error', 'Periode tidak bisa disimpan.');
            }
            $this->redirect('terms');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('terms/form' . ($id ? '/' . $id : '/0/' . $structureId));
        }
    }

    // Tandai berjalan dengan satu klik (dari halaman Struktur dan Data Pengurus), tanpa membuka formulir periode. $_POST['back'] = halaman asal.
    public function mark_current() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $terms = $this->model('TermModel');
        $term = $terms->findById((int) ($_POST['id'] ?? 0));
        if (!$term) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($term['structure_id']);

        $back = $this->safeBack($_POST['back'] ?? '');
        if ($term['status'] === 'ended') {
            Flash::set('error', 'Periode yang sudah selesai tidak ditandai berjalan dengan tombol ini. Ubah periodenya bila memang perlu.');
            $this->redirect($back);
        }
        try {
            $terms->markCurrent($term['id']);
            Flash::set('success', $term['level'] . ' ' . $term['structure_name'] . ': periode ' . $term['name'] . ' ditandai berjalan.');
        } catch (PDOException $e) {
            error_log('Tandai berjalan gagal: ' . $e->getMessage());
            Flash::set('error', 'Periode tidak bisa ditandai berjalan.');
        }
        $this->redirect($back);
    }

    // Halaman tujuan kembali: hanya halaman daftar yang dikenal (atau profil satu orang, people/detail/ID) dengan query string biasa; selain itu daftar periode.
    private function safeBack($back) {
        $back = is_string($back) ? trim($back) : '';
        return preg_match('/^(structures|people(\/detail\/\d+)?|terms)(\?[A-Za-z0-9_=&%.+\-]*)?$/', $back) ? $back : 'terms';
    }

    // Menyalin susunan Bidang & unit dari periode lain pada struktur yang sama. Dibutuhkan karena
    // unit terikat periode: tanpa ini, tiap periode baru harus disusun ulang dari nol.
    public function clone_units() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $targetId = (int) ($_POST['target_term_id'] ?? 0);
        $sourceId = (int) ($_POST['source_term_id'] ?? 0);

        $terms = $this->model('TermModel');
        $target = $terms->findById($targetId);
        if (!$target) $this->notFound('Periode tujuan tidak ditemukan.');
        $this->requireScope($target['structure_id']);

        try {
            $result = $terms->cloneUnitsFrom($sourceId, $targetId);
            Flash::set('success', 'Disalin: ' . $result['groups'] . ' Bidang dan ' . $result['units']
                . ' unit. Penempatan pengurus tidak ikut disalin.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Clone unit gagal: ' . $e->getMessage());
            Flash::set('error', 'Penyalinan gagal. Pastikan periode tujuan masih kosong dari Bidang bernama sama.');
        }

        $this->redirect('terms/detail/' . $targetId);
    }

    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('terms');

        $id = (int) ($_POST['id'] ?? 0);
        $terms = $this->model('TermModel');
        $row = $terms->findById($id);
        if (!$row) $this->notFound('Periode tidak ditemukan.');
        $this->requireScope($row['structure_id']);

        try {
            $terms->softDelete($id);
            Flash::set('success', 'Periode dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('terms');
    }
}
