# Panduan Migrasi: Dari API Muktamar ke API DIWAN

Panduan ini untuk **pengembang Aplikasi Mukernas, Aplikasi Syuro, dan aplikasi penarik lain**
yang sebelumnya memakai data dari database/API Muktamar V dan ingin beralih sepenuhnya ke DIWAN.

> **Mulai November 2026, DIWAN adalah satu-satunya sumber data kepengurusan Wahdah Islamiyah.**
> Database Muktamar V tidak lagi diperbarui dan akan dihapus.

---

## Kenapa harus pindah

| Muktamar V | DIWAN |
|---|---|
| Data beku sejak Muktamar (awal 2026) | Diperbarui terus oleh tim kepengurusan |
| Kode registrasi `MV-`, `KH-` — format acara | UUID stabil, bisa disinkronkan |
| Kolom kesehatan, mahram, akomodasi, dll. | Hanya data kepengurusan: orang, jabatan, struktur |
| Tidak ada penanda "menjabat" atau "tidak menjabat" | Ada penempatan aktif di periode berjalan |
| Banyak kolom terenkripsi (NIK) | NIK tersedia lewat endpoint khusus (izin terpisah) |

---

## Yang berubah

### 1. Tidak ada lagi kode registrasi `MV-` / `KH-`

DIWAN memakai UUID v4 standar (`b757e8bf-ff94-4f43-a6a9-d21dd197874e`).
Kalau aplikasi Anda menampilkan nomor registrasi Muktamar, hapus tampilan itu —
nilainya tidak ada di DIWAN dan tidak relevan lagi.

### 2. "Peserta" diganti "Pengurus yang menjabat"

Di Muktamar, semua orang adalah "peserta" dengan kategori (`PESERTA_PENUH`, `PANITIA`, dll).
Di DIWAN, konsepnya:

- **Orang** (`/people`) — semua yang pernah tercatat, termasuk yang sudah tidak menjabat.
- **Penempatan** (`/placements?current=1`) — siapa yang **saat ini** menjabat, di jabatan apa,
  di struktur mana.

**Untuk mendapat daftar pengurus yang menjabat, SELALU pakai `/placements?current=1`.**
Jangan pakai `/people` saja — endpoint itu mengembalikan semua orang termasuk yang
sudah tidak punya jabatan.

### 3. Jabatan bukan "amanah" teks bebas

Di Muktamar, `amanah` adalah teks bebas (`"Ketua / Anggota / Anggota"`).
Di DIWAN, jabatan terstruktur:

```
position_code: "ketua_departemen"
position_name: "Ketua Departemen"
unit_name:     "Departemen Data dan Teknologi Informasi"
structure:     "DPP Dewan Pimpinan Pusat"
```

Pakai `position_code` untuk logika, `position_name` untuk tampilan.

### 4. Pengurus Inti = `is_core: true`

Tidak perlu menebak dari nama jabatan. Filter: `/placements?current=1&core=1`.

---

## Langkah migrasi

### Langkah 1: Minta token DIWAN

Hubungi Super Admin DIWAN. Token berbentuk `pgr_<prefix>_<secret>`.
Tes dengan:

```bash
curl -H "Authorization: Bearer $TOKEN" \
     https://diwan.wahdah.or.id/api/v1/whoami
```

### Langkah 2: Tarikan awal

```bash
curl -H "Authorization: Bearer $TOKEN" \
     'https://diwan.wahdah.or.id/api/v1/sync/full?limit=1000'
```

Ini mengembalikan `structures`, `terms`, dan `placements` (hanya periode berjalan).
Habiskan kursor sampai `has_more: false`, lalu simpan `sync_token`.

Untuk data orang, tarik `/people` berhalaman (kursor), atau langsung dari data `person_uuid`
yang ada di penempatan.

### Langkah 3: Ganti tabel lokal

| Tabel lama (Muktamar) | Tabel baru (DIWAN) |
|---|---|
| `muktamar_peserta` | `diwan_people` (dari `/people`) |
| `muktamar_pengurus` | `diwan_placements` (dari `/placements?current=1`) |
| kolom `kategori` | Tidak ada — semua orang di DIWAN adalah pengurus |
| kolom `amanah` | `position_name` + `unit_name` |
| kolom `nomor_registrasi` (`MV-xxx`) | Hapus — tidak ada padanannya |
| kolom `delegasi_daerah` | `structure` di penempatan |
| kolom `uuid_sdm` | `person_uuid` (UUID DIWAN) |

### Langkah 4: Sinkronisasi berkala

Jalankan `/sync/changes?since=<sync_token>` setiap 15–60 menit.
Detail lengkap: [API.md bagian 9](API.md#9-protokol-sinkronisasi).

### Langkah 5: Hapus koneksi Muktamar

- Cabut semua environment variable / konfigurasi ke database atau API Muktamar
- Hapus tabel lokal `muktamar_*` yang sudah tidak terpakai
- Hapus kode yang membaca format `MV-`, `KH-`, atau kolom Muktamar

---

## Perbedaan angka yang mungkin muncul

| Situasi | Penjelasan |
|---|---|
| DIWAN > Muktamar | DIWAN terus menerima data baru setelah Muktamar berakhir |
| `/people` > Data Pengurus di admin DIWAN | `/people` menyertakan orang tanpa jabatan; admin DIWAN "Per Struktur" hanya menampilkan yang menjabat |
| `/placements?current=1` = Data Pengurus aktif | Ini angka yang paling cocok dibandingkan |

**Jangan bandingkan jumlah `/people` dengan jumlah peserta Muktamar** — keduanya mengukur
hal yang berbeda.

---

## Contoh: dari Mukernas lama ke Mukernas baru

**Sebelum (membaca API Muktamar):**
```
GET muktamar-api/peserta → 5.071 peserta (termasuk panitia, undangan)
Tampilan: MV-B757E8BF | PESERTA_PENUH | Ketua / Anggota
```

**Sesudah (membaca API DIWAN):**
```
GET diwan/api/v1/placements?current=1 → hanya pengurus yang menjabat
Tampilan: Muhammad Adhan, S.AP., M.Pd | Ketua Departemen DATIN | DPP
```

Tidak ada lagi kode `MV-`, tidak ada lagi kategori peserta, tidak ada lagi teks amanah
yang disambung garis miring.

---

## Referensi lengkap

- [Panduan Integrasi API DIWAN](API.md) — endpoint, skema field, sinkronisasi, contoh kode
- Hubungi Super Admin DIWAN untuk token dan izin baru
