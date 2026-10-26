<?php
// PATH LOKASI FILE: app/Core/AdminScope.php
// Lingkup akses admin: struktur mana saja yang boleh dilihat/dikelola akun yang sedang login.
//
// Diadaptasi dari AdminScope di syuro, dengan dua perubahan:
//  1. Perhitungan keturunan memakai materialized path (structures.path LIKE '/1/39/%') alih-alih
//     recursive CTE. Hasilnya identik, tapi jadi satu index range scan -- dan tetap benar saat
//     jumlah DPC/DPRa nanti bertambah banyak.
//  2. Satu akun bisa punya BEBERAPA baris peran+lingkup (account_roles), jadi lingkup efektifnya
//     adalah GABUNGAN seluruh subtree tersebut, bukan satu titik akar saja seperti di syuro.
//
// Yang TIDAK berubah dan memang disengaja: lingkup tidak pernah menyeberang cabang. Admin DPW
// Sulawesi Tenggara mengelola DPW itu + seluruh DPD/DPC di bawahnya, tapi TIDAK bisa menyentuh MWW
// Sulawesi Tenggara -- dua node berbeda di cabang berbeda, meski mewakili wilayah yang sama.
// "Admin Nasional" = akun yang lingkupnya berada di struktur akar (DPP), yang karena MWP menggantung
// di bawah DPP otomatis mencakup sayap Muslimah.

class AdminScope {
    // Dipanggil saat login berhasil. $roles = baris account_roles milik akun tsb.
    public static function initSession($roles) {
        $scopeAll = false;
        $rootIds = [];

        foreach ($roles as $r) {
            // super_admin tanpa lingkup = seluruh sistem. Satu baris seperti ini membuat
            // perhitungan subtree tidak relevan, jadi kita tandai dan berhenti menghitung.
            if ($r['role'] === 'super_admin' && empty($r['scope_structure_id'])) {
                $scopeAll = true;
            } elseif (!empty($r['scope_structure_id'])) {
                $rootIds[] = (int) $r['scope_structure_id'];
            }
        }
        $rootIds = array_values(array_unique($rootIds));

        require_once ROOT_DIR . '/app/Models/StructureModel.php';
        $model = new StructureModel();

        $_SESSION['scope'] = [
            'all'           => $scopeAll,
            'root_ids'      => $rootIds,
            'structure_ids' => $scopeAll ? [] : $model->descendantIdsOfMany($rootIds),
            'is_national'   => $scopeAll || $model->anyIsRoot($rootIds),
            'label'         => self::buildLabel($model, $scopeAll, $rootIds),
        ];
    }

    private static function buildLabel($model, $scopeAll, $rootIds) {
        if ($scopeAll) return SCOPE_LABEL_ROOT . ' (Super Admin)';
        if (empty($rootIds)) return 'Tanpa lingkup';

        $labels = [];
        foreach ($model->findManyById($rootIds) as $s) {
            $labels[] = empty($s['parent_id']) ? SCOPE_LABEL_ROOT : trim($s['level'] . ' ' . $s['name']);
        }
        return implode(' + ', $labels);
    }

    // Menghitung ulang daftar struktur dari titik akar yang sudah ada di sesi (tidak pernah melebar dari akar itu). Dipanggil setelah
    // admin mengubah pohon, supaya struktur yang baru ia tambahkan langsung terlihat dan bisa dipakai tanpa keluar-masuk lagi.
    public static function refresh() {
        if (empty($_SESSION['scope']) || self::isUnbounded()) return;
        require_once ROOT_DIR . '/app/Models/StructureModel.php';
        $_SESSION['scope']['structure_ids'] = (new StructureModel())->descendantIdsOfMany(self::rootIds());
    }

    // Label siap tampil di sidebar (mis. "Nasional" atau "DPW Sulawesi Tenggara"), di-cache sejak
    // login supaya tidak perlu query ulang di tiap halaman.
    public static function label() {
        return $_SESSION['scope']['label'] ?? null;
    }

    // true = tidak ada batas struktur sama sekali (super admin tanpa lingkup).
    public static function isUnbounded() {
        return (bool) ($_SESSION['scope']['all'] ?? false);
    }

    public static function isNational() {
        return (bool) ($_SESSION['scope']['is_national'] ?? false);
    }

    // Titik akar lingkup (bukan daftar keturunannya) -- dipakai sebagai nilai bawaan form.
    public static function rootIds() {
        return $_SESSION['scope']['root_ids'] ?? [];
    }

    // Daftar ID struktur yang boleh diakses. Kosong + isUnbounded() = semuanya.
    public static function structureIds() {
        return $_SESSION['scope']['structure_ids'] ?? [];
    }

    public static function allows($structureId) {
        if ($structureId === null || $structureId === '') return false;
        if (self::isUnbounded()) return true;
        return in_array((int) $structureId, self::structureIds(), true);
    }

    // Fragmen SQL siap sisip untuk membatasi query ke lingkup saat ini, mis.
    //   "SELECT ... WHERE " . AdminScope::sqlFilter('pl.structure_id')
    // Aman tanpa parameter binding karena seluruh nilainya berasal dari array_map('intval', ...)
    // di StructureModel, bukan dari input pengguna. Lingkup kosong -> '1=0' (tidak match apa pun),
    // bukan '1=1' -- kalau perhitungan lingkup gagal, hasilnya harus TIDAK TERLIHAT, bukan terbuka.
    public static function sqlFilter($column) {
        if (self::isUnbounded()) return '1=1';
        $ids = self::structureIds();
        if (empty($ids)) return '1=0';
        return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
}
