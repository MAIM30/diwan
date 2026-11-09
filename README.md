# DIWAN Wahdah Islamiyah

**DIWAN** = **D**ata **I**nduk **D**ewan Pimpinan **W**ahdah Islamiyah -- *Sistem Tata Kelola Data Kepengurusan Wahdah Islamiyah*.
Berjalan di `diwan.wahdah.or.id`.

Sumber data tunggal untuk data pengurus Wahdah Islamiyah. Menggantikan pengelolaan manual berbasis
Google Form + Apps Script, dan menjadi sumber data yang dibaca aplikasi lain (syuro, dsb.) lewat API
baca-saja.

PHP MVC tanpa framework dan tanpa Composer — sengaja konsisten dengan syuro supaya keduanya enak
dirawat bersama. Target MySQL 8.0+ (memakai generated column dan composite foreign key).

## Yang membedakannya dari skema syuro

1. **Periode adalah entitas bersama.** governance_periods menyimpan acuan nama dan tanggal yang dapat
   dipakai banyak struktur. Setiap struktur memiliki baris terms sendiri untuk status, SK, dan susunannya;
   penempatan tetap terikat ke terms.id, sehingga riwayat dan rekap lintas struktur sama-sama terjaga.
2. **Orang ≠ akun.** `people` adalah subjek data, `accounts` adalah hak login. Di syuro satu kolom
   `users.role` dipaksa mewakili dua sumbu: posisi di organisasi dan hak akses teknis.
3. **Jabatan adalah katalog.** `positions` menggantikan `position_name` VARCHAR bebas. Kolom
   `positions.is_core` menggantikan seluruh `CASE WHEN` di `app/Core/PengurusInti.php` syuro:
   Pengurus Inti kini = `placements.structure_unit_id IS NULL AND positions.is_core = 1`. Aturannya satu tempat:
   `PositionModel::CORE_SQL`. Jabatan dibedakan menurut tempatnya: **Ketua DPW** dan **Ketua DPD**, **Wakil Ketua DPW** dan
   **Wakil Ketua DPD** (tingkat Wilayah dan Daerah), **Ketua Bidang** dan **Wakil Ketua Bidang** (Pengurus Inti DPP, migrasi 020;
   urutan resmi SKUO diatur lewat `sort_order`), **Ketua** / **Wakil Ketua** polos (tingkat struktur lain seperti MWP, selalu inti), dan
   **Wakil Ketua Departemen/Lembaga** (di dalam unit, tak pernah inti). Kepala unit dibedakan menurut jenis unitnya:
   **Ketua Departemen**, **Ketua Lembaga**, **Ketua Biro**, **Ketua Badan** (migrasi 021). `positions.unit_kinds` membatasi
   jenis unit tempat sebuah jabatan boleh dipegang, dan `PositionModel::mismatch()` menolak penempatan yang tak cocok di setiap
   jalur penulisan (formulir, persetujuan pendaftaran, pemindahan struktur). Dulu ada kekecualian per tingkat (`positions.core_from_tier`,
   "Inti mulai jenjang"); migrasi 019 menghapusnya karena sudah setara "selalu inti".
4. **Bidang adalah tabel, dan terikat periode.** `organization_units.bidang` di syuro adalah VARCHAR
   bebas (21 nilai berbeda sudah beredar tanpa penjaga typo). Di sini `unit_groups` terikat ke
   (struktur + periode), karena susunan Bidang berubah antar SK.
5. **UUID permanen** di tiap entitas yang dipublikasikan, supaya aplikasi konsumen bisa menyimpan
   referensi stabil.
6. **Soft delete** di entitas yang dipublikasikan, supaya penghapusan bisa dikabarkan ke konsumen.
7. **Nama dipisah**: gelar depan | nama asli | gelar belakang (lihat bagian *Nama* di bawah).

## Pemasangan

```bash
php database/install.php --db=db_pengurus \
    --admin-phone=08xxxxxxxxxx \
    --admin-password='kata sandi minimal 10 karakter' \
    --admin-name='Nama Admin'
```

Yang dilakukan installer: membuat database, menerapkan `001_schema.sql` + `002_seed_positions.sql`,
menjalankan migrasi bernomor berikutnya (`003`-`016`, **idempoten** -- aman dijalankan ulang di
basis data baru maupun yang sudah berisi), membuat struktur akar DPP beserta MWP sebagai cabang
Muslimah di bawahnya, lalu membuat satu akun Super Admin. Tambahkan `--fresh` untuk menghapus database dan memasang ulang dari nol (pengembangan
lokal saja).

Di server, salin `config/config.production.example.php` menjadi `config/config.local.php` dan isi
nilainya. Berkas itu tidak ikut paket deploy.

Nama aplikasi ada di satu tempat, `config/config.php` (`APP_SHORT`, `APP_NAME`, `APP_ACRONYM`, `APP_TAGLINE`); judul halaman, logo, dan halaman masuk
membacanya dari sana. Alamat situs, nama basis data, dan domain email admin (`@pengurus.wahdah.or.id`) tidak ikut berubah.

**Logo** ada di `public/assets/img/`: `diwan-logo.png` (lambang + tulisan "DIWAN WI", **latar transparan**, dipakai di panel halaman masuk), `diwan-mark.png` (lambang saja, di kotak
putih logo sidebar dan bilah atas portal), dan `diwan-logo-full.png` (berkas asli beserta dua baris tulisan di bawahnya). Dua baris itu, **"Data Induk Dewan Pimpinan Wahdah
Islamiyah"** dan **"Sistem Tata Kelola Data Kepengurusan Wahdah Islamiyah"**, sengaja dipasang sebagai teks HTML (dari `APP_ACRONYM` dan `APP_TAGLINE`), bukan bagian
gambar, supaya tajam di semua ukuran layar, bisa disalin, dan terbaca pembaca layar.

**Ikon aplikasi (favicon)** memakai lambang saja (tanpa tulisan, yang tak terbaca pada 16 px), dipotong dari `diwan-logo.png` yang transparan: `public/favicon.ico` (16/32/48 px;
juga disajikan di `/favicon.ico`), `public/assets/img/icon-192.png`, dan `apple-touch-icon.png` (180 px, berlatar putih karena iOS tak menerima transparan). Tag-nya dibuat oleh
`Ui::favicons()` dan dipasang di `<head>` semua halaman, termasuk halaman galat 403/404. Berversi `?v=` (waktu ubah berkas) karena nginx menyajikannya "immutable" 30 hari:
setelah mengganti berkasnya, ikon di peramban ikut berganti saat deploy. Mengganti logo berarti membuat ulang ketiga berkas ini dari lambang yang baru.

Panel halaman masuk (kiri di layar lebar, kepala atas bertepi bergelombang di ponsel) sengaja **terang**: tulisan "DIWAN" pada logo berwarna teal gelap, jadi di atas
latar teal gelap ia tidak terbaca. Judul dan kalimat konteks di panel itu opsional (`$headline`/`$tagline` pada `auth/_brand.php`); hanya halaman Ganti kata sandi
yang memakainya.

## Struktur berkas

```
index.php                     bootstrap tipis -> public/index.php
public/index.php              front controller tunggal
config/                       konfigurasi (config.local.php dibuat manual di server)
database/
  001_schema.sql              skema dasar (17 tabel)
  002_seed_positions.sql      katalog 19 jabatan
  003_pisah_nama.php          migrasi: nama terpisah, people.home_structure_id, audit_log.structure_id
  004_portal_pengurus.php     migrasi: people.portal_enabled (saklar akses portal per orang)
  install.php                 pemasangan + migrasi (CLI)
  005_data_diri.php           migrasi: people.occupation (pekerjaan) + people.family_phone (HP keluarga)
  006_alamat_dan_sandi.php    migrasi: people.village/district (kelurahan, kecamatan) + accounts.must_change_password
  007_inti_wakil_ketua.php    migrasi: positions.core_from_tier (riwayat; kolomnya dihapus migrasi 019)
  008-016_*.php               migrasi lanjutan: gateway WA, OTP, biodata, audit, SKUO, catatan tinjauan impor, tingkat DPLN/MWRa, acuan periode bersama
  018_ketua_dpw_dpd.php       migrasi: jabatan "Ketua DPW" dan "Ketua DPD" (pucuk pimpinan Wilayah dan Daerah)
  019_wakil_ketua_spesifik.php  migrasi: Wakil Ketua DPW / DPD / Departemen-Lembaga; menghapus positions.core_from_tier
  020_ketua_bidang_inti_dpp.php migrasi: Ketua Bidang / Wakil Ketua Bidang di Pengurus Inti DPP + urutan resmi Pengurus Inti DPP
  021_ketua_menurut_jenis_unit.php  migrasi: Ketua Departemen / Lembaga / Biro / Badan + positions.unit_kinds (batas jenis unit)
  022_katalog_unit_per_sayap.php    migrasi: katalog Unit Organisasi dipisah per sayap (unit_catalog.wing: Umum / Muslimah), nama unik per sayap
  sinkron_ketua_dpw_dpd.php   memasukkan Ketua DPW/DPD dari tab DPW dan DPD di Google Sheet pengurus (CLI; dry-run bawaan, --commit untuk menyimpan)
  sinkron_sheet_dpp.php       menyamakan penempatan satu struktur dengan tab-nya di Google Sheet pengurus: --tab=DPP (bawaan) atau --tab=MWP;
                              pilihan --rinci, --mirip, --buat-unit, --buat-orang untuk sheet tanpa nomor HP (CLI; dry-run bawaan, --commit untuk menyimpan)
  migrate.php                 menerapkan migrasi bernomor pada basis data yang SUDAH terpasang (idempoten; jalankan setiap kali kode diperbarui)
  migrate_syuro.php           migrasi satu kali dari db_syuro (CLI; dry-run bawaan)
  provision_admins.php        satu admin_struktur per struktur yang belum punya (CLI; dry-run bawaan)
  reset_password.php          mengganti kata sandi akun admin (CLI)
  import_biodata.php          impor formulir Biodata Pengurus (PDF -> JSON -> orang) (CLI; dry-run bawaan)
  tools/biodata_pdf_to_json.py  mengekstrak PDF biodata menjadi biodata.json + foto (butuh pdftotext)
tests/
  scan_placeholders.php       penjaga regresi: placeholder SQL bernama yang dipakai dua kali
  biodata_matcher_test.php    uji BiodataMatcher (tanggal lahir, telepon, nama, jabatan -> unit) dengan isian formulir nyata
app/Core/
  App.php                     router
  AdminScope.php              lingkup akses hierarkis per struktur
  Api.php                     autentikasi token, rate limit, response JSON
  Auth.php, Csrf.php, LoginThrottle.php   sesi & pembatas masuk ADMIN
  Member.php, MemberThrottle.php          sesi & pembatas masuk PENGURUS (portal)
  Audit.php                   jejak perubahan + pembukaan NIK
  Text.php                    pemecah nama, normalisasi nama/telepon/NIK (dedup)
  Ui.php                      ikon SVG, avatar, tampilan nama bergelar, tanggal & waktu relatif
  Chart.php                   grafik SVG sisi server (donat, batang, area) + padanan tabel
  Uuid.php                    UUID v4 & token API tanpa Composer
  Photo.php                   sanitasi foto profil (dipakai unggahan DAN impor biodata)
  SkFile.php                  berkas SK (PDF) di storage/sk, di luar folder publik
  AdminNaming.php             nama + nomor pengganti + kata sandi acak untuk admin bawaan struktur
  BiodataMatcher.php          pembaca isian formulir biodata yang ditulis bebas (fungsi murni, tanpa DB)
app/Models/                   satu model per entitas + SyncModel (query API) + DashboardModel + MemberModel (portal)
app/Controllers/              satu controller per sumber daya + ApiController
app/Views/                    layouts (sidebar+topbar), admin, public, errors
public/assets/css/app.css     satu stylesheet, tanpa framework
public/assets/js/app.js       peningkatan progresif: tooltip grafik, tab, menu mobile (tanpa pustaka)
public/uploads/people/        foto profil (ditulis ulang sebagai JPEG 512x512; tak boleh mengeksekusi kode)
```

## Nama: gelar depan | nama asli | gelar belakang

`people` dan `registration_requests` menyimpan tiga kolom: `title_prefix` ("Dr. H."), `name` (nama asli,
tanpa gelar), `title_suffix` ("Lc., M.A."). **`full_name` adalah kolom turunan (generated, STORED)** --
gabungan ketiganya yang dihitung MySQL sendiri. Dengan begitu nama lengkap tidak mungkin berselisih
dari bagian-bagiannya, dan semua kode (serta konsumen API) yang membaca `full_name` tetap berjalan.
Ekspresi MySQL dan `Text::joinName()` PHP diuji sama untuk 300 kombinasi acak termasuk Unicode.

Mengapa dipisah: pencarian, pengurutan, dan deteksi data ganda bekerja di atas **nama asli** -- daftar
terurut menurut "Abdul..." bukan "Dr. ...", dan "zaitun" menemukan "Dr. Muhammad Zaitun Rasmin, Lc., M.A.".

- **Formulir** punya tiga kolom + pratinjau nama lengkap. Gelar yang terlanjur ditempel di kolom nama
  ("Dr. Ahmad, Lc.") dipindahkan otomatis ke kolom gelar, dan pengguna diberi tahu.
- **`Text::splitName()`** memecah string lama (impor SK, migrasi dari syuro). Aturannya: gelar belakang =
  semua setelah koma pertama; gelar depan = token awal yang dikenali (`Dr, Prof, H, Hj, Ust, Ir, ...`);
  tanpa koma, gelar belakang hanya ditebak dari token penutup yang jelas (`S.Pd`, `M.Si.`, `Lc`).
  **"M." sengaja bukan gelar** (hampir selalu "Muhammad"), dan "A.R." (inisial) dibedakan dari
  "M.A." (Magister Agama) lewat daftar pasangan huruf yang dikenal. Hasil pemecahan data massal tetap
  perlu ditinjau manusia.
- **API**: objek orang kini memuat `title_prefix`, `name`, `title_suffix` selain `full_name` (tambahan,
  tidak ada yang dihapus).

## Visibilitas orang

`people` tidak punya kolom struktur (seseorang bisa menjabat di beberapa struktur). Seorang admin
melihat sebuah baris orang bila punya penempatan di lingkupnya **atau** `people.home_structure_id` (struktur
tempat ia pertama didaftarkan) berada di lingkupnya. Butir kedua ada karena orang yang baru dibuat belum
punya penempatan: tanpanya ia tak terlihat oleh admin yang baru saja membuatnya dan tak bisa dipilih saat
menambahkan penempatan pertamanya.

Setiap aksi atas satu orang (lihat, ubah, hapus, unggah foto) memeriksa visibilitas ini; di luar lingkup
dijawab 404 (bukan 403) agar keberadaan baris tidak bocor. Pencocokan data ganda tetap berlaku ke
seluruh basis data (satu orang, satu baris), tetapi biodata calon di luar lingkup diredaksi -- hanya nama.

Penghapusan pengurus tersedia dari halaman profil dan merupakan soft delete. Konfirmasi menyebut bahwa seluruh
penempatan yang belum dihapus, termasuk riwayat, ikut disembunyikan. Admin hanya dapat menghapus orang bila semua
penempatannya berada dalam lingkupnya. Halaman **Data Terhapus** dapat memulihkan orang dan penempatan yang terhapus
dalam aksi yang sama; penghapusan penempatan tersendiri tidak ikut dipulihkan.

## Tampilan

Sidebar + topbar (pencarian, notifikasi pendaftar, menu pengguna), dasbor, dan profil bertab mengikuti
referensi desain, disesuaikan dengan data kepengurusan yang sebenarnya -- tidak ada widget hiasan.
Warna dasar teal (`--primer*` di `app.css`); status memakai warna non-teal (hijau/merah/kuning) supaya
tidak tertukar dengan warna merek. Responsif: sidebar menjadi menu geser di bawah 960px.

Setiap kartu dasbor dibatasi lingkup admin yang login. Angka "Pengurus aktif" di dasbor, di daftar
"Sedang menjabat", dan di basis data didefinisikan SAMA (`DashboardModel::CURRENT`).

**Grafik** dibuat SVG/HTML di sisi server (`Chart.php`), tanpa pustaka dan tanpa CDN:

| Data | Bentuk | Alasan |
|---|---|---|
| Penempatan dicatat per bulan | area, satu seri | tren satu seri; tanpa kotak legenda |
| Pengurus per tingkat | donat, ramp satu hue | tingkat berurutan (Pusat sampai Ranting) = ordinal |
| Jenis kelamin | satu batang bertumpuk | donat 2 irisan dianggap bentuk keliru |
| Pengurus per struktur | batang horizontal, satu warna | membandingkan kategori nominal |

Palet grafik divalidasi dengan `validate_palette.js`: dua seri (teal + oranye) dengan dE CVD 16,6 dan
normal 28,7; ramp tingkat `#064558, #0a7a90, #0f96b0, #35b0c7, #66c3d4` lolos cek ordinal (ujung terang
2,03:1). Setiap grafik punya **padanan tabel** (`<details>`), tooltip yang juga muncul lewat fokus papan
ketik, dan angka yang diuji sama dengan basis data. Mode gelap belum ada.

Dua pasangan warna dari spesifikasi warna dasar tidak mencapai kontras AA 4,5:1: teks putih pada tombol
`--primer` (3,49:1) dan teks putih di ujung terang gradien hero (2,09:1). Keduanya dipertahankan sesuai
spesifikasi; menukar dasar tombol ke `--primer-tua` menaikkannya ke 5,0:1.

## Foto profil

Diunggah dari halaman profil (hanya yang berhak ubah). Berkas **tidak pernah disimpan apa adanya**:
jenis ditentukan dari isi (`finfo` + `getimagesize`), didekode GD, dipotong persegi dari tengah, lalu
ditulis ulang sebagai JPEG 512x512. Itu membuang EXIF (termasuk lokasi GPS), metadata, dan muatan yang
disembunyikan. Nama berkas = UUID orangnya, bukan nama kiriman pengguna. Batas: 3 MB, ~16 megapiksel,
JPG/PNG/WebP. `public/uploads/.htaccess` melarang eksekusi kode. Tanpa foto, avatar berupa inisial
nama asli (bukan gelar).

## Portal Pengurus (login pengurus dengan nomor WhatsApp)

Dua pintu masuk yang terpisah, dengan sesi yang terpisah:

| | Admin | Pengurus |
|---|---|---|
| Alamat masuk | `/auth/login` | `/pengurus/login` (juga tujuan `/` bagi pengunjung baru) |
| Kredensial | nomor WA + kata sandi | **nomor WA saja** (NIK 16 digit tetap diterima bila sudah tercatat) |
| Sesi | `$_SESSION['auth']` (Auth) | `$_SESSION['member']` (Member) |
| Boleh | mengelola data di lingkupnya | **hanya melihat** datanya sendiri |
| Halaman | dasbor admin, dst. | `/pengurus` (beranda), `/pengurus/susunan`, `/pengurus/riwayat`, `/pengurus/data` |

Kunci sesinya berbeda dan tidak ada pemeriksaan hak admin yang membaca kunci `member`, jadi sesi
pengurus tidak pernah dianggap sesi admin (diuji: 7 halaman admin + 2 aksi tulis, semuanya ditolak).
Satu peramban boleh memegang kedua sesi; "keluar" dari salah satunya menghancurkan **seluruh** sesi
(aman di komputer bersama).

### Masuk dengan nomor WA (sejak NIK sulit dikumpulkan)

NIK belum terkumpul untuk hampir semua pengurus, jadi kolom masuk portal kini **Nomor WhatsApp** (`people.phone`). Isiannya
16 digit dianggap NIK (jalur lama, tetap berfungsi); selain itu dinormalisasi sebagai nomor WA (`08...`, `+62 8...`, `628...`
sama saja). **UUID tetap kunci publik dan NIK tetap jalur pencarian tambahan di API**; yang berubah hanya cara pengurus masuk.

**Nomor WA lebih lemah daripada NIK sebagai kredensial**: ia tersimpan di kontak dan grup banyak orang, jadi siapa pun yang tahu nomor
seorang pengurus bisa melihat apa yang boleh dilihat pemiliknya di portal (tersamar; yang bisa ia ubah dibatasi daftar putih, lihat *Ubah data saya*). Semua pengaman
yang ada dipertahankan, ditambah dua yang khusus nomor:

| Pengaman tambahan | Cara kerja |
|---|---|
| Nomor bersama tak bisa dipakai masuk | `people.phone` sengaja tidak unik (mis. nomor suami pada data pengurus muslimah). Portal tak punya faktor kedua untuk memilih pemiliknya, jadi nomor yang dipakai **dua orang atau lebih** (yang belum dihapus) tidak bisa dipakai masuk oleh siapa pun, bukan "masuk sebagai yang pertama". Dihitung semua pemilik, bukan hanya yang layak, supaya hasilnya tak berubah mengikuti status orang lain. Pemilik ber-NIK tetap bisa masuk dengan NIK-nya. Admin melihat alasannya di profil ("Nomor WA dipakai orang lain juga") |
| Pesan seragam | nomor tak terdaftar, dipakai bersama, akses dicabut, nonaktif, belum punya penempatan: satu pesan yang **identik** dengan NIK tak dikenal |

Setelah nomor dikenali, **bila gateway WhatsApp aktif** portal meminta pengurus mengetik **kode OTP 4 digit** yang ditampilkan di atas kolom isian halaman
verifikasi. Kode **tidak dikirim lewat WhatsApp** (pengiriman OTP membuat nomor gateway sering diblokir WhatsApp), jadi
kode ini hanya langkah konfirmasi, **bukan bukti bahwa pengguna memegang nomor itu**. Kode berlaku 5 menit, sekali pakai,
dan percobaan gagal dibatasi. **Bila gateway tidak aktif, langkah OTP ditiadakan** dan nomor yang lolos semua pemeriksaan langsung masuk.
"Aktif" = `Whatsapp::isActive()`: URL dan kunci terisi, gateway menjawab `/status` (batas 3 detik), dan WhatsApp-nya tersambung (`status: open`);
URL yang terisi saja tidak cukup (URL bawaan `localhost:3001` tetap terisi walau gateway mati). Gateway WhatsApp dipakai untuk notifikasi dan broadcast
(dan sebagai penentu langkah OTP ini). `PORTAL_ENABLED = false` mematikan seluruh portal dan `people.portal_enabled` mencabut akses satu orang seketika.

### Soal risiko: NIK dan nomor WA bukan rahasia

Memakai NIK sebagai satu-satunya kredensial adalah pilihan yang lemah: NIK tercetak di KTP, dipakai
banyak sistem, dan polanya terstruktur (6 digit wilayah + 6 digit tanggal lahir + 4 digit urut). **Siapa
pun yang tahu NIK seseorang bisa masuk sebagai orang itu.** Portal ini dibangun sesuai permintaan, dan
pengamannya dipasang di sekelilingnya tanpa mengubah sifat "satu kredensial saja" (berlaku sama untuk nomor WA):

| Pengaman | Cara kerja |
|---|---|
| Tulis terbatas | satu-satunya aksi tulis adalah *Ubah data saya*, dan hanya untuk kolom daftar putih (lihat bawah); nomor WA, NIK, nama, dan jabatan tak bisa diubah dari portal, jadi sesi yang disalahgunakan tak bisa merebut akun |
| Data tersamar | **NIK dan nomor WA tampil utuh** (data milik sendiri; keputusan pemilik sistem 2026-10-21); HP keluarga `62812*****890` dan email `ad***@...` tersamar; tanggal/tempat lahir dan alamat jalan **tidak ditampilkan** |
| Rekan se-unit | hanya nama + jabatan; tanpa foto, telepon, NIK, atau biodata lain |
| Pembatas per IP | 8 gagal/15 menit **atau** 30 gagal/24 jam mengunci IP itu -- walau NIK yang dicoba benar. Per IP, bukan per NIK: NIK yang salah memang tak ada orangnya, jadi hitungan per NIK tak pernah menahan penyapuan |
| Salah format tak dihitung | bukan nomor WA maupun NIK 16 digit = tidak ada pencarian = tak ada yang bisa ditebak |
| Pesan seragam | nomor tak terdaftar, akses dicabut, nonaktif, atau belum punya penempatan: satu pesan yang sama |
| Sesi pendek | habis setelah 30 menit menganggur, batas mutlak 8 jam |
| Saklar per orang | `people.portal_enabled` (kotak centang di Ubah Data). Pencabutan berlaku **seketika**, termasuk sesi yang sedang berjalan: kelayakan diperiksa ulang di basis data pada setiap halaman |
| Saklar global | `PORTAL_ENABLED = false` mematikan seluruh portal |
| Riwayat masuk | beranda menampilkan 6 masuk terakhir (waktu + IP tersamar) milik pemiliknya, supaya akses yang tak dikenal terlihat. Admin melihat "terakhir masuk" di profil |
| Tanpa cache | `Cache-Control: no-store` di semua halaman portal |

**Konsekuensi NIK utuh.** Masuk ke portal hanya bermodal nomor WA, dan nomor WA bukan rahasia; maka siapa pun yang tahu nomor WA seorang pengurus bisa melihat NIK
lengkapnya di portal. Itu keputusan yang disengaja (NIK memang milik pemiliknya sendiri), tetapi ia menambah nilai nomor WA sebagai sasaran. OTP yang
ditampilkan di layar (lihat atas) tidak menutupnya, karena kode itu terlihat oleh siapa pun yang memasukkan nomor tersebut; ketika gateway tidak aktif dan langkah itu hilang,
perlindungannya memang sama. NIK di jejak audit tetap disamarkan.

Admin dapat mematikan seluruh portal melalui `PORTAL_ENABLED` atau mencabut akses satu orang lewat `people.portal_enabled`
bila akses perlu dihentikan.

**Syarat masuk** (`MemberModel::ELIGIBLE`, satu-satunya definisinya): orang belum dihapus, status
`active`, `portal_enabled = 1`, dan punya setidaknya satu penempatan; masuk dengan nomor WA juga mensyaratkan nomor itu hanya dipakai satu orang.

**Konfigurasi** (`config/config.php`): `PORTAL_ENABLED`, `PORTAL_IDLE_MINUTES`, `PORTAL_SESSION_HOURS`,
`PORTAL_FAIL_MAX_SHORT`, `PORTAL_FAIL_MAX_DAY`. Catatan: batas menganggur efektif juga dibatasi
`session.gc_maxlifetime` PHP (bawaan 24 menit).

> **Isi `TRUSTED_IP_HEADER` sebelum portal dibuka ke publik** bila server berada di belakang
> proxy/CDN (mis. `HTTP_CF_CONNECTING_IP` untuk Cloudflare). Tanpa itu semua pengunjung tampak berasal
> dari satu IP, sehingga satu orang yang salah ketik berkali-kali mengunci semua pengurus.

Pencarian NIK saat masuk memakai `people.nik_guard` (kolom turunan ber-indeks unik). Kolom `nik` biasa
tidak terindeks, sehingga pencarian lewat `nik` memindai seluruh tabel pada tiap percobaan masuk -- titik
yang paling mungkin diserbu. `PersonModel::findByNik` (lookup API) juga sudah dialihkan ke kolom ini.

### Ubah data saya (pengurus memperbarui data pribadinya sendiri)

Halaman **Data Saya > Ubah data saya** (`pengurus/ubah`). Karena sesi portal hanya bermodal nomor WA (bukan rahasia), rancangannya:

| | Bidang | Perilaku |
|---|---|---|
| **Nama** | gelar depan, nama asli, gelar belakang | tampil terisi; nama asli wajib; gelar yang terlanjur diketik di kolom nama dipindah otomatis (`MemberModel::NAME_FIELDS`) |
| **Kasar** | kelurahan/desa, kecamatan, kota/kabupaten, provinsi, tempat lahir, pekerjaan | tampil terisi; boleh diubah atau dikosongkan |
| **Sensitif (tulis-saja)** | jalan/BTN/lorong, tanggal lahir, email, HP keluarga | **tidak ditampilkan ulang** (hanya lencana "sudah terisi"); kosong = jangan ubah; terganti hanya bila diketik yang baru |
| **Tak bisa diubah** | nomor WhatsApp, NIK, jenis kelamin | lewat admin |

Nomor WA dan NIK sengaja tak bisa diubah dari portal: kalau bisa, siapa pun yang tahu nomor seseorang bisa memindahkannya ke nomornya sendiri dan mengunci pemilik aslinya.
Daftar putih kolom ada di `MemberModel::SELF_FIELDS` (kiriman lain, termasuk `id`/`person_id`/`phone`/`nik`, diabaikan); validasi **semua-atau-tidak-sama-sekali**;
maks 10 perubahan per jam; setiap perubahan tercatat (`event: portal_edit`, nilai lama + baru) dan tampil di riwayat aktivitas profil admin
("Diubah sendiri oleh pengurus: ..."), tetapi disaring dari umpan dasbor. Alamat disimpan bertingkat: Jalan/BTN/Lorong (`address_text`), Kelurahan (`village`),
Kecamatan (`district`), Kota/Kabupaten (`city`), Provinsi (`province`); keempat yang terakhir dipilih bertingkat dari data wilayah resmi (bagian berikut).
**Perubahan merambat ke konsumen API**: nama (gelar depan, nama asli, gelar belakang), email, tanggal lahir, kota, dan provinsi ada di kontrak API, jadi perubahan
pengurus langsung terbaca syuro (kelurahan, kecamatan, jalan, HP keluarga, pekerjaan tidak).

### Data wilayah (domisili bertingkat)

Provinsi -> Kota/Kabupaten -> Kecamatan -> Kelurahan/Desa dipilih berurutan di formulir admin (*Ubah pengurus*) dan di Portal Pengurus (*Ubah data saya*). Daftarnya dari
API statis **wilayah.id** (38 provinsi; kode dan nama mengikuti Kepmendagri No 300.2.2-2138 Tahun 2025, pembaruan data 2025-07-04, bersumber dari repositori `cahyadsn/wilayah`).
Yang tersimpan tetap **nama** di kolom `province`, `city`, `district`, `village` (misalnya "Kota Kendari", "Kabupaten Gowa"), jadi kontrak API ke syuro tidak berubah.

- **Lewat server, bukan peramban.** `LokasiController` (`lokasi/provinsi`, `lokasi/kabupaten/<kode>`, `lokasi/kecamatan/<kode>`, `lokasi/kelurahan/<kode>`) dan `app/Core/Lokasi.php`.
  wilayah.id tidak menyatakan CORS, batas pemakaian, lisensi, atau jaminan ketersediaan; lewat server alamat IP pengguna tak sampai ke pihak ketiga. Hanya sesi admin
  (bukan yang masih bersandi sementara) atau sesi pengurus yang boleh memanggilnya.
- **Salinan di `storage/cache/lokasi/`** (di luar folder publik, terblokir `.htaccess`), dipakai ulang 90 hari. Bila API gagal, **salinan lama tetap dilayani**
  (`stale: true`) daripada mematikan isian; bila tak ada salinan sama sekali, endpoint menjawab 503 dan formulir kembali ke kolom teks biasa.
- **Kode induk diverifikasi** terhadap daftar induknya sebelum diteruskan (provinsi -> kabupaten -> kecamatan). Kode ngawur ditolak (400/404) tanpa menghubungi API,
  sehingga tak ada yang bisa memaksa server menembak API tanpa henti atau menumpuk berkas cache. Isi balasan API divalidasi bentuknya (kode anak harus berawalan kode induk)
  dan yang tak masuk akal tidak pernah disalin.
- **Di formulir** (`app.js`, atribut `data-lokasi`): kolom teks aslinya tetap ada di HTML sebagai cadangan; bila daftar tak bisa dimuat, kolom itu tetap bisa diketik.
  Nilai lama yang tak cocok dengan daftar (data impor atau ketikan bebas, mis. "Makassar", "KAB. GOWA") **tidak dibuang**: tampil sebagai pilihan "(data lama)" dan tetap
  terkirim sampai diganti. Begitu induknya dipilih, nilai lama yang cocok dengan TEPAT satu nama resmi dipilihkan otomatis ("Makassar" -> "Kota Makassar"); yang ambigu
  ("Bogor": ada Kabupaten dan Kota) dibiarkan. Mengganti induk mengosongkan semua turunannya, jadi alamat tak bisa tersisa setengah lama setengah baru.
- **Server tidak memaksa** nilai harus ada di daftar: data lama dan keadaan API mati tetap bisa disimpan. Penegakan keras ditunda bersama pembersihan data lama
  (saat ini 174 baris `city` bertulisan bebas, `province`/`district`/`village` masih kosong).
- **Konfigurasi** (`config/config.php`): `LOKASI_API_BASE` (bawaan `https://wilayah.id/api`), `LOKASI_CACHE_DIR`.

### Notifikasi dan konfirmasi (SweetAlert2)

Pustaka **SweetAlert2 11.26.25** (MIT) disimpan di `public/assets/vendor/sweetalert2/` (bukan dari CDN, jadi tak ada ketergantungan pihak ketiga saat berjalan; integritas paket npm
diverifikasi terhadap registry saat diunduh, lihat `VERSI.txt`). Dimuat lewat `layouts/scripts.php`, sebelum `app.js`.

- **Pesan sekali-tampil (Flash)** dicetak server sebagai kotak `.alert` biasa (`layouts/flash.php`), lalu `app.js` menggantinya: **sukses dan info = toast** di kanan atas
  (hilang sendiri 4,5 detik), **galat dan peringatan = jendela** yang harus ditutup. Tanpa JavaScript kotak `.alert` tetap terbaca.
- **Konfirmasi tindakan berisiko**: tambahkan `data-confirm="Pertanyaannya?"` pada `<form>` (dan `onsubmit="return confirm(this.dataset.confirm)"` sebagai cadangan bila
  pustaka gagal dimuat). Dialog berikon peringatan, tombol "Ya, lanjutkan" merah dan "Batal", **fokus awal di Batal** supaya Enter tak mengeksekusi. Dipakai di 7 tempat
  (hapus foto/struktur/Bidang/unit, akhiri penempatan, terbitkan/cabut token).
- Teks pesan selalu lewat `title`/`text` (bukan `html`), jadi isi pesan tak pernah ditafsirkan sebagai HTML.

### Tampilan portal: ponsel-dulu, kaca, bilah bawah

Pengurus membuka portal terutama dari ponsel, jadi tampilannya dirancang dari layar sempit ke atas
(area admin tetap padat-data ala desktop). Terinspirasi satu referensi UI ponsel bergaya *glassmorphism*
(Metrics / Teams / Calendar); yang dipakai hanya yang cocok dengan data kepengurusan:

| Unsur referensi | Keputusan | Alasan / wujudnya |
|---|---|---|
| Bilah tab bawah, tab aktif pil | **Dipakai** (`.m-tabbar`, ≤760px; melayang, putih, tab aktif pil teal muda) | 4 tab: Beranda, Susunan, Riwayat, Data Saya. Tab aktif memunculkan label teks; tiap tab ber-`aria-label`, tepat satu `aria-current="page"`; menghormati area aman layar berponi (`viewport-fit=cover`). Di layar lebar tetap menu atas |
| Kartu kaca (blur + tembus pandang) | **Dipakai, hanya di portal** | latar gradien lembut, kartu 74% putih + `backdrop-filter`; tanpa dukungan blur turun ke kartu hampir opak. Teks tak pernah di atas latar ramai, jadi kontras tidak turun |
| Judul halaman besar | **Dipakai** | 32px di ponsel |
| Pil penyaring | **Dipakai** di Riwayat | Semua / Berjalan / Arsip dengan hitungan; `?f=` tak dikenal jatuh ke "semua" |
| Angka besar + chip + bar progres | **Dipakai, diberi arti nyata** | "sisa masa jabatan" (`4 th 10 bln`) + persen berjalan, dihitung dari tanggal periode. Periode molor (lewat tanggal akhir rencana tapi belum diganti) ditulis apa adanya: "Lewat N" + ikon peringatan, bukan disembunyikan |
| Kalender bulan + daftar "Today" | **Diganti linimasa (Gantt)** | pengurus tak punya agenda harian di sini; yang bermakna adalah rentang jabatan antarperiode. Garis "Hari ini", warna berjalan = teal, arsip = abu-abu, tooltip dan padanan tabel |
| Kluster avatar tim | **Dipakai** di Susunan | sampai 3 pimpinan (jabatan inti, urut protokoler) lalu daftar anggota; avatar rekan selalu inisial, tanpa foto |
| "Best Employees" (podium) | **Ditolak** | aplikasi ini tidak punya data kinerja, dan meranking pengurus bukan fungsi registri kepengurusan |
| Kalender rapat / agenda | **Ditolak** | penjadwalan rapat adalah domain syuro. Bila nanti perlu "Rapat Saya", cukup tautan ke syuro lewat UUID |

Linimasa dibuat SVG di sisi server (`Chart::gantt`), tanpa pustaka; viewBox 360 ≈ lebar kartu ponsel supaya
teks terbaca 1:1. Bentuk *emphasis* (satu aksen + abu-abu) karena data hanya punya dua keadaan; warna
divalidasi dengan `validate_palette.js` (abu-abu 2,88:1 < 3:1 sah karena ada label teks "· berjalan",
legenda, tooltip, dan tabel). Semua teks yang berasal dari data (nama struktur, periode) di-escape,
termasuk di atribut tooltip dan `aria-label` (diuji dengan nama struktur bermuatan skrip).

### Beranda portal (`/pengurus`)

Satu kartu gelap (**Jabatan saat ini**) sebagai fokus, lalu kartu putih. Layar ≥900px dua kolom (kiri: jabatan, Biodata, jabatan lain, perjalanan; kanan: data saya,
rekan, riwayat masuk); di bawahnya satu kolom dengan urutan menurut kelas `bn-t-*` (kolom dilebur dengan `display: contents`). Latar melengkung dan cincin persen yang
lama sudah dilepas; persen berjalan kini gelembung pada garis periode.

| Blok | Isi | Sumber |
|---|---|---|
| Jabatan saat ini | nama jabatan, unit (atau Pengurus Inti / Pimpinan Lainnya), rantai struktur, **sisa masa jabatan sebagai angka besar** ("55 hari", "4 tahun 10 bulan"), garis periode dengan tanda tahun dan posisi hari ini. Molor: "+15 hari" kuning, label "Lewat dari tanggal akhir rencana" | `MemberModel::life`, `PortalHome::countdown/yearTicks`, `Ui::spanParts` |
| Biodata | Isi / Ubah, Unduh PDF, status Drive | `BiodataModel` |
| Jabatan lain | kartu dengan bar sisa waktu; ponsel: kartu geser, desktop: dua kolom; tampil paling banyak 4 (`PortalHome::OTHERS_MAX`), sisanya "+ N jabatan lainnya" ke Riwayat | `MemberModel::currentOf` |
| Data saya | enam butir (donat n/6). Yang bisa diisi sendiri (email, tanggal lahir, alamat) bertombol **Isi** ke kolomnya di Ubah data; WhatsApp, NIK, foto profil "dikelola admin" tanpa tombol | `MemberModel::completeness` |
| Rekan | 4 rekan se-unit (atau pimpinan struktur bila jabatannya tingkat struktur), nama + jabatan saja, "+ N rekan lainnya" | `colleagues`, `colleaguesCount` |
| Perjalanan saya | tiga penempatan terbaru, kolom tahun di kiri | `PortalHome::career` |
| Terakhir masuk | satu baris; enam masuk terakhir (waktu, IP tersamar) terbuka bila diketuk | `loginHistory` |

**Jabatan utama** = Pengurus Inti tingkat struktur lebih dulu, lalu urutan semula (periode terbaru lebih dulu) (`PortalHome::orderCurrent`). **Tidak sedang menjabat**: kartu terang
berisi jabatan terakhir; blok rekan tidak tampil. **Privasi** sama dengan halaman Data Saya: NIK dan WhatsApp utuh, email tersamar, tanggal lahir dan alamat jalan **tidak
ditampilkan** (butir yang terisi cukup bertanda "Terisi"). Foto Biodata dihitung sebagai foto profil. Nama struktur yang jabarannya sudah namanya ("Dewan Pimpinan Pusat")
ditulis "DPP". Bahan perhitungan ada di `app/Core/PortalHome.php` (tanpa database), diuji di `tests/portal_beranda_test.php` (termasuk beranda sungguhan untuk pengurus satu
jabatan, rangkap, dan tidak menjabat di database lokal, serta penjaga agar gaya/markup lama tidak tersisa).

## Jejak SK (Surat Keputusan)

Setiap periode bisa memuat **nomor SK, tanggal SK, dan berkas PDF-nya**; setiap penempatan memuat `sk_reference` ("D.001/QR/PU-WI/03/1448 Lamp. 1"). Halaman periode
menampilkan tautan berkas dan **cakupan jejak** ("667 dari 667 penempatan tertaut SK").

- **Penyimpanan.** `storage/sk/<uuid>.pdf`, **di luar folder publik**; `storage/` diblokir di `.htaccess` dan punya `.htaccess` sendiri (`Require all denied`).
  Nama berkas = UUID acak, bukan nama unggahan.
- **Unduhan** hanya lewat `terms/sk/<id>`: wajib masuk **dan** periodenya berada di lingkup admin (admin DPW tak bisa membuka SK DPP). Dilayani dengan
  `Content-Security-Policy: sandbox`, `nosniff`, `no-store`; nama berkas disanitasi (nomor SK bermuatan CRLF tak menyuntikkan header). Nilai `sk_file_path` di basis data
  divalidasi bentuknya (`sk/<uuid>.pdf`), jadi baris yang ternoda tak bisa dipakai membaca berkas lain.
- **Unggah** lewat formulir periode (hanya yang boleh mengubah data): jenis ditentukan dari ISI (finfo + tanda `%PDF-`), maks 20 MB (batas `upload_max_filesize` PHP
  di server disebut di pesan galat). PDF tidak bisa disucihamakan seperti foto, makanya hanya admin yang boleh mengunggah dan berkas tak pernah dieksekusi/dirender
  dalam konteks aplikasi. Satu berkas boleh dipakai dua periode (satu SK mengesahkan DPP dan MWP); berkas lama dihapus dari disk hanya bila tak dipakai periode lain.

**SK yang sudah tercatat:** SK Pemimpin Umum No. **D.001/QR/PU-WI/03/1448**, 12 September 2026 M (30 Rabiulawal 1448 H), Lampiran 1 = DPP 2026-2031 (667 penempatan),
Lampiran 2 = MWP 2026-2031 (239). Perbandingan dengan isi SK saat dicatat: 24 jabatan pucuk DPP dan 15 pucuk MWP cocok (nama inti, 38 dari 39 persis dan 1 beda ejaan
yang dikoreksi: "Dhanny" -> "Dhany"), dan **jumlah anggota tiap unit** cocok semuanya, kecuali dua yang dijelaskan: Departemen Data dan TI (Muhammad Adhan, Ketuanya
di SK, sempat tersangkut di periode arsip "DPP 2021-2026" karena tanggal masa amanah jabatan DPW-nya di syuro; dipindahkan, dan periode arsip sintesis itu dihapus lunak)
dan Kesehatan MWP (SK menulis dr. Dewi Sartika dua kali, sebagai Sekretaris dan Anggota; disimpan sekali). **Yang belum diperiksa nama per nama** adalah anggota
non-pucuk; itu butuh OCR atau pembacaan halaman satu per satu. SK periode 2021-2026 DPW dan MWW Sultra belum ada.

## Admin per struktur (Pusat, Wilayah, Daerah, Cabang, Ranting)

Hak akses terhadap data **mengikuti struktur**: sebuah peran `admin_struktur` berlingkup satu struktur otomatis mencakup struktur itu +
seluruh keturunannya (`AdminScope`, materialized path), dan tidak pernah menyeberang cabang (admin DPW tidak menyentuh MWW).

| Tingkat | Contoh admin | Lingkup yang terlihat/dikelola |
|---|---|---|
| Pusat | DPP | seluruh sistem, termasuk sayap Muslimah (MWP menggantung di bawah DPP) |
| Pusat Muslimah | MWP | MWP + MWW + MWD + MWC + MWRa |
| Wilayah | DPW Sulawesi Tenggara | DPW itu + semua DPD/DPC/DPRa di bawahnya, **bukan** MWW Sultra |
| Luar Negeri | DPLN | hanya DPLN dan keturunan yang berada di bawahnya |
| Daerah | DPD Kota Kendari | DPD itu + DPC + DPRa di bawahnya |
| Cabang | DPC Mandonga | DPC itu + DPRa di bawahnya |
| Ranting | DPRa Ranting Satu atau MWRa Ranting Satu | hanya ranting itu |

Yang dibatasi: daftar & profil orang (di luar lingkup dijawab 404), formulir ubah, penempatan, periode, unit, pendaftar (tinjau + verifikasi),
struktur (nama induk di luar lingkup tak ditampilkan), daftar akun, dan semua aksi tulis. **Mendelegasikan hanya ke bawah**: admin boleh membuat
akun dengan lingkup di dalam lingkupnya sendiri; ia tak bisa memberi lingkup ke induk, saudara, atau sayap lain, tak bisa menerbitkan `super_admin`
atau peran tanpa lingkup (`AccountModel::assertCanGrant`, dijalankan sebelum akun ditulis sehingga tak ada akun yatim). Halaman Token API khusus Super Admin.

**Menyediakan adminnya**

```bash
php database/provision_admins.php --db=db_pengurus                         # lihat dulu (dry-run)
php database/provision_admins.php --db=db_pengurus --commit --out=kredensial.csv
```

Satu akun `admin_struktur` per struktur yang belum punya (idempoten; struktur baru, mis. DPC/DPRa, terisi pada jalan berikutnya). Kata sandi
acak 16 karakter hanya tampil sekali (di basis data hanya hash); `--out` tidak pernah menimpa berkas yang sudah ada, karena kata sandi yang sudah
terbit tak bisa dipulihkan. Di antarmuka: isi tiap simpul di halaman **Struktur** memuat blok "Admin struktur ini" dan tautan **+ Buat admin** yang mengisi form akun
(peran + lingkup + nama usulan); admin tingkat bawah dibuat dari akun tingkat atasnya.

**Masuk sebagai admin: email ATAU nomor WA + kata sandi.** Kolom masuk menerima `nama@pengurus.wahdah.or.id` (tak peka huruf besar/kecil) atau
nomor WhatsApp; email diisi di menu Akun (harus unik). Pembatas percobaan bekerja per nama pengguna + IP (email diringkas jadi kunci 20 karakter).
Pesan gagal seragam untuk nama pengguna tak terdaftar, sandi salah, dan akun ditangguhkan.

**Kata sandi sementara.** Akun dengan `must_change_password = 1` hanya boleh membuka halaman **Ganti kata sandi** (`/sandi`) dan Keluar sampai sandinya
diganti (`Controller::requireLogin`). Sandi baru minimal 10 karakter, harus berbeda dari yang lama, dan **tidak boleh memuat bagian depan email atau nomor
WA** -- persis pola sandi sementara yang mudah ditebak (`admin.dpp123`). Akun yang dibuat lewat menu Akun mencentang "kata sandi sementara" secara bawaan.

**Lima admin awal** (dibuat 2026-10-21): `admin.dpp@`, `admin.mwp@`, `admin.dpw.sultra@`, `admin.mww.sultra@`, `admin.dpd.kendari@pengurus.wahdah.or.id`
(lingkup masing-masing: DPP, MWP, DPW Sultra, MWW Sultra, DPD Kota Kendari), kata sandi sementaranya bagian depan email + `123`, wajib diganti saat
masuk pertama. Admin lain dibuat lewat menu Akun / tombol **+ Buat admin** di halaman Struktur.

**Halaman Struktur berupa pohon.** Tiap struktur adalah satu baris ringkas (nama, tingkat, lencana *Muslimah* / *nonaktif*, jumlah anak langsung); mengetuknya
membuka keterangan (kode wilayah bila ada, tautan periode, admin struktur, tombol Ubah/Hapus) dan anak-anaknya, yang masing-masing juga tertutup sampai diketuk.
Hanya satu puncak dalam lingkup (mis. DPP bagi Super Admin, DPD bagi Admin Daerah) yang terbuka sejak awal; **Buka semua / Tutup semua** membuka atau menutup seluruhnya.
Dibuat dari elemen `<details>` bawaan peramban (bisa dengan papan ketik, tetap bekerja tanpa JavaScript), dan struktur induk di luar lingkup admin tidak ikut tampil.
Lingkup admin (`AdminScope`) dihitung saat masuk; setelah admin menambah atau mengubah struktur lewat halaman ini lingkupnya dihitung ulang dari akar yang sama
(`AdminScope::refresh`), sehingga struktur barunya langsung terlihat dan langkah berikutnya (form periode) tidak ditolak.

**Pilihan Tingkat di form struktur mengikuti lingkup admin** (`StructureModel::levelsInScope`): di tiap sayap, mulai dari struktur tertingginya dalam lingkup ke bawah.
Admin DPW melihat DPW/DPD/DPC/DPRa, admin DPD melihat DPD/DPC/DPRa, dan seterusnya; admin DPP dapat membuat DPLN setingkat DPW dan admin MWC dapat membuat MWRa; sayap yang tak ada dalam lingkupnya (Muslimah bagi admin DPW) tidak muncul,
sedangkan admin DPP dan Super Admin melihat semuanya. Batas yang sama dipaksakan di server saat menyimpan, jadi mengirim level lain secara manual ditolak.

**Susunan periode dikelompokkan per kategori.** Halaman detail periode menampilkan "Susunan Lengkap" sebagai daftar yang membuka isinya saat diketuk (pola `<details>`
yang sama dengan halaman Struktur, lengkap dengan Buka semua / Tutup semua), dikelompokkan oleh `Susunan::arrange` (`app/Core/Susunan.php`):

| Simpul | Isinya |
|---|---|
| **Pengurus Inti** | jabatan tingkat struktur (tanpa unit) yang di katalog jabatan inti bagi struktur itu: bertanda `is_core`; terbuka sejak awal, dengan kolom Kontak |
| **Pimpinan Lainnya** | jabatan tingkat struktur yang bukan inti (mis. Wakil Ketua di DPP/MWP, Pengarah); hanya tampil bila ada |
| **Bidang** → tiap Bidang → unit | tiap Bidang memuat unit yang dinaunginya: **Departemen dulu, lalu Lembaga**, lalu jenis lain (Biro, Badan, Pusat, Divisi); sayap Muslimah berlabel **Subbidang** |
| kelompok lain (Pusat-Pusat, Badan-Badan, Biro-Biro) | kelompok bukan-Bidang milik periode itu, memakai namanya sendiri |
| **Unit tanpa Bidang** | unit yang belum dinaungi Bidang mana pun (mis. seluruh unit DPW/MWW Sultra sekarang) |

Setiap penempatan masuk tepat satu simpul (yang tak terpetakan jatuh ke Pimpinan Lainnya, tidak pernah hilang); Bidang dan unit yang kosong tetap tampil.
"N orang" menghitung orang yang masih menjabat (penempatan belum berakhir), satu orang dengan dua jabatan di satu simpul dihitung sekali; baris yang sudah berakhir
tetap tampil dengan lencana "berakhir". Kolom Ubah/Akhiri tetap hanya untuk yang boleh mengubah data.

**Form Penempatan** (`/placements/form/{periode}[/{penempatan}]`) disusun Siapa / Di mana dan sebagai apa / Kapan / Dokumen. Orangnya selalu tampil sebagai kartu bernama: yang punya
penempatan itu (mengubah), `?person_id=` dari profil, atau hasil **pencarian** `placements/orang?q=` (JSON, nama / WA / NIK lengkap, paling banyak 15, dalam lingkup admin) saat
menambah; dulu orang dicari di 2.000 nama pertama menurut abjad sehingga kolomnya kosong untuk sebagian besar pengurus. Jenis kelamin dan sayap mengikuti orangnya (laki-laki: Umum,
perempuan: Muslimah), bukan isian tersendiri. Karena nama pilihan di form tidak sama dengan nama simpul di susunan, kotak **Akan tampil di** menunjukkan simpul tujuannya menurut Unit dan
Jabatan yang dipilih (`PlacementModel::landingLabel`, cermin `Susunan::arrange`): di dalam unit = Bidang/kelompok › unit; tanpa unit = Pengurus Inti bila jabatannya inti, selain itu Pimpinan
Lainnya. Pesan sesudah menyimpan menyebut tempat itu juga ("Dipindah dari A ke B"), dan simpanan yang tidak mengubah apa pun dijawab peringatan, bukan "berhasil". Panel **Penempatan lain**
berada di luar form simpan: form di dalam form tidak sah di HTML, dan browser membuang yang di dalam, sehingga tombol Anulir dulu menyimpan form alih-alih menganulir.

**Data Pengurus: rekap dan dua tampilan.** Halaman `/people` punya baris **Jenjang** (DPP, DPW, DPD, MWP, MWW, MWD, DSO, DSY, DPK: jumlah orang yang sedang menjabat di jenjang itu; `?lv=DPW`),
tiga kelompok kartu rekap, lalu satu dari dua tampilan (`?susun=struktur|daftar`):

- **Rekap tiga kelompok** (`PersonModel::recapCounts`): *Status jabatan* (Menjabat, Rangkap, Tidak menjabat), *Kelengkapan data* (Data lengkap, Belum lengkap; definisi
  `PersonModel::COMPLETE_SQL`: **nomor WA dan jenis kelamin terisi**; NIK belum dihitung, karena NIK akan menjadi ID unik penghubung ke aplikasi lain dan kelak diwajibkan,
  dengan sumber yang diharapkan Database Kader, sedangkan DIWAN hanya database Pengurus. Saat NIK diwajibkan, ubah `COMPLETE_SQL`, `missingFields()`, dan teks `COMPLETE_RULE` /
  `COMPLETE_SHORT` bersama di `PersonModel`; `tests/people_per_struktur_test.php` menjaga predikat SQL dan padanan PHP-nya tetap sepakat), *Jenis kelamin*. Angkanya selalu saling cocok: Menjabat + Tidak menjabat = total, Rangkap + tunggal = Menjabat, Lengkap + Belum = total.
  Rekap mengikuti **pencarian dan jenjang** yang dipilih, tetapi tidak mengikuti penyaring kartunya sendiri (jenis kelamin, kelengkapan, rangkap): kartu-kartu itu memecah himpunan
  yang sama, kalau ikut menyaring maka memilih "Perempuan" akan menolkan kartu "Laki-laki". Lencana judul tetap total seluruh lingkup admin.
- **Per Struktur** (bawaan bila tak ada pencarian atau penyaring daftar): jenjang, lalu struktur, tanpa tingkatan perantara. Menekan **MWW** langsung menampilkan seluruh provinsi,
  **MWD** seluruh kabupaten/kota (DPW dan DPD sama); induknya (provinsi) hanya keterangan kecil di bawah nama ("Kab. Barru" / "Sulawesi Selatan") dan pengurut, jadi kabupaten satu
  provinsi berdampingan. Induk dipakai hanya bila tingkat Wilayah ke bawah (`structures.tier >= 2`) dan dalam lingkup admin: DPP dan MWP adalah pucuk nasional (dulu aturannya
  `depth >= 1`, sehingga seluruh MWW tampil di bawah kelompok "MWP"). Setiap baris punya kolom yang sama: Orang, **Inti** (kotak K·S·B: Ketua, Sekretaris, Bendahara terisi di
  tingkat struktur; kode jabatannya di `PositionModel::INTI_*`; sifat struktur, tidak ikut penyaring), Data lengkap (bilah: hijau mulai 90%, kuning mulai 75%), dan L · P. Angka
  dihitung satu query (`PersonModel::overviewByStructure`) dan disusun `PeopleOverview::build`. Isi sebuah struktur **dimuat saat barisnya diketuk**
  (`GET /people/struktur/{term_id}`, potongan HTML): struktur **tanpa Bidang dan unit** (kebanyakan DPW, DPD, MWW, MWD) menampilkan **nama dan jabatan pengurusnya langsung**
  (judul kecil Pengurus Inti, lalu Pimpinan Lainnya; kolom Jabatan, Nama, Kontak, dan Masa hanya bila ada penempatan bertanggal), tanpa simpul untuk dibuka; struktur yang punya
  Bidang atau unit (DPP, MWP, dan yang berunit seperti MWW Sulawesi Tenggara) tetap berupa pohon Susunan (Pengurus Inti, Bidang, unit; kerangka dan penyajiannya dipakai bersama
  halaman detail periode: `Susunan::arrange` dan `views/admin/_susunan.php`). Di halaman ini lencana "rangkap" per baris tidak ditampilkan: bendera "utama" yang menjadi dasarnya
  kosong di hampir semua penempatan (100% DPD, MWD, DSO, DSY, DPK), jadi hampir setiap baris akan salah disebut rangkap; rangkap yang sebenarnya dihitung kartu "Rangkap".
  Jenjang satu-struktur (DPP, MWP, DSO, DSY, DPK) tidak terbuka otomatis karena isinya berat, begitu juga jenjang dengan lebih dari 60 struktur (DPD dan MWD; ringkasannya sudah ada
  di baris jenjangnya): daftar itu terbuka bila jenjangnya dipilih atau diketuk. Tombol "Buka semua jenjang" hanya membuka jenjang, bukan ratusan baris struktur.
  Per Struktur memuat SEMUA struktur dalam lingkup, termasuk yang belum punya pengurus atau periode (angka nol, K·S·B kosong; yang belum punya periode berjalan barisnya statis dengan
  tautan Buat periode): struktur yang baru dibuat (mis. DPLN Arab Saudi) langsung tampil, dan yang kosong justru yang perlu dipantau (`PersonModel::overviewByStructure(..., $includeEmpty)`).
  Tombol "Sembunyikan struktur tanpa pengurus" (`?kosong=0`) mengembalikan hanya yang berpenghuni; dengan pencarian atau penyaring orang struktur kosong tak disertakan. Chip **Jenjang**
  berasal dari struktur yang ADA (`StructureModel::levelCountsInScope`) ditambah yang punya orang, bukan hanya yang berpenghuni, jadi jenjang baru langsung tampil (redup bila belum ada orangnya).
  Pencarian dan penyaring ikut ke isi struktur (`PersonModel::idsInScope`), jadi isinya cocok dengan angka barisnya.
- **Daftar** (bawaan bila ada pencarian, atau tautan dari luar seperti dasbor dan "Tanpa SKUO"): tabel A-Z dengan label jenjang, kolom Kontak (WA dan NIK), Kelengkapan
  (menyebut apa yang kurang; tooltip pada badge dan judul kolom, serta satu baris keterangan di bawah tabel, menyatakan bahwa Lengkap = nomor WA dan jenis kelamin terisi dan NIK belum
  dihitung, jadi baris yang NIK-nya "—" tetap Lengkap), dan aksi berupa ikon.

Lingkup admin berlaku di semuanya; nama struktur induk di luar lingkup tidak pernah dibuka. `?susun=bidang` (tampilan lama "Per Bidang") dialihkan ke Per Struktur.

**Struktur Organisasi** (`/structures`) punya saringan supaya yang dicari mudah ditemukan (dulu hanya pohon dengan Buka/Tutup semua, dan seluruh ~1.100 simpul dimuat sekaligus, sekitar 2 MB):
baris **Jenjang** (chip per jenjang yang ADA, dikelompokkan Umum / Muslimah / Dewan, dengan jumlah struktur), kotak cari (nama, kode wilayah, atau nama induk; `?q=`), dan chip status
(`?f=`: Punya periode berjalan, **Periode belum ditandai berjalan** bila ada, Belum punya periode, Punya admin untuk yang boleh mengelola akun, dan Nonaktif bila ada). Angka tiap chip mengikuti penyaring lain, jadi sama dengan
baris yang tampil bila diketuk; chip berangka nol redup. Dua tampilan (`?tampil=`): **Daftar** (bawaan; tabel berhalaman 25/50/100, terurut jenjang, induk, nama; kolom Struktur, Jenjang,
Kode wilayah, Periode berjalan, Admin, dan aksi ikon) dan **Pohon** (hierarki yang bisa dibuka; dengan saringan hanya yang cocok beserta induk-induknya, yang dibuka dan tampil redup tanpa
isi). Seluruhnya disaring di PHP dari satu daftar (`StructuresController::index`), tanpa perubahan database, dan hanya struktur dalam lingkup admin yang ikut (induk di luar lingkup tak
pernah tampil). Hapus kembali ke daftar yang sama lewat `back` (hanya karakter query string biasa yang diterima). Diuji di `tests/structures_page_test.php`.

**Periode yang sudah dibuat tetapi belum ditandai berjalan.** Kolom "Periode berjalan" di Struktur dan baris struktur di Data Pengurus hanya melihat periode bertanda `is_current`.
Periode yang dibuat tanpa mencentang "Tandai sebagai periode yang sedang berjalan" (status Draf) dulu tampil "belum ada" + Buat periode, padahal membuat periode itu lagi gagal
("sudah menggunakan Periode Kepengurusan tersebut"; kasus DPLN baru). Kini tiga keadaan dibedakan (`StructureModel::pendingTermsInScope`, `Ui::termGap`): **berjalan** (nama periode),
**ada tetapi belum ditandai** (tertulis "Periode 2026-2031 belum ditandai berjalan" + tombol **Tandai berjalan**, satu klik, `TermsController::mark_current`: tanda dilepas dari periode
lain di struktur itu, Draf menjadi Aktif, kembali ke halaman asal yang dikenal saja), dan **belum ada** ("Belum punya periode berjalan" + Buat periode; juga bila hanya ada periode yang
sudah selesai). Pencegahan di sumbernya: formulir periode baru mencentang "berjalan" bila strukturnya belum punya periode berjalan; menandai berjalan pada Draf menjadikannya Aktif;
Buat periode untuk acuan yang sudah dipakai struktur itu mengarahkan ke periode yang ada (bukan galat buntu); dan menyimpan periode yang membuat struktur tanpa periode berjalan memberi
peringatan. **Profil orang** (`/people/detail/ID`): penempatan yang belum berakhir di periode seperti itu tak terhitung "Jabatan Saat Ini" (hanya periode berjalan yang dihitung), padahal tercatat
di Riwayat; kartunya dulu tampak kosong ("Tidak sedang menjabat di periode berjalan"). Kini kartu menjelaskannya ("Belum terhitung menjabat", struktur, periode, dan tombol Tandai berjalan yang
kembali ke profil itu), dan Riwayat memberi lencana "belum ditandai berjalan". Hanya periode milik struktur yang BELUM punya periode berjalan yang dihitung menunggu; periode berikutnya milik
struktur yang sudah punya periode berjalan bukan masalah. Diuji di `tests/term_current_test.php`.

**Periode Kepengurusan** (`/terms`) memakai pola saringan yang sama (dulu seluruh ~520 periode tampil sekaligus tanpa satu pun saringan): baris **Jenjang** (chip), baris **Acuan**
(chip acuan periode, hanya bila ada lebih dari satu, mis. 2021-2026 dan 2026-2031; `?pr=`), kotak cari (`?q=`; setiap kata harus cocok di jenjang, nama, induk, acuan, nama periode, atau
nomor SK, jadi "kab maros" menemukan "Kab. Maros"), dan chip status (`?f=`: Berjalan, Arsip, Belum ada pengurus, Ada SK, Tanpa SK). Angka tiap chip mengikuti semua saringan lain.
Tabel berhalaman 25/50/100, terurut jenjang, induk, nama, periode terbaru; nama struktur ditulis sekali per struktur. `?structure=ID` (tautan dari Struktur dan dasbor) tetap berlaku dan
ikut terbawa di setiap tautan. Nama induk di luar lingkup admin tak tampil dan tak bisa dicari. Seluruhnya disaring di PHP dari `TermModel::allInScope`, tanpa perubahan database.
Diuji di `tests/terms_page_test.php`.

**Pemilih struktur (Tambah Jabatan).** Dropdown "Struktur dan Periode" di modal Tambah Jabatan (profil orang) dan di formulir penempatan dulu memuat ratusan struktur sekaligus (modal:
semua periode sayap orangnya, ±300 baris dimuat di halaman profil). Kini diganti pemilih (`app/Views/admin/_struktur_picker.php`, `app.js`): chip **jenjang** (dengan jumlah), kotak cari
(kata-kata terpisah, termasuk nama induk), dan paling banyak 8 baris (`TermModel::PICKER_LIMIT`) berisi jenjang, nama, "di bawah {induk}", dan periode (yang bukan berjalan bertanda
"periode lain"). Daftarnya dimuat dari `placements/structures?q=&lv=&wing=&gp=` (JSON; hanya struktur dalam lingkup admin, nama induk di luar lingkup tak bocor lewat pencarian);
halaman profil tidak lagi memuat daftar struktur sama sekali. Sayap menurut orangnya (laki-laki: Umum, perempuan: Muslimah); di formulir penempatan acuan periode dibatasi pada acuan
periode yang sedang dibuka dan sayap berubah mengikuti orang yang dipilih (pilihan yang tak cocok lagi dibuang). Yang dipilih tampil sebagai kartu kecil dengan tombol **Ganti**; nilainya
(id periode) ada di `<input type="hidden">` yang memicu `change`, jadi pemuatan unit dan jabatan tak berubah. Enter di kotak cari memilih hasil pertama. Diuji di
`tests/structure_picker_test.php` (pencarian, lingkup, titik JSON, pemasangan di profil dan formulir).

**Pengelompokan Unit** (`/units/grouping`, menu samping "Pengelompokan Unit"; tombolnya juga ada di Katalog Unit dan di halaman Bidang & Unit tiap periode). Halaman tersendiri
untuk menyusun DAN mengubah pengelompokan unit langsung di tempat, menurut STRUKTUR: tiap struktur dalam lingkup yang punya periode berjalan menjadi satu simpul (sayap umum dulu lalu
Muslimah, dari Pusat ke Ranting), berisi Bidang/kelompok-nya (Bidang, Subbidang, lalu kelompok lain seperti Pengurus Harian, Biro-Biro, Pusat-Pusat) dan di tiap Bidang unit-unitnya
(Departemen dulu, lalu Lembaga, lalu jenis lain), serta "Unit tanpa Bidang". Ringkasan di baris struktur mis. "9 Bidang · 52 unit" atau "29 unit · belum ada Bidang".
Daftarnya DISARING karena ada 500+ struktur dan hampir semuanya masih kosong: chip **Jenjang** (DPP, DPW, MWP, ...), chip status **Sudah disusun** (bawaan; punya Bidang atau unit di periode
berjalan) / **Belum disusun** / **Semua**, dan kolom cari nama (`?lv=`, `?st=`, `?q=`; `UnitsController::grouping`). Paling banyak 60 baris tampil (baris `?open=` selalu ikut), dan isi rincinya
(Bidang, unit, calon Ketua Bidang) hanya dimuat untuk baris yang tampil. Tanpa `?st=`, yang dipakai "Sudah disusun", kecuali belum ada yang disusun (mis. admin satu DPD baru): saat itu "Semua".
Saringan ikut terbawa lewat `f_lv`/`f_st`/`f_q` di tiap formulir, jadi sesudah menyimpan pengguna kembali ke daftar yang sama.
Alurnya: (1) di sebuah struktur, **Tambah unit** (tingkat struktur): satu isian nama dengan saran dari katalog SAYAP struktur itu, lalu **Masuk ke**: *Otomatis menurut jenis unit* (Biro, Badan,
Pusat masuk ke kelompok "Biro-Biro" / "Badan-Badan" / "Pusat-Pusat", dibuat bila belum ada: `UnitModel::groupForKind`; jenis lain masuk tanpa Bidang), *Tanpa Bidang*, atau salah satu kelompok.
Nama yang sudah ada di katalog dipakai ulang (jenisnya tidak berubah); yang belum ada dibuat di katalog sayap itu dan dicatat di jejak audit (`UnitModel::catalogEnsure`), dan baru untuk nama
semacam itu **Jenis unit** ditanyakan (dengan JavaScript: jenisnya ditebak dari kata pertama nama, dan tombolnya menjadi "Buat baru & tambah"). Penolakan (sayap tak cocok, unit sudah ada di
periode itu) diputuskan SEBELUM kelompok otomatis dibuat, supaya tak meninggalkan kelompok kosong; pesan sesudah menyimpan menyebut kelompok tempat unit itu mendarat. (2) **Tambah Bidang atau
kelompok** hanya untuk Bidang, Subbidang, atau kelompok baru (nama + **Jenis kelompok**); di dalam sebuah Bidang tetap ada **Tambah unit** yang langsung ke Bidang itu. Dari Katalog Unit,
unit yang belum terpasang punya tombol **Pasang** (dan sesudah menambah unit baru, halaman katalog menampilkan kotak "Pasang ... ke struktur"): memilih struktur lalu masuk otomatis ke kelompok
menurut jenisnya lewat `units/unit_save` yang sama (`masuk=auto`). Sayap katalog wajib dipilih saat menambah unit ke katalog (tidak ada bawaan Umum). (3) tiap Bidang punya **Ubah Bidang/Ubah kelompok** (nama, jenis, urutan, Hapus bila kosong), tiap unit punya **Ubah** (nama tampil,
urutan, Hapus bila belum berisi pengurus). Panel itu `<details class="sn-edit">` tanpa JavaScript; Buka semua / Tutup semua tidak membukanya. Unit yang sudah ada dipindah antar Bidang dengan
**pemilih Bidang** di barisnya, lalu **Simpan pengelompokan** menyimpan seluruh perubahan satu struktur sekaligus (`units/grouping_save`, `UnitModel::regroup`; pemilih terhubung ke formulir lewat
atribut `form`): hanya unit yang Bidangnya berubah yang ditulis dan dicatat, Bidang milik periode lain menggagalkan seluruh simpanan, dan id unit dari periode lain diabaikan. Pemilih baru
muncul setelah struktur itu punya Bidang. Struktur yang belum punya periode berjalan (mis. semua DPD saat ini) dikumpulkan di simpul "Belum punya periode berjalan" dengan tombol Buat periode,
karena Bidang dan unit terikat periode. Aksi simpan/hapus (`group_save`, `group_delete`, `unit_save`, `unit_delete`) dipakai bersama halaman Bidang & Unit lama; halaman ini menandai asalnya
dengan `return=grouping` (hanya nilai persis itu yang dikenali) supaya pengguna kembali ke strukturnya di sini, terbuka. Aksi-aksi itu kini memastikan Bidang/unit yang diubah benar-benar milik
periode yang lingkupnya diperiksa (dulu cukup mengirim id), memotong nama dan urutan ke batas kolom, dan menghidupkan kembali baris yang pernah dihapus bila nama/unit yang sama dibuat lagi
(kunci uniknya menghitung baris yang sudah dihapus). Admin berlingkup hanya melihat dan mengubah struktur dalam lingkupnya; peran viewer melihat tanpa tombol dan pemilih.

**Nomor pengganti.** Akun yang dibuat tanpa nomor WA sungguhan (mis. lewat `provision_admins.php`) memakai nomor pengganti. Admin bawaan dibuat sebelum ada orang yang ditunjuk, jadi belum ada nomor
sungguhan. Dipakai blok yang tak mungkin dimiliki siapa pun: nomor nasional Indonesia tak pernah diawali 0, sehingga `62` + `000` + id struktur bukan nomor WA
yang sah. Diketik di kolom Nomor WhatsApp sebagai `0000` + id struktur 6 digit (struktur #39 -> `0000000039`). Begitu adminnya ditunjuk, ganti nomornya lewat
**Akun > Ubah** (halaman Struktur menandainya "nomor pengganti").

**Super Admin** (lingkup seluruh sistem, tanpa batas) masuk dengan `superadmin@pengurus.wahdah.or.id` atau nomor WA akunnya. Kata sandinya tidak
dicatat di berkas mana pun; hanya hash-nya yang ada di basis data. Sandi awalnya ditetapkan pemilik sistem dan bertanda **sementara**, jadi wajib diganti
saat masuk pertama (sandi baru tak boleh memuat "superadmin"). Diputar atau dipulihkan lewat
`php database/reset_password.php --db=db_pengurus --phone=08123456789` (acak bila `--password` tak diisi, dicetak sekali).

Diuji (`16 struktur lima tingkat + sayap Muslimah, 117 pemeriksaan`): setiap admin terhadap setiap struktur, baca dan tulis, delegasi naik/turun/menyamping, dan
alat penyedia akun (dry-run, idempoten, tak menimpa berkas). Uji itu terbukti sensitif: dengan `AdminScope::sqlFilter` sengaja dirusak, 19 pemeriksaan gagal.

## Lingkup akses admin

Lingkup efektif sebuah peran = struktur itu **beserta seluruh keturunannya**, dihitung dari
`structures.path` (materialized path `/1/2/3/`) sebagai satu index range scan. `parent_id` tetap
sumber kebenaran; `path` dipelihara `StructureModel`, jadi semua penulisan `parent_id` harus lewat
model itu.

Sayap Muslimah adalah **cabang paralel** di bawah akar yang sama (`MWP.parent_id = DPP`), bukan pohon
kedua. Konsekuensinya disengaja:

- Lingkup akar (DPP) = "Nasional", otomatis mencakup sayap Muslimah.
- Lingkup DPW **tidak pernah** menyeberang ke MWW yang mewakili wilayah sama — keduanya node berbeda
  di cabang berbeda.
- `region_code` + `tier` + `wing` dipakai untuk *mengaitkan* pasangan wilayah itu demi laporan, tanpa
  menyatukannya.

Satu akun boleh punya beberapa baris `account_roles`, masing-masing dengan lingkupnya sendiri;
lingkup efektifnya adalah gabungan seluruh subtree tersebut.

## Perlakuan NIK (data pribadi sensitif)

NIK adalah Nomor Induk Kependudukan 16 digit. **UUID tetap satu-satunya kunci publik**; NIK adalah
jalur lookup tambahan. Alasannya: NIK bisa belum terbit saat pendaftaran, dan bisa dikoreksi kalau
salah ketik — keduanya akan memutus tautan salinan lokal konsumen kalau NIK dipakai sebagai kunci.

Pengamanannya:

| Lapis | Perlakuan |
|---|---|
| Response API daftar/sinkronisasi | NIK **tidak pernah** ikut. Daftar field ditulis eksplisit di `SyncModel`, tidak ada `SELECT *` yang mengalir ke response. |
| Endpoint lookup | `GET /api/v1/people/by-nik/{nik}` butuh scope `people:nik` tersendiri, terpisah dari `people:read`. |
| Bentuk lookup | Hanya NIK utuh 16 digit. Tidak ada pencarian sebagian, jadi tidak bisa dipakai menyapu daftar nomor. |
| Response lookup | Tidak memuat ulang NIK-nya — pemanggil sudah tahu nomor yang ia kirim. |
| Jejak audit | Setiap pemanggilan tercatat beserta nama klien, **termasuk yang tidak menemukan apa pun**. Nomornya disimpan bertopeng (`3174********0001`). |
| Log API | NIK di path diredaksi menjadi `[nik-diredaksi]`. |
| Antarmuka admin | Ditampilkan bertopeng di daftar maupun detail. |

NIK disimpan apa adanya (bukan terenkripsi) supaya tetap bisa di-index unik dan ditampilkan ke
verifikator; pengamanannya bertumpu pada kontrol akses di atas. Alternatif yang lebih ketat adalah
*blind index* (HMAC untuk lookup + kolom terenkripsi untuk tampilan), yang menukar risiko kebocoran
dengan risiko kehilangan kunci — belum diambil karena aplikasi ini dirawat satu orang.

## Kontrak API

Baca-saja, **hanya menerima GET**. Tidak ada endpoint tulis, jadi aplikasi konsumen tidak bisa
mengubah data master dari jauh. Autentikasi: `Authorization: Bearer pgr_<prefix>_<secret>`.

### Endpoint

| Endpoint | Izin | Keterangan |
|---|---|---|
| `GET /api/v1/whoami` | — | Memastikan token & izinnya tanpa menarik data |
| `GET /api/v1/structures` | `structures:read` | Seluruh struktur, terurut mengikuti pohon |
| `GET /api/v1/structures/{uuid}` | `structures:read` | Satu struktur |
| `GET /api/v1/skuos` | `structures:read` | Katalog SKUO terurut, dikelompokkan, dengan ID dan UUID |
| `GET /api/v1/terms?structure={uuid}` | `structures:read` | Periode, bisa disaring per struktur |
| `GET /api/v1/people` | `people:read` | Berhalaman dengan kursor |
| `GET /api/v1/people/{uuid}` | `people:read` | Satu orang |
| `GET /api/v1/people/by-nik/{nik}` | `people:nik` | Lookup by NIK (lihat bagian NIK di atas) |
| `GET /api/v1/placements` | `placements:read` | Berhalaman; saring `structure`, `term`, `person`, `current=1`, `core=1` |
| `GET /api/v1/sync/full` | ketiganya | Tarikan awal |
| `GET /api/v1/sync/changes?since=` | ketiganya | Tarikan inkremental, **termasuk penghapusan** |

Parameter umum: `since` (ISO-8601 atau epoch detik), `cursor`, `limit` (bawaan 200, maksimum 1000),
`include_deleted=1`.

**Panduan lengkap untuk pengembang aplikasi konsumen ada di [`docs/API.md`](docs/API.md)** — skema
field tiap entitas, protokol sinkronisasi langkah demi langkah, daftar jebakan, dan contoh klien
PHP/Node/Python yang siap pakai. Bagian di bawah ini hanya ringkasan untuk perawat aplikasi ini.

### Bentuk response

```json
{
  "data": [ { "uuid": "...", "...": "..." } ],
  "meta": { "count": 2, "next_cursor": "1792474620000:1", "server_time": "2026-10-20T13:37:04+08:00" }
}
```

Galat: `{"error": {"code": "insufficient_scope", "message": "..."}}` dengan status 400/401/403/404/405/429.

### Sinkronisasi inkremental

Kursor berbentuk `<epoch_ms>:<id>` — gabungan `(updated_at, id)`, bukan waktu tunggal, dengan presisi
milidetik (`updated_at` bertipe `DATETIME(3)`). Beberapa baris
bisa punya `updated_at` identik (impor massal menulis ratusan baris dalam milidetik yang sama), dan
kursor berbasis waktu tunggal akan melewatkan atau mengulang baris di batas halaman.

Alur yang disarankan untuk konsumen:

1. `GET /sync/full` → kuras `placements_next_cursor` sampai `has_more: false`, simpan `meta.sync_token`.
2. Berkala: `GET /sync/changes?since=<sync_token>` → kuras `cursor_people` dan `cursor_placements`
   sampai `has_more: false`, terapkan perubahan, simpan `sync_token` baru.
3. Baris dengan `"deleted": true` dihapus dari salinan lokal. Inilah sebabnya tabel di sini memakai
   soft delete: `WHERE updated_at > ?` saja tidak pernah bisa mengabarkan penghapusan.
4. Semua baris dikunci `uuid`. Jangan pernah menyimpan id internal aplikasi ini.

Dua hal yang membuat alur ini tidak pernah kehilangan baris, dan karenanya tidak boleh diubah
sembarangan:

- **`sync_token` diambil sebelum query pertama jalan**, bukan sesudah yang terakhir. Baris yang
  ditulis selagi response disusun punya `updated_at` lebih besar dari token, jadi ikut terbawa di
  panggilan berikutnya. Kalau token diambil belakangan, baris itu lolos dari respons ini (query-nya
  sudah lewat) sekaligus dari panggilan berikutnya (`updated_at`-nya lebih kecil dari token).
- **`sync_token` hanya dikirim bila `has_more: false`.** Selama masih ada `*_next_cursor`, jendela
  perubahan belum selesai terkirim; memberi token baru di tengah jalan membuat konsumen yang
  mengabaikan kursor memajukan penanda waktunya melewati baris yang belum pernah ia terima. Dengan
  token ditahan, jalur yang salah itu tidak bisa ditempuh sama sekali.

Karena `since` inklusif (`updated_at >= since`), konsumen bisa menerima baris yang sama dua kali —
penerapan di sisi konsumen harus idempoten (*upsert* by `uuid`).

`placements` sudah menyertakan `is_core` yang dihitung di sisi server, sehingga konsumen tidak perlu
menyalin aturan Pengurus Inti. Sejak migrasi 007 aturan itu bergantung pada tier struktur: Wakil Ketua `is_core: true` di DPW ke bawah (dan sayap Muslimah-nya),
`false` di DPP/MWP; filter `core=1` mengikuti definisi yang sama.

### Contoh

```bash
TOKEN='pgr_xxxxxxxxxxxx_xxxx...'

curl -H "Authorization: Bearer $TOKEN" https://diwan.wahdah.or.id/api/v1/whoami
curl -H "Authorization: Bearer $TOKEN" 'https://diwan.wahdah.or.id/api/v1/placements?current=1&core=1'
curl -H "Authorization: Bearer $TOKEN" 'https://diwan.wahdah.or.id/api/v1/sync/changes?since=2026-10-01T00:00:00+08:00'
```

Rate limit per klien, bawaan 60/menit. Response membawa `X-RateLimit-Limit` dan
`X-RateLimit-Remaining`; saat terlampaui, status 429 dengan `Retry-After: 60`.

## Alur pendaftaran

`Diajukan → Diverifikasi → Aktif`, atau `Ditolak`.

Pengajuan disimpan di `registration_requests` dan **tidak pernah langsung menulis** ke `people`.
Data master tetap bersih (API tidak pernah menyajikan orang yang belum diverifikasi), dan setiap
pengajuan punya pemeriksa/waktu/alasannya sendiri. Nomor WA dinormalisasi dan menjadi kunci anti-duplikat:
nomor yang sudah dimiliki orang di `people` atau pengajuan aktif lain ditolak saat dikirim, sehingga
tidak membuat antrean verifikasi baru. Untuk memperbarui penempatan/data orang yang sudah terdaftar,
hubungi admin struktur.

Pencocokan calon di pengajuan lama yang masih ditinjau memakai nomor WA, NIK, dan nama (termasuk nama
setelah gelar dibuang). Sistem hanya mengusulkan orang yang cocok; bila ada beberapa orang dengan
nomor WA yang sama, verifikator tetap memilih secara manual. Saat aktivasi, nomor WA diperiksa lagi
agar perubahan yang terjadi selama peninjauan tidak membuat orang duplikat.

Status **Aktif** di aplikasi ini adalah **syarat, bukan jaminan** mendapat akun di syuro atau
aplikasi lain. Aktivasi tidak membuat baris `accounts` dan tidak mengirim apa pun ke syuro.

## Penjaga integritas di tingkat database

Beberapa aturan sengaja ditegakkan database, bukan disiplin kode:

- `uq_terms_one_current` — maksimal satu periode berjalan per struktur, lewat generated column
  (MySQL tidak punya partial unique index).
- `uq_placements_live` — mencegah penempatan ganda. Dibutuhkan karena `UNIQUE` biasa tidak menangkap
  duplikat saat `structure_unit_id` NULL (NULL ≠ NULL di MySQL) — padahal justru di situ letak
  jabatan pucuk pimpinan. Penjaganya bernilai NULL saat `deleted_at` terisi, sehingga baris yang
  sudah dihapus boleh dibuat ulang.
- `fk_placements_term (structure_id, term_id)` — menjamin `structure_id` yang didenormalisasi
  benar-benar milik pemilik periodenya.
- `fk_placements_unit (term_id, structure_unit_id)` — menjamin unit yang dirujuk berasal dari periode
  yang sama. Tidak diperiksa saat unit NULL (semantik MATCH SIMPLE), jadi penempatan tingkat
  struktur tetap sah.
- `fk_unit_groups_term`, `fk_structure_units_group` — Bidang dan unit tidak bisa menyeberang periode.
- `uq_people_nik_live` — NIK unik di antara baris hidup, tetap mengizinkan banyak baris tanpa NIK.

## Catatan teknis yang mudah terlewat

- **Jangan memakai placeholder bernama yang sama dua kali dalam satu query.** `Database.php` memakai
  `ATTR_EMULATE_PREPARES=false`, dan MySQL menolaknya (SQLSTATE HY093). Kesalahan ini baru muncul di jalur
  yang jarang dilewati (pencarian berkata kunci, paginasi kursor), jadi dijaga dengan
  `php tests/scan_placeholders.php` -- jalankan sebelum deploy. Pakai nama berbeda, mis. `:kw_a`, `:kw_b`.
- **Pembuatan akun + peran satu transaksi** (`AccountModel::saveWithRoles`), dengan pemeriksaan hak
  *sebelum* menulis. Sebelumnya penolakan eskalasi hak meninggalkan akun yatim tanpa peran.
- **Keluar = `session_regenerate_id(true)`**, bukan `session_destroy()` + hapus cookie: cara kedua membuat sesi
  pengganti (pembawa pesan "Anda sudah keluar") memakai ID yang cookie-nya baru dihapus, sehingga pesannya
  tidak pernah sampai ke peramban.
- **Mengganti `parent_id` struktur harus lewat `StructureModel`**, karena di situlah `path` ikut diperbarui.
- **Controller tanpa aksi `index` dijawab 404**, bukan galat. `/units` atau `/placements` (yang hanya punya aksi
  bernama, mis. `units/catalog`) dulu melempar TypeError dari `call_user_func_array`: 500 di produksi, dan di server
  ber-`display_errors` menampilkan stack trace beserta path file. Pemeriksaannya di `App::__construct`.

## Migrasi dari syuro

```bash
# 1. Basis data tujuan HARUS baru dipasang (skrip menolak bila sudah berisi orang/penempatan)
php database/install.php --db=db_pengurus --fresh --admin-phone=08xxxxxxxxxx --admin-password='...'

# 2. Coba dulu: seluruh perubahan di-rollback, hanya laporan yang dicetak
php database/migrate_syuro.php --db=db_pengurus

# 3. Setelah laporannya disetujui
php database/migrate_syuro.php --db=db_pengurus --commit
```

Satu transaksi: gagal di tengah berarti tidak ada yang tersimpan. `--source=db_syuro` bawaan; `--host/--user/--pass` bila
tidak di localhost. Jalan kedua di basis data yang sama ditolak.

| syuro | pengurus | Catatan |
|---|---|---|
| `organizations` (38) | `structures` | disatukan dengan akar DPP + MWP bawaan installer; path/depth dihitung; DPW & MWW Sultra diberi `region_code = SULTRA` |
| `master_departments` (105) | `unit_catalog` | jenis unit dari kata pertama nama (Departemen/Lembaga/Biro/Badan/Pusat/Divisi); kepala bawaan Pusat=Kepala, Divisi=Koordinator, lainnya=Ketua |
| `organization_units` (134) + teks `bidang` | `structure_units` + `unit_groups` | dibuat di periode berjalan struktur itu, plus periode lama mana pun yang dirujuk penempatan. "Bidang X" = bidang, "Subbidang X" = subbidang, "Biro-Biro"/"Pusat-Pusat"/"Badan-Badan" = grup |
| `users` (1.046 ber-penempatan) | `people` | nama dipisah gelar depan / nama / gelar belakang (`Text::splitName`); telepon E.164; email palsu `*.local` dibuang; `home_structure_id` = struktur penempatan utama |
| `user_placements` (1.157) | `placements` | `source = migrasi_syuro`, `is_primary` dibawa |
| `users.masa_amanah_*` | `terms` | lihat aturan periode |

**Aturan periode** (syuro menyimpan masa amanah di baris pengguna, bukan milik struktur):

- **DPW dan MWW: selalu 2021-2026** (1 Jan 2021 - 31 Des 2026), keputusan pengelola data. Syuro hanya menyimpan tanggal itu untuk
  satu pengurus DPW; 250 penempatan DPW/MWW lainnya kosong. Konstantanya `FIXED_TERMS` di atas skrip.
- Tingkat lain: tanggal dari pengurusnya. Per struktur, periode bertanggal mulai terbaru yang sudah lewat = **berjalan**; yang lebih
  tua = **arsip**, dipotong sehari sebelum periode berikutnya bila tumpang tindih; yang belum mulai = draft.
- Penempatan **tanpa tanggal di tingkat selain DPW/MWW** (20 di data saat ini: 19 DPP, 1 MWP) ditempatkan di periode yang paling banyak
  dipakai struktur itu (2026-2031) dan dicatat di `import_review_items` untuk dikonfirmasi.
- Satu pengurus DPP yang tercatat 2021-2026 menghasilkan periode arsip DPP 2021-2026 (dipotong 2026-09-11, sehari sebelum DPP
  2026-2031 mulai) berisi satu penempatan; ikut masuk daftar tinjau, sebab tampak seperti data uji.

**Yang sengaja tidak dibawa:**

- **Akun dan peran syuro.** `Pimpinan`/`Sekretaris` di syuro berlingkup akar (nasional); memetakannya otomatis ke `admin_struktur`
  berarti memberi hak admin nasional lewat migrasi tanpa keputusan siapa pun. Orangnya tetap ada di daftar Orang; beri peran lewat menu
  Akun. Laporan migrasi mencetak daftar pemegang peran syuro.
- **NIK** (syuro tidak menyimpannya) sehingga belum ada orang hasil migrasi yang bisa masuk Portal Pengurus sampai NIK-nya diisi.
- Pengguna tanpa penempatan (Super Admin syuro).
- Jejak audit per baris; penelusurannya lewat `placements.source` dan satu baris `import_batches`.

Diverifikasi dengan pembanding terpisah (tidak memakai kode migrasi): tiap penempatan syuro punya padanan tepat satu di tujuan
(nama, jabatan, struktur, unit, `is_primary` identik sebagai multiset), periode, atribut orang, Bidang tiap unit, dan hasil tarikan API
penuh sama dengan isi basis data.

## Impor biodata pengurus (formulir PDF)

Formulir **"Biodata / Profil Singkat Pengurus"** (11 isian + foto pas) diimpor dalam dua langkah. Formulirnya tidak memuat NIK
maupun jenis kelamin, dan jabatan/bidangnya ditulis bebas ("Anggota LPYP", "Humas Dpp wahdah"), jadi seluruh aturan di bawah dibuat
sehati-hati mungkin: **lebih baik tidak tahu daripada salah**.

```bash
# 1. PDF -> biodata.json + foto  (butuh `pdftotext` / poppler di PATH)
python database/tools/biodata_pdf_to_json.py <folder-pdf> <folder-keluaran>

# 2. Coba dulu (di-rollback, hanya laporan), lalu simpan
php database/import_biodata.php --db=db_pengurus --json=<folder-keluaran>/biodata.json [--verbose]
php database/import_biodata.php --db=db_pengurus --json=<folder-keluaran>/biodata.json --commit
```

Opsi: `--verbose` (cetak kecocokan lemah & isi butir tinjau), `--no-photos`, `--gender=<berkas.json>` ({"Nama persis di formulir": "L"|"P"},
jawaban pasti pengelola untuk jenis kelamin yang tak bisa diduga). **Idempoten**: dijalankan ulang tanpa perubahan data, hasilnya sama
(tak ada orang/pendaftaran/butir tinjau ganda). Migrasi 005 diterapkan otomatis di depan.

**Yang diimpor** (Identitas Diri): tanggal & tempat lahir, domisili (`city`; alamat jalan masuk `address_text`), nomor WA, **pekerjaan**
(`people.occupation`), **nomor HP keluarga** (`people.family_phone`), dan **foto pas**. Pengalaman organisasi, kompetensi, dan karya
belum punya tempat di skema dan tidak diimpor. Satu orang bisa punya banyak formulir (di data nyata ada yang 26 kali); yang dipakai
versi terbaru (tanggal tanda tangan, lalu tanggal berkas), dan dua formulir senama yang nomor WA *dan* tanggal lahirnya berbeda
dianggap dua orang dan ditahan.

**Mencocokkan formulir ke orang yang sudah ada** (berlapis, nama saja baru cukup bila kandidatnya tunggal dan gelarnya tak bertentangan):

| Tingkat | Syarat |
|---|---|
| nama+gelar | nama asli sama dan gelar belakang sama (urutan & titik diabaikan: "SPd MPd" = "S.Pd., M.Pd.") |
| nama | nama asli sama, salah satu sisi tak mencantumkan gelar, kandidat tunggal, nama >= 2 kata |
| nama+unit | nama sama tetapi gelar berbeda/ambigu, dan unit di formulir ada di penempatan orang itu |
| mirip | selisih ejaan (kemiripan >= 90%, unggul jelas dari yang kedua) **dan** gelar kompatibel + tercantum, atau unit cocok, atau nomor WA sama |

*Gelar kompatibel* = kosong di salah satu sisi atau yang satu himpunan bagian dari yang lain ("S.Pd." dan "S.Pd., M.Pd." orang yang sama;
"S.Si." dan "S.Pd." bukan). **Nama satu kata** ("Abdullah", "Sapar") tidak pernah dicocokkan tanpa bukti kedua, dan dua formulir yang jatuh
ke orang yang sama keduanya ditahan.

**Pada orang yang cocok, biodata hanya MENGISI yang kosong** (aturan yang sama dengan aktivasi pendaftaran). Nomor WA yang
bertentangan, tanggal lahir yang tak terbaca ("5 Menit 1968"), dan semua selisih lain dicatat sebagai butir tinjau, tidak menimpa. Nama
dan gelar dari SK tidak diubah. HP keluarga yang sama dengan nomor orangnya sendiri tidak disimpan. Setiap perubahan tercatat di
`audit_log` (`event: import_biodata`, disaring dari umpan aktivitas dasbor agar ratusan baris tidak menenggelamkan aktivitas nyata).

**Jabatan dibaca lalu dibandingkan, tidak pernah diubah.** `BiodataMatcher::parseRole` memecah teks bebas menjadi pasangan (jabatan, unit)
memakai daftar singkatan/ejaan (`UNIT_PATTERNS`, mis. LP2KS, LPYP, DUWD, Humas, "Depdikdasmen"), mengabaikan badan daerah ("Ketua DPD WI
Sragen") dan tidak memasangkan jabatan pucuk (Sekretaris Jenderal) dengan unit. Hasilnya dilaporkan: sama / jabatan beda pada unit yang
sama ("Ketua" di formulir, "Anggota" di SK) / unit tak tercatat. Itu daftar kerja bagi pengelola; penempatan resmi tetap mengikuti SK.

**Yang belum dikenali tidak langsung jadi pengurus.** Orang yang tak ditemukan/ambigu dikirim sebagai **pendaftaran berstatus Diajukan**
ke DPP (periode berjalan) dengan unit & jabatan terbaca sebagai usulan, lengkap dengan calon kecocokannya, supaya verifikator yang memutuskan
lewat alur biasa. Karena formulir tidak memuat jenis kelamin, ia **diduga** (urutan bukti: orang senama di data kita, satu-satunya orang yang
namanya sangat mirip, kamus nama depan dari data kita, awalan "Hj."); dugaan ditandai `gender_dugaan`, tampil sebagai peringatan di halaman
tinjau, dan **dapat dikoreksi saat mengaktifkan**. Yang tak bisa diduga ditahan (tanpa `--gender`). Foto orang yang belum diverifikasi
tidak dipasang.

Perubahan alur pendaftaran yang menyertainya: aktivasi orang BARU kini membawa `extra_data.profil` (tempat lahir, domisili, pekerjaan,
HP keluarga) ke data orangnya; formulir aktivasi memilih unit yang diusulkan dan memuat pilihan jenis kelamin (hanya berlaku untuk orang
baru; orang yang sudah ada tidak pernah ditimpa); halaman tinjau menampilkan konteks formulir (jabatan & tanggal lahir tertulis).

**Kerahasiaan.** `family_phone` data pribadi pihak ketiga: **tidak ikut di API** (`SyncModel` memilih kolom eksplisit), tersamar di Portal Pengurus
dan di tinjauan pendaftar, dan utuh hanya bagi admin yang boleh mengubah data orang itu. `occupation` juga tidak diekspos API. Foto lewat
`Photo::process` (jenis dari isi berkas, EXIF dibuang, JPEG 512x512, nama = UUID orang). PDF diperlakukan sebagai data tidak dipercaya: hanya
dibaca (`pdftotext`, pemindaian aliran JPEG), tak pernah dieksekusi.
**Foto menyembuhkan diri:** bila `photo_path` terisi tetapi berkasnya hilang dari disk (terhapus, salah salin), menjalankan impor lagi dengan `biodata.json` + foto
sumbernya membuat ulang berkasnya lewat pipeline yang sama, tanpa menyentuh basis data. **Jangan mengosongkan `public/uploads/people/` atau `storage/sk/`
secara massal** (mis. `rm *.jpg`): isinya data sungguhan, dan berkas yang dirujuk basis data ikut hilang.

Diverifikasi dengan pembanding terpisah yang tidak memakai logika pencocok: tiap tanggal + tempat lahir di database ditemukan di teks formulir
yang namanya cocok, tak ada foto/nomor WA yang dipakai dua orang, foto semuanya JPEG 512x512 tanpa EXIF, nama/gelar/penempatan tak tersentuh.

## Yang belum dikerjakan

- **SK periode lain.** Hanya SK DPP/MWP 2026-2031 yang tercatat. SK DPW dan MWW Sultra 2021-2026 belum ada (periodenya ditetapkan pengelola saat migrasi).
- **Verifikasi nama per nama terhadap SK.** Baru pucuk pimpinan (39 nama) dan jumlah per unit; sisanya bergantung pada data sumber syuro (yang memuat satu salah eja).

- **Impor massal SK.** Skrip untuk mengimpor SK massal belum tersedia. Migrasi syuro dan impor biodata
  mengisi daftar kasus yang perlu ditinjau; panel **Tinjauan Impor** kini mencatat keputusan dan catatan
  Super Admin. Keputusan ini tidak mengubah data orang atau penempatan secara otomatis. Tombolnya hanya tampak bagi
  Super Admin karena batch impor lama tidak selalu terikat ke satu struktur.
