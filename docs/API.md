# Panduan Integrasi API DIWAN

Panduan ini ditujukan untuk **pengembang aplikasi konsumen** — aplikasi lain (syuro, portal wilayah,
papan informasi, dsb.) yang ingin membaca data kepengurusan Wahdah Islamiyah dari DIWAN.

Anda tidak perlu tahu apa pun tentang bagian dalam DIWAN untuk memakai panduan ini. Yang dibutuhkan
hanya satu token dan kemampuan mengirim permintaan HTTP GET.

- **Basis URL produksi:** `https://diwan.wahdah.or.id/api/v1`
- **Basis URL pengembangan:** `http://localhost/pengurus/api/v1`
- **Metode:** hanya `GET`
- **Format:** JSON (`application/json; charset=utf-8`)

> Aplikasi sedang dipindah ke hosting baru (Hostinger, domain `diwan.wahdah.or.id` via Cloudflare).
> Basis URL produksi di atas belum aktif sampai pemindahan ini selesai dan DNS diarahkan — jangan
> mengintegrasikan Syuro ke URL ini sampai ada konfirmasi bahwa deploy sudah selesai.

---

## Daftar Isi

1. [Mulai cepat](#1-mulai-cepat)
2. [Prinsip yang perlu dipahami lebih dulu](#2-prinsip-yang-perlu-dipahami-lebih-dulu)
3. [Autentikasi](#3-autentikasi)
4. [Izin (scope) dan lingkup wilayah](#4-izin-scope-dan-lingkup-wilayah)
5. [Bentuk response dan galat](#5-bentuk-response-dan-galat)
6. [Referensi endpoint](#6-referensi-endpoint)
7. [Skema field tiap entitas](#7-skema-field-tiap-entitas)
8. [Paginasi dengan kursor](#8-paginasi-dengan-kursor)
9. [Protokol sinkronisasi](#9-protokol-sinkronisasi)
10. [Batas permintaan (rate limit)](#10-batas-permintaan-rate-limit)
11. [NIK: aturan khusus](#11-nik-aturan-khusus)
12. [Jebakan yang sering terjadi](#12-jebakan-yang-sering-terjadi)
13. [Contoh klien lengkap](#13-contoh-klien-lengkap)
14. [Daftar periksa integrasi](#14-daftar-periksa-integrasi)

---

## 1. Mulai cepat

Minta token ke Super Admin DIWAN, lalu pastikan dulu token itu hidup:

```bash
TOKEN='pgr_645a22029d1b_ba691e6a...'   # nilai lengkap dari Super Admin

curl -H "Authorization: Bearer $TOKEN" \
     https://diwan.wahdah.or.id/api/v1/whoami
```

```json
{
  "data": {
    "client": "syuro",
    "scopes": ["structures:read", "people:read", "placements:read"],
    "scope_structure": null,
    "rate_limit_per_min": 60,
    "expires_at": null
  },
  "meta": { "count": 1, "server_time": "2026-10-24T00:41:19+08:00" }
}
```

Kalau ini berhasil, integrasi Anda sudah bisa jalan. Pertanyaan paling umum —
*"siapa saja Pengurus Inti yang menjabat sekarang?"* — dijawab satu permintaan:

```bash
curl -H "Authorization: Bearer $TOKEN" \
     'https://diwan.wahdah.or.id/api/v1/placements?current=1&core=1&limit=1000'
```

---

## 2. Prinsip yang perlu dipahami lebih dulu

Empat hal ini menjelaskan hampir semua keputusan desain API-nya. Membacanya lebih dulu akan
menghemat banyak waktu.

**1. API ini baca-saja, dan itu disengaja.**
Tidak ada satu pun endpoint tulis. Data kepengurusan hanya bisa berubah lewat antarmuka admin DIWAN
yang melewati verifikasi manusia. Artinya: bug separah apa pun di aplikasi Anda tidak akan pernah
bisa merusak data sumber. Jangan merancang alur yang mengandaikan Anda bisa menulis balik.

**2. Setiap objek dikunci `uuid`, bukan id angka.**
Id internal DIWAN tidak pernah keluar. Simpan `uuid` sebagai kunci di salinan lokal Anda; nilainya
dijamin tidak pernah berubah sepanjang umur baris. Ini yang membuat basis data DIWAN bisa dirapikan
atau di-reindex tanpa memutus salinan Anda.

**3. Penghapusan dikabarkan, bukan didiamkan.**
Baris yang dihapus tetap dikirim, dengan `"deleted": true`. Kalau Anda hanya menyaring
"apa yang berubah sejak X", penghapusan tidak akan pernah sampai — salinan lokal Anda akan menyimpan
pengurus yang sudah tidak ada selamanya. Lihat [bagian 9](#9-protokol-sinkronisasi).

**4. Token ini untuk server, bukan untuk browser.**
API ini sengaja **tidak** mengirim header CORS. Token tidak boleh dipasang di JavaScript sisi klien —
di sana ia tidak pernah bisa dirahasiakan. Panggil dari backend Anda, simpan hasilnya, sajikan ke
browser lewat aplikasi Anda sendiri.

---

## 3. Autentikasi

Setiap permintaan wajib membawa header:

```
Authorization: Bearer pgr_<prefix>_<secret>
```

Bentuk token: `pgr_` + 12 karakter heksadesimal + `_` + 64 karakter heksadesimal.
Contoh: `pgr_645a22029d1b_ba691e6a6fe091a9231b784c8632e80a6632575ba21dcf45c2c22208a7f53c8d`

### Cara memperoleh token

Super Admin DIWAN menerbitkannya di menu **Token API**. Saat menerbitkan, ia menentukan:
nama aplikasi Anda, daftar izin, batas permintaan per menit, batas subtree wilayah (opsional),
dan tanggal kedaluwarsa (opsional).

### Yang harus Anda ketahui tentang token

| Hal | Keterangan |
|---|---|
| **Ditampilkan sekali** | DIWAN hanya menyimpan hash SHA-256-nya. Kalau hilang, tidak bisa ditampilkan ulang — harus dirotasi. |
| **Rotasi tanpa masa tenggang** | Saat Super Admin merotasi token, token lama **langsung** mati. Sediakan cara mengganti token tanpa deploy ulang (variabel lingkungan / tabel konfigurasi). |
| **Pencabutan** | Token yang dicabut menjawab `403 token_revoked`. |
| **Kedaluwarsa** | Token lewat tanggal menjawab `403 token_expired`. |

Simpan token sebagai rahasia: variabel lingkungan atau secret manager, **jangan** di repositori kode,
jangan di log, jangan di URL (selalu di header).

---

## 4. Izin (scope) dan lingkup wilayah

### Izin

Token membawa daftar izin. Endpoint yang butuh izin yang tidak dimiliki menjawab
`403 insufficient_scope`.

| Izin | Membuka |
|---|---|
| `structures:read` | `/structures`, `/terms` |
| `people:read` | `/people`, `/people/{uuid}`, `/people/by-phone/{phone}` |
| `placements:read` | `/placements` |
| `people:nik` | `/people/by-nik/{nik}` |

`/sync/full` dan `/sync/changes` membutuhkan **ketiga** izin `structures:read`, `people:read`, dan
`placements:read` sekaligus.

> **`people:nik` sengaja dipisah dari `people:read`.** Token sinkronisasi harian tidak perlu membuka
> nomor KTP, jadi sebaiknya tidak diberi izin itu. Mintalah `people:nik` hanya bila aplikasi Anda
> memang punya alur yang mengharuskannya, dan siapkan alasannya.

### Lingkup wilayah (subtree)

Token bisa dibatasi ke satu subtree struktur — misalnya token untuk pengurus DPW Sulawesi Tenggara
hanya melihat DPW itu beserta seluruh DPD/DPC/DPRa di bawahnya.

Kalau token Anda dibatasi, `/whoami` menjawab `"scope_structure": "terbatas"`. Efeknya:

- `/structures` dan `/terms` hanya memuat struktur di dalam subtree.
- `/placements` hanya memuat penempatan di dalam subtree.
- `/people` hanya memuat orang yang **punya penempatan** di dalam subtree. (Tabel orang tidak punya
  kolom wilayah — seseorang bisa menjabat di beberapa struktur sekaligus, jadi keanggotaan
  lingkupnya ditentukan penempatannya.)
- Mengambil orang di luar subtree lewat `/people/{uuid}` menjawab `404 not_found`, sama persis
  seperti orang yang memang tidak ada. Batas lingkup tidak membocorkan keberadaan data di luarnya.

---

## 5. Bentuk response dan galat

### Berhasil

Setiap response sukses punya dua kunci teratas: `data` dan `meta`.

```json
{
  "data": [ { "uuid": "...", "...": "..." } ],
  "meta": {
    "count": 2,
    "server_time": "2026-10-24T00:41:19+08:00",
    "next_cursor": "1792474620000:1"
  }
}
```

- `data` — array objek untuk endpoint daftar, objek tunggal untuk endpoint satuan
  (`/whoami`, `/people/{uuid}`, `/structures/{uuid}`).
- `meta.count` — jumlah baris di `data`. Untuk endpoint `/sync/*` nilainya **objek** berisi jumlah
  per koleksi, bukan angka tunggal (lihat [bagian 9](#9-protokol-sinkronisasi)).
- `meta.server_time` — waktu server, ISO-8601 dengan offset (zona `Asia/Makassar`, `+08:00`).
- `meta.next_cursor` — ada bila masih ada halaman berikutnya; `null` bila sudah habis.

### Galat

```json
{ "error": { "code": "insufficient_scope", "message": "Token ini tidak punya izin \"people:read\"." } }
```

Periksa `error.code` di kode Anda, jangan `error.message` — pesannya berbahasa Indonesia dan bisa
berubah sewaktu-waktu; kodenya stabil.

| HTTP | `code` | Arti | Tindakan yang tepat |
|---|---|---|---|
| 400 | `bad_request` | Parameter salah bentuk (cursor, since, NIK) | Perbaiki permintaan; jangan ulangi apa adanya |
| 401 | `unauthorized` | Header tidak ada, bentuk token salah, atau token tidak dikenal | Periksa konfigurasi token |
| 403 | `insufficient_scope` | Token tidak punya izin yang diminta | Minta izin tambahan ke Super Admin |
| 403 | `token_revoked` | Token sudah dicabut | Minta token baru |
| 403 | `token_expired` | Token lewat tanggal kedaluwarsa | Minta token baru |
| 404 | `not_found` | Objek tidak ada, atau di luar lingkup token | Perlakukan sebagai "tidak ada" |
| 404 | `unknown_resource` | Nama endpoint salah | Perbaiki URL |
| 404 | `unknown_version` | Bukan `/api/v1/` | Perbaiki URL |
| 405 | `method_not_allowed` | Bukan GET | API ini hanya menerima GET |
| 429 | `rate_limited` | Melebihi batas per menit | Tunggu `Retry-After` detik, lalu ulangi |
| 500 | `server_error` | Galat di sisi DIWAN | Ulangi dengan jeda menaik; laporkan bila menetap |

Aturan praktis: **4xx jangan diulang begitu saja** (kecuali 429), **5xx boleh diulang** dengan jeda
yang menaik (*exponential backoff*).

---

## 6. Referensi endpoint

### `GET /whoami`

Memastikan token dan izinnya tanpa menarik data. Tidak butuh izin apa pun selain token yang sah.
Pakai ini sebagai *health check* integrasi Anda.

### `GET /structures`

Seluruh struktur organisasi, terurut mengikuti pohon (induk selalu muncul sebelum anaknya — aman
untuk dimasukkan ke tabel ber-*foreign key* secara berurutan).

| Parameter | Keterangan |
|---|---|
| `since` | Hanya yang berubah sejak waktu ini (ISO-8601 atau epoch detik) |
| `include_deleted=1` | Sertakan baris terhapus (`"deleted": true`) |

Tidak berhalaman — selalu lengkap dalam satu response.

### `GET /structures/{uuid}`

Satu struktur. `404` bila tidak ada, sudah dihapus, atau di luar lingkup token.

### `GET /terms`

Periode kepengurusan.

| Parameter | Keterangan |
|---|---|
| `structure` | UUID struktur; hanya periode milik struktur itu |
| `since` | Hanya yang berubah sejak waktu ini |
| `include_deleted=1` | Sertakan baris terhapus |

Tidak berhalaman.

### `GET /people`

Daftar orang. **Berhalaman** — lihat [bagian 8](#8-paginasi-dengan-kursor).

| Parameter | Keterangan |
|---|---|
| `since` | Hanya yang berubah sejak waktu ini |
| `cursor` | Lanjutan halaman, dari `meta.next_cursor` |
| `limit` | 1–1000, bawaan 200 |
| `include_deleted=1` | Sertakan baris terhapus |

NIK tidak pernah ada di endpoint ini. Lihat [bagian 11](#11-nik-aturan-khusus).

### `GET /people/{uuid}`

Satu orang.

### `GET /people/by-nik/{nik}`

Lookup berdasarkan NIK utuh 16 digit. Butuh izin `people:nik`. Lihat
[bagian 11](#11-nik-aturan-khusus) untuk aturan lengkapnya.

### `GET /people/by-phone/{phone}`

Lookup berdasarkan nomor HP/WhatsApp. Izinnya `people:read` biasa (BUKAN `people:nik` — nomor HP
sudah ikut terbuka apa adanya di `/people`).

> **`phone` bukan kolom unik.** Nomor kadang dipakai bersama (mis. nomor suami tercatat juga di data
> pengurus muslimah). Nomor yang dipakai lebih dari satu orang menjawab `404 not_found` — **ambigu
> diperlakukan sama dengan tidak ditemukan**, bukan mengembalikan salah satu secara sembarang. Jangan
> asumsikan nomor HP selalu mengidentifikasi tepat satu orang.

### `GET /placements`

Penempatan jabatan — inilah inti data kepengurusan: siapa menjabat apa, di struktur mana, periode
mana. **Berhalaman.**

| Parameter | Keterangan |
|---|---|
| `structure` | UUID struktur |
| `term` | UUID periode |
| `person` | UUID orang |
| `current=1` | Hanya periode yang sedang berjalan |
| `core=1` | Hanya Pengurus Inti |
| `since` | Hanya yang berubah sejak waktu ini |
| `cursor` | Lanjutan halaman |
| `limit` | 1–1000, bawaan 200 |
| `include_deleted=1` | Sertakan baris terhapus |

Filter bisa digabung. `?current=1&core=1` menjawab *"siapa Pengurus Inti yang menjabat sekarang"*
tanpa perlu tahu nama periodenya.

> **`is_core` dihitung di server.** Jangan menyalin aturannya ke aplikasi Anda — aturannya bergantung
> pada tier struktur dan bisa berubah lewat data, tanpa deploy. Contoh yang berlaku sekarang:
> Wakil Ketua adalah inti di DPW ke bawah, tetapi **bukan** di DPP/MWP. Jabatan di dalam unit
> (Departemen/Lembaga/Biro) tidak pernah inti.

### `GET /sync/full` dan `GET /sync/changes`

Lihat [bagian 9](#9-protokol-sinkronisasi) — ini perlu penjelasan tersendiri.

---

## 7. Skema field tiap entitas

Semua field waktu berformat ISO-8601 dengan offset. Semua field tanggal berformat `YYYY-MM-DD`.
Field bertanda *nullable* bisa bernilai `null`.

### Struktur (`structures`)

| Field | Tipe | Keterangan |
|---|---|---|
| `uuid` | string | Kunci utama di salinan lokal Anda |
| `parent_uuid` | string \| null | `null` hanya untuk akar (DPP) |
| `level` | enum | `DPP` `DPW` `DPD` `DPC` `DPRa` `MWP` `MWW` `MWD` `MWC` |
| `wing` | enum | `umum` (DP*) atau `muslimah` (MW*) |
| `tier` | int | `1` Pusat, `2` Wilayah, `3` Daerah, `4` Cabang, `5` Ranting |
| `name` | string | Nama wilayah, mis. `"Sulawesi Tenggara"` |
| `region_code` | string \| null | Kode wilayah resmi bila ada |
| `depth` | int | Kedalaman dari akar; akar = `0` |
| `is_active` | bool | |
| `updated_at` | datetime | Dasar sinkronisasi inkremental |
| `deleted` | bool | `true` berarti hapus dari salinan lokal |

`level` dan `tier` menyatakan hal yang sama dari dua sisi: `tier` nyaman untuk perbandingan
(`tier >= 2`), `level` nyaman untuk ditampilkan. Sayap Muslimah punya tier yang setara dengan sayap
umum — `MWW` dan `DPW` sama-sama tier 2.

### Periode (`terms`)

| Field | Tipe | Keterangan |
|---|---|---|
| `uuid` | string | |
| `structure_uuid` | string | Struktur pemilik periode |
| `name` | string | Mis. `"2021-2026"` |
| `start_date` | date \| null | |
| `end_date_planned` | date \| null | Akhir menurut SK |
| `end_date_actual` | date \| null | Akhir sebenarnya, bila sudah berakhir |
| `status` | enum | `draft` `active` `ended` |
| `is_current` | bool | Periode yang sedang berjalan |
| `sk_number` | string \| null | Nomor Surat Keputusan |
| `sk_date` | date \| null | Tanggal SK |
| `updated_at` | datetime | |
| `deleted` | bool | |

Pakai `is_current`, bukan perbandingan tanggal: periode bisa diperpanjang melewati
`end_date_planned` tanpa kehilangan status berjalannya.

### Orang (`people`)

| Field | Tipe | Keterangan |
|---|---|---|
| `uuid` | string | |
| `title_prefix` | string \| null | Gelar depan, mis. `"Dr. H."` |
| `name` | string | **Nama asli tanpa gelar** — pakai ini untuk mengurutkan & mencocokkan |
| `title_suffix` | string \| null | Gelar belakang, mis. `"Lc., M.A."` |
| `full_name` | string | Gabungan ketiganya, siap tampil |
| `gender` | enum | `L` atau `P` |
| `phone` | string \| null | E.164 tanpa `+`, mis. `"6281234567890"` |
| `email` | string \| null | |
| `birth_date` | date \| null | |
| `city` | string \| null | Kota/kabupaten domisili |
| `province` | string \| null | Provinsi domisili |
| `status` | enum | `active` `inactive` `deceased` |
| `updated_at` | datetime | |
| `deleted` | bool | |

Nama disimpan terpisah supaya Anda tidak perlu memecah `full_name` sendiri — pemecahan gelar penuh
jebakan (`"M."` di data Indonesia hampir selalu "Muhammad", bukan "Magister").

**`nik` tidak ada di sini, dan tidak akan pernah ada.**

### Penempatan (`placements`)

| Field | Tipe | Keterangan |
|---|---|---|
| `uuid` | string | |
| `person_uuid` | string | Relasi ke orang |
| `person_name` | string | Nama lengkap, disalin demi kenyamanan tampilan |
| `structure_uuid` | string | Relasi ke struktur |
| `structure` | string | Label siap tampil, mis. `"DPW Sulawesi Tenggara"` |
| `term_uuid` | string | Relasi ke periode |
| `term_name` | string | Mis. `"2021-2026"` |
| `term_is_current` | bool | Periode ini sedang berjalan |
| `position_code` | string | Kode jabatan stabil, mis. `"ketua"` — pakai ini untuk logika |
| `position_name` | string | Nama jabatan siap tampil, mis. `"Ketua"` |
| `is_core` | bool | Pengurus Inti; dihitung server |
| `order` | int | Urutan tampil jabatan (kecil = lebih tinggi) |
| `unit_uuid` | string \| null | Unit (Departemen/Lembaga/Biro); `null` = jabatan di tingkat struktur |
| `unit_name` | string \| null | |
| `unit_abbr` | string \| null | Singkatan unit |
| `unit_group` | string \| null | Bidang induk unit |
| `unit_group_kind` | enum \| null | `bidang` `subbidang` `grup` |
| `is_primary` | bool | Penempatan utama orang ini |
| `start_date` | date \| null | |
| `end_date` | date \| null | `null` = masih berjalan |
| `end_reason` | enum \| null | `selesai_periode` `mengundurkan_diri` `mutasi` `wafat` `diberhentikan` `lainnya` |
| `updated_at` | datetime | |
| `deleted` | bool | |

Pakai `position_code` untuk percabangan logika dan `position_name` untuk ditampilkan. Nama bisa
diperhalus redaksinya; kodenya tidak berubah.

Satu orang bisa punya beberapa penempatan sekaligus (rangkap jabatan di beberapa struktur). Urutkan
dengan `order` untuk menampilkan susunan pengurus sesuai hierarki jabatan.

---

## 8. Paginasi dengan kursor

Endpoint `/people` dan `/placements` berhalaman. Polanya selalu sama:

1. Panggil tanpa `cursor`.
2. Baca `meta.next_cursor`.
3. Kalau `null`, selesai. Kalau tidak, panggil lagi dengan `cursor=<nilai itu>` dan parameter lain
   **tetap sama persis**.

```bash
# halaman 1
curl -H "Authorization: Bearer $TOKEN" '.../people?limit=500'
# -> "next_cursor": "1792497219804:1319"

# halaman 2
curl -H "Authorization: Bearer $TOKEN" '.../people?limit=500&cursor=1792497219804:1319'
```

Kursor berbentuk `<epoch_milidetik>:<id>` — **perlakukan sebagai teks buram.** Jangan mengurai,
menyusun, atau menebaknya sendiri; bentuknya bisa berubah.

Mengapa kursor gabungan, bukan sekadar `since`? Karena banyak baris bisa punya `updated_at` yang
persis sama (impor massal menulis ratusan baris dalam milidetik yang sama). Kursor berbasis waktu
tunggal akan melewatkan atau mengulang baris tepat di batas halaman. Pasangan `(updated_at, id)`
membuat urutannya tidak pernah ambigu.

`limit` bawaan 200, maksimum 1000. Nilai di luar rentang itu tidak ditolak — nilai `< 1` memakai
bawaan, nilai `> 1000` dipotong ke 1000. Jangan andalkan jumlah baris yang kembali sama dengan
`limit` yang Anda minta; selalu baca `next_cursor`.

---

## 9. Protokol sinkronisasi

Bagian ini yang paling penting kalau Anda menyimpan salinan lokal. Salah di sini menghasilkan
salinan yang *diam-diam* tidak lengkap — jenis bug yang paling sulit ditemukan belakangan.

### Dua aturan emas

> **Aturan 1 — `sync_token` hanya terbit kalau `has_more` bernilai `false`.**
> Selama `has_more: true`, jendela perubahan belum selesai dikirim dan `sync_token` **tidak ada** di
> response. Habiskan dulu kursornya.

> **Aturan 2 — majukan penanda waktu Anda HANYA dengan `sync_token`.**
> Jangan pernah memakai jam lokal, `server_time`, atau waktu terbesar dari baris yang Anda terima
> sebagai `since` berikutnya.

Kedua aturan itu saling menguatkan: karena token ditahan sampai semuanya terkirim, alur yang salah
tidak bisa ditempuh — tanpa token, Anda tidak punya nilai `since` baru untuk dimajukan.

### Langkah 1 — tarikan awal: `GET /sync/full`

```bash
curl -H "Authorization: Bearer $TOKEN" '.../sync/full?limit=1000'
```

```json
{
  "data": {
    "structures": [ ... ],
    "terms": [ ... ],
    "placements": [ ... ]
  },
  "meta": {
    "count": { "structures": 1106, "terms": 516, "placements": 1000 },
    "placements_next_cursor": "1792497219106:1158",
    "has_more": true,
    "catatan": "Masih ada sisa. Ulangi permintaan ..."
  }
}
```

- `structures` dan `terms` **lengkap di halaman pertama** dan tidak berhalaman.
- `placements` berhalaman lewat `placements_next_cursor`. Ulangi `/sync/full` dengan
  `cursor_placements=<nilai itu>` sampai `has_more: false`.
- Di halaman lanjutan, `structures` dan `terms` dikirim sebagai array kosong — bukan diulang.
  Jangan mengosongkan tabel lokal Anda karena melihat array kosong itu.
- `placements` di sini hanya periode yang **sedang berjalan**. Butuh riwayat lengkap? Tarik terpisah
  lewat `/placements` tanpa `current=1`.
- Setelah `has_more: false`, simpan `meta.sync_token`.

### Langkah 2 — tarikan berkala: `GET /sync/changes`

```bash
curl -H "Authorization: Bearer $TOKEN" \
     '.../sync/changes?since=2026-10-24T00:47:25%2B08:00&limit=1000'
```

Parameter `since` **wajib**; isinya `sync_token` yang Anda simpan. Ingat meng-*URL-encode* tanda
`+` pada offset zona waktu menjadi `%2B` — kalau tidak, ia terbaca sebagai spasi dan zona waktunya
meleset delapan jam.

Response memuat empat koleksi dan **dua** kursor terpisah:

```json
{
  "data": {
    "structures": [ ... ],
    "terms": [ ... ],
    "people": [ ... ],
    "placements": [ ... ]
  },
  "meta": {
    "count": { "structures": 0, "terms": 0, "people": 2, "placements": 0 },
    "people_next_cursor": "1792773887890:1048",
    "placements_next_cursor": null,
    "has_more": true
  }
}
```

| Kursor di `meta` | Kirim balik sebagai |
|---|---|
| `people_next_cursor` | `cursor_people` |
| `placements_next_cursor` | `cursor_placements` |

Selama `has_more: true`: ulangi dengan **`since` yang sama persis** ditambah kursor-kursor yang
belum habis. Jangan ganti `since` di tengah pengurasan.

`/sync/changes` **selalu** menyertakan baris terhapus — di sinilah penghapusan sampai ke Anda.
Terapkan setiap baris ber-`"deleted": true` sebagai penghapusan di salinan lokal.

### Urutan penerapan

Terapkan dalam urutan ini supaya relasi tidak pernah menunjuk ke baris yang belum ada:

1. `structures` (sudah terurut induk-sebelum-anak)
2. `terms`
3. `people`
4. `placements`

Penghapusan paling aman diterapkan dengan urutan kebalikannya, atau cukup dengan menonaktifkan
pemeriksaan relasi saat menerapkan satu batch.

### Mengapa tidak kehilangan baris

`since` bersifat **inklusif** (`updated_at >= since`) dan `sync_token` diambil **sebelum** query
pertama dijalankan. Akibatnya baris yang ditulis tepat selagi response disusun akan ikut terkirim
lagi di panggilan berikutnya — Anda mungkin menerima baris yang sama dua kali, tetapi tidak akan
pernah melewatkannya.

Karena itu **penerapan harus idempoten**: pakai *upsert* berdasarkan `uuid`, bukan *insert*. Menerima
baris yang sama dua kali harus menghasilkan keadaan yang sama persis.

### Seberapa sering?

Data kepengurusan berubah dalam hitungan hari, bukan detik. Sekali tiap 15–60 menit sudah lebih dari
cukup. Menarik tiap menit hanya menghabiskan kuota rate limit Anda tanpa menambah kesegaran yang
berarti.

---

## 10. Batas permintaan (rate limit)

Setiap token punya batas permintaan per menit (bawaan 60, ditentukan Super Admin). Setiap response
membawa:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
```

Saat terlampaui: `429` dengan `code: "rate_limited"` dan header `Retry-After: 60`.

Yang perlu dilakukan aplikasi Anda:

- Hormati `Retry-After`. Jangan mencoba ulang lebih cepat dari itu.
- Pantau `X-RateLimit-Remaining` dan rem sendiri sebelum mentok.
- Pakai `limit` besar (sampai 1000) agar jumlah permintaan sedikit, bukan `limit` kecil berkali-kali.
- Jadwalkan sinkronisasi di luar jam sibuk bila memungkinkan.

Batasnya dihitung per token. Kalau integrasi Anda punya beberapa proses paralel, mereka berbagi
kuota yang sama.

---

## 11. NIK: aturan khusus

NIK adalah data pribadi sensitif dan diperlakukan berbeda dari seluruh field lain.

**NIK tidak pernah muncul di `/people`, `/sync/full`, maupun `/sync/changes`.** Daftar field setiap
entitas ditulis eksplisit di sisi server, jadi menambah kolom sensitif ke basis data DIWAN di masa
depan pun tidak akan otomatis membocorkannya lewat API.

Satu-satunya jalan adalah `GET /people/by-nik/{nik}`, dengan aturan:

- Butuh izin `people:nik` tersendiri. `people:read` saja tidak cukup.
- Hanya menerima **NIK utuh 16 digit**. Tidak ada pencarian sebagian, jadi endpoint ini tidak bisa
  dipakai menyapu rentang nomor. NIK kurang/lebih dari 16 digit menjawab `400`.
- **Setiap pemanggilan diaudit** lengkap dengan nama aplikasi konsumen Anda — termasuk pemanggilan
  yang tidak menemukan apa pun.
- **Response tidak memuat ulang NIK-nya.** Anda sudah tahu nomor yang Anda kirim; mengembalikannya
  hanya menambah satu tempat lagi nomor itu tersalin (di log, cache, dsb.).
- Tidak ditemukan dan di luar lingkup token menjawab `404` yang identik.

Kalau Anda meminta izin ini, perlakukan NIK di sisi Anda dengan standar yang sama: jangan simpan
bila tidak perlu, jangan tulis ke log, jangan tampilkan tanpa alasan.

---

## 12. Jebakan yang sering terjadi

| Jebakan | Akibat | Yang benar |
|---|---|---|
| Menyimpan `sync_token` padahal `has_more: true` | **Perubahan hilang permanen** — salinan lokal diam-diam tidak lengkap | Habiskan semua kursor dulu; token memang tidak dikirim sampai `has_more: false` |
| Memakai jam lokal / `server_time` sebagai `since` | Melewatkan baris yang ditulis selagi response disusun | Hanya `sync_token` yang boleh jadi `since` berikutnya |
| Lupa meng-*encode* `+` di `since` | Zona waktu meleset 8 jam | `%2B` — atau pakai epoch detik: `since=1792497219` |
| Mengirim balik `people_next_cursor` sebagai `cursor` | Parameternya diabaikan; halaman pertama terus berulang | Namanya `cursor_people` di `/sync/changes` (`cursor` di `/people`) |
| Mengganti `since` di tengah pengurasan kursor | Baris terlewat atau ganda | `since` tetap sama persis sampai `has_more: false` |
| `INSERT` alih-alih *upsert* | Gagal duplikat saat baris terkirim ulang | *Upsert* berdasarkan `uuid`; penerapan harus idempoten |
| Mengabaikan `"deleted": true` | Pengurus yang sudah dihapus hidup selamanya di salinan Anda | Terapkan sebagai penghapusan |
| Mengosongkan tabel karena `structures: []` | Seluruh struktur lokal terhapus di halaman lanjutan `/sync/full` | Array kosong di halaman lanjutan berarti "tidak ada yang baru", bukan "tidak ada apa-apa" |
| Menyimpan id internal | Tidak ada id internal yang keluar | Kunci semuanya dengan `uuid` |
| Menyalin aturan Pengurus Inti | Logika Anda melenceng saat aturannya berubah | Pakai `is_core` / `core=1` dari server |
| Memasang token di JavaScript browser | Token bocor; API juga tidak mengirim CORS | Panggil dari backend |
| Mengulang `4xx` apa adanya | Membuang kuota tanpa pernah berhasil | Hanya ulangi `429` (sesuai `Retry-After`) dan `5xx` |
| Membandingkan tanggal untuk cari periode berjalan | Salah saat periode diperpanjang | Pakai `is_current` / `current=1` |
| Mengasumsikan `/people/by-phone/{phone}` selalu menemukan satu orang | Nomor bersama (mis. suami-istri) menjawab `404`, padahal "ada" di data | `phone` bukan kolom unik; `404` dari endpoint ini juga berarti "ambigu", bukan cuma "tidak ada" |

---

## 13. Contoh klien lengkap

### PHP — sinkronisasi inkremental yang benar

```php
<?php
const API  = 'https://diwan.wahdah.or.id/api/v1';
const TOKEN = '...';   // dari variabel lingkungan, bukan ditulis di sini

function apiGet(string $path, array $query = []): array {
    $url = API . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . TOKEN],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);

    if ($status === 429) {
        sleep(60);
        return apiGet($path, $query);   // 429 boleh diulang
    }
    if ($status >= 400) {
        throw new RuntimeException("API $status: " . ($json['error']['code'] ?? 'unknown'));
    }
    return $json;
}

// Mengambil SATU jendela perubahan sampai habis, lalu mengembalikan sync_token baru.
// http_build_query() mengurus encoding '+' pada offset zona waktu.
function tarikPerubahan(string $since): string {
    $cursors = [];

    while (true) {
        $r = apiGet('/sync/changes', ['since' => $since, 'limit' => 1000] + $cursors);

        // Urutan penerapan penting: induk sebelum anak, orang sebelum penempatan.
        terapkan('structures', $r['data']['structures']);
        terapkan('terms',      $r['data']['terms']);
        terapkan('people',     $r['data']['people']);
        terapkan('placements', $r['data']['placements']);

        if (empty($r['meta']['has_more'])) {
            // Hanya di sini sync_token ada. Inilah satu-satunya nilai yang boleh
            // dipakai sebagai 'since' berikutnya.
            return $r['meta']['sync_token'];
        }

        $cursors = array_filter([
            'cursor_people'     => $r['meta']['people_next_cursor']     ?? null,
            'cursor_placements' => $r['meta']['placements_next_cursor'] ?? null,
        ]);
    }
}

function terapkan(string $entitas, array $rows): void {
    foreach ($rows as $row) {
        if (!empty($row['deleted'])) {
            hapusLokal($entitas, $row['uuid']);   // penghapusan HARUS ikut diterapkan
        } else {
            upsertLokal($entitas, $row);          // upsert by uuid -> idempoten
        }
    }
}

// Pemakaian: simpan token yang dikembalikan, pakai di jalannya yang berikutnya.
$sinceBaru = tarikPerubahan(bacaTokenTersimpan());
simpanToken($sinceBaru);
```

### Node.js — tarikan awal

```js
const API = 'https://diwan.wahdah.or.id/api/v1';
const TOKEN = process.env.DIWAN_TOKEN;

async function apiGet(path, query = {}) {
  const url = new URL(API + path);
  for (const [k, v] of Object.entries(query)) {
    if (v != null) url.searchParams.set(k, v);   // URLSearchParams mengurus encoding '+'
  }

  const res = await fetch(url, { headers: { Authorization: `Bearer ${TOKEN}` } });
  const json = await res.json();

  if (res.status === 429) {
    const wait = Number(res.headers.get('retry-after') || 60);
    await new Promise(r => setTimeout(r, wait * 1000));
    return apiGet(path, query);
  }
  if (!res.ok) throw new Error(`API ${res.status}: ${json.error?.code}`);

  return json;
}

async function tarikanAwal() {
  const semua = { structures: [], terms: [], placements: [] };
  let cursor = null;

  while (true) {
    const r = await apiGet('/sync/full', { limit: 1000, cursor_placements: cursor });

    // Di halaman lanjutan structures & terms berupa array kosong -- concat aman,
    // tetapi JANGAN menafsirkannya sebagai "tidak ada struktur".
    semua.structures.push(...r.data.structures);
    semua.terms.push(...r.data.terms);
    semua.placements.push(...r.data.placements);

    if (!r.meta.has_more) {
      return { ...semua, syncToken: r.meta.sync_token };
    }
    cursor = r.meta.placements_next_cursor;
  }
}
```

### Python — susunan Pengurus Inti yang menjabat

```python
import os, requests

API = "https://diwan.wahdah.or.id/api/v1"
SESSION = requests.Session()
SESSION.headers["Authorization"] = f"Bearer {os.environ['DIWAN_TOKEN']}"

def api_get(path, **params):
    r = SESSION.get(f"{API}{path}", params=params, timeout=60)
    if r.status_code >= 400:
        raise RuntimeError(f"API {r.status_code}: {r.json().get('error', {}).get('code')}")
    return r.json()

def pengurus_inti_berjalan():
    rows, cursor = [], None
    while True:
        r = api_get("/placements", current=1, core=1, limit=1000, cursor=cursor)
        rows += r["data"]
        cursor = r["meta"].get("next_cursor")
        if not cursor:
            return rows

for p in sorted(pengurus_inti_berjalan(), key=lambda x: (x["structure"], x["order"])):
    print(f"{p['structure']:40} {p['position_name']:20} {p['person_name']}")
```

---

## 14. Daftar periksa integrasi

Sebelum menyebut integrasi Anda siap produksi:

**Token & keamanan**
- [ ] Token disimpan sebagai rahasia (variabel lingkungan / secret manager), bukan di repositori
- [ ] Token bisa diganti tanpa deploy ulang (siap menghadapi rotasi mendadak)
- [ ] Token tidak pernah masuk log, URL, atau JavaScript sisi browser
- [ ] Hanya meminta izin yang benar-benar dipakai (khususnya: `people:nik` hanya bila perlu)

**Ketahanan**
- [ ] `429` ditangani dengan menghormati `Retry-After`
- [ ] `5xx` diulang dengan jeda menaik; `4xx` tidak diulang begitu saja
- [ ] Pemeriksaan `error.code`, bukan `error.message`
- [ ] Ada *health check* berkala ke `/whoami`

**Kebenaran data**
- [ ] Semua entitas dikunci `uuid`; tidak ada id internal DIWAN yang disimpan
- [ ] Penerapan bersifat *upsert* (idempoten), bukan *insert*
- [ ] Baris `"deleted": true` benar-benar dihapus dari salinan lokal
- [ ] Seluruh kursor dikuras sampai `has_more: false` sebelum `sync_token` disimpan
- [ ] `since` tidak pernah diisi dari jam lokal atau `server_time`
- [ ] `+` pada `since` di-*encode* (`%2B`), atau memakai epoch detik
- [ ] Urutan penerapan: structures → terms → people → placements
- [ ] `is_core` / `current=1` diambil dari server, bukan dihitung ulang
- [ ] `position_code` dipakai untuk logika, `position_name` untuk tampilan

**Operasional**
- [ ] Frekuensi sinkronisasi wajar (15–60 menit, bukan tiap menit)
- [ ] Ada log jumlah baris diterapkan per sinkronisasi, untuk memantau kalau tiba-tiba nol
- [ ] Ada peringatan bila sinkronisasi gagal berturut-turut

---

## Pertanyaan & perubahan kontrak

API ini berversi di URL (`/api/v1/`). Penambahan field baru bisa terjadi kapan saja dan **bukan**
perubahan yang merusak — aplikasi Anda harus mengabaikan field yang tidak dikenalnya, bukan
menolaknya. Perubahan yang merusak akan terbit sebagai `/api/v2/`.

Untuk permintaan izin tambahan, penyesuaian batas per menit, atau laporan masalah, hubungi Super
Admin DIWAN.
