<?php
// PATH LOKASI FILE: app/Controllers/StructuresController.php

class StructuresController extends Controller {
    const PER_PAGE_OPTIONS = [25, 50, 100];
    // Saringan status (?f=), satu pilihan. 'punya_admin' hanya untuk yang boleh mengelola akun; 'nonaktif' hanya ditawarkan bila ada yang nonaktif.
    const FILTERS = ['berperiode' => 'Punya periode berjalan', 'tanpa_periode' => 'Belum punya periode', 'punya_admin' => 'Punya admin', 'nonaktif' => 'Nonaktif'];

    // Struktur dalam lingkup admin, dengan saringan supaya yang dicari mudah ditemukan: jenjang (?lv=), nama / kode wilayah / induk (?q=) dan
    // status (?f=). Dua tampilan (?tampil=): "daftar" (tabel berhalaman, bawaan: ringan dan mudah dipindai) dan "pohon" (hierarki; dengan
    // saringan hanya yang cocok beserta induk-induknya, dan induk itu dibuka). Seluruhnya disaring di PHP dari satu daftar (sekitar 1.100
    // baris), tanpa perubahan database. Jenjang di chip berasal dari STRUKTUR yang ada, jadi jenjang baru (mis. DPLN) langsung tampil.
    public function index() {
        $this->requireLogin();
        $structures = $this->model('StructureModel');
        $canEdit = Auth::canEdit();

        $all = $structures->allInScope();   // sudah urut path: induk selalu sebelum anaknya
        // Admin tiap struktur hanya ditampilkan kepada yang boleh mengelola akun.
        $admins = $canEdit ? $this->model('AccountModel')->adminsByStructure(array_column($all, 'id')) : [];
        $terms = $structures->currentTermsInScope();

        // Parameter bisa datang sebagai larik (?lv[]=x): hanya teks yang dipakai, selebihnya dianggap kosong.
        $param = function ($key) { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; };

        $byId = [];
        foreach ($all as $r) $byId[(int) $r['id']] = $r;
        $items = [];
        foreach ($all as $r) {
            $id = (int) $r['id'];
            $p = $r['parent_id'] !== null ? ($byId[(int) $r['parent_id']] ?? null) : null;   // induk di luar lingkup tidak pernah ikut tampil
            $items[$id] = $r + [
                'short'        => PeopleOverview::shortName($r['name']),
                'parent_text'  => $p ? $p['level'] . ' ' . PeopleOverview::shortName($p['name']) : '',   // untuk pencarian
                // Untuk tampilan: induk nasional (DPP, MWP) cukup jenjangnya; "Dewan Pimpinan Pusat" di bawah setiap DPW hanya memanjangkan baris.
                'parent_label' => $p ? ((int) $p['tier'] <= 1 ? $p['level'] : $p['level'] . ' ' . PeopleOverview::shortName($p['name'])) : '',
                'parent_short' => $p ? PeopleOverview::shortName($p['name']) : '',
                'term'         => $terms[$id] ?? null,
                'admins'       => $admins[$id] ?? [],
            ];
        }

        $levelsPresent = array_keys(array_count_values(array_column($all, 'level')));
        $lv = $param('lv');
        if (!in_array($lv, $levelsPresent, true)) $lv = '';
        $q = mb_substr($param('q'), 0, 100);
        $nonaktif = count(array_filter($items, function ($r) { return !$r['is_active']; }));
        $filters = [];
        foreach (self::FILTERS as $k => $label) {
            if (($k === 'punya_admin' && !$canEdit) || ($k === 'nonaktif' && $nonaktif === 0)) continue;
            $filters[$k] = $label;
        }
        $f = $param('f');
        if (!isset($filters[$f])) $f = '';
        $tampil = $param('tampil') === 'pohon' ? 'pohon' : 'daftar';

        $matchQ = function (array $r) use ($q) {
            return $q === '' || mb_stripos($r['level'] . ' ' . $r['name'] . ' ' . $r['region_code'] . ' ' . $r['parent_text'], $q) !== false;
        };
        $status = function ($key, array $r) {
            switch ($key) {
                case 'berperiode':    return $r['term'] !== null;
                case 'tanpa_periode': return $r['term'] === null;
                case 'punya_admin':   return !empty($r['admins']);
                case 'nonaktif':      return !$r['is_active'];
            }
            return true;
        };

        // Angka chip: jenjang dihitung menurut pencarian + status, status menurut pencarian + jenjang, supaya tiap angka = baris yang akan tampil bila diketuk.
        $levelCounts = []; $filterCounts = array_fill_keys(array_keys($filters), 0); $filterCounts[''] = 0;
        foreach ($items as $r) {
            if (!$matchQ($r)) continue;
            if ($f === '' || $status($f, $r)) $levelCounts[$r['level']] = ($levelCounts[$r['level']] ?? 0) + 1;
            if ($lv === '' || $r['level'] === $lv) {
                $filterCounts['']++;
                foreach ($filters as $k => $label) if ($status($k, $r)) $filterCounts[$k]++;
            }
        }
        $levelCounts = array_replace(array_fill_keys($levelsPresent, 0), $levelCounts);

        $matched = array_values(array_filter($items, function ($r) use ($lv, $matchQ, $f, $status) {
            return ($lv === '' || $r['level'] === $lv) && $matchQ($r) && ($f === '' || $status($f, $r));
        }));
        $filtered = $lv !== '' || $q !== '' || $f !== '';

        $perPage = in_array((int) $param('per_page'), self::PER_PAGE_OPTIONS, true) ? (int) $param('per_page') : 50;
        $page = max(1, (int) $param('page'));
        $rows = []; $tops = []; $kids = []; $matchIds = []; $openIds = [];

        if ($tampil === 'daftar') {
            // Urutan jenjang baku (DPP, DPW, DPLN, DPD, ...), lalu induknya (kabupaten satu provinsi berdampingan), lalu nama.
            $rank = array_flip(PeopleOverview::orderedLevels($levelsPresent));
            usort($matched, function ($a, $b) use ($rank) {
                return ($rank[$a['level']] <=> $rank[$b['level']]) ?: strnatcasecmp($a['parent_short'], $b['parent_short']) ?: strnatcasecmp($a['short'], $b['short']);
            });
            $page = min($page, max(1, (int) ceil(count($matched) / $perPage)));
            $rows = array_slice($matched, ($page - 1) * $perPage, $perPage);
        } else {
            // Pohon: tanpa saringan semuanya; dengan saringan hanya yang cocok beserta induk-induknya (induk yang tak cocok tampil redup).
            // Struktur yang induknya di luar lingkup (atau tak ada) menjadi puncak.
            foreach ($matched as $r) $matchIds[(int) $r['id']] = true;
            $visible = $matchIds;
            if (!$filtered) {
                foreach ($items as $id => $r) $visible[$id] = true;
            } else {
                foreach (array_keys($matchIds) as $id) {
                    $pid = $items[$id]['parent_id'];
                    while ($pid !== null && isset($items[(int) $pid]) && !isset($visible[(int) $pid])) {
                        $visible[(int) $pid] = true;
                        $pid = $items[(int) $pid]['parent_id'];
                    }
                }
            }
            foreach ($all as $r) {
                $id = (int) $r['id'];
                if (!isset($visible[$id])) continue;
                $pid = $r['parent_id'] === null ? 0 : (int) $r['parent_id'];
                if ($pid && isset($visible[$pid])) $kids[$pid][] = $items[$id];
                else $tops[] = $items[$id];
            }
            // Dibuka: dengan saringan, tiap simpul yang punya anak tampil (jalan menuju yang cocok); tanpa saringan hanya puncak tunggal.
            if ($filtered) $openIds = array_fill_keys(array_keys($kids), true);
            elseif (count($tops) === 1) $openIds[(int) $tops[0]['id']] = true;
        }

        $this->view('admin/structures/index', [
            'title'        => 'Struktur Organisasi',
            'canEdit'      => $canEdit,
            'allCount'     => count($items),
            'total'        => count($matched),
            'rows'         => $rows,
            'tops'         => $tops,
            'kids'         => $kids,
            'matchIds'     => $matchIds,
            'openIds'      => $openIds,
            'filtered'     => $filtered,
            'levelCounts'  => $levelCounts,
            'filters'      => $filters,
            'filterCounts' => $filterCounts,
            'lv'           => $lv,
            'q'            => $q,
            'f'            => $f,
            'tampil'       => $tampil,
            'page'         => $page,
            'perPage'      => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function form($id = null) {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();

        $structures = $this->model('StructureModel');
        $row = null;

        if ($id) {
            $row = $structures->findById($id);
            if (!$row) $this->notFound('Struktur tidak ditemukan.');
            $this->requireScope($row['id']);
        }

        $this->view('admin/structures/form', [
            'title'   => $id ? 'Ubah Struktur' : 'Tambah Struktur',
            'row'     => $row,
            'parents' => $structures->allInScope(),
            'levels'  => $structures->levelsInScope(),
        ]);
    }

    public function save() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('structures');

        $structures = $this->model('StructureModel');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        $data = [
            'level'       => $_POST['level'] ?? '',
            'name'        => $_POST['name'] ?? '',
            'parent_id'   => $_POST['parent_id'] ?? null,
            'region_code' => trim($_POST['region_code'] ?? ''),
            'is_active'   => !empty($_POST['is_active']),
        ];

        if ($data['name'] === '' || !in_array($data['level'], $structures->levelsInScope(), true)) {
            Flash::set('error', 'Nama dan tingkat struktur wajib diisi dengan benar.');
            $this->redirect('structures/form' . ($id ? '/' . $id : ''));
        }

        // Induk harus berada di dalam lingkup admin -- kalau tidak, admin DPW bisa menggantungkan
        // struktur baru di bawah DPW lain.
        if (!empty($data['parent_id'])) {
            $this->requireScope($data['parent_id']);
            $parent = $structures->findById($data['parent_id']);
            $requiredParent = ['DPLN' => 'DPP', 'MWRa' => 'MWC'][$data['level']] ?? null;
            if ($requiredParent !== null && (!$parent || $parent['level'] !== $requiredParent)) {
                Flash::set('error', $data['level'] . ' harus berada di bawah ' . $requiredParent . '.');
                $this->redirect('structures/form' . ($id ? '/' . $id : ''));
            }
        } elseif (!AdminScope::isUnbounded()) {
            Flash::set('error', 'Hanya Super Admin yang boleh membuat struktur tanpa induk (akar).');
            $this->redirect('structures');
        } elseif (in_array($data['level'], ['DPLN', 'MWRa'], true)) {
            Flash::set('error', $data['level'] . ' wajib memiliki induk yang sesuai.');
            $this->redirect('structures/form' . ($id ? '/' . $id : ''));
        }

        if ($id) {
            $existing = $structures->findById($id);
            if (!$existing) $this->notFound('Struktur tidak ditemukan.');
            $this->requireScope($id);
        }

        try {
            if ($id) {
                $structures->update($id, $data);
                AdminScope::refresh();
                Flash::set('success', 'Struktur diperbarui.');
            } else {
                $newId = $structures->create($data);
                AdminScope::refresh();
                Flash::set('success', 'Struktur ditambahkan. Langkah berikutnya: buat periode kepengurusannya.');
                $this->redirect('terms/form/0/' . $newId);
            }
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Simpan struktur gagal: ' . $e->getMessage());
            Flash::set('error', 'Struktur tidak bisa disimpan.');
        }

        $this->redirect('structures');
    }

    public function delete() {
        $this->requireLogin();
        if (!Auth::canEdit()) $this->denied();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('structures');

        $id = (int) ($_POST['id'] ?? 0);
        $structures = $this->model('StructureModel');
        $row = $structures->findById($id);
        if (!$row) $this->notFound('Struktur tidak ditemukan.');
        $this->requireScope($id);

        try {
            $structures->softDelete($id);
            Flash::set('success', 'Struktur dihapus.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        // Kembali ke daftar yang sama (saringan dan halaman); hanya karakter query string biasa yang diterima.
        $back = $_POST['back'] ?? '';
        $this->redirect('structures' . (is_string($back) && $back !== '' && preg_match('/^[A-Za-z0-9_=&%.+\\-]*$/', $back) ? '?' . $back : ''));
    }
}
