# Panduan Deploy ke Server Produksi

Server: VPS ID Cloudhost, diakses lewat VS Code Remote-SSH (`depdatin_wi@103.193.177.46`),
situs hidup di `https://diwan.wahdah.or.id` (Cloudflare proxied). Kode disinkron lewat Git —
`/var/www/diwan` adalah git clone dari `github.com/MAIM30/diwan`, autentikasi lewat deploy key
read-only (`~/.ssh/diwan_deploy` di server, Host alias `github-diwan`).

---

## A. Perubahan kode (Controller, View, Model, JS, CSS, .htaccess, dll.)

1. Perubahan dikerjakan & di-commit di lokal (Claude melakukan ini: commit + `git push` ke GitHub).
2. Buka terminal di jendela VS Code Remote-SSH yang tersambung ke server.
3. Jalankan:
   ```bash
   cd /var/www/diwan && git fetch origin && git merge --ff-only origin/main
   ```
   (Dua langkah ini sengaja dipakai alih-alih `git pull`: di server ini `git pull --ff-only` pernah gagal dengan
   "Cannot fast-forward to multiple branches" padahal konfigurasinya normal; `fetch` + `merge --ff-only` selalu
   jelas tujuannya dan menolak bila riwayat server menyimpang.)
4. Tidak perlu restart apa pun untuk perubahan PHP/view biasa — PHP-FPM membaca ulang file
   setiap request.
5. Verifikasi cepat:
   ```bash
   curl -I https://diwan.wahdah.or.id/pengurus/login
   ```
   Pastikan `HTTP/2 200`, bukan redirect berulang atau error.

## B. Perubahan skema database (migrasi baru di `database/0XX_*.php` atau `001_schema.sql`)

Setelah `git pull` (langkah A di atas), jalankan migrasi di server:
```bash
php database/migrate.php --user=diwan --pass='ISI_PASSWORD' --db=db_pengurus
```
Aman dijalankan berulang (idempoten) — skrip ini melewati migrasi yang sudah pernah diterapkan.
Migrasi 015 menambah tingkat DPLN/MWRa; migrasi 016 membuat acuan periode bersama dan menautkan periode lama
berdasarkan nama serta tanggal yang sama. Migrasi 016 tidak menghapus atau mengganti penempatan.

## C. Perubahan DATA (bukan skema — isi tabel: orang, penempatan, dll.)

**Hati-hati:** server sekarang LIVE. Kalau ada yang sudah mendaftar/mengubah data lewat Portal
Pengurus atau admin langsung di server, menimpa database server dengan dump penuh dari lokal akan
**menghapus data itu**. Sebelum melakukan ini, pastikan dulu:

- Apakah perubahan data cukup kecil untuk ditulis sebagai migrasi terarah (skrip PHP yang
  UPDATE/INSERT baris tertentu saja, bukan dump penuh) — ini yang lebih aman dan jadi kebiasaan
  kita sejauh ini (lihat `database/007_inti_wakil_ketua.php`, `008_whatsapp_gateway.php` sebagai
  contoh pola migrasi terarah).
- Kalau memang perlu dump penuh (mis. impor data massal baru), **koordinasikan dulu dengan Claude**
  sebelum menimpa — perlu dicek dulu apakah ada data baru di server yang akan hilang.

Kalau sudah dipastikan aman, alur dump penuh:
```bash
# di lokal (Laragon)
mysqldump -u root db_pengurus > diwan_data.sql
```
Lalu drag file `.sql` itu ke server lewat VS Code Explorer (Explorer → Explorer, bukan git — file
dump tidak ikut repo), lalu di server:
```bash
mysql -u diwan -p db_pengurus < diwan_data.sql
```

## D. Perubahan di WA Gateway (`wa-gateway/server.js` atau `wa-gateway/package.json`)

1. `git pull` seperti biasa (langkah A).
2. Kalau `package.json` berubah (ada dependency baru):
   ```bash
   cd /var/www/diwan/wa-gateway && npm install --production
   ```
3. Restart proses PM2 supaya perubahan kode WA Gateway terpakai:
   ```bash
   pm2 restart diwan-wa
   pm2 logs diwan-wa --lines 30   # cek tidak ada error setelah restart
   ```

## E. Kalau ada pengaturan baru yang perlu ditambahkan ke `config/config.local.php`

File ini sengaja TIDAK ikut Git (isinya kredensial server). Kalau suatu migrasi/fitur baru
butuh konstanta baru di sana, Claude akan bilang eksplisit — tambahkan manual lewat VS Code
Remote-SSH, lihat `config/config.production.example.php` di repo untuk contoh terbarunya.

## F. Troubleshooting cepat

```bash
curl -IL https://diwan.wahdah.or.id/           # cek redirect/response dari luar
sudo tail -50 /var/log/nginx/error.log         # error level web server
pm2 logs diwan-wa --lines 50                   # error WA Gateway
git -C /var/www/diwan status                   # pastikan tidak ada perubahan lokal nyangkut di server
```
