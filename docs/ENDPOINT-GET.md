# Daftar Endpoint GET — API DIWAN v1

Daftar ringkas semua URL yang bisa dipanggil aplikasi konsumen. Penjelasan mendalam (protokol
sinkronisasi, skema field lengkap, contoh klien, jebakan) ada di [API.md](API.md).

*Terakhir diverifikasi: 2026-11-12 terhadap kode dan salinan data produksi. Daftar field tiap endpoint di
bawah dicocokkan langsung dengan keluaran nyata API (urutan dan jumlahnya sama); nilai-nilai yang boleh
muncul (tingkat, kode jabatan, jenis kelompok) dicocokkan dengan isi data.*

- **Basis URL produksi:** `https://diwan.wahdah.or.id/api/v1`
- **Basis URL pengembangan:** `http://localhost/pengurus/api/v1`
- **Metode:** hanya `GET` (metode lain dijawab `405 method_not_allowed`)
- **Format:** JSON (`application/json; charset=utf-8`), kecuali `/people/{uuid}/photo` yang berupa gambar JPEG
- **Autentikasi:** setiap permintaan wajib membawa header `Authorization: Bearer pgr_<prefix>_<secret>`.
  Token diterbitkan Super Admin DIWAN di menu **Token API**.

```bash
curl -H "Authorization: Bearer $TOKEN" https://diwan.wahdah.or.id/api/v1/whoami
```

---

## Ringkasan

| # | Endpoint | Izin yang dibutuhkan | Berhalaman | Kegunaan |
|---|---|---|---|---|
| 1 | `GET /whoami` | token sah saja | – | Cek token & izin (health check) |
| 2 | `GET /structures` | `structures:read` | – | Seluruh struktur organisasi |
| 3 | `GET /structures/{uuid}` | `structures:read` | – | Satu struktur |
| 4 | `GET /skuos` | `structures:read` | – | Katalog SKUO pusat (urutan dan kelompok baku) |
| 5 | `GET /terms` | `structures:read` | – | Periode kepengurusan |
| 6 | `GET /people` | `people:read` | ya | Daftar orang |
| 7 | `GET /people/{uuid}` | `people:read` | – | Satu orang |
| 8 | `GET /people/{uuid}/photo` | `people:read` | – | Foto profil satu orang (gambar JPEG, bukan JSON) |
| 9 | `GET /people/by-phone/{phone}` | `people:read` | – | Cari orang lewat nomor HP/WA |
| 10 | `GET /people/by-nik/{nik}` | **`people:nik`** | – | Cari orang lewat NIK 16 digit |
| 11 | `GET /placements` | `placements:read` | ya | Penempatan jabatan (inti data kepengurusan) |
| 12 | `GET /sync/full` | ketiga izin `*:read`¹ | ya | Tarikan awal untuk salinan lokal |
| 13 | `GET /sync/changes` | ketiga izin `*:read`¹ | ya | Tarikan perubahan berkala, termasuk penghapusan |

¹ `structures:read` + `people:read` + `placements:read`, ketiganya sekaligus.

Nama endpoint di luar daftar ini dijawab `404 unknown_resource`; versi selain `v1` dijawab
`404 unknown_version`.

---

## Parameter umum

Berlaku di endpoint yang mencantumkannya.

| Parameter | Nilai | Keterangan |
|---|---|---|
| `since` | ISO-8601 (`2026-10-24T00:47:25%2B08:00`) atau epoch detik (`1792497219`) | Hanya baris yang berubah sejak waktu itu (inklusif). **Tanda `+` wajib di-encode `%2B`.** |
| `include_deleted` | `1` | Sertakan baris terhapus (`"deleted": true`). Di `/sync/changes` selalu aktif. |
| `limit` | 1–1000, bawaan 200 | Jumlah baris per halaman. `< 1` memakai bawaan, `> 1000` dipotong ke 1000. |
| `cursor` | dari `meta.next_cursor` | Lanjutan halaman. Perlakukan sebagai teks buram. |

Semua `{uuid}` dan parameter `structure` / `term` / `person` harus berupa UUID; bentuk lain dijawab
`400 bad_request`.

---

## Rincian per endpoint

### 1. `GET /whoami`

```
/whoami
```

Memastikan token hidup dan melihat izinnya tanpa menarik data.

Respons: `client`, `scopes`, `scope_structure` (`null` = seluruh data, `"terbatas"` = dibatasi
subtree), `rate_limit_per_min`, `expires_at`.

---

### 2. `GET /structures`

```
/structures
/structures?since=1792497219
/structures?include_deleted=1
```

Parameter: `since`, `include_deleted`. Tidak berhalaman — selalu lengkap dalam satu respons.
Urutan mengikuti pohon (induk selalu sebelum anak).

Field: `uuid`, `parent_uuid`, `level`, `wing`, `tier`, `name`, `region_code`, `depth`, `is_active`,
`updated_at`, `deleted`.

- `level`: kode tingkat (teks). Saat ini: `DPP` `DPW` `DPLN` `DPD` `DPC` `DPRa` `MWP` `MWW` `MWLN` `MWD` `MWC` `MWRa` **`DSO` `DSY` `DPK`**.
  **Daftar ini dikelola admin DIWAN dan bisa bertambah** (menu Tingkat Struktur): perlakukan `level` sebagai teks bebas, jangan sebagai enum tetap.
- `wing`: `umum` | `muslimah` · `tier`: `1` Pusat … `5` Ranting. Keduanya ditetapkan per tingkat (bukan lagi diturunkan dari awalan kode).
- `DSO` (Dewan Syura), `DSY` (Dewan Syariah), `DPK` (Dewan Pengawas Keuangan) adalah **akar tersendiri**
  (`parent_uuid: null`, `depth: 0`, `tier: 1`, `wing: umum`), sejajar dengan DPP — bukan anak DPP.
  Jangan mengasumsikan hanya ada satu akar.
- Induk tiap tingkat (per data sekarang): `DPW` dan `DPLN` → `DPP`; `DPD` → `DPW`; `MWP` → `DPP`; `MWW` dan
  `MWLN` → `MWP`; `MWD` → `MWW`. **`MWLN` (Muslimah Luar Negeri) berinduk MWP, bukan MWW**, dan `DPLN` berinduk
  DPP, bukan DPW: keduanya tier `2` tetapi tidak berada di bawah wilayah mana pun. Ikuti `parent_uuid`, jangan
  menurunkan induk dari `level` atau `tier`.

---

### 3. `GET /structures/{uuid}`

```
/structures/0b9c6e1e-0000-0000-0000-000000000000
```

Satu struktur, field sama seperti di atas. `404 not_found` bila tidak ada, sudah dihapus, atau di
luar lingkup token.

---

### 4. `GET /skuos`

```
/skuos
```

Katalog SKUO pusat (sayap umum) dengan urutan dan kelompok baku. Tanpa parameter, tidak berhalaman, tanpa
`since` — selalu lengkap dalam satu respons.

`data` berupa daftar kelompok berurutan: `PI` (Pengurus Inti), `BIDANG I` … `BIDANG IX`, `PUSAT-PUSAT`, `SPI`,
`BADAN`, `BIRO`.

- Field kelompok: `code`, `name`, `units`, `order`.
- Field unit: `id`, `uuid`, `name`, `abbreviation`, `kind`, `wing`, `order`.
- `uuid` adalah kunci stabil. `id` hanyalah ID internal katalog di instalasi ini, untuk aplikasi yang sudah
  memakai ID; jangan dijadikan kunci sinkronisasi.
- `PI` hanya penanda kelompok: `units` selalu kosong, karena Pengurus Inti dipegang di tingkat struktur
  (`unit_uuid: null` di `/placements`), bukan di dalam unit.
- `kind` adalah **kode jenis unit**, bukan daftar tertutup: `departemen` `lembaga` `biro` `badan` `pusat`
  `divisi` `komisi` `belum` (Belum ditentukan; dulu `lainnya`). Admin dapat menambah jenis lain.
- Yang tercantum hanya unit sayap umum yang termasuk daftar baku SKUO pusat. Unit lain di katalog (mis. Komisi)
  dan unit sayap Muslimah tidak ada di sini; susunan aktual per struktur dan periode ada di `/placements`
  (`unit_uuid`, `unit_group`).

---

### 5. `GET /terms`

```
/terms
/terms?structure=<uuid-struktur>
```

Parameter: `structure`, `since`, `include_deleted`. Tidak berhalaman.

Field: `uuid`, `structure_uuid`, `governance_period_uuid`, `name` (mis. `"2021-2026"`), `start_date`,
`end_date_planned`, `end_date_actual`, `status` (`draft` | `active` | `ended`), `is_current`,
`sk_number`, `sk_date`, `updated_at`, `deleted`.

Untuk mencari periode berjalan pakai `is_current`, bukan perbandingan tanggal.

---

### 6. `GET /people`

```
/people?limit=500
/people?limit=500&cursor=1792497219804:1319
/people?since=1792497219&include_deleted=1
```

Parameter: `since`, `cursor`, `limit`, `include_deleted`. **Berhalaman** — ulangi dengan
`cursor=<meta.next_cursor>` sampai `next_cursor` bernilai `null`.

Field: `uuid`, `title_prefix`, `name` (tanpa gelar), `title_suffix`, `full_name`, `gender` (`L` | `P`),
`phone` (E.164 tanpa `+`, mis. `6281234567890`), `email`, `birth_date`, `city`, `province`,
`status` (`active` | `inactive` | `deceased`), `photo_url`, `photo_updated_at`, `updated_at`, `deleted`.

`photo_url` adalah alamat endpoint foto orang itu ([nomor 8](#8-get-peopleuuidphoto)), `photo_updated_at`
adalah kapan berkas fotonya terakhir berubah; keduanya `null` bila orang itu tidak punya foto **atau perempuan**
(foto perempuan tidak diberikan lewat API). Fotonya tidak ikut di dalam JSON.

**NIK tidak pernah ada di endpoint ini.**

**Tidak setiap orang punya penempatan.** `/people` memuat semua orang yang tercatat, termasuk yang belum (atau
tidak lagi) punya penempatan — mis. pendaftar yang diterima tanpa penempatan, atau orang yang baru dimasukkan
dan belum ditempatkan (sekitar sepersepuluh data). Di `/placements?person=<uuid>` mereka kosong. Jangan
menyaring `/people` dengan syarat "punya penempatan" kecuali itu memang yang Anda maksud. Token **berlingkup
subtree** hanya melihat orang yang punya penempatan di subtree-nya, jadi tidak melihat mereka sama sekali.

---

### 7. `GET /people/{uuid}`

```
/people/7f1d2a40-0000-0000-0000-000000000000
```

Satu orang, field sama seperti di atas. `404 not_found` bila tidak ada atau di luar lingkup token.

---

### 8. `GET /people/{uuid}/photo`

```
/people/7f1d2a40-0000-0000-0000-000000000000/photo
```

Foto profil orang itu: **berkas gambar** (`Content-Type: image/jpeg`, persegi 512×512), bukan JSON. Izin dan
lingkup sama dengan `/people/{uuid}`. Pakai alamat dari field `photo_url`, jangan menyusunnya sendiri.

- **Foto perempuan tidak diberikan lewat API.** Orang dengan `gender: "P"` selalu `photo_url: null` dan endpoint
  ini menjawab `404`, walau DIWAN menyimpan fotonya.
- `404 not_found` bila orang itu tidak punya foto, perempuan, tidak ada, terhapus, atau di luar lingkup token.
  Semuanya sengaja tak dibedakan.
- Wajib membawa token, jadi **tidak bisa dipakai langsung di `<img src>` browser**. Unduh dari backend, simpan
  salinan, lalu sajikan dari aplikasi Anda.
- Respons membawa `ETag`. Kirim kembali sebagai `If-None-Match` untuk mendapat `304` tanpa isi. Cara yang lebih
  hemat: bandingkan `photo_updated_at` di `/people` dengan yang Anda simpan, dan unduh hanya yang berbeda.
- Foto yang diganti atau dihapus membuat orang itu muncul lagi di `/sync/changes` (`updated_at` ikut berubah),
  dengan `photo_updated_at` baru atau `photo_url: null`. Bila `null`, hapus foto lokalnya.
- Setiap foto adalah satu permintaan dan dihitung ke batas per menit token. Tarikan awal untuk semua orang
  berfoto akan memakan beberapa menit pada batas 60/menit; hormati `Retry-After`.

---

### 9. `GET /people/by-phone/{phone}`

```
/people/by-phone/081234567890
/people/by-phone/6281234567890
/people/by-phone/%2B6281234567890
```

Mencari orang lewat nomor HP/WhatsApp. Format `08…`, `62…`, dan `+62…` sama-sama diterima
(dinormalisasi ke `62…`); nomor di luar 10–15 digit dijawab `400 bad_request`.

> **Nomor bukan kolom unik.** Nomor yang dipakai lebih dari satu orang dijawab `404 not_found` —
> ambigu diperlakukan sama dengan tidak ditemukan. Jangan mengasumsikan `404` berarti "tidak ada di
> data".

Respons: satu objek orang (field seperti `/people/{uuid}`).

---

### 10. `GET /people/by-nik/{nik}`

```
/people/by-nik/7371xxxxxxxxxxxx
```

Butuh izin **`people:nik`** tersendiri (`people:read` saja tidak cukup). Hanya menerima NIK utuh
16 digit; selain itu `400 bad_request`. Setiap pemanggilan **diaudit** beserta nama aplikasi —
termasuk yang tidak menemukan apa pun. Respons tidak memuat ulang NIK-nya.

Respons: satu objek orang. Tidak ditemukan dan di luar lingkup token sama-sama `404 not_found`.

---

### 11. `GET /placements`

```
/placements?current=1&core=1&limit=1000
/placements?structure=<uuid>&current=1
/placements?person=<uuid>
/placements?term=<uuid>&limit=1000
```

Penempatan jabatan: siapa menjabat apa, di struktur mana, periode mana. **Berhalaman.**

| Parameter | Keterangan |
|---|---|
| `structure` | UUID struktur |
| `term` | UUID periode |
| `person` | UUID orang |
| `current=1` | Hanya periode yang sedang berjalan |
| `core=1` | Hanya Pengurus Inti |
| `since`, `cursor`, `limit`, `include_deleted` | Seperti di tabel parameter umum |

Filter bisa digabung.

Field: `uuid`, `person_uuid`, `person_name`, `structure_uuid`, `structure` (label, mis.
`"DPW Sulawesi Tenggara"`), `term_uuid`, `term_name`, `governance_period_uuid`, `term_is_current`,
`position_code`, `position_name`, `is_core`, `order`, `unit_uuid`, `unit_name`, `unit_abbr`,
`unit_group`, `unit_group_kind`, `is_primary`, `start_date`, `end_date`, `end_reason`, `updated_at`,
`deleted`.

`is_core` dihitung server — jangan disalin aturannya. Pakai `position_code` untuk logika dan
`position_name` untuk tampilan.

`person_name`, `structure`, `term_name`, `unit_name`, `unit_abbr`, dan `unit_group` adalah salinan **untuk
tampilan** pada saat baris ditulis. Mengganti nama sebuah struktur, orang, atau unit tidak membuat penempatannya
dikirim ulang; nama yang berlaku ada di `/structures` dan `/people` (kunci: `structure_uuid`, `person_uuid`).
Sebaliknya, perubahan pada jabatan (`position_code`, `position_name`, `order`, `is_core`) **ikut dikirim ulang**:
setiap kali admin mengubah kode, nama, urutan, atau tanda inti sebuah jabatan, semua penempatan hidup jabatan itu
muncul lagi di `/sync/changes` dengan nilai barunya.

**Kode jabatan dibedakan menurut tempatnya** (jangan bercabang hanya pada `ketua` / `wakil_ketua`):

| Tempat | Ketua | Wakil Ketua |
|---|---|---|
| Pengurus Inti DPP (tanpa unit) | `ketua_bidang` | `wakil_ketua_bidang` |
| DPW (tingkat struktur) | `ketua_dpw` | `wakil_ketua_dpw` |
| DPD (tingkat struktur) | `ketua_dpd` | `wakil_ketua_dpd` |
| MWW (tingkat struktur) | `ketua_mww` | `wakil_ketua` |
| MWD (tingkat struktur) | `ketua_mwd` | `wakil_ketua` |
| Tingkat struktur lain (MWP, DPLN, MWLN, DSO, DSY, DPK, …) | `ketua` | `wakil_ketua` |
| Unit Departemen / Lembaga / Biro / Badan | `ketua_departemen` / `ketua_lembaga` / `ketua_biro` / `ketua_badan` | `wakil_ketua_departemen` (Departemen) / `wakil_ketua_lembaga` (Lembaga); Biro dan Badan belum punya kode wakil |

Ketua unit Divisi dan Komisi berkode `ketua`; kepala unit Pusat dan "Belum ditentukan" umumnya `kepala`.
**Data lama belum tentu tuntas dipindahkan**: di DPD, MWW, dan MWD masih ada penempatan berkode `ketua` polos
di samping `ketua_dpd` / `ketua_mww` / `ketua_mwd` — terima keduanya sebagai Ketua (di DPW pemindahannya sudah tuntas).

**Daftar kode ini bukan daftar tertutup, dan sebuah kode bisa berganti.** Admin dapat menambah jabatan dan mengganti
kodenya lewat menu Jabatan (mis. pada 11 November 2026 `wakil_ketua_departemen_lembaga` dipecah menjadi
`wakil_ketua_departemen` dan `wakil_ketua_lembaga`; `ketua_dewan` dan `pemimpin_umum` ada di katalog tetapi belum
dipegang siapa pun). Karena itu: perlakukan kode yang tak dikenal sebagai jabatan biasa, bukan galat; jangan
mengeraskan daftar kode di aplikasi Anda; dan terapkan penempatan sebagai *upsert* berdasarkan `uuid` supaya kode
yang berganti ikut tergantikan.

Kunci unit pada `unit_uuid`, bukan `unit_name`: nama yang sama (mis. "Departemen Kesehatan") bisa ada
di sayap Umum dan Muslimah sebagai dua unit berbeda.

---

### 12. `GET /sync/full`

```
/sync/full?limit=1000
/sync/full?limit=1000&cursor_placements=1792497219106:1158
```

Tarikan awal untuk membangun salinan lokal. Parameter: `limit`, `cursor_placements`.

Respons `data`: `structures`, `terms`, `placements` (hanya periode berjalan). `structures` dan `terms`
lengkap di halaman pertama; di halaman lanjutan berupa **array kosong** (bukan berarti "tidak ada").
Ulangi dengan `cursor_placements=<meta.placements_next_cursor>` sampai `meta.has_more` bernilai `false`,
lalu simpan `meta.sync_token`.

---

### 13. `GET /sync/changes`

```
/sync/changes?since=2026-10-24T00:47:25%2B08:00&limit=1000
/sync/changes?since=<sama>&limit=1000&cursor_people=<..>&cursor_placements=<..>
```

Perubahan sejak `since` — **wajib**, isinya `sync_token` dari tarikan sebelumnya. Parameter: `since`,
`limit`, `cursor_people`, `cursor_placements`.

Respons `data`: `structures`, `terms`, `people`, `placements`. **Selalu menyertakan baris terhapus**
(`"deleted": true`) — terapkan sebagai penghapusan di salinan lokal. Selama `meta.has_more` `true`,
ulangi dengan `since` yang sama persis dan kursor yang belum habis:

| `meta` | Kirim balik sebagai |
|---|---|
| `people_next_cursor` | `cursor_people` |
| `placements_next_cursor` | `cursor_placements` |

`meta.sync_token` baru **hanya terbit** saat `has_more` `false`; hanya nilai itu yang boleh dipakai
sebagai `since` berikutnya. Penerapan harus *upsert* berdasarkan `uuid` (baris bisa terkirim dua kali).
Urutan penerapan: structures → terms → people → placements.

---

## Bentuk respons

Sukses:

```json
{ "data": [ ... ], "meta": { "count": 2, "server_time": "2026-11-02T10:00:00+08:00", "next_cursor": null } }
```

`data` berupa array (daftar) atau objek (satuan). Di `/sync/*`, `meta.count` berupa objek jumlah per
koleksi.

Galat:

```json
{ "error": { "code": "insufficient_scope", "message": "..." } }
```

| HTTP | `code` | Tindakan |
|---|---|---|
| 400 | `bad_request` | Perbaiki parameter; jangan ulangi apa adanya |
| 401 | `unauthorized` | Periksa token / header |
| 403 | `insufficient_scope` · `token_revoked` · `token_expired` | Minta izin / token baru ke Super Admin |
| 404 | `not_found` · `unknown_resource` · `unknown_version` | Objek tidak ada / di luar lingkup, atau URL salah |
| 405 | `method_not_allowed` | Hanya GET |
| 429 | `rate_limited` | Tunggu `Retry-After` detik (60), lalu ulangi |
| 500 | `server_error` | Ulangi dengan jeda menaik |

Cek `error.code`, bukan `error.message`. Batas permintaan per token (bawaan 60/menit) terbaca di header
`X-RateLimit-Limit` dan `X-RateLimit-Remaining`.

---

## Resep: kebutuhan → URL

| Kebutuhan aplikasi | Panggil |
|---|---|
| Memastikan token masih hidup | `/whoami` |
| Siapa Pengurus Inti yang menjabat sekarang | `/placements?current=1&core=1&limit=1000` |
| Susunan pengurus satu struktur | `/placements?structure=<uuid>&current=1` |
| Riwayat jabatan satu orang | `/placements?person=<uuid>` |
| Periode kepengurusan satu struktur | `/terms?structure=<uuid>` |
| Daftar seluruh struktur / pohon organisasi | `/structures` |
| Urutan dan kelompok baku SKUO pusat (Bidang I–IX, Pusat, Badan, Biro) | `/skuos` |
| Menampilkan foto pengurus | `photo_url` dari `/people` → `/people/{uuid}/photo` (dari backend, simpan salinan) |
| Mengenali pengguna dari nomor WA-nya (login, verifikasi) | `/people/by-phone/{phone}` |
| Mengenali orang dari NIK (hanya bila benar-benar perlu) | `/people/by-nik/{nik}` |
| Membangun lalu menjaga salinan lokal | `/sync/full` sekali, lalu `/sync/changes` tiap 15–60 menit |

---

## Yang perlu diingat

- **API baca-saja.** Tidak ada endpoint tulis; data hanya berubah lewat antarmuka admin DIWAN.
- **Kunci semuanya dengan `uuid`.** Id internal tidak pernah keluar.
- **Panggil dari backend, bukan browser.** API sengaja tidak mengirim header CORS; token tidak boleh
  ada di JavaScript sisi klien.
- **Token dengan lingkup terbatas** hanya melihat subtree-nya; objek di luar subtree dijawab `404`,
  sama seperti objek yang tidak ada.
