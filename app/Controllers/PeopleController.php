<?php
// PATH LOKASI FILE: app/Controllers/PeopleController.php
// Data orang + riwayat penempatannya. Halaman profil di sini adalah tampilan yang tidak mungkin
// dibuat di skema syuro: seluruh jabatan seseorang, lintas periode dan lintas struktur, dalam
// satu tempat.
//
// Setiap aksi yang menyentuh SATU orang memeriksa visibilitasnya menurut lingkup admin
// (PersonModel::isVisible) -- tanpa itu, admin wilayah bisa membuka atau mengubah orang mana pun
// hanya dengan mengganti id di URL.

class PeopleController extends Controller {

    private function loadVisible($id) {
        $people = $this->model('PersonModel');
        $person = $people->findById($id);
        if (!$person || !$people->isVisible($id)) {
            // Sama untuk "tidak ada" dan "di luar lingkup": jangan membocorkan keberadaan baris.
            $this->notFound('Data orang tidak ditemukan.');
        }
        return [$people, $person];
    }

    const VALID_FILTERS = ['tanpa_hp', 'tanpa_nik', 'tanpa_penempatan', 'tanpa_unit', 'nonaktif',
                           'lengkap', 'belum_lengkap', 'rangkap', 'tidak_rangkap', 'tidak_menjabat'];
    const PER_PAGE_OPTIONS = [25, 50, 100];

    public function index() {
        $this->requireLogin();

        $people = $this->model('PersonModel');
        // Pencarian bisa datang sebagai larik (?q[]=x): hanya teks yang dipakai, selebihnya dianggap kosong.
        $keyword = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
        $gender = in_array($_GET['gender'] ?? '', ['L', 'P'], true) ? $_GET['gender'] : '';
        $filter = in_array($_GET['filter'] ?? '', self::VALID_FILTERS, true) ? $_GET['filter'] : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 0), self::PER_PAGE_OPTIONS, true)
                 ? (int) $_GET['per_page'] : 25;
        $serving = !empty($_GET['menjabat']);

        // Jenjang (?lv=DPLN): semua jenjang yang punya STRUKTUR dalam lingkup admin atau punya orang; nilai lain diabaikan. Dulu hanya yang
        // berpenghuni, sehingga jenjang baru (mis. DPLN) tak tampil sampai ada pengurusnya. $levelCounts = level => jumlah orang (boleh nol).
        $structCounts = $this->model('StructureModel')->levelCountsInScope();
        $peopleCounts = $people->levelCounts();
        $levelCounts = [];
        foreach (PeopleOverview::orderedLevels(array_unique(array_merge(array_keys($structCounts), array_keys(array_filter($peopleCounts))))) as $l) {
            $levelCounts[$l] = (int) ($peopleCounts[$l] ?? 0);
        }
        $lv = is_string($_GET['lv'] ?? '') ? ($_GET['lv'] ?? '') : '';
        $level = isset($levelCounts[$lv]) ? $lv : '';

        $filters = ['gender' => $gender, 'filter' => $filter, 'serving' => $serving, 'level' => $level];

        // Tampilan (?susun=struktur|daftar). Tautan dari luar (dasbor, "Tanpa SKUO") tidak membawanya dan hampir selalu menginginkan
        // daftar nama, jadi tanpa pilihan eksplisit "Per Struktur" hanya dipakai bila tak ada pencarian maupun penyaring daftar.
        // 'bidang' adalah nilai lama, sebelum Per Bidang digabung ke Per Struktur.
        $susun = $_GET['susun'] ?? '';
        if ($susun === 'bidang') $susun = 'struktur';
        if (!in_array($susun, ['struktur', 'daftar'], true)) {
            $susun = ($keyword === '' && $filter === '' && !$serving) ? 'struktur' : 'daftar';
        }
        $perStruktur = $susun === 'struktur';

        // Per Struktur memuat SEMUA struktur dalam lingkup, termasuk yang belum punya pengurus atau periode (angkanya nol), kecuali ada
        // pencarian atau penyaring orang (struktur kosong tak punya apa-apa untuk dicocokkan) atau orang memilih menyembunyikannya (?kosong=0).
        $facet = $keyword !== '' || $gender !== '' || $filter !== '' || $serving;
        $showEmpty = ($_GET['kosong'] ?? '') !== '0';

        $levels = $this->model('StructureModel')->levelsInScope();
        $umum = ['DPP', 'DPW', 'DPLN', 'DPD', 'DPC', 'DPRa'];
        $musl = ['MWP', 'MWW', 'MWD', 'MWC', 'MWRa'];

        $data = [
            'title'    => 'Data Pengurus',
            'keyword'  => $keyword,
            'gender'   => $gender,
            'filter'   => $filter,
            'serving'  => $serving,
            'level'    => $level,
            'levelCounts' => $levelCounts,
            'structCounts' => $structCounts,
            'showEmpty' => $showEmpty,
            'hasFacet' => $facet,
            'perStruktur' => $perStruktur,
            'totalAll' => $people->countInScope('', []),
            'recap'    => $people->recapCounts($keyword, $level),
            'showL'    => !empty(array_intersect($levels, $umum)),
            'showP'    => !empty(array_intersect($levels, $musl)),
            'page'     => $page,
            'perPage'  => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'rows'     => [],
            'total'    => 0,
            'blocks'   => [],
            'openLevels' => [],
        ];

        if ($perStruktur) {
            $data['blocks'] = PeopleOverview::build($people->overviewByStructure($keyword, $filters, $showEmpty && !$facet), $people->intiFlags());
            $data['openLevels'] = PeopleOverview::defaultOpen($data['blocks'], $level);
        } else {
            $data['rows'] = $people->searchInScope($keyword, $perPage, ($page - 1) * $perPage, $filters);
            $data['total'] = $people->countInScope($keyword, $filters);
        }

        $this->view('admin/people/index', $data);
    }

    public function deleted() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $this->view('admin/people/deleted', [
            'title' => 'Data Pengurus Terhapus',
            'rows'  => $this->model('PersonModel')->deletedInScope(),
        ]);
    }

    public function restore() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('people/deleted');

        $id = (int) ($_POST['id'] ?? 0);
        try {
            if (!$this->model('PersonModel')->restoreDeleted($id)) {
                $this->notFound('Data terhapus tidak ditemukan.');
            }
            Flash::set('success', 'Data pengurus dan penempatan yang terhapus bersamanya berhasil dipulihkan.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect('people/deleted');
    }

    // Isi satu struktur di tampilan "Per Struktur" (Pengurus Inti, Bidang, unit): potongan HTML yang dimuat saat barisnya dibuka, bukan
    // halaman utuh. Pencarian dan penyaring daftar ikut lewat query string supaya isinya cocok dengan angka di baris itu.
    public function struktur($termId) {
        $this->requireLogin();

        $term = $this->model('TermModel')->findById((int) $termId);
        // Sama untuk "tidak ada" dan "di luar lingkup": jangan membocorkan keberadaan periode.
        if (!$term || !AdminScope::allows($term['structure_id'])) $this->notFound('Struktur tidak ditemukan.');

        $people = $this->model('PersonModel');
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $gender = in_array($_GET['gender'] ?? '', ['L', 'P'], true) ? $_GET['gender'] : '';
        $filter = in_array($_GET['filter'] ?? '', self::VALID_FILTERS, true) ? $_GET['filter'] : '';
        $serving = !empty($_GET['menjabat']);
        $facet = $keyword !== '' || $gender !== '' || $filter !== '' || $serving;

        $ids = $facet ? array_flip($people->idsInScope($keyword, ['gender' => $gender, 'filter' => $filter, 'serving' => $serving])) : null;
        $today = date('Y-m-d');
        $rows = array_values(array_filter($this->model('PlacementModel')->byTerm($term['id']), function ($r) use ($ids, $today) {
            return ($ids === null || isset($ids[(int) $r['person_id']])) && (empty($r['end_date']) || $r['end_date'] >= $today);
        }));

        $units = $this->model('UnitModel');
        $tree = Susunan::arrange($rows, $units->groupsByTerm($term['id']), $units->unitsByTerm($term['id']));
        if ($facet) $tree = Susunan::prune($tree);

        $flags = $people->intiFlags((int) $term['structure_id'])[(int) $term['structure_id']] ?? ['k' => false, 's' => false, 'b' => false];

        // Struktur tanpa Bidang dan unit (kebanyakan DPW, DPD, MWW, MWD) hanya punya Pengurus Inti dan Pimpinan Lainnya: tak ada tingkatan
        // untuk dibuka, jadi nama dan jabatan pengurusnya langsung ditampilkan. Penyaringan yang membuang semua Bidang dan unit juga
        // membuatnya "rata". Struktur yang punya Bidang atau unit (DPP, MWP, ...) tetap berupa pohon.
        $flat = !array_filter($tree, function ($n) { return !empty($n['children']); });

        $this->view('admin/people/_roster', ['term' => $term, 'tree' => $tree, 'flags' => $flags, 'facet' => $facet, 'flat' => $flat]);
    }

    public function detail($id) {
        $this->requireLogin();
        [$people, $person] = $this->loadVisible($id);

        $placements = $this->model('PlacementModel');
        $all = $placements->historyOfPerson($id);

        // Riwayat dibatasi pada penempatan di dalam lingkup admin; sisanya hanya dihitung.
        $history = array_values(array_filter($all, function ($h) { return AdminScope::allows($h['structure_id']); }));
        $hidden = count($all) - count($history);

        // Jabatan saat ini: periode berjalan dan belum berakhir.
        $today = date('Y-m-d');
        $current = array_values(array_filter($history, function ($h) use ($today) {
            return !empty($h['is_current']) && (empty($h['end_date']) || $h['end_date'] >= $today);
        }));

        // Tambah jabatan dari profil dapat memilih struktur lain tanpa harus memakai acuan
        // periode yang sama dengan penempatan orang yang sedang dilihat.
        $expectedWing = $person['gender'] === 'P' ? 'muslimah' : 'umum';
        $availableTerms = array_values(array_filter(
            $this->model('TermModel')->allInScope(),
            function ($term) use ($expectedWing) {
                return ($term['wing'] ?? '') === $expectedWing;
            }
        ));
        usort($availableTerms, function ($a, $b) {
            if ((int) $a['is_current'] !== (int) $b['is_current']) return (int) $b['is_current'] <=> (int) $a['is_current'];
            return strcmp($a['structure_path'], $b['structure_path']);
        });

        $dash = $this->model('DashboardModel');

        // Status akses Portal Pengurus untuk orang ini. Urutan = alasan yang PERTAMA menghalangi.
        // (Sengaja berbeda dari pesan di halaman masuk, yang seragam demi tidak membocorkan apa-apa;
        // admin berhak tahu alasan sebenarnya.)
        $shared = $this->model('MemberModel')->phoneSharedWith($id, $person['phone']);
        if (!(int) $person['portal_enabled'])   $portal = 'revoked';
        elseif (empty($person['nik']) && empty($person['phone'])) $portal = 'no_cred';
        elseif (empty($person['nik']) && $shared > 0)           $portal = 'phone_shared';
        elseif ($person['status'] !== 'active') $portal = 'inactive';
        elseif (count($all) === 0)              $portal = 'no_placement';
        else                                    $portal = 'ok';
        $portalLast = $this->model('MemberModel')->lastLogin($id);

        $this->view('admin/people/detail', [
            'title'    => Ui::fullName($person),
            'person'   => $person,
            'history'  => $history,
            'hidden'   => $hidden,
            'allPlacementCount' => count($all),
            'current'  => $current,
            'availableTerms' => $availableTerms,
            'portal'     => $portal,
            'portalLast' => $portalLast,
            'activity' => $dash->activityForPerson($id, 8),
        ]);
    }

    // Daftar penempatan aktif lainnya untuk pop-up Rekap Rangkap.
    public function other_placements($id) {
        $this->requireLogin();
        [, $person] = $this->loadVisible($id);
        $today = date('Y-m-d');
        $rows = array_values(array_filter(
            $this->model('PlacementModel')->historyOfPerson($id),
            function ($row) use ($today) {
                return !empty($row['is_current'])
                    && (empty($row['end_date']) || $row['end_date'] >= $today)
                    && AdminScope::allows($row['structure_id']);
            }
        ));
        $featuredId = (int) ($_GET['featured'] ?? 0);
        $rows = array_values(array_filter($rows, function ($row) use ($featuredId) {
            return (int) $row['id'] !== $featuredId;
        }));

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_map(function ($row) {
            return [
                'position' => $row['position_name'],
                'level' => $row['level'],
                'structure' => $row['structure_name'],
                'unit' => $row['unit_name'] ?: '',
                'period' => $row['term_name'],
            ];
        }, $rows), JSON_UNESCAPED_UNICODE);
    }

    public function form($id = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $row = null;
        if ($id) {
            [, $row] = $this->loadVisible($id);
        }

        $this->view('admin/people/form', [
            'title' => $id ? 'Ubah Data Orang' : 'Tambah Orang',
            'row'   => $row,
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('people');

        $people = $this->model('PersonModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $back = 'people/form' . ($id ? '/' . $id : '');

        if ($id) {
            $this->loadVisible($id); // tolak mengubah orang di luar lingkup
        }

        // Tiga bagian nama. Gelar yang terlanjur ditempel di kolom nama dipindahkan otomatis.
        [$pre, $name, $suf, $moved] = Text::normalizeNameParts(
            $_POST['title_prefix'] ?? '', $_POST['name'] ?? '', $_POST['title_suffix'] ?? ''
        );

        $data = [
            'title_prefix' => $pre,
            'name'         => $name,
            'title_suffix' => $suf,
            'gender'       => ($_POST['gender'] ?? '') === 'P' ? 'P' : 'L',
            'nik'          => trim($_POST['nik'] ?? ''),
            'phone'        => trim($_POST['phone'] ?? ''),
            'family_phone' => trim($_POST['family_phone'] ?? ''),
            'occupation'   => trim($_POST['occupation'] ?? ''),
            'email'        => trim($_POST['email'] ?? ''),
            'birth_date'   => $_POST['birth_date'] ?? '',
            'birth_place'  => trim($_POST['birth_place'] ?? ''),
            'address_text' => trim($_POST['address_text'] ?? ''),
            'village'      => trim($_POST['village'] ?? ''),
            'district'     => trim($_POST['district'] ?? ''),
            'city'         => trim($_POST['city'] ?? ''),
            'province'     => trim($_POST['province'] ?? ''),
            'status'       => in_array($_POST['status'] ?? '', ['active', 'inactive', 'deceased'], true) ? $_POST['status'] : 'active',
            'portal_enabled' => !empty($_POST['portal_enabled']),
            'notes'        => trim($_POST['notes'] ?? ''),
        ];

        if ($name === '') {
            Flash::set('error', 'Nama wajib diisi.');
            $this->redirect($back);
        }

        // NIK diisi tapi bukan 16 digit -> hampir pasti salah ketik. Ditolak dengan pesan jelas
        // daripada diam-diam disimpan sebagai NULL dan dianggap "belum ada NIK".
        if ($data['nik'] !== '' && Text::normalizeNik($data['nik']) === null) {
            Flash::set('error', 'NIK harus tepat 16 digit angka. Kosongkan bila belum ada.');
            $this->redirect($back);
        }

        // Calon duplikat hanya DIPERINGATKAN, tidak memblokir -- bisa jadi memang dua orang berbeda
        // dengan nama sama. Yang memutuskan tetap manusia.
        if (!$id) {
            $match = $people->matchCandidates(Text::joinName($pre, $name, $suf), $data['nik']);
            if ($match['confidence'] !== 'none' && empty($_POST['confirm_duplicate'])) {
                $match['candidates'] = $people->redactOutOfScope($match['candidates']);
                $this->view('admin/people/confirm_duplicate', [
                    'title' => 'Kemungkinan Data Ganda',
                    'input' => $data,
                    'match' => $match,
                ]);
                return;
            }
        }

        try {
            $newId = $people->save($id, $data);
            Flash::set('success', 'Data orang disimpan.' . ($moved
                ? ' Gelar yang tertulis di kolom nama sudah dipindahkan ke kolom gelar.' : ''));
            $this->redirect('people/detail/' . $newId);
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect($back);
        }
    }

    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('people');

        $id = (int) ($_POST['id'] ?? 0);
        [$people, $person] = $this->loadVisible($id);

        // Kembali ke daftar dengan penyaringan dan halaman yang sama; hanya kunci yang dikenal daftar yang dipakai ulang.
        parse_str((string) ($_POST['back'] ?? ''), $q);
        $keep = array_filter(array_intersect_key((array) $q, array_flip(['q', 'gender', 'filter', 'menjabat', 'lv', 'susun', 'page', 'per_page'])), 'is_scalar');
        $back = 'people' . ($keep ? '?' . http_build_query($keep) : '');

        try {
            $removed = $people->softDelete($id, ($_POST['with_placements'] ?? '') === '1');
            Flash::set('success', 'Data ' . $person['full_name'] . ' dihapus' . ($removed ? ', beserta ' . $removed . ' penempatannya.' : '.'));
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        $this->redirect($back);
    }

    // ---------- Foto ----------

    // Mengunggah / menghapus foto profil.
    //
    // Berkas unggahan TIDAK PERNAH disimpan apa adanya. Gambar didekode GD lalu ditulis ulang sebagai
    // JPEG 512x512 persegi: itu membuang EXIF (lokasi GPS dsb.), metadata, dan muatan apa pun yang
    // disembunyikan di dalam berkas, dan memastikan yang tersimpan benar-benar gambar. Nama berkas
    // diturunkan dari UUID orangnya -- bukan dari nama yang dikirim pengguna.
    public function photo() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('people');

        $id = (int) ($_POST['id'] ?? 0);
        [$people, $person] = $this->loadVisible($id);
        $back = 'people/detail/' . $id;

        $rel = 'uploads/people/' . $person['uuid'] . '.jpg';

        if (!empty($_POST['remove'])) {
            if (is_file(ROOT_DIR . '/public/' . $rel)) @unlink(ROOT_DIR . '/public/' . $rel);
            $people->setPhoto($id, null);
            Flash::set('success', 'Foto dihapus.');
            $this->redirect($back);
        }

        // Seluruh sanitasi (jenis dari ISI, batas piksel, EXIF dibuang, JPEG 512x512) ada di Photo,
        // supaya impor biodata dan unggahan pengurus sendiri memakai pipeline yang sama persis.
        $err = Photo::fromUpload($_FILES['photo'] ?? null, ROOT_DIR . '/public/' . $rel);
        if ($err !== null) {
            Flash::set('error', Photo::message($err));
            $this->redirect($back);
        }

        $people->setPhoto($id, $rel);
        Flash::set('success', 'Foto diperbarui.');
        $this->redirect($back);
    }
}
