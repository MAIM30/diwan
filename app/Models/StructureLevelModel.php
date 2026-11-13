<?php
// PATH LOKASI FILE: app/Models/StructureLevelModel.php
// Pengelolaan daftar tingkat struktur (tabel structure_levels, migrasi 027): membuat, mengubah, menonaktifkan, dan menghapus tingkat.
// Pembacaannya lewat StructureLevels (app/Core). Struktur yang memakai sebuah tingkat menyimpan kodenya di structures.level, serta SALINAN
// kelompok (wing) dan tier tingkat itu; model ini menyelaraskan salinan itu bila kelompok atau tier sebuah tingkat diubah.

require_once ROOT_DIR . '/app/Core/StructureLevels.php';

class StructureLevelModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Tiap tingkat beserta pemakaiannya: structures = struktur yang masih ada, removed = struktur yang sudah dihapus tetapi masih menyimpan
    // tingkat ini (foreign key tetap menahannya), children = tingkat lain yang menjadikannya induk. Dasar tombol Hapus: yang dipakai tak bisa dihapus.
    public function levelList() {
        $live = $this->db->query("SELECT level, COUNT(*) FROM structures WHERE deleted_at IS NULL GROUP BY level")->fetchAll(PDO::FETCH_KEY_PAIR);
        $all = $this->db->query("SELECT level, COUNT(*) FROM structures GROUP BY level")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (StructureLevels::all() as $code => $r) {
            $n = (int) ($live[$code] ?? 0);
            $out[] = $r + ['structures' => $n, 'removed' => (int) ($all[$code] ?? 0) - $n, 'children' => StructureLevels::childrenOf($code)];
        }
        return $out;
    }

    // $code null = tingkat baru (kodenya dipilih sekali ini lalu TIDAK berubah lagi); selain itu mengubah nama, kelompok, tier, induk,
    // sebutan, urutan, dan status aktif. Tingkat nonaktif tak ditawarkan untuk struktur baru, tetapi struktur yang sudah bertingkat itu tetap
    // utuh. $data: code (hanya untuk yang baru), name, grp, tier, parent_code, parent_strict, short_label, sort_order ('' = urutan berikutnya
    // di kelompoknya), is_active. Mengembalikan kode tingkatnya.
    public function save($code, array $data) {
        $cur = null;
        if ($code !== null && $code !== '') {
            $cur = StructureLevels::find($code);
            if (!$cur) throw new RuntimeException('Tingkat tidak ditemukan.');
            $code = $cur['code'];
            if (!empty($cur['is_system'])) {
                // DPP dan MWP dikenal langsung oleh kode akses (admin nasional): hanya nama, sebutan, dan urutan yang boleh berubah. Isian
                // lainnya (di formulir terkunci, jadi tak terkirim) dipakai apa adanya dari yang tersimpan.
                $data['grp'] = $cur['grp']; $data['tier'] = (int) $cur['tier']; $data['parent_code'] = (string) $cur['parent_code'];
                $data['parent_strict'] = (int) $cur['parent_strict']; $data['is_active'] = 1;
            }
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Nama tingkat wajib diisi.');
        if (mb_strlen($name) > 100) throw new RuntimeException('Nama tingkat terlalu panjang (paling banyak 100 huruf).');
        $short = trim((string) ($data['short_label'] ?? ''));
        if (mb_strlen($short) > 30) throw new RuntimeException('Sebutan untuk nama admin terlalu panjang (paling banyak 30 huruf).');

        $grp = (string) ($data['grp'] ?? '');
        if (!isset(StructureLevels::GROUPS[$grp])) throw new RuntimeException('Pilih kelompok tingkat: Umum, Muslimah, atau Dewan.');
        $tier = (int) ($data['tier'] ?? 0);
        if ($tier < 1 || $tier > 5) throw new RuntimeException('Pilih "setingkat" dari Pusat sampai Ranting.');

        $parent = trim((string) ($data['parent_code'] ?? ''));
        if ($parent !== '') {
            $parentRow = StructureLevels::find($parent);
            if (!$parentRow) throw new RuntimeException('Tingkat induk "' . $parent . '" tidak ada.');
            $parent = $parentRow['code'];   // huruf besar-kecil seperti yang tersimpan
            if ($cur !== null && $this->isSameOrAncestor($code, $parent)) {
                throw new RuntimeException('Tingkat induk tidak boleh tingkat itu sendiri atau turunannya.');
            }
        }
        $strict = ($parent !== '' && !empty($data['parent_strict'])) ? 1 : 0;

        foreach (StructureLevels::all() as $c => $r) {   // nama harus unik (tanpa membedakan huruf besar-kecil), selain pada tingkat ini sendiri
            if ($c !== $code && mb_strtolower($r['name']) === mb_strtolower($name)) throw new RuntimeException('Nama "' . $r['name'] . '" sudah dipakai tingkat ' . $c . '.');
        }

        if ($cur === null) {
            $new = trim((string) ($data['code'] ?? ''));
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,9}$/', $new)) {
                throw new RuntimeException('Singkatan tingkat harus 2-10 huruf atau angka, diawali huruf, tanpa spasi (mis. DPLN).');
            }
            // PRIMARY KEY (utf8mb4_unicode_ci) tak membedakan huruf besar-kecil: "dpw" dan "DPW" sama, tetapi daftar di PHP membedakannya.
            foreach (StructureLevels::all() as $c => $r) {
                if (strcasecmp($c, $new) === 0) throw new RuntimeException('Singkatan "' . $c . '" sudah dipakai (' . $r['name'] . ').');
            }
            $order = $this->orderFor($grp, $data['sort_order'] ?? '');
            $this->db->prepare("INSERT INTO structure_levels (code, name, grp, tier, parent_code, parent_strict, short_label, sort_order, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
                     ->execute([$new, $name, $grp, $tier, $parent !== '' ? $parent : null, $strict, $short !== '' ? $short : null, $order]);
            Audit::log('structure_levels', 0, 'create', null, ['code' => $new, 'name' => $name, 'grp' => $grp, 'tier' => $tier, 'parent_code' => $parent, 'sort_order' => $order]);
            StructureLevels::reset();
            return $new;
        }

        $active = !empty($data['is_active']) ? 1 : 0;
        $order = (($data['sort_order'] ?? '') !== '') ? max(0, min(65535, (int) $data['sort_order'])) : (int) $cur['sort_order'];

        $wingChanged = StructureLevels::structureWing($code) !== ($grp === 'musl' ? 'muslimah' : 'umum');
        $tierChanged = (int) $cur['tier'] !== $tier;
        if ($wingChanged) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM structures WHERE level = ? AND deleted_at IS NULL");
            $stmt->execute([$code]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                throw new RuntimeException('Kelompok Umum/Muslimah ' . $code . ' tidak bisa diubah selama masih ada ' . $count . ' struktur bertingkat ini: unit di struktur-struktur itu memakai katalog sayap yang lama. Pindahkan atau hapus strukturnya lebih dulu.');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE structure_levels SET name = ?, grp = ?, tier = ?, parent_code = ?, parent_strict = ?, short_label = ?, sort_order = ?, is_active = ? WHERE code = ?")
                     ->execute([$name, $grp, $tier, $parent !== '' ? $parent : null, $strict, $short !== '' ? $short : null, $order, $active, $code]);
            if ($wingChanged || $tierChanged) {
                // Salinan di structures (wing, tier) mengikuti. Baris yang sudah dihapus ikut diselaraskan supaya tak ada yang basi bila dipulihkan.
                $this->db->prepare("UPDATE structures SET wing = ?, tier = ? WHERE level = ?")
                         ->execute([$grp === 'musl' ? 'muslimah' : 'umum', $tier, $code]);
            }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
        Audit::log('structure_levels', 0, 'update',
                   ['code' => $code, 'name' => $cur['name'], 'grp' => $cur['grp'], 'tier' => $cur['tier'], 'parent_code' => $cur['parent_code'], 'parent_strict' => $cur['parent_strict'], 'sort_order' => $cur['sort_order'], 'is_active' => $cur['is_active']],
                   ['code' => $code, 'name' => $name, 'grp' => $grp, 'tier' => $tier, 'parent_code' => $parent, 'parent_strict' => $strict, 'sort_order' => $order, 'is_active' => $active]);
        StructureLevels::reset();
        return $code;
    }

    // Menghapus tingkat yang tak terpakai. Yang masih dipakai ditolak dengan alasan yang bisa dikerjakan; yang hanya tak diperlukan lagi
    // bisa dinonaktifkan lewat save() (tak lagi ditawarkan, struktur lama tetap utuh). Tingkat bawaan sistem tak bisa dihapus.
    public function delete($code) {
        $cur = StructureLevels::find($code);
        if (!$cur) throw new RuntimeException('Tingkat tidak ditemukan.');
        $code = $cur['code'];
        if (!empty($cur['is_system'])) throw new RuntimeException($code . ' adalah tingkat bawaan sistem (pucuk nasional yang dikenali kode akses), jadi tidak bisa dihapus.');

        foreach ($this->levelList() as $r) {
            if ($r['code'] !== $code) continue;
            if ($r['structures'] > 0) {
                throw new RuntimeException($code . ' masih dipakai ' . $r['structures'] . ' struktur. Ubah tingkat struktur-struktur itu lebih dulu (Ubah pada baris strukturnya), atau nonaktifkan tingkat ini bila hanya tak ingin ditawarkan lagi.');
            }
            if ($r['removed'] > 0) {
                throw new RuntimeException($code . ' masih tercatat pada ' . $r['removed'] . ' struktur yang sudah dihapus, sehingga tidak bisa dihapus dari daftar. Nonaktifkan saja bila tak ingin ditawarkan lagi.');
            }
            if ($r['children']) {
                throw new RuntimeException($code . ' masih menjadi induk tingkat ' . implode(', ', $r['children']) . '. Ubah dulu induk tingkat itu, atau nonaktifkan ' . $code . '.');
            }
        }
        $this->db->prepare("DELETE FROM structure_levels WHERE code = ? AND is_system = 0")->execute([$code]);
        Audit::log('structure_levels', 0, 'delete', ['code' => $code, 'name' => $cur['name']], null);
        StructureLevels::reset();
        return true;
    }

    // Urutan bagi tingkat baru: angka yang diketik, atau 10 di atas urutan terbesar di kelompoknya (kelompok Umum mulai 10, Muslimah 110,
    // Dewan 210, jadi tingkat baru jatuh di ujung kelompoknya).
    private function orderFor($grp, $given) {
        if ((string) $given !== '') return max(0, min(65535, (int) $given));
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM structure_levels WHERE grp = ?");
        $stmt->execute([$grp]);
        $max = $stmt->fetchColumn();
        $base = ['umum' => 0, 'musl' => 100, 'dewan' => 200][$grp];
        return (int) min(65535, $max === null ? $base + 10 : $max + 10);
    }

    // Apakah $candidate adalah $code sendiri atau salah satu turunannya (menelusuri induk ke atas dari $candidate): dipakai mencegah
    // lingkaran induk (A berinduk B, B berinduk A).
    private function isSameOrAncestor($code, $candidate) {
        $seen = [];
        for ($c = $candidate; $c !== null && !isset($seen[strtolower($c)]); $c = StructureLevels::parentOf($c)) {
            if (strcasecmp($c, $code) === 0) return true;
            $seen[strtolower($c)] = true;
        }
        return false;
    }
}
