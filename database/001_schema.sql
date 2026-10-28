-- PATH LOKASI FILE: database/001_schema.sql
-- Skema awal pengurus.wahdah.or.id -- sumber data tunggal untuk data pengurus Wahdah Islamiyah.
--
-- Prinsip desain (dan bedanya dengan skema syuro yang digantikan):
--  1. PERIODE JADI ENTITAS. Di syuro masa amanah adalah dua kolom di baris `users`, jadi begitu periode
--     baru diisi, riwayat periode lama tertimpa. Di sini tiap penempatan WAJIB terikat ke satu `terms.id`,
--     sehingga periode lama tetap utuh sebagai arsip.
--  2. ORANG != AKUN. `people` adalah subjek data (biodata + riwayat jabatan), `accounts` adalah hak login
--     ke aplikasi ini. Pelajaran dari syuro: satu kolom `role` pernah dipaksa mewakili dua sumbu berbeda
--     (posisi di organisasi vs kemampuan teknis di sistem).
--  3. JABATAN JADI KATALOG. `positions` menggantikan `user_placements.position_name` VARCHAR bebas,
--     dan kolom `positions.is_core` menggantikan CASE WHEN di app/Core/PengurusInti.php.
--  4. UUID PERMANEN di tiap entitas yang dipublikasikan ke API, supaya konsumen (syuro, dll) menyimpan
--     referensi stabil meski id internal berubah.
--  5. SOFT DELETE (`deleted_at`) di entitas yang dipublikasikan, supaya sinkronisasi inkremental bisa
--     mengabarkan penghapusan -- `WHERE updated_at > ?` saja tidak pernah bisa memberi tahu konsumen
--     bahwa sebuah baris sudah dihapus.
--
-- Target: MySQL 8.0+ (memakai generated column dan composite foreign key).
-- Konvensi: identifier Inggris (konsisten dengan syuro), komentar Indonesia.

SET NAMES utf8mb4;

-- =====================================================================================================
-- 1. STRUKTUR ORGANISASI
-- =====================================================================================================

-- Pohon struktur: DPP > DPW > DPD > DPC > DPRa (serta DPLN setingkat DPW), dengan sayap Muslimah
-- MWP > MWW > MWD > MWC > MWRa sebagai
-- CABANG PARALEL yang menggantung di bawah akar yang sama (MWP.parent_id = DPP), persis seperti syuro.
-- Konsekuensinya memang disengaja: lingkup "Nasional" (akar) mencakup sayap Muslimah, tetapi lingkup
-- DPW TIDAK pernah menyeberang ke MWW yang mewakili wilayah sama -- keduanya node berbeda di cabang
-- berbeda. `region_code` + `tier` + `wing` dipakai untuk MENGAITKAN pasangan wilayah itu demi keperluan
-- tampilan/laporan, tanpa menyatukannya jadi satu entitas.
CREATE TABLE structures (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,
  level         ENUM('DPP','DPW','DPD','DPC','DPRa','DPLN','MWP','MWW','MWD','MWC','MWRa') NOT NULL,
  name          VARCHAR(150) NOT NULL,
  parent_id     INT UNSIGNED NULL,

  -- Sayap organisasi, diturunkan dari `level` supaya tidak mungkin tidak sinkron.
  wing          ENUM('umum','muslimah')
                GENERATED ALWAYS AS (IF(level IN ('MWP','MWW','MWD','MWC','MWRa'),'muslimah','umum')) STORED,
  -- Tingkat administratif: 1=Pusat, 2=Wilayah, 3=Daerah, 4=Cabang, 5=Ranting. Dua node yang mewakili
  -- wilayah sama (DPW Sultra & MWW Sultra) punya tier sama tapi wing berbeda.
  tier          TINYINT UNSIGNED
                GENERATED ALWAYS AS (CASE level
                  WHEN 'DPP'  THEN 1 WHEN 'MWP' THEN 1
                  WHEN 'DPW'  THEN 2 WHEN 'DPLN' THEN 2 WHEN 'MWW' THEN 2
                  WHEN 'DPD'  THEN 3 WHEN 'MWD' THEN 3
                  WHEN 'DPC'  THEN 4 WHEN 'MWC' THEN 4
                  WHEN 'DPRa' THEN 5 WHEN 'MWRa' THEN 5 END) STORED,
  -- Kode wilayah bersama (mis. 'SULTRA') -- pengait DPW<->MWW, bukan bagian dari hierarki.
  region_code   VARCHAR(20)  NULL,

  -- Materialized path ('/1/2/3/') + kedalaman, dipelihara aplikasi saat insert/pindah induk.
  -- parent_id tetap sumber kebenaran; path hanya jalur cepat: "struktur ini + seluruh keturunannya"
  -- jadi satu index range scan (path LIKE '/1/2/%') tanpa recursive CTE di tiap request.
  -- Pohonnya hampir statis (39 node hari ini), jadi biaya pemeliharaannya murah.
  path          VARCHAR(255) NOT NULL DEFAULT '',
  depth         TINYINT UNSIGNED NOT NULL DEFAULT 0,

  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_structures_uuid (uuid),
  KEY idx_structures_parent (parent_id),
  KEY idx_structures_path (path),
  KEY idx_structures_region (region_code, tier, wing),
  KEY idx_structures_sync (updated_at, id),
  CONSTRAINT fk_structures_parent FOREIGN KEY (parent_id) REFERENCES structures (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 2. PERIODE / MASA KEPENGURUSAN
-- =====================================================================================================

-- Acuan periode dapat dipakai bersama oleh banyak struktur. Setiap struktur tetap memiliki baris
-- terms sendiri karena status, SK, dan penempatan dikelola per struktur.
--
-- Tanggal akhir dipisah dua karena organisasi riil sering molor beberapa bulan sebelum musyawarah
-- berikutnya: `end_date_planned` adalah apa yang tertulis di SK, `end_date_actual` diisi saat serah
-- terima benar-benar terjadi. Keduanya NULL-able supaya periode berjalan tidak perlu dipaksa berakhir.
CREATE TABLE governance_periods (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36) NOT NULL,
  name              VARCHAR(50) NOT NULL,
  start_date        DATE NOT NULL,
  end_date_planned  DATE NULL,
  identity_key      CHAR(64) GENERATED ALWAYS AS
                    (SHA2(CONCAT(name, '#', DATE_FORMAT(start_date, '%Y-%m-%d'), '#', COALESCE(DATE_FORMAT(end_date_planned, '%Y-%m-%d'), '')), 256)) STORED,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_governance_periods_uuid (uuid),
  UNIQUE KEY uq_governance_periods_identity (identity_key),
  KEY idx_governance_periods_dates (start_date, end_date_planned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE terms (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)     NOT NULL,
  governance_period_id INT UNSIGNED NOT NULL,
  structure_id      INT UNSIGNED NOT NULL,
  name              VARCHAR(50)  NOT NULL,  -- snapshot dari governance_periods
  start_date        DATE         NOT NULL,   -- snapshot dari governance_periods
  end_date_planned  DATE         NULL,       -- snapshot dari governance_periods
  end_date_actual   DATE         NULL,

  status            ENUM('draft','active','ended') NOT NULL DEFAULT 'draft',
  -- Penanda periode yang sedang berjalan. Dijaga unik per struktur lewat generated column di bawah:
  -- MySQL tidak punya partial unique index, jadi kolom penjaga ini bernilai structure_id saat
  -- is_current=1 dan NULL saat tidak -- NULL tidak pernah bertabrakan di UNIQUE KEY.
  is_current        TINYINT(1)   NOT NULL DEFAULT 0,
  current_guard     INT UNSIGNED GENERATED ALWAYS AS (IF(is_current = 1, structure_id, NULL)) STORED,

  -- Jejak SK yang menetapkan periode ini.
  sk_number         VARCHAR(100) NULL,
  sk_date           DATE         NULL,
  sk_file_path      VARCHAR(255) NULL,
  notes             TEXT         NULL,

  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_terms_uuid (uuid),
  UNIQUE KEY uq_terms_structure_name (structure_id, name),
  UNIQUE KEY uq_terms_structure_period (structure_id, governance_period_id),
  UNIQUE KEY uq_terms_one_current (current_guard),
  -- Index acuan untuk composite FK dari `unit_groups`, `structure_units`, dan `placements`.
  UNIQUE KEY uq_terms_structure_id (structure_id, id),
  KEY idx_terms_dates (start_date, end_date_planned),
  KEY idx_terms_sync (updated_at, id),
  CONSTRAINT fk_terms_structure FOREIGN KEY (structure_id) REFERENCES structures (id) ON DELETE RESTRICT,
  CONSTRAINT fk_terms_governance_period FOREIGN KEY (governance_period_id) REFERENCES governance_periods (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 3. ORANG
-- =====================================================================================================

CREATE TABLE people (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Identitas permanen yang dipublikasikan ke API. Konsumen (syuro) mengunci salinan lokalnya ke sini.
  uuid            CHAR(36)     NOT NULL,

  full_name       VARCHAR(200) NOT NULL,  -- apa adanya SK, lengkap dengan gelar
  -- Hasil normalisasi nama untuk dedup & pencarian: gelar setelah koma dibuang, gelar depan umum
  -- (dr/drg/prof/ir/hj/h/kh/ns/apt) dibuang, tanda baca dibuang, lowercase. Dihitung aplikasi (regex
  -- di luar kemampuan generated column) -- WAJIB dihitung ulang setiap full_name berubah.
  -- Pola ini diambil dari normalizeBase() di syuro database/import_sk_dpp_2026_2031.php.
  name_normalized VARCHAR(200) NOT NULL,

  -- Nomor Induk Kependudukan (16 digit KTP). DATA PRIBADI SENSITIF, diperlakukan khusus:
  --   * BUKAN kunci publik API -- `uuid` tetap satu-satunya kunci yang dipublikasikan dan disimpan
  --     konsumen. Alasannya NIK bisa belum terbit saat pendaftaran, dan bisa dikoreksi kalau salah
  --     ketik; keduanya akan memutus tautan salinan lokal syuro kalau NIK dipakai sebagai kunci.
  --   * Dapat dipanggil aplikasi lain HANYA lewat endpoint lookup khusus (GET /people/by-nik/{nik})
  --     yang mensyaratkan scope 'people:nik' pada token klien, dan setiap pemanggilannya diaudit.
  --   * TIDAK PERNAH ikut di response daftar/sinkronisasi, dan diredaksi dari api_request_log.
  -- Disimpan apa adanya (bukan terenkripsi) supaya tetap bisa di-index unik dan ditampilkan ke
  -- verifikator; pengamanannya bertumpu pada kontrol akses di atas. Lihat catatan di README.
  nik             CHAR(16)     NULL,
  -- Penjaga keunikan NIK yang tetap mengizinkan baris soft-deleted dan baris tanpa NIK
  -- (NULL tidak pernah bertabrakan di UNIQUE KEY), pola yang sama dengan placements.dup_guard.
  nik_guard       CHAR(16)     GENERATED ALWAYS AS (IF(deleted_at IS NULL, nik, NULL)) STORED,

  gender          ENUM('L','P') NOT NULL,
  phone           VARCHAR(20)  NULL,  -- nomor WA, disimpan ternormalisasi E.164 ('628xxx')
  email           VARCHAR(150) NULL,
  birth_date      DATE         NULL,
  birth_place     VARCHAR(100) NULL,
  address_text    VARCHAR(255) NULL,
  city            VARCHAR(100) NULL,
  province        VARCHAR(100) NULL,
  photo_path      VARCHAR(255) NULL,

  -- Siklus hidup baris orang, BUKAN alur verifikasi pendaftaran (itu di registration_requests).
  status          ENUM('active','inactive','deceased') NOT NULL DEFAULT 'active',
  notes           TEXT         NULL,

  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at      DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_people_uuid (uuid),
  UNIQUE KEY uq_people_nik_live (nik_guard),
  -- phone sengaja BUKAN unique: nomor WA kadang dipakai bersama (mis. nomor suami terdaftar di data
  -- pengurus muslimah). Keunikan ditegakkan di `accounts.phone`, tempat nomor benar-benar menjadi
  -- identitas login. Di sini cukup index untuk pencarian & pencocokan calon duplikat.
  KEY idx_people_phone (phone),
  KEY idx_people_normalized (name_normalized),
  KEY idx_people_sync (updated_at, id),
  FULLTEXT KEY ft_people_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 4. KATALOG JABATAN
-- =====================================================================================================

-- Menggantikan user_placements.position_name (VARCHAR bebas) di syuro, yang memaksa definisi
-- "Pengurus Inti" hidup sebagai CASE WHEN di app/Core/PengurusInti.php.
--
-- `is_core` + `structure_unit_id IS NULL` di `placements` kini mereproduksi aturan itu sebagai DATA:
--   pengurus inti = placements.structure_unit_id IS NULL AND positions.is_core = 1
-- (ditambah satu kekecualian per tier lewat positions.core_from_tier, migrasi 007: Wakil Ketua inti di DPW ke bawah, bukan di DPP/MWP.)
-- Label "Ketua"/"Sekretaris"/"Bendahara" yang polos tetap dipakai dua-duanya (pucuk pimpinan DPW
-- maupun kepala Departemen di DPP) -- pembedanya structure_unit_id, sama seperti di syuro.
CREATE TABLE positions (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(40)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  -- Di mana jabatan ini wajar dipakai: pucuk pimpinan struktur, di dalam unit SKUO, atau keduanya.
  applies_to   ENUM('structure','unit','both') NOT NULL DEFAULT 'both',
  -- 1 = termasuk Pengurus Inti BILA dipakai di level struktur (structure_unit_id IS NULL).
  is_core      TINYINT(1)   NOT NULL DEFAULT 0,
  -- Urutan protokoler untuk menampilkan susunan pengurus (makin kecil makin atas).
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,

  PRIMARY KEY (id),
  UNIQUE KEY uq_positions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 5. SKUO: KATALOG UNIT, BIDANG, DAN UNIT PER STRUKTUR+PERIODE
-- =====================================================================================================

-- Katalog nama unit yang DIPAKAI BERSAMA lintas struktur -- sengaja mempertahankan pola
-- master_departments di syuro (UNIQUE pada name). "Departemen Kesehatan" adalah satu baris di sini,
-- dipakai oleh DPP maupun MWP. Bidang-nya TIDAK disimpan di sini (lihat unit_groups).
CREATE TABLE unit_catalog (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,
  name          VARCHAR(200) NOT NULL,
  abbreviation  VARCHAR(50)  NULL,
  -- Jenis unit menentukan istilah kepala unitnya: Departemen/Lembaga/Biro/Badan -> "Ketua",
  -- Pusat -> "Kepala", Divisi -> "Koordinator". Disimpan eksplisit, tidak ditebak dari awalan nama.
  kind          ENUM('departemen','lembaga','biro','badan','pusat','divisi','lainnya') NOT NULL DEFAULT 'lainnya',
  -- Jabatan kepala unit yang diharapkan -- default untuk UI/impor, bukan batasan keras.
  default_head_position_id SMALLINT UNSIGNED NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  UNIQUE KEY uq_unit_catalog_uuid (uuid),
  UNIQUE KEY uq_unit_catalog_name (name),
  KEY idx_unit_catalog_head (default_head_position_id),
  CONSTRAINT fk_unit_catalog_head FOREIGN KEY (default_head_position_id)
    REFERENCES positions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Bidang" (DPP) / "Subbidang" (Muslimah) / grup longgar ("Biro-Biro", "Pusat-Pusat", "Badan-Badan").
-- Dipromosikan dari kolom VARCHAR bebas organization_units.bidang di syuro menjadi tabel sendiri,
-- karena di syuro 21 nilai teks berbeda sudah beredar tanpa penjaga typo, dan tanpa cara mengurutkan
-- atau mengganti nama satu Bidang tanpa menyentuh setiap baris unit di bawahnya.
--
-- Terikat ke (structure_id, term_id) karena susunan Bidang BERUBAH antar periode -- SK 2026-2031
-- memperkenalkan "Bidang Transformasi Digital" yang tidak ada di periode sebelumnya. Kalau tabel ini
-- hanya terikat struktur, masalah "riwayat tertimpa" yang mau kita hilangkan akan muncul kembali,
-- cuma naik satu lapis.
CREATE TABLE unit_groups (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,
  structure_id  INT UNSIGNED NOT NULL,
  term_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(200) NOT NULL,
  kind          ENUM('bidang','subbidang','grup') NOT NULL DEFAULT 'bidang',
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_unit_groups_uuid (uuid),
  UNIQUE KEY uq_unit_groups_term_name (term_id, name),
  -- Index acuan untuk composite FK dari structure_units.
  UNIQUE KEY uq_unit_groups_term_id (term_id, id),
  KEY idx_unit_groups_structure (structure_id),
  KEY idx_unit_groups_sync (updated_at, id),
  -- Menjamin term_id benar-benar milik structure_id ini (bukan periode struktur lain).
  CONSTRAINT fk_unit_groups_term FOREIGN KEY (structure_id, term_id)
    REFERENCES terms (structure_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit yang benar-benar ada di satu struktur pada satu periode, beserta Bidang penaungnya.
-- Inilah tempat relasi (struktur + periode + unit) -> Bidang, bukan di katalog unit: "Departemen
-- Kesehatan" bernaung di "Bidang Pelayanan Umat" untuk DPP tapi "Subbidang Pelayanan Umat" untuk MWP.
CREATE TABLE structure_units (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)     NOT NULL,
  structure_id     INT UNSIGNED NOT NULL,
  term_id          INT UNSIGNED NOT NULL,
  unit_catalog_id  INT UNSIGNED NOT NULL,
  unit_group_id    INT UNSIGNED NULL,   -- NULL = belum dipetakan ke Bidang mana pun
  -- Nama tampilan khusus bila struktur ini menyebut unitnya berbeda dari katalog (jarang, tapi ada).
  display_name     VARCHAR(200) NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at       DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_structure_units_uuid (uuid),
  UNIQUE KEY uq_structure_units_term_unit (term_id, unit_catalog_id),
  -- Index acuan untuk composite FK dari placements.
  UNIQUE KEY uq_structure_units_term_id (term_id, id),
  KEY idx_structure_units_structure (structure_id),
  KEY idx_structure_units_catalog (unit_catalog_id),
  KEY idx_structure_units_group (term_id, unit_group_id),
  KEY idx_structure_units_sync (updated_at, id),
  CONSTRAINT fk_structure_units_term FOREIGN KEY (structure_id, term_id)
    REFERENCES terms (structure_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_structure_units_catalog FOREIGN KEY (unit_catalog_id)
    REFERENCES unit_catalog (id) ON DELETE RESTRICT,
  -- Bidang penaung WAJIB berasal dari periode yang sama. Composite FK dengan kolom NULL-able tidak
  -- diperiksa saat unit_group_id NULL (semantik MATCH SIMPLE), jadi "belum dipetakan" tetap sah.
  -- RESTRICT, bukan SET NULL: selain MySQL menolak SET NULL pada composite FK yang salah satu
  -- kolomnya NOT NULL (term_id), menghapus satu Bidang selagi masih menaungi unit memang harus
  -- ditolak -- unitnya dipindahkan dulu secara sadar.
  CONSTRAINT fk_structure_units_group FOREIGN KEY (term_id, unit_group_id)
    REFERENCES unit_groups (term_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 6. PENEMPATAN -- TABEL INTI
-- =====================================================================================================

-- Satu baris = satu orang memegang satu jabatan di satu tempat pada satu periode.
-- Satu orang boleh punya banyak baris: lintas periode (riwayat) DAN dalam periode yang sama (rangkap).
-- Di syuro, 92 dari 1.047 user sudah memegang lebih dari satu penempatan.
CREATE TABLE placements (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)     NOT NULL,
  person_id         INT UNSIGNED NOT NULL,
  term_id           INT UNSIGNED NOT NULL,   -- WAJIB: inilah pembeda utama dari skema syuro
  -- Didenormalisasi dari terms.structure_id karena hampir setiap query menyaringnya (lingkup admin,
  -- daftar per struktur, endpoint API). Dijaga tidak melenceng oleh composite FK ke terms, bukan
  -- oleh disiplin kode aplikasi.
  structure_id      INT UNSIGNED NOT NULL,
  -- NULL = jabatan di pucuk pimpinan struktur (Ketua Umum DPP, Ketua DPW, dst).
  -- Terisi = jabatan di dalam unit SKUO (Ketua Departemen Kesehatan, Anggota Biro Hukum, dst).
  structure_unit_id INT UNSIGNED NULL,
  position_id       SMALLINT UNSIGNED NOT NULL,

  is_primary        TINYINT(1)   NOT NULL DEFAULT 0,  -- penempatan utama orang ini di periode tsb

  -- Masa menjabat DI DALAM periode, untuk pergantian di tengah jalan (mundur, mutasi, wafat,
  -- diberhentikan). NULL = mengikuti tanggal periode. syuro tidak punya ini sama sekali.
  start_date        DATE         NULL,
  end_date          DATE         NULL,
  end_reason        ENUM('selesai_periode','mengundurkan_diri','mutasi','wafat','diberhentikan','lainnya') NULL,

  -- Asal data, supaya hasil impor massal/migrasi bisa dibedakan dari entri manual saat audit.
  source            ENUM('sk','registrasi','migrasi_syuro','manual') NOT NULL DEFAULT 'manual',
  sk_reference      VARCHAR(100) NULL,
  notes             TEXT         NULL,

  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,

  -- Penjaga duplikat. Dua masalah diselesaikan sekaligus:
  --  (a) UNIQUE biasa tidak menangkap duplikat saat structure_unit_id NULL (NULL != NULL di MySQL),
  --      padahal justru di situ letak jabatan pucuk pimpinan -- tanpa ini "Ketua Umum DPP" bisa
  --      masuk dua kali tanpa ditolak.
  --  (b) Baris yang sudah di-soft-delete harus boleh dibuat ulang, jadi penjaganya dibuat NULL
  --      ketika deleted_at terisi.
  dup_guard         VARCHAR(80)
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL,
                      CONCAT_WS('-', term_id, COALESCE(structure_unit_id, 0), position_id, person_id),
                      NULL)) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY uq_placements_uuid (uuid),
  UNIQUE KEY uq_placements_live (dup_guard),
  KEY idx_placements_person (person_id),
  KEY idx_placements_scope (structure_id, term_id),
  KEY idx_placements_term_unit (term_id, structure_unit_id),
  KEY idx_placements_position (position_id),
  KEY idx_placements_sync (updated_at, id),
  CONSTRAINT fk_placements_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT,
  -- Menjamin structure_id yang didenormalisasi benar-benar pemilik term_id ini.
  CONSTRAINT fk_placements_term FOREIGN KEY (structure_id, term_id)
    REFERENCES terms (structure_id, id) ON DELETE RESTRICT,
  -- Menjamin unit yang dirujuk berasal dari periode yang sama (tidak diperiksa saat unit NULL).
  CONSTRAINT fk_placements_unit FOREIGN KEY (term_id, structure_unit_id)
    REFERENCES structure_units (term_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_placements_position FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 7. AKUN & HAK AKSES (SUMBU TERPISAH DARI JABATAN STRUKTURAL)
-- =====================================================================================================

-- Hak login ke APLIKASI INI. Dipisah dari `people` karena keduanya hidup berbeda: ada pengurus tanpa
-- akun (mayoritas -- di syuro hanya 134 dari 1.047 punya nomor telepon tercatat), dan ada pemegang
-- akun yang bukan pengurus (admin IT). Di syuro keduanya satu tabel `users`, lalu `role` dipaksa
-- mewakili dua hal berbeda sekaligus; pemisahan ini mencegahnya terulang.
CREATE TABLE accounts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36)     NOT NULL,
  person_id      INT UNSIGNED NULL,  -- NULL = pemegang akun bukan pengurus
  phone          VARCHAR(20)  NOT NULL,  -- identitas login (OTP WA), ternormalisasi E.164
  email          VARCHAR(150) NULL,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(150) NOT NULL,
  status         ENUM('active','suspended') NOT NULL DEFAULT 'active',
  last_login_at  DATETIME(3)  NULL,
  created_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_uuid (uuid),
  UNIQUE KEY uq_accounts_phone (phone),
  UNIQUE KEY uq_accounts_email (email),
  KEY idx_accounts_person (person_id),
  CONSTRAINT fk_accounts_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Peran + lingkup, BANYAK baris per akun. Di syuro ini dua kolom di `users` (role +
-- scope_organization_id), jadi satu akun hanya bisa punya satu peran di satu lingkup. Tabel terpisah
-- mengakomodasi kasus nyata: satu orang mengelola DPD Kota Kendari sekaligus jadi verifikator DPW.
--
-- Lingkup efektif = struktur ini + SELURUH keturunannya (structures.path LIKE '<path>%'), sama
-- seperti AdminScope::orgIds() di syuro. "Admin Nasional" = baris dengan lingkup di struktur akar.
CREATE TABLE account_roles (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id         INT UNSIGNED NOT NULL,
  role               ENUM('super_admin','admin_struktur','verifikator','viewer') NOT NULL,
  -- NULL hanya sah untuk super_admin (lingkupnya seluruh sistem, tanpa titik akar tertentu).
  scope_structure_id INT UNSIGNED NULL,
  granted_by         INT UNSIGNED NULL,
  created_at         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  UNIQUE KEY uq_account_roles (account_id, role, scope_structure_id),
  KEY idx_account_roles_scope (scope_structure_id),
  KEY idx_account_roles_granter (granted_by),
  CONSTRAINT fk_account_roles_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_account_roles_scope FOREIGN KEY (scope_structure_id) REFERENCES structures (id) ON DELETE CASCADE,
  CONSTRAINT fk_account_roles_granter FOREIGN KEY (granted_by) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak percobaan login, dasar pembatas brute force (lihat app/Core/LoginThrottle.php).
-- Dihitung per (phone + ip), bukan salah satunya saja. Dipangkas berkala oleh tugas terjadwal.
CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone        VARCHAR(20)  NOT NULL,
  ip           VARBINARY(16) NULL,
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  KEY idx_login_attempts_lookup (phone, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 8. PENDAFTARAN & VERIFIKASI
-- =====================================================================================================

-- Pengajuan pendaftar, DISIMPAN TERPISAH dari `people` dan tidak pernah langsung menulis ke sana.
-- Alasannya: data master tetap bersih (API tidak pernah menyajikan orang yang belum diverifikasi),
-- satu pengajuan punya pemeriksa/waktu/alasan penolakannya sendiri, dan satu orang bisa mengajukan
-- lagi di periode berikutnya tanpa menimpa apa pun.
--
-- Status: submitted (Diajukan) -> verified (Diverifikasi) -> activated (Aktif), atau rejected.
-- Verifikasi dilakukan admin yang lingkupnya mencakup target_structure_id. "Aktif" di sini adalah
-- SYARAT, bukan jaminan otomatis dapat akun di aplikasi lain -- pembuatan akun syuro tetap terpisah.
CREATE TABLE registration_requests (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                 CHAR(36)     NOT NULL,

  -- Cuplikan data yang diisi pendaftar, apa adanya. Disimpan utuh supaya tetap bisa dibandingkan
  -- dengan hasil akhir setelah verifikator mengoreksi apa pun.
  full_name            VARCHAR(200) NOT NULL,
  gender               ENUM('L','P') NOT NULL,
  phone                VARCHAR(20)  NOT NULL,
  email                VARCHAR(150) NULL,
  birth_date           DATE         NULL,
  -- NIK yang diisi pendaftar. Sengaja TIDAK unique di sini: dua pengajuan dengan NIK sama adalah
  -- sinyal duplikat yang harus dilihat verifikator, bukan error yang menolak formulir di depan
  -- pendaftar (penolakan itu sendiri membocorkan bahwa NIK tersebut sudah terdaftar).
  nik                  CHAR(16)     NULL,
  extra_data           JSON         NULL,  -- jawaban tambahan yang belum jadi kolom tetap

  -- Jabatan yang diajukan.
  target_structure_id  INT UNSIGNED NOT NULL,
  target_term_id       INT UNSIGNED NULL,
  target_unit_id       INT UNSIGNED NULL,
  target_position_id   SMALLINT UNSIGNED NULL,

  status               ENUM('submitted','verified','rejected','activated') NOT NULL DEFAULT 'submitted',

  -- Hasil pencocokan duplikat otomatis. Mengikuti aturan impor SK di syuro: exact match dan
  -- normalized match hanya DIUSULKAN, TIDAK PERNAH auto-merge. Verifikator yang memutuskan.
  matched_person_id    INT UNSIGNED NULL,
  match_confidence     ENUM('exact','normalized','ambiguous','none') NOT NULL DEFAULT 'none',

  reviewed_by          INT UNSIGNED NULL,
  reviewed_at          DATETIME(3)  NULL,
  review_note          TEXT         NULL,

  -- Jejak hasil aktivasi: baris mana yang akhirnya dibuat/dipakai.
  resulting_person_id    INT UNSIGNED NULL,
  resulting_placement_id INT UNSIGNED NULL,

  submitted_ip         VARBINARY(16) NULL,
  created_at           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_uuid (uuid),
  KEY idx_reg_status (status, target_structure_id),
  KEY idx_reg_structure (target_structure_id),
  KEY idx_reg_matched (matched_person_id),
  KEY idx_reg_nik (nik),
  KEY idx_reg_reviewer (reviewed_by),
  KEY idx_reg_result_person (resulting_person_id),
  KEY idx_reg_result_placement (resulting_placement_id),
  KEY idx_reg_term (target_term_id),
  KEY idx_reg_unit (target_unit_id),
  KEY idx_reg_position (target_position_id),
  CONSTRAINT fk_reg_structure FOREIGN KEY (target_structure_id) REFERENCES structures (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_term FOREIGN KEY (target_term_id) REFERENCES terms (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_unit FOREIGN KEY (target_unit_id) REFERENCES structure_units (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_position FOREIGN KEY (target_position_id) REFERENCES positions (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_matched FOREIGN KEY (matched_person_id) REFERENCES people (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_reviewer FOREIGN KEY (reviewed_by) REFERENCES accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_result_person FOREIGN KEY (resulting_person_id) REFERENCES people (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_result_placement FOREIGN KEY (resulting_placement_id) REFERENCES placements (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 9. IMPOR MASSAL (SK & MIGRASI)
-- =====================================================================================================

-- Satu kali jalan impor (satu SK, atau migrasi dari syuro).
CREATE TABLE import_batches (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)     NOT NULL,
  label             VARCHAR(200) NOT NULL,  -- mis. 'SK DPP 2026-2031'
  source            ENUM('sk','syuro','csv','lainnya') NOT NULL DEFAULT 'sk',
  structure_id      INT UNSIGNED NULL,
  term_id           INT UNSIGNED NULL,
  status            ENUM('running','completed','failed','rolled_back') NOT NULL DEFAULT 'running',
  stat_new          INT UNSIGNED NOT NULL DEFAULT 0,
  stat_matched      INT UNSIGNED NOT NULL DEFAULT 0,
  stat_needs_review INT UNSIGNED NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at       DATETIME(3)  NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_import_batches_uuid (uuid),
  KEY idx_import_batches_structure (structure_id),
  KEY idx_import_batches_term (term_id),
  KEY idx_import_batches_creator (created_by),
  CONSTRAINT fk_import_batches_structure FOREIGN KEY (structure_id) REFERENCES structures (id) ON DELETE SET NULL,
  CONSTRAINT fk_import_batches_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE SET NULL,
  CONSTRAINT fk_import_batches_creator FOREIGN KEY (created_by) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daftar kasus yang perlu ditinjau manusia. Di syuro log ini hanya dicetak ke stdout saat script
-- impor berjalan ("PERLU DICEK MANUAL: tercocok dengan user yang SUDAH ADA sebelum impor ini"),
-- jadi hilang begitu terminal ditutup. Di sini jadi daftar kerja yang bisa diselesaikan.
CREATE TABLE import_review_items (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id            INT UNSIGNED NOT NULL,
  raw_name            VARCHAR(200) NOT NULL,  -- nama apa adanya di sumber
  raw_context         VARCHAR(255) NULL,      -- unit/jabatan di sumber, untuk memudahkan penilaian
  reason              ENUM('matched_existing','matched_in_batch','ambiguous','unmapped_position','unmapped_unit','lainnya') NOT NULL,
  candidate_person_id INT UNSIGNED NULL,
  resolution          ENUM('pending','confirmed','split','corrected','ignored') NOT NULL DEFAULT 'pending',
  resolution_note     TEXT         NULL,
  resolved_by         INT UNSIGNED NULL,
  resolved_at         DATETIME(3)  NULL,
  note                TEXT         NULL,

  PRIMARY KEY (id),
  KEY idx_review_batch (batch_id, resolution),
  KEY idx_review_candidate (candidate_person_id),
  KEY idx_review_resolver (resolved_by),
  CONSTRAINT fk_review_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_review_candidate FOREIGN KEY (candidate_person_id) REFERENCES people (id) ON DELETE SET NULL,
  CONSTRAINT fk_review_resolver FOREIGN KEY (resolved_by) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 10. API BACA-SAJA UNTUK APLIKASI KONSUMEN
-- =====================================================================================================

CREATE TABLE api_clients (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid               CHAR(36)     NOT NULL,
  name               VARCHAR(100) NOT NULL,  -- mis. 'syuro'
  -- Token TIDAK PERNAH disimpan utuh. `token_prefix` (12 karakter pertama, tidak rahasia) dipakai
  -- untuk menemukan barisnya tanpa memindai seluruh tabel; `token_hash` SHA-256 untuk verifikasinya.
  token_prefix       CHAR(12)     NOT NULL,
  token_hash         CHAR(64)     NOT NULL,
  -- Daftar izin, mis. ["structures:read","people:read","placements:read"].
  scopes             JSON         NOT NULL,
  -- Opsional: batasi klien hanya ke satu subtree struktur (klien tingkat wilayah).
  scope_structure_id INT UNSIGNED NULL,
  rate_limit_per_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  status             ENUM('active','revoked') NOT NULL DEFAULT 'active',
  last_used_at       DATETIME(3)  NULL,
  expires_at         DATETIME(3)  NULL,
  created_at         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  UNIQUE KEY uq_api_clients_uuid (uuid),
  UNIQUE KEY uq_api_clients_prefix (token_prefix),
  KEY idx_api_clients_scope (scope_structure_id),
  CONSTRAINT fk_api_clients_scope FOREIGN KEY (scope_structure_id) REFERENCES structures (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak permintaan API: dasar rate limit (hitung per menit per klien) sekaligus audit pemakaian.
-- Dipangkas berkala (mis. simpan 30 hari) oleh tugas terjadwal.
CREATE TABLE api_request_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id     INT UNSIGNED NOT NULL,
  endpoint      VARCHAR(120) NOT NULL,
  query_string  VARCHAR(255) NULL,
  http_status   SMALLINT UNSIGNED NOT NULL,
  row_count     INT UNSIGNED NULL,
  duration_ms   INT UNSIGNED NULL,
  ip            VARBINARY(16) NULL,
  requested_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  KEY idx_api_log_rate (client_id, requested_at),
  CONSTRAINT fk_api_log_client FOREIGN KEY (client_id) REFERENCES api_clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================================
-- 11. AUDIT
-- =====================================================================================================

-- Aplikasi ini jadi rujukan resmi susunan pengurus, jadi "siapa mengubah apa, kapan" perlu terekam
-- -- terutama untuk placements dan terms, yang punya konsekuensi di luar sistem.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity      VARCHAR(40)  NOT NULL,  -- nama tabel
  entity_id   INT UNSIGNED NOT NULL,
  action      ENUM('create','update','delete','restore') NOT NULL,
  before_data JSON         NULL,
  after_data  JSON         NULL,
  account_id  INT UNSIGNED NULL,      -- NULL = proses sistem (impor/migrasi)
  ip          VARBINARY(16) NULL,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

  PRIMARY KEY (id),
  KEY idx_audit_entity (entity, entity_id, created_at),
  KEY idx_audit_account (account_id, created_at),
  CONSTRAINT fk_audit_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
