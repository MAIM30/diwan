# DIWAN Wahdah Islamiyah

**DIWAN** = **D**ata **I**nduk **D**ewan Pimpinan **W**ahdah Islamiyah -- *Sistem Tata Kelola Data Kepengurusan Wahdah Islamiyah*.
Berjalan di `pengurus.wahdah.or.id`.

Sumber data tunggal untuk data pengurus Wahdah Islamiyah. Menggantikan pengelolaan manual berbasis
Google Form + Apps Script, dan menjadi sumber data yang dibaca aplikasi lain (syuro, dsb.) lewat API
baca-saja.

PHP MVC tanpa framework dan tanpa Composer — sengaja konsisten dengan syuro supaya keduanya enak
dirawat bersama. Target MySQL 8.0+ (memakai generated column dan composite foreign key).

## Yang membedakannya dari skema syuro

1. **Periode adalah entitas.** Di syuro masa amanah adalah dua kolom di baris `users`, jadi mengisi
   periode baru menimpa riwayat periode lama. Di sini setiap penempatan wajib terikat ke satu
   `terms.id`, sehingga periode lama tetap utuh sebagai arsip.
2. **Orang ≠ akun.** `people` adalah subjek data, `accounts` adalah hak login. Di syuro satu kolom
   `users.role` dipaksa mewakili dua sumbu: posisi di organisasi dan hak akses teknis.
3. **Jabatan adalah katalog.** `positions` menggantikan `position_name` VARCHAR bebas. Kolom
   `positions.is_core` menggantikan seluruh `CASE WHEN` di `app/Core/PengurusInti.php` syuro:
   Pengurus Inti kini = `placements.structure_unit_id IS NULL AND positions.is_core = 1`, ditambah satu kekecualian per tingkat:
   `positions.core_from_tier` (migrasi 007) menandai jabatan yang baru inti mulai tier tertentu. Wakil Ketua bernilai 2, jadi ia inti di DPW/DPD/DPC/DPRa dan
   MWW/MWD/MWC tetapi tidak di DPP/MWP. Aturannya satu tempat: `PositionModel::CORE_SQL` (dan padanannya `PositionModel::isCoreAt`).
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
menjalankan migrasi bernomor berikutnya (`003_pisah_nama.php`, `004_portal_pengurus.php`, `005_data_diri.php`, `006_alamat_dan_sandi.php`, `007_inti_wakil_ketua.php`, **idempoten** -- aman dijalankan ulang di
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
  007_inti_wakil_ketua.php    migrasi: positions.core_from_tier (Wakil Ketua = Pengurus Inti mulai tier Wilayah)
  migrate.php                 menerapkan migrasi 003-007 pada basis data yang SUDAH terpasang (idempoten; jalankan setiap kali kode diperbarui)
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

Yang menguatkannya tanpa mengubah alurnya: **OTP WhatsApp** (nomor + kode sekali pakai). Itu butuh gateway pengirim pesan WA, yang belum
ada di proyek ini; hanya `PengurusController::login_process` yang perlu disentuh. Sampai itu ada, `PORTAL_ENABLED = false` mematikan
seluruh portal dan `people.portal_enabled` mencabut akses satu orang seketika.

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
lengkapnya di portal. Itu keputusan yang disengaja (NIK memang milik pemiliknya sendiri), tetapi ia menambah nilai nomor WA sebagai sasaran. Yang menutupnya adalah
faktor kedua (OTP WhatsApp, lihat atas); NIK di jejak audit tetap disamarkan.

Yang bisa menguatkannya tanpa mengubah alurnya: **tanggal lahir sebagai faktor kedua** (NIK + tanggal
lahir), atau **OTP WhatsApp** seperti di syuro. Keduanya hanya menyentuh `PengurusController::login_process`.

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
| **Kasar** | kelurahan/desa, kecamatan, kota/kabupaten, provinsi, tempat lahir, pekerjaan | tampil terisi; boleh diubah atau dikosongkan |
| **Sensitif (tulis-saja)** | jalan/BTN/lorong, tanggal lahir, email, HP keluarga | **tidak ditampilkan ulang** (hanya lencana "sudah terisi"); kosong = jangan ubah; terganti hanya bila diketik yang baru |
| **Tak bisa diubah** | nomor WhatsApp, NIK, nama & gelar, jenis kelamin | lewat admin |

Nomor WA dan NIK sengaja tak bisa diubah dari portal: kalau bisa, siapa pun yang tahu nomor seseorang bisa memindahkannya ke nomornya sendiri dan mengunci pemilik aslinya.
Daftar putih kolom ada di `MemberModel::SELF_FIELDS` (kiriman lain, termasuk `id`/`person_id`/`phone`/`nik`, diabaikan); validasi **semua-atau-tidak-sama-sekali**;
maks 10 perubahan per jam; setiap perubahan tercatat (`event: portal_edit`, nilai lama + baru) dan tampil di riwayat aktivitas profil admin
("Diubah sendiri oleh pengurus: ..."), tetapi disaring dari umpan dasbor. Alamat disimpan bertingkat: Jalan/BTN/Lorong (`address_text`), Kelurahan (`village`),
Kecamatan (`district`), Kota/Kabupaten (`city`), Provinsi (`province`); keempat yang terakhir dipilih bertingkat dari data wilayah resmi (bagian berikut).
**Perubahan merambat ke konsumen API**: email, tanggal lahir, kota, dan provinsi ada di kontrak API, jadi perubahan pengurus langsung terbaca syuro (kelurahan,
kecamatan, jalan, HP keluarga, pekerjaan tidak).

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
| Bilah tab bawah gelap, tab aktif pil putih | **Dipakai** (`.m-tabbar`, ≤760px) | 4 tab: Beranda, Susunan, Riwayat, Data Saya. Tab aktif memunculkan label teks; tiap tab ber-`aria-label`, tepat satu `aria-current="page"`; menghormati area aman layar berponi (`viewport-fit=cover`). Di layar lebar tetap menu atas |
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
| Pusat Muslimah | MWP | MWP + MWW + MWD + MWC |
| Wilayah | DPW Sulawesi Tenggara | DPW itu + semua DPD/DPC/DPRa di bawahnya, **bukan** MWW Sultra |
| Daerah | DPD Kota Kendari | DPD itu + DPC + DPRa di bawahnya |
| Cabang | DPC Mandonga | DPC itu + DPRa di bawahnya |
| Ranting | DPRa Ranting Satu | hanya ranting itu |

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
Admin DPW melihat DPW/DPD/DPC/DPRa, admin DPD melihat DPD/DPC/DPRa, dan seterusnya; sayap yang tak ada dalam lingkupnya (Muslimah bagi admin DPW) tidak muncul,
sedangkan admin DPP dan Super Admin melihat semuanya. Batas yang sama dipaksakan di server saat menyimpan, jadi mengirim level lain secara manual ditolak.

**Susunan periode dikelompokkan per kategori.** Halaman detail periode menampilkan "Susunan Lengkap" sebagai daftar yang membuka isinya saat diketuk (pola `<details>`
yang sama dengan halaman Struktur, lengkap dengan Buka semua / Tutup semua), dikelompokkan oleh `Susunan::arrange` (`app/Core/Susunan.php`):

| Simpul | Isinya |
|---|---|
| **Pengurus Inti** | jabatan tingkat struktur (tanpa unit) yang di katalog jabatan inti bagi struktur itu: bertanda `is_core`, atau `core_from_tier` sama/lebih kecil dari tier-nya (Wakil Ketua di DPW/DPD/DPC/DPRa dan MWW/MWD/MWC); terbuka sejak awal, dengan kolom Kontak |
| **Pimpinan Lainnya** | jabatan tingkat struktur yang bukan inti (mis. Wakil Ketua di DPP/MWP, Pengarah); hanya tampil bila ada |
| **Bidang** → tiap Bidang → unit | tiap Bidang memuat unit yang dinaunginya: **Departemen dulu, lalu Lembaga**, lalu jenis lain (Biro, Badan, Pusat, Divisi); sayap Muslimah berlabel **Subbidang** |
| kelompok lain (Pusat-Pusat, Badan-Badan, Biro-Biro) | kelompok bukan-Bidang milik periode itu, memakai namanya sendiri |
| **Unit tanpa Bidang** | unit yang belum dinaungi Bidang mana pun (mis. seluruh unit DPW/MWW Sultra sekarang) |

Setiap penempatan masuk tepat satu simpul (yang tak terpetakan jatuh ke Pimpinan Lainnya, tidak pernah hilang); Bidang dan unit yang kosong tetap tampil.
"N orang" menghitung orang yang masih menjabat (penempatan belum berakhir), satu orang dengan dua jabatan di satu simpul dihitung sekali; baris yang sudah berakhir
tetap tampil dengan lencana "berakhir". Kolom Ubah/Akhiri tetap hanya untuk yang boleh mengubah data.

**Data Pengurus: tampilan "Per Bidang".** Chip **Per Bidang** di halaman Data Pengurus (`/people?susun=bidang`) mengganti tabel A-Z dengan daftar yang sama: tiap
struktur dalam lingkup yang punya periode berjalan menjadi satu simpul (mis. DPP, MWP, DPW Sultra), berisi Pengurus Inti, Bidang beserta Departemen dan Lembaganya, dst.
(kerangka dan penyajiannya dipakai bersama halaman detail periode: `Susunan::arrange` dan `views/admin/_susunan.php`). Hanya penempatan yang masih berjalan
yang ditampilkan, dan seorang pengurus yang merangkap muncul di setiap Bidangnya. Pencarian dan penyaringan (jenis kelamin, tanpa nomor WA, dst.) memakai himpunan orang
yang SAMA dengan daftar A-Z (`PersonModel::idsInScope`); bila ada penyaringan, simpul kosong dibuang dan semua simpul dibuka. Orang yang cocok tetapi tak punya penempatan
berjalan (belum ditempatkan, atau semua jabatannya sudah berakhir) tidak hilang: mereka ada di simpul "Belum menjabat di periode berjalan". Lingkup admin berlaku seperti biasa.

**Pengelompokan Unit** (`/units/grouping`, menu samping "Pengelompokan Unit"; tombolnya juga ada di Katalog Unit dan di halaman Bidang & Unit tiap periode). Halaman tersendiri
untuk menyusun DAN mengubah pengelompokan unit langsung di tempat, menurut STRUKTUR: tiap struktur dalam lingkup yang punya periode berjalan menjadi satu simpul (sayap umum dulu lalu
Muslimah, dari Pusat ke Ranting), berisi Bidang/kelompok-nya (Bidang, Subbidang, lalu kelompok lain seperti Pengurus Harian, Biro-Biro, Pusat-Pusat) dan di tiap Bidang unit-unitnya
(Departemen dulu, lalu Lembaga, lalu jenis lain), serta "Unit tanpa Bidang". Ringkasan di baris struktur mis. "9 Bidang · 52 unit" atau "29 unit · belum ada Bidang".
Alurnya dari atas ke bawah: (1) di sebuah struktur, **Tambah Bidang atau kelompok** (nama + jenis: Bidang, Subbidang untuk sayap Muslimah, atau kelompok lain); (2) di dalam Bidang itu,
**Tambah unit**: ketik nama atau pilih dari saran katalog; nama yang sudah ada di katalog dipakai ulang (jenisnya tidak berubah), yang belum ada dibuat di katalog dengan jenis yang dipilih
dan dicatat di jejak audit (`UnitModel::catalogEnsure`); (3) tiap Bidang punya **Ubah Bidang/Ubah kelompok** (nama, jenis, urutan, Hapus bila kosong), tiap unit punya **Ubah** (nama tampil,
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

curl -H "Authorization: Bearer $TOKEN" https://pengurus.wahdah.or.id/api/v1/whoami
curl -H "Authorization: Bearer $TOKEN" 'https://pengurus.wahdah.or.id/api/v1/placements?current=1&core=1'
curl -H "Authorization: Bearer $TOKEN" 'https://pengurus.wahdah.or.id/api/v1/sync/changes?since=2026-10-01T00:00:00+08:00'
```

Rate limit per klien, bawaan 60/menit. Response membawa `X-RateLimit-Limit` dan
`X-RateLimit-Remaining`; saat terlampaui, status 429 dengan `Retry-After: 60`.

## Alur pendaftaran

`Diajukan → Diverifikasi → Aktif`, atau `Ditolak`.

Pengajuan disimpan di `registration_requests` dan **tidak pernah langsung menulis** ke `people`.
Data master tetap bersih (API tidak pernah menyajikan orang yang belum diverifikasi), setiap
pengajuan punya pemeriksa/waktu/alasannya sendiri, dan satu orang bisa mengajukan lagi di periode
berikutnya tanpa menimpa apa pun.

Pencocokan duplikat otomatis hanya **mengusulkan** — mengikuti aturan impor SK syuro yang terbukti
pada ~600 baris: exact match, normalized match (gelar dibuang), dan kasus `ambiguous` yang sengaja
tidak memilih sendiri. Keputusan "ini orang yang sama" selalu diambil verifikator.

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

- **Impor massal SK.** Tabel `import_batches` dan `import_review_items` sudah ada di skema, tapi
  antarmuka dan skrip importnya belum. Di syuro, log "perlu dicek manual" hanya dicetak ke stdout
  dan hilang begitu terminal ditutup; tabel ini ada supaya daftar itu jadi pekerjaan yang bisa
  diselesaikan.
  Migrasi syuro dan impor biodata sudah mengisi `import_review_items` (butir ber-`resolution = pending`), tetapi layar untuk
  meninjaunya belum ada; sementara ini dibaca lewat SQL.
- **Login OTP WhatsApp.** Saat ini login memakai nomor + kata sandi dengan pembatas percobaan.
  Pola OTP syuro bisa diadopsi menyusul.
- **Unggah berkas SK.** Kolom `terms.sk_file_path` sudah ada, mekanisme unggahnya belum.
