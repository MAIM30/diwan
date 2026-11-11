-- =====================================================================================================================
-- Rentang periode kepengurusan DPW dan DPD (nama periode + tanggal) dari
--   "DAFTAR SK PEMBENTUKAN - PERIODE KEPENGURUSAN DPD-DPW (update awal 2026).xlsx"   (lembar DPW dan DPD)
-- Dibuat 2026-11-11. Mencocokkan menurut NAMA (DPW: nama provinsi; DPD: provinsi + nama kabupaten/kota seperti di tabel structures).
--
-- Yang dilakukan, berurutan:
--   1. Periode bersama (governance_periods): satu baris per rentang ("2022-2027", dst.); yang sudah ada dipakai ulang (mis. 2021-2026).
--      Tanggalnya 1 Januari tahun awal sampai 31 Desember tahun akhir, sama dengan periode 2021-2026 yang sudah ada.
--   2. DPW/DPD yang SUDAH punya periode berjalan: periodenya dipindah ke rentang di daftar (nama, tanggal mulai, tanggal akhir).
--      Tidak disentuh: status, tanda berjalan, penempatan, dan periode yang sudah punya nomor SK terdaftar.
--   3. DPW/DPD di daftar yang BELUM punya periode sama sekali: dibuat periodenya (aktif bila belum lewat, selain itu "ended").
--   4. Ringkasan hasil (SELECT) dan COMMIT.
--
-- Dipakai:  mysql -u diwan -p db_pengurus < periode_dpw_dpd.sql        (atau tempel di klien SQL)
-- Aman diulang: yang sudah sesuai tidak diubah lagi. Untuk uji coba tanpa menyimpan, ganti COMMIT di akhir dengan ROLLBACK.
-- Cadangkan dulu (mysqldump db_pengurus > cadangan.sql): ini mengubah tanggal periode, dan tanggal akhir memengaruhi sisa masa jabatan
-- yang tampil di portal pengurus.
--
-- Periode yang SUDAH LEWAT (mis. 2018-2023): bila @terapkan_periode_lewat = 1 (bawaan) ikut dipasang apa adanya, sehingga pengurus DPD itu
-- tampil dengan masa jabatan yang sudah habis. Ubah ke 0 bila hanya ingin memasang periode yang masih berjalan.
-- =====================================================================================================================

SET NAMES utf8mb4;
SET @terapkan_periode_lewat = 1;

START TRANSACTION;

CREATE TEMPORARY TABLE tmp_periode (
  level    VARCHAR(4)   NOT NULL,
  provinsi VARCHAR(100) NULL,       -- nama DPW induk (kosong untuk baris DPW)
  nama     VARCHAR(150) NOT NULL,   -- DPW: nama provinsi; DPD: nama seperti di structures ("Kab. Gowa", "Kota Gorontalo")
  periode  VARCHAR(9)   NOT NULL,   -- "TTTT-TTTT"
  KEY idx_tmp_periode (level, nama)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_periode (level, provinsi, nama, periode) VALUES
  -- DPW
  ('DPW', NULL, 'Aceh', '2023-2028'),
  ('DPW', NULL, 'Bali', '2023-2028'),
  ('DPW', NULL, 'Banten', '2023-2028'),
  ('DPW', NULL, 'Bengkulu', '2023-2028'),
  ('DPW', NULL, 'DKI Jakarta', '2022-2027'),
  ('DPW', NULL, 'Daerah Istimewa Yogyakarta', '2023-2028'),
  ('DPW', NULL, 'Gorontalo', '2021-2026'),
  ('DPW', NULL, 'Jambi', '2023-2028'),
  ('DPW', NULL, 'Jawa Barat', '2024-2029'),
  ('DPW', NULL, 'Jawa Tengah', '2023-2028'),
  ('DPW', NULL, 'Jawa Timur', '2023-2028'),
  ('DPW', NULL, 'Kalimantan Barat', '2023-2028'),
  ('DPW', NULL, 'Kalimantan Selatan', '2024-2029'),
  ('DPW', NULL, 'Kalimantan Tengah', '2023-2028'),
  ('DPW', NULL, 'Kalimantan Timur', '2021-2026'),
  ('DPW', NULL, 'Kepulauan Bangka Belitung', '2024-2029'),
  ('DPW', NULL, 'Kepulauan Riau', '2023-2028'),
  ('DPW', NULL, 'Lampung', '2024-2029'),
  ('DPW', NULL, 'Maluku', '2024-2029'),
  ('DPW', NULL, 'Maluku Utara', '2023-2028'),
  ('DPW', NULL, 'Nusa Tenggara Barat', '2022-2027'),
  ('DPW', NULL, 'Nusa Tenggara Timur', '2023-2028'),
  ('DPW', NULL, 'Papua', '2024-2029'),
  ('DPW', NULL, 'Papua Barat', '2024-2029'),
  ('DPW', NULL, 'Papua Barat Daya', '2022-2027'),
  ('DPW', NULL, 'Papua Selatan', '2022-2027'),
  ('DPW', NULL, 'Papua Tengah', '2025-2030'),
  ('DPW', NULL, 'Riau', '2024-2029'),
  ('DPW', NULL, 'Sulawesi Barat', '2021-2026'),
  ('DPW', NULL, 'Sulawesi Selatan', '2022-2027'),
  ('DPW', NULL, 'Sulawesi Tengah', '2022-2027'),
  ('DPW', NULL, 'Sulawesi Tenggara', '2022-2027'),
  ('DPW', NULL, 'Sulawesi Utara', '2022-2027'),
  ('DPW', NULL, 'Sumatera Barat', '2023-2028'),
  ('DPW', NULL, 'Sumatera Selatan', '2024-2029'),
  ('DPW', NULL, 'Sumatera Utara', '2023-2028'),
  -- DPD
  ('DPD', 'Aceh', 'Kab. Aceh Besar', '2018-2023'),
  ('DPD', 'Aceh', 'Kab. Aceh Tenggara', '2018-2023'),
  ('DPD', 'Aceh', 'Kab. Bireuen', '2018-2023'),
  ('DPD', 'Aceh', 'Kota Banda Aceh', '2023-2028'),
  ('DPD', 'Aceh', 'Kota Sabang', '2023-2028'),
  ('DPD', 'Bali', 'Kab. Badung', '2021-2026'),
  ('DPD', 'Bali', 'Kab. Buleleng', '2023-2028'),
  ('DPD', 'Bali', 'Kab. Gianyar', '2025-2030'),
  ('DPD', 'Bali', 'Kab. Tabanan', '2023-2028'),
  ('DPD', 'Bali', 'Kota Denpasar', '2023-2028'),
  ('DPD', 'Banten', 'Kab. Lebak', '2024-2029'),
  ('DPD', 'Banten', 'Kab. Pandeglang', '2021-2026'),
  ('DPD', 'Banten', 'Kab. Serang', '2024-2029'),
  ('DPD', 'Banten', 'Kab. Tangerang', '2022-2027'),
  ('DPD', 'Banten', 'Kota Cilegon', '2022-2027'),
  ('DPD', 'Banten', 'Kota Serang', '2024-2029'),
  ('DPD', 'Banten', 'Kota Tangerang', '2020-2025'),
  ('DPD', 'Banten', 'Kota Tangerang Selatan', '2023-2028'),
  ('DPD', 'Bengkulu', 'Kab. Bengkulu Tengah', '2022-2027'),
  ('DPD', 'Bengkulu', 'Kab. Bengkulu Utara', '2024-2029'),
  ('DPD', 'Bengkulu', 'Kab. Kepahiang', '2025-2030'),
  ('DPD', 'Bengkulu', 'Kab. Mukomuko', '2023-2028'),
  ('DPD', 'Bengkulu', 'Kab. Rejang Lebong', '2024-2029'),
  ('DPD', 'Bengkulu', 'Kab. Seluma', '2023-2028'),
  ('DPD', 'Bengkulu', 'Kota Bengkulu', '2023-2028'),
  ('DPD', 'DKI Jakarta', 'Kab. Administrasi Kepulauan Seribu', '2021-2026'),
  ('DPD', 'DKI Jakarta', 'Kota Administrasi Jakarta Barat', '2020-2025'),
  ('DPD', 'DKI Jakarta', 'Kota Administrasi Jakarta Pusat', '2018-2023'),
  ('DPD', 'DKI Jakarta', 'Kota Administrasi Jakarta Selatan', '2022-2027'),
  ('DPD', 'DKI Jakarta', 'Kota Administrasi Jakarta Timur', '2023-2028'),
  ('DPD', 'DKI Jakarta', 'Kota Administrasi Jakarta Utara', '2022-2027'),
  ('DPD', 'Daerah Istimewa Yogyakarta', 'Kab. Bantul', '2023-2028'),
  ('DPD', 'Daerah Istimewa Yogyakarta', 'Kab. Gunungkidul', '2023-2028'),
  ('DPD', 'Daerah Istimewa Yogyakarta', 'Kab. Kulon Progo', '2019-2024'),
  ('DPD', 'Daerah Istimewa Yogyakarta', 'Kab. Sleman', '2023-2028'),
  ('DPD', 'Daerah Istimewa Yogyakarta', 'Kota Yogyakarta', '2023-2028'),
  ('DPD', 'Gorontalo', 'Kab. Boalemo', '2023-2028'),
  ('DPD', 'Gorontalo', 'Kab. Bone Bolango', '2021-2026'),
  ('DPD', 'Gorontalo', 'Kab. Gorontalo', '2021-2026'),
  ('DPD', 'Gorontalo', 'Kab. Gorontalo Utara', '2025-2030'),
  ('DPD', 'Gorontalo', 'Kab. Pohuwato', '2023-2028'),
  ('DPD', 'Gorontalo', 'Kota Gorontalo', '2021-2026'),
  ('DPD', 'Jambi', 'Kab. Batanghari', '2018-2023'),
  ('DPD', 'Jambi', 'Kab. Muaro Jambi', '2023-2028'),
  ('DPD', 'Jambi', 'Kota Jambi', '2023-2028'),
  ('DPD', 'Jawa Barat', 'Kab. Bandung', '2023-2028'),
  ('DPD', 'Jawa Barat', 'Kab. Bandung Barat', '2019-2024'),
  ('DPD', 'Jawa Barat', 'Kab. Bekasi', '2018-2023'),
  ('DPD', 'Jawa Barat', 'Kab. Bogor', '2024-2029'),
  ('DPD', 'Jawa Barat', 'Kab. Cianjur', '2023-2028'),
  ('DPD', 'Jawa Barat', 'Kab. Karawang', '2018-2023'),
  ('DPD', 'Jawa Barat', 'Kab. Pangandaran', '2020-2025'),
  ('DPD', 'Jawa Barat', 'Kab. Purwakarta', '2019-2024'),
  ('DPD', 'Jawa Barat', 'Kab. Subang', '2021-2026'),
  ('DPD', 'Jawa Barat', 'Kab. Sukabumi', '2019-2024'),
  ('DPD', 'Jawa Barat', 'Kab. Sumedang', '2022-2027'),
  ('DPD', 'Jawa Barat', 'Kab. Tasikmalaya', '2022-2027'),
  ('DPD', 'Jawa Barat', 'Kota Bandung', '2024-2029'),
  ('DPD', 'Jawa Barat', 'Kota Bekasi', '2025-2030'),
  ('DPD', 'Jawa Barat', 'Kota Bogor', '2021-2026'),
  ('DPD', 'Jawa Barat', 'Kota Cimahi', '2020-2025'),
  ('DPD', 'Jawa Barat', 'Kota Depok', '2023-2028'),
  ('DPD', 'Jawa Barat', 'Kota Sukabumi', '2020-2025'),
  ('DPD', 'Jawa Barat', 'Kota Tasikmalaya', '2021-2026'),
  ('DPD', 'Jawa Tengah', 'Kab. Banjarnegara', '2025-2030'),
  ('DPD', 'Jawa Tengah', 'Kab. Banyumas', '2022-2027'),
  ('DPD', 'Jawa Tengah', 'Kab. Brebes', '2023-2028'),
  ('DPD', 'Jawa Tengah', 'Kab. Karanganyar', '2024-2029'),
  ('DPD', 'Jawa Tengah', 'Kab. Kebumen', '2022-2027'),
  ('DPD', 'Jawa Tengah', 'Kab. Klaten', '2021-2026'),
  ('DPD', 'Jawa Tengah', 'Kab. Purbalingga', '2024-2029'),
  ('DPD', 'Jawa Tengah', 'Kab. Purworejo', '2023-2028'),
  ('DPD', 'Jawa Tengah', 'Kab. Semarang', '2024-2029'),
  ('DPD', 'Jawa Tengah', 'Kab. Sragen', '2024-2029'),
  ('DPD', 'Jawa Tengah', 'Kab. Sukoharjo', '2022-2027'),
  ('DPD', 'Jawa Tengah', 'Kab. Temanggung', '2024-2029'),
  ('DPD', 'Jawa Tengah', 'Kab. Wonogiri', '2025-2030'),
  ('DPD', 'Jawa Tengah', 'Kota Semarang', '2023-2028'),
  ('DPD', 'Jawa Tengah', 'Kota Surakarta', '2022-2027'),
  ('DPD', 'Jawa Tengah', 'Kota Tegal', '2025-2030'),
  ('DPD', 'Jawa Timur', 'Kab. Kediri', '2018-2023'),
  ('DPD', 'Jawa Timur', 'Kab. Lumajang', '2022-2027'),
  ('DPD', 'Jawa Timur', 'Kab. Malang', '2025-2030'),
  ('DPD', 'Jawa Timur', 'Kab. Sidoarjo', '2018-2023'),
  ('DPD', 'Jawa Timur', 'Kab. Trenggalek', '2023-2028'),
  ('DPD', 'Jawa Timur', 'Kota Malang', '2018-2023'),
  ('DPD', 'Jawa Timur', 'Kota Surabaya', '2015-2019'),
  ('DPD', 'Kalimantan Barat', 'Kab. Kubu Raya', '2023-2028'),
  ('DPD', 'Kalimantan Barat', 'Kab. Mempawah', '2023-2028'),
  ('DPD', 'Kalimantan Barat', 'Kab. Sambas', '2023-2028'),
  ('DPD', 'Kalimantan Barat', 'Kota Pontianak', '2023-2028'),
  ('DPD', 'Kalimantan Selatan', 'Kab. Barito Kuala', '2018-2023'),
  ('DPD', 'Kalimantan Selatan', 'Kab. Hulu Sungai Tengah', '2022-2027'),
  ('DPD', 'Kalimantan Selatan', 'Kab. Tanah Bumbu', '2023-2028'),
  ('DPD', 'Kalimantan Selatan', 'Kab. Tanah Laut', '2025-2030'),
  ('DPD', 'Kalimantan Selatan', 'Kota Banjarbaru', '2024-2029'),
  ('DPD', 'Kalimantan Selatan', 'Kota Banjarmasin', '2018-2023'),
  ('DPD', 'Kalimantan Tengah', 'Kab. Katingan', '2024-2029'),
  ('DPD', 'Kalimantan Tengah', 'Kab. Kotawaringin Barat', '2022-2027'),
  ('DPD', 'Kalimantan Tengah', 'Kab. Kotawaringin Timur', '2024-2029'),
  ('DPD', 'Kalimantan Tengah', 'Kab. Pulang Pisau', '2024-2029'),
  ('DPD', 'Kalimantan Tengah', 'Kota Palangkaraya', '2024-2029'),
  ('DPD', 'Kalimantan Timur', 'Kab. Berau', '2025-2030'),
  ('DPD', 'Kalimantan Timur', 'Kab. Kutai Barat', '2023-2028'),
  ('DPD', 'Kalimantan Timur', 'Kab. Kutai Kartanegara', '2024-2029'),
  ('DPD', 'Kalimantan Timur', 'Kab. Kutai Timur', '2025-2030'),
  ('DPD', 'Kalimantan Timur', 'Kab. Mahakam Ulu', '2021-2026'),
  ('DPD', 'Kalimantan Timur', 'Kab. Paser', '2022-2027'),
  ('DPD', 'Kalimantan Timur', 'Kab. Penajam Paser Utara', '2022-2027'),
  ('DPD', 'Kalimantan Timur', 'Kota Balikpapan', '2023-2028'),
  ('DPD', 'Kalimantan Timur', 'Kota Bontang', '2023-2028'),
  ('DPD', 'Kalimantan Timur', 'Kota Samarinda', '2023-2028'),
  ('DPD', 'Kalimantan Utara', 'Kab. Bulungan', '2023-2028'),
  ('DPD', 'Kalimantan Utara', 'Kab. Malinau', '2023-2028'),
  ('DPD', 'Kalimantan Utara', 'Kab. Nunukan', '2023-2028'),
  ('DPD', 'Kalimantan Utara', 'Kab. Tana Tidung', '2021-2026'),
  ('DPD', 'Kalimantan Utara', 'Kota Tarakan', '2024-2029'),
  ('DPD', 'Kepulauan Bangka Belitung', 'Kab. Bangka', '2024-2029'),
  ('DPD', 'Kepulauan Bangka Belitung', 'Kab. Bangka Selatan', '2025-2030'),
  ('DPD', 'Kepulauan Bangka Belitung', 'Kab. Bangka Tengah', '2024-2029'),
  ('DPD', 'Kepulauan Bangka Belitung', 'Kota Pangkal Pinang', '2024-2029'),
  ('DPD', 'Kepulauan Riau', 'Kab. Bintan', '2025-2030'),
  ('DPD', 'Kepulauan Riau', 'Kab. Karimun', '2025-2030'),
  ('DPD', 'Kepulauan Riau', 'Kota Batam', '2020-2025'),
  ('DPD', 'Kepulauan Riau', 'Kota Tanjung Pinang', '2025-2030'),
  ('DPD', 'Lampung', 'Kab. Lampung Selatan', '2018-2023'),
  ('DPD', 'Lampung', 'Kab. Lampung Tengah', '2024-2029'),
  ('DPD', 'Lampung', 'Kab. Pesawaran', '2018-2023'),
  ('DPD', 'Lampung', 'Kota Bandar Lampung', '2018-2023'),
  ('DPD', 'Maluku', 'Kab. Buru', '2024-2029'),
  ('DPD', 'Maluku', 'Kab. Buru Selatan', '2024-2029'),
  ('DPD', 'Maluku', 'Kab. Maluku Tengah', '2018-2023'),
  ('DPD', 'Maluku', 'Kota Ambon', '2024-2029'),
  ('DPD', 'Maluku', 'Kota Tual', '2018-2023'),
  ('DPD', 'Maluku Utara', 'Kab. Halmahera Barat', '2024-2029'),
  ('DPD', 'Maluku Utara', 'Kab. Halmahera Selatan', '2019-2024'),
  ('DPD', 'Maluku Utara', 'Kab. Halmahera Tengah', '2017-2022'),
  ('DPD', 'Maluku Utara', 'Kab. Halmahera Utara', '2021-2026'),
  ('DPD', 'Maluku Utara', 'Kab. Pulau Morotai', '2025-2030'),
  ('DPD', 'Maluku Utara', 'Kab. Pulau Taliabu', '2022-2027'),
  ('DPD', 'Maluku Utara', 'Kota Ternate', '2022-2027'),
  ('DPD', 'Maluku Utara', 'Kota Tidore Kepulauan', '2019-2024'),
  ('DPD', 'Nusa Tenggara Barat', 'Kab. Bima', '2021-2026'),
  ('DPD', 'Nusa Tenggara Barat', 'Kab. Lombok Timur', '2018-2023'),
  ('DPD', 'Nusa Tenggara Barat', 'Kab. Lombok Utara', '2018-2023'),
  ('DPD', 'Nusa Tenggara Barat', 'Kab. Sumbawa', '2021-2026'),
  ('DPD', 'Nusa Tenggara Barat', 'Kota Bima', '2025-2030'),
  ('DPD', 'Nusa Tenggara Barat', 'Kota Mataram', '2018-2023'),
  ('DPD', 'Nusa Tenggara Timur', 'Kab. Ende', '2018-2023'),
  ('DPD', 'Nusa Tenggara Timur', 'Kab. Flores Timur', '2022-2027'),
  ('DPD', 'Nusa Tenggara Timur', 'Kab. Kupang', '2021-2026'),
  ('DPD', 'Nusa Tenggara Timur', 'Kab. Lembata', '2023-2028'),
  ('DPD', 'Nusa Tenggara Timur', 'Kab. Nagekeo', '2016-2021'),
  ('DPD', 'Nusa Tenggara Timur', 'Kota Kupang', '2020-2025'),
  ('DPD', 'Papua', 'Kab. Biak Numfor', '2025-2030'),
  ('DPD', 'Papua', 'Kab. Jayapura', '2023-2028'),
  ('DPD', 'Papua', 'Kab. Kepulauan Yapen', '2023-2028'),
  ('DPD', 'Papua', 'Kota Jayapura', '2019-2024'),
  ('DPD', 'Papua Barat', 'Kab. Fak Fak', '2021-2026'),
  ('DPD', 'Papua Barat', 'Kab. Kaimana', '2019-2024'),
  ('DPD', 'Papua Barat', 'Kab. Manokwari', '2017-2022'),
  ('DPD', 'Papua Barat', 'Kab. Teluk Bintuni', '2024-2029'),
  ('DPD', 'Papua Barat Daya', 'Kab. Raja Ampat', '2022-2027'),
  ('DPD', 'Papua Barat Daya', 'Kab. Sorong', '2024-2029'),
  ('DPD', 'Papua Barat Daya', 'Kota Sorong', '2022-2027'),
  ('DPD', 'Papua Selatan', 'Kab. Asmat', '2021-2026'),
  ('DPD', 'Papua Selatan', 'Kab. Boven Digoel', '2025-2030'),
  ('DPD', 'Papua Selatan', 'Kab. Mappi', '2022-2027'),
  ('DPD', 'Papua Selatan', 'Kab. Merauke', '2025-2030'),
  ('DPD', 'Papua Tengah', 'Kab. Dogiyai', '2025-2030'),
  ('DPD', 'Papua Tengah', 'Kab. Mimika', '2025-2030'),
  ('DPD', 'Papua Tengah', 'Kab. Nabire', '2022-2027'),
  ('DPD', 'Riau', 'Kab. Rokan Hulu', '2018-2023'),
  ('DPD', 'Riau', 'Kab. Siak', '2018-2023'),
  ('DPD', 'Riau', 'Kota Pekanbaru', '2018-2023'),
  ('DPD', 'Sulawesi Barat', 'Kab. Majene', '2022-2027'),
  ('DPD', 'Sulawesi Barat', 'Kab. Mamasa', '2017-2022'),
  ('DPD', 'Sulawesi Barat', 'Kab. Mamuju', '2025-2030'),
  ('DPD', 'Sulawesi Barat', 'Kab. Mamuju Tengah', '2025-2030'),
  ('DPD', 'Sulawesi Barat', 'Kab. Pasangkayu', '2025-2030'),
  ('DPD', 'Sulawesi Barat', 'Kab. Polewali Mandar', '2023-2028'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Bantaeng', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Barru', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Bone', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Bulukumba', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Enrekang', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Gowa', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Jeneponto', '2025-2030'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Kepulauan Selayar', '2023-2028'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Luwu', '2024-2029'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Luwu Timur', '2025-2030'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Luwu Utara', '2025-2030'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Maros', '2023-2028'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Pangkajene dan Kepulauan', '2024-2029'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Pinrang', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Sidenreng Rappang', '2023-2028'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Sinjai', '2023-2028'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Soppeng', '2025-2030'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Takalar', '2024-2029'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Tana Toraja', '2022-2027'),
  ('DPD', 'Sulawesi Selatan', 'Kab. Toraja Utara', '2024-2029'),
  ('DPD', 'Sulawesi Selatan', 'Kota Makassar', '2021-2026'),
  ('DPD', 'Sulawesi Selatan', 'Kota Palopo', '2024-2029'),
  ('DPD', 'Sulawesi Selatan', 'Kota Parepare', '2022-2027'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Banggai', '2024-2029'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Banggai Kepulauan', '2025-2030'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Banggai Laut', '2023-2028'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Buol', '2025-2030'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Donggala', '2023-2028'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Morowali', '2023-2028'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Morowali Utara', '2025-2030'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Parigi Moutong', '2025-2030'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Poso', '2023-2028'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Sigi', '2021-2026'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Tojo Una Una', '2021-2026'),
  ('DPD', 'Sulawesi Tengah', 'Kab. Toli-Toli', '2023-2028'),
  ('DPD', 'Sulawesi Tengah', 'Kota Palu', '2021-2026'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Bombana', '2018-2023'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Buton', '2018-2023'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Buton Selatan', '2024-2029'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Buton Tengah', '2023-2028'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Buton Utara', '2024-2029'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Kolaka', '2022-2027'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Kolaka Timur', '2018-2023'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Kolaka Utara', '2022-2027'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Konawe', '2025-2030'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Konawe Kepulauan', '2022-2027'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Konawe Selatan', '2022-2027'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Konawe Utara', '2019-2024'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Muna', '2023-2028'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Muna Barat', '2019-2024'),
  ('DPD', 'Sulawesi Tenggara', 'Kab. Wakatobi', '2021-2026'),
  ('DPD', 'Sulawesi Tenggara', 'Kota Bau Bau', '2022-2027'),
  ('DPD', 'Sulawesi Tenggara', 'Kota Kendari', '2021-2026'),
  ('DPD', 'Sulawesi Utara', 'Kab. Bolaang Mongondow Selatan', '2018-2023'),
  ('DPD', 'Sulawesi Utara', 'Kab. Bolaang Mongondow Timur', '2018-2023'),
  ('DPD', 'Sulawesi Utara', 'Kab. Kepulauan Talaud', '2024-2029'),
  ('DPD', 'Sulawesi Utara', 'Kab. Minahasa', '2013-2017'),
  ('DPD', 'Sulawesi Utara', 'Kab. Minahasa Selatan', '2017-2022'),
  ('DPD', 'Sulawesi Utara', 'Kab. Minahasa Tenggara', '2017-2022'),
  ('DPD', 'Sulawesi Utara', 'Kab. Minahasa Utara', '2013-2017'),
  ('DPD', 'Sulawesi Utara', 'Kota Bitung', '2024-2029'),
  ('DPD', 'Sulawesi Utara', 'Kota Kotamobagu', '2020-2025'),
  ('DPD', 'Sulawesi Utara', 'Kota Manado', '2022-2027'),
  ('DPD', 'Sumatera Barat', 'Kab. Pesisir Selatan', '2023-2028'),
  ('DPD', 'Sumatera Barat', 'Kab. Solok Selatan', '2022-2027'),
  ('DPD', 'Sumatera Barat', 'Kota Bukittinggi', '2020-2025'),
  ('DPD', 'Sumatera Barat', 'Kota Padang', '2023-2028'),
  ('DPD', 'Sumatera Barat', 'Kota Pariaman', '2018-2023'),
  ('DPD', 'Sumatera Barat', 'Kota Payakumbuh', '2020-2025'),
  ('DPD', 'Sumatera Selatan', 'Kab. Musi Banyuasin', '2018-2023'),
  ('DPD', 'Sumatera Selatan', 'Kab. Ogan Komering Ilir', '2018-2023'),
  ('DPD', 'Sumatera Selatan', 'Kab. Ogan Komering Ulu Timur', '2025-2030'),
  ('DPD', 'Sumatera Selatan', 'Kota Palembang', '2018-2023'),
  ('DPD', 'Sumatera Utara', 'Kab. Deli Serdang', '2023-2028'),
  ('DPD', 'Sumatera Utara', 'Kab. Langkat', '2025-2030'),
  ('DPD', 'Sumatera Utara', 'Kota Binjai', '2025-2030'),
  ('DPD', 'Sumatera Utara', 'Kota Medan', '2025-2030'),
  ('DPD', 'Sumatera Utara', 'Kota Pematangsiantar', '2025-2030');

-- Baris di bawah TIDAK dipasang otomatis: rentang tahunnya tertulis polos (tanpa pasangan Hijri) dan bukan lima tahun, kemungkinan salah ketik. Periksa, lalu
-- buang tanda "--" pada baris yang benar dan jalankan ulang berkas ini:
-- INSERT INTO tmp_periode (level, provinsi, nama, periode) VALUES
--  ('DPW', NULL, 'Kalimantan Utara', '2026-2030'),   -- sheet baris 13: "2026 - 2030"
--  ('DPD', 'Sulawesi Selatan', 'Kab. Wajo', '2025-2029'),   -- sheet baris 51: "2025  - 2029"
--  ('DPD', 'Sulawesi Utara', 'Kab. Bolaang Mongondow Utara', '2023-2027'),   -- sheet baris 102: "2023 - 2027"
--  ('DPD', 'Sulawesi Utara', 'Kab. Kepulauan Sangihe', '2023-2027'),   -- sheet baris 114: "2023 - 2027"

-- Baris daftar yang TIDAK memuat rentang periode (dilewati; periode di database tidak diubah):
--   DPD Sulawesi Utara / Kab. Bolaang Mongondow  (sheet baris 98: Penunjukan Ketua)
--   DPD Sulawesi Utara / Kota Tomohon  (sheet baris 113: Penunjukan Ketua)
--   DPD Sulawesi Utara / Kab. Kep. Siau Tagulandang Biaro  (sheet baris 115: Penunjukan Ketua)
--   DPD Nusa Tenggara Barat / Kab. Dompu  (sheet baris 133: Penunjukan Ketua)

-- 1. Periode bersama: satu baris per rentang yang belum ada.
INSERT INTO governance_periods (uuid, name, start_date, end_date_planned)
SELECT UUID(), r.periode, STR_TO_DATE(CONCAT(LEFT(r.periode, 4), '-01-01'), '%Y-%m-%d'), STR_TO_DATE(CONCAT(RIGHT(r.periode, 4), '-12-31'), '%Y-%m-%d')
FROM (SELECT DISTINCT periode FROM tmp_periode) r
WHERE NOT EXISTS (SELECT 1 FROM governance_periods g WHERE g.name = r.periode);
SELECT ROW_COUNT() AS periode_bersama_baru;

-- 2. Periode berjalan DPW/DPD yang sudah ada dipindah ke rentang di daftar.
UPDATE terms t
JOIN structures s ON s.id = t.structure_id AND s.deleted_at IS NULL
LEFT JOIN structures p ON p.id = s.parent_id
JOIN tmp_periode m ON m.level = s.level AND m.nama = s.name AND (s.level = 'DPW' OR m.provinsi = p.name)
JOIN governance_periods g ON g.name = m.periode
SET t.governance_period_id = g.id, t.name = g.name, t.start_date = g.start_date, t.end_date_planned = g.end_date_planned
WHERE t.is_current = 1 AND t.deleted_at IS NULL AND t.sk_number IS NULL
  AND (g.end_date_planned >= CURDATE() OR @terapkan_periode_lewat = 1)
  AND (t.governance_period_id <> g.id OR t.name <> g.name OR t.start_date <> g.start_date OR t.end_date_planned <> g.end_date_planned);
SELECT ROW_COUNT() AS periode_diubah;

-- 3. DPW/DPD di daftar yang belum punya periode sama sekali: buat periodenya.
INSERT INTO terms (uuid, governance_period_id, structure_id, name, start_date, end_date_planned, status, is_current, notes)
SELECT UUID(), g.id, s.id, g.name, g.start_date, g.end_date_planned,
       IF(g.end_date_planned >= CURDATE(), 'active', 'ended'), IF(g.end_date_planned >= CURDATE(), 1, 0),
       'Rentang periode dari DAFTAR SK PEMBENTUKAN - PERIODE KEPENGURUSAN DPD-DPW (update awal 2026), dimuat 2026-11-11.'
FROM tmp_periode m
JOIN structures s ON s.level = m.level AND s.name = m.nama AND s.deleted_at IS NULL
LEFT JOIN structures p ON p.id = s.parent_id
JOIN governance_periods g ON g.name = m.periode
WHERE (s.level = 'DPW' OR m.provinsi = p.name)
  AND (g.end_date_planned >= CURDATE() OR @terapkan_periode_lewat = 1)
  AND NOT EXISTS (SELECT 1 FROM terms t WHERE t.structure_id = s.id AND t.deleted_at IS NULL);
SELECT ROW_COUNT() AS periode_dibuat;

-- 4. Ringkasan: jumlah DPW/DPD per rentang periode berjalan sesudah perubahan.
SELECT s.level, t.name AS periode, t.end_date_planned AS berakhir, COUNT(*) AS jumlah
FROM terms t JOIN structures s ON s.id = t.structure_id
WHERE s.level IN ('DPW', 'DPD') AND t.is_current = 1 AND t.deleted_at IS NULL
GROUP BY s.level, t.name, t.end_date_planned ORDER BY s.level, t.name;

-- Nama di daftar yang tidak ketemu strukturnya di database (seharusnya kosong).
SELECT m.level, m.provinsi, m.nama FROM tmp_periode m
WHERE NOT EXISTS (SELECT 1 FROM structures s LEFT JOIN structures p ON p.id = s.parent_id
                  WHERE s.level = m.level AND s.name = m.nama AND s.deleted_at IS NULL AND (s.level = 'DPW' OR m.provinsi = p.name));

DROP TEMPORARY TABLE tmp_periode;
COMMIT;
