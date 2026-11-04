# Daftar Endpoint GET — API DIWAN v1

Daftar ringkas semua URL yang bisa dipanggil aplikasi konsumen. Penjelasan mendalam (protokol
sinkronisasi, skema field lengkap, contoh klien, jebakan) ada di [API.md](API.md).

- **Basis URL produksi:** `https://diwan.wahdah.or.id/api/v1`
- **Basis URL pengembangan:** `http://localhost/pengurus/api/v1`
- **Metode:** hanya `GET` (metode lain dijawab `405 method_not_allowed`)
- **Format:** JSON (`application/json; charset=utf-8`)
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
| 4 | `GET /terms` | `structures:read` | – | Periode kepengurusan |
| 5 | `GET /people` | `people:read` | ya | Daftar orang |
| 6 | `GET /people/{uuid}` | `people:read` | – | Satu orang |
| 7 | `GET /people/by-phone/{phone}` | `people:read` | – | Cari orang lewat nomor HP/WA |
| 8 | `GET /people/by-nik/{nik}` | **`people:nik`** | – | Cari orang lewat NIK 16 digit |
| 9 | `GET /placements` | `placements:read` | ya | Penempatan jabatan (inti data kepengurusan) |
| 10 | `GET /forums` | `forums:read` | – | Forum permusyawaratan |
| 11 | `GET /forums/{uuid}` | `forums:read` | – | Satu forum |
| 12 | `GET /forums/{uuid}/participants` | `forums:read` | ya | Peserta forum |
| 13 | `GET /sync/full` | ketiga izin `*:read`¹ | ya | Tarikan awal untuk salinan lokal |
| 14 | `GET /sync/changes` | ketiga izin `*:read`¹ | ya | Tarikan perubahan berkala, termasuk penghapusan |

¹ `structures:read` + `people:read` + `placements:read`, ketiganya sekaligus. Token yang juga
  memiliki `forums:read` akan mendapat koleksi `forums` dan `forum_participants` di respons sync.

Nama endpoint di luar daftar ini dijawab `404 unknown_resource`; versi selain `v1` dijawab
`404 unknown_version`. Endpoint `forums` membutuhkan izin `forums:read` yang terpisah dari
tiga izin dasar.

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

- `level`: `DPP` `DPW` `DPLN` `DPD` `DPC` `DPRa` `MWP` `MWW` `MWD` `MWC` `MWRa` **`DSO` `DSY` `DPK`**
- `wing`: `umum` | `muslimah` · `tier`: `1` Pusat … `5` Ranting
- `DSO` (Dewan Syura), `DSY` (Dewan Syariah), `DPK` (Dewan Pengawas Keuangan) adalah **akar tersendiri**
  (`parent_uuid: null`, `depth: 0`, `tier: 1`, `wing: umum`), sejajar dengan DPP — bukan anak DPP.
  Jangan mengasumsikan hanya ada satu akar.

---

### 3. `GET /structures/{uuid}`

```
/structures/0b9c6e1e-0000-0000-0000-000000000000
```

Satu struktur, field sama seperti di atas. `404 not_found` bila tidak ada, sudah dihapus, atau di
luar lingkup token.

---

### 4. `GET /terms`

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

### 5. `GET /people`

```
/people?limit=500
/people?limit=500&cursor=1792497219804:1319
/people?since=1792497219&include_deleted=1
```

Parameter: `since`, `cursor`, `limit`, `include_deleted`. **Berhalaman** — ulangi dengan
`cursor=<meta.next_cursor>` sampai `next_cursor` bernilai `null`.

Field: `uuid`, `title_prefix`, `name` (tanpa gelar), `title_suffix`, `full_name`, `gender` (`L` | `P`),
`phone` (E.164 tanpa `+`, mis. `6281234567890`), `email`, `birth_date`, `city`, `province`,
`status` (`active` | `inactive` | `deceased`), `updated_at`, `deleted`.

**NIK tidak pernah ada di endpoint ini.**

---

### 6. `GET /people/{uuid}`

```
/people/7f1d2a40-0000-0000-0000-000000000000
```

Satu orang, field sama seperti di atas. `404 not_found` bila tidak ada atau di luar lingkup token.

---

### 7. `GET /people/by-phone/{phone}`

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

### 8. `GET /people/by-nik/{nik}`

```
/people/by-nik/7371xxxxxxxxxxxx
```

Butuh izin **`people:nik`** tersendiri (`people:read` saja tidak cukup). Hanya menerima NIK utuh
16 digit; selain itu `400 bad_request`. Setiap pemanggilan **diaudit** beserta nama aplikasi —
termasuk yang tidak menemukan apa pun. Respons tidak memuat ulang NIK-nya.

Respons: satu objek orang. Tidak ditemukan dan di luar lingkup token sama-sama `404 not_found`.

---

### 9. `GET /placements`

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

---

### 10. `GET /forums`

```
/forums
/forums?since=1792497219
/forums?include_deleted=1
```

Parameter: `since`, `include_deleted`. Tidak berhalaman — selalu lengkap dalam satu respons.

Field: `uuid`, `name`, `type` (`mukernas` | `muktamar` | `musyawarah_wilayah` | `musyawarah_daerah` |
`musyawarah_cabang` | `lainnya`), `organizer_structure_uuid`, `organizer_structure` (label),
`start_date`, `end_date`, `status` (`draft` | `terbuka` | `dikunci` | `selesai`), `locked_at`,
`decision_ref`, `updated_at`, `deleted`.

Token dengan lingkup terbatas hanya melihat forum yang **penyelenggaranya** berada di subtree token.
Forum di luar subtree dijawab `404 not_found`.

---

### 11. `GET /forums/{uuid}`

```
/forums/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

Satu forum, field sama seperti di atas. `404 not_found` bila tidak ada, sudah dihapus, atau di
luar lingkup token.

---

### 12. `GET /forums/{uuid}/participants`

```
/forums/{uuid}/participants?limit=1000
/forums/{uuid}/participants?since=1792497219&include_deleted=1
/forums/{uuid}/participants?status=ditetapkan
```

Peserta forum. **Berhalaman** dengan kursor.

Parameter: `since`, `cursor`, `limit`, `include_deleted`, `status` (filter opsional: `ditetapkan`,
`dicabut`, `diganti`).

Field: `uuid`, `forum_uuid`, `person_uuid`, `person_name`, `category` (`peserta_penuh` | `undangan` |
`peninjau`), `representing_structure_uuid`, `representing_structure` (label), `status` (`ditetapkan` |
`dicabut` | `diganti`), `replaced_by_uuid`, `decision_ref`, `changed_after_lock`, `updated_at`,
`deleted`.

> **Peserta berstatus `dicabut` atau `diganti` bukan baris "terhapus".** Mereka tetap dikirim dalam
> respons biasa (tanpa `include_deleted`). Untuk daftar aktif saja, saring `status = ditetapkan`
> atau gunakan parameter `?status=ditetapkan`. Penanda `deleted: true` hanya muncul pada baris yang
> benar-benar dihapus admin (kekeliruan entri sebelum forum dikunci).

> **NIK tidak pernah ada di endpoint peserta forum.**

---

### 13. `GET /sync/full`

```
/sync/full?limit=1000
/sync/full?limit=1000&cursor_placements=1792497219106:1158
```

Tarikan awal untuk membangun salinan lokal. Parameter: `limit`, `cursor_placements`.

Respons `data`: `structures`, `terms`, `placements` (hanya periode berjalan). `structures` dan `terms`
lengkap di halaman pertama; di halaman lanjutan berupa **array kosong** (bukan berarti "tidak ada").
Ulangi dengan `cursor_placements=<meta.placements_next_cursor>` sampai `meta.has_more` bernilai `false`,
lalu simpan `meta.sync_token`.

**Token dengan `forums:read`:** respons juga menyertakan `forums` (lengkap di halaman pertama) dan
`forum_participants` (berhalaman, kursor `cursor_forum_participants`). Token tanpa izin ini mendapat
respons yang persis sama seperti sebelumnya.

---

### 14. `GET /sync/changes`

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
| `forum_participants_next_cursor` | `cursor_forum_participants` |

`meta.sync_token` baru **hanya terbit** saat `has_more` `false`; hanya nilai itu yang boleh dipakai
sebagai `since` berikutnya. Penerapan harus *upsert* berdasarkan `uuid` (baris bisa terkirim dua kali).
Urutan penerapan: structures → terms → people → forums → forum_participants → placements.

**Token dengan `forums:read`:** respons juga menyertakan `forums` dan `forum_participants`
(dengan penanda `deleted`). Token tanpa izin ini mendapat respons yang persis sama seperti sebelumnya.

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
| Mengenali pengguna dari nomor WA-nya (login, verifikasi) | `/people/by-phone/{phone}` |
| Mengenali orang dari NIK (hanya bila benar-benar perlu) | `/people/by-nik/{nik}` |
| Daftar forum permusyawaratan yang ada | `/forums` |
| Peserta aktif satu forum | `/forums/{uuid}/participants?status=ditetapkan` |
| Membangun lalu menjaga salinan lokal | `/sync/full` sekali, lalu `/sync/changes` tiap 15–60 menit |

---

## Yang perlu diingat

- **API baca-saja.** Tidak ada endpoint tulis; data hanya berubah lewat antarmuka admin DIWAN.
- **Kunci semuanya dengan `uuid`.** Id internal tidak pernah keluar.
- **Panggil dari backend, bukan browser.** API sengaja tidak mengirim header CORS; token tidak boleh
  ada di JavaScript sisi klien.
- **Token dengan lingkup terbatas** hanya melihat subtree-nya; objek di luar subtree dijawab `404`,
  sama seperti objek yang tidak ada.
