-- PATH LOKASI FILE: database/002_seed_positions.sql
-- Katalog jabatan awal. Diturunkan dari 17 nilai `position_name` yang benar-benar beredar di
-- user_placements syuro hari ini, ditambah istilah yang disyaratkan SK tapi belum terpakai di sana.
--
-- Cara membaca `is_core`: 1 berarti jabatan ini termasuk Pengurus Inti KETIKA dipegang di level
-- struktur (placements.structure_unit_id IS NULL). Label polos "Ketua"/"Sekretaris"/"Bendahara"
-- dipakai di dua tempat sekaligus -- pucuk pimpinan DPW/DPD/DPC/MW* maupun kepala Departemen di DPP --
-- dan pembedanya tetap structure_unit_id, bukan nama jabatannya. Jadi:
--   pengurus inti = JOIN positions p ON p.id = pl.position_id
--                   WHERE pl.structure_unit_id IS NULL AND p.is_core = 1
-- Itu menggantikan seluruh PengurusInti::sqlCaseFragment() di syuro.
-- Satu tambahan: 'wakil_ketua' berstatus is_core = 0 di sini, tetapi migrasi 007 memberinya core_from_tier = 2, sehingga ia inti
-- di DPW/DPD/DPC/DPRa dan MWW/MWD/MWC (tier 2 ke bawah) namun tidak di DPP/MWP (PositionModel::CORE_SQL).
--
-- `applies_to` hanya pemandu UI/validasi saat entri (jabatan mana yang wajar ditawarkan di konteks
-- mana), bukan batasan keras di tingkat database.

INSERT INTO positions (code, name, applies_to, is_core, sort_order) VALUES
  -- Pucuk pimpinan tingkat DPP: memakai istilah '...Umum'/'...Jenderal' sesuai SK.
  ('ketua_umum',                'Ketua Umum',                'structure', 1,  10),
  ('ketua_harian',              'Ketua Harian',              'structure', 1,  20),
  ('sekretaris_jenderal',       'Sekretaris Jenderal',       'structure', 1,  30),
  ('wakil_sekretaris_jenderal', 'Wakil Sekretaris Jenderal', 'structure', 1,  40),
  ('bendahara_umum',            'Bendahara Umum',            'structure', 1,  50),
  ('wakil_bendahara_umum',      'Wakil Bendahara Umum',      'structure', 1,  60),

  -- Istilah polos: pucuk pimpinan DPW/DPD/DPC/DPRa dan MWP/MWW/MWD/MWC, SEKALIGUS kepala unit SKUO.
  ('ketua',                     'Ketua',                     'both',      1,  11),
  ('wakil_ketua',               'Wakil Ketua',               'both',      0,  21),
  ('sekretaris',                'Sekretaris',                'both',      1,  31),
  ('wakil_sekretaris',          'Wakil Sekretaris',          'both',      1,  41),
  ('bendahara',                 'Bendahara',                 'both',      1,  51),
  ('wakil_bendahara',           'Wakil Bendahara',           'both',      1,  61),

  -- Khas unit tertentu: 'Kepala' untuk Pusat, 'Koordinator' untuk sub-divisi.
  ('kepala',                    'Kepala',                    'unit',      0,  12),
  ('wakil_kepala',              'Wakil Kepala',              'unit',      0,  22),
  ('koordinator',               'Koordinator',               'unit',      0,  13),
  ('wakil_koordinator',         'Wakil Koordinator',         'unit',      0,  23),

  -- Jabatan non-struktural yang sudah terpakai di data syuro.
  ('pengarah',                  'Pengarah',                  'both',      0,   5),
  ('tim_pakar',                 'Tim Pakar',                  'both',      0,  80),
  ('anggota',                   'Anggota',                   'both',      0,  90);

-- Istilah kepala unit per jenis unit, dipakai sebagai default saat entri/impor:
-- Departemen/Lembaga/Biro/Badan -> Ketua, Pusat -> Kepala, Divisi -> Koordinator.
-- Diisi menyusul saat unit_catalog di-seed, karena mengacu ke positions.id yang baru dibuat di atas.
