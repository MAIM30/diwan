#!/bin/bash
# Setup script untuk server DIWAN — jalankan sekali setelah upload project
# Usage: bash deploy/setup-server.sh

set -e

echo "=== Setup DIWAN Server ==="

# 1. Pastikan folder storage dan cache ada & bisa ditulis
echo "[1/6] Membuat folder storage..."
mkdir -p /var/www/diwan/storage/cache/lokasi
mkdir -p /var/www/diwan/storage/sk
mkdir -p /var/www/diwan/public/uploads/people
chown -R www-data:www-data /var/www/diwan/storage
chown -R www-data:www-data /var/www/diwan/public/uploads
chmod -R 775 /var/www/diwan/storage
chmod -R 775 /var/www/diwan/public/uploads

# 2. Pasang Nginx config
echo "[2/6] Memasang konfigurasi Nginx..."
sudo cp /var/www/diwan/deploy/nginx-diwan.conf /etc/nginx/sites-available/diwan
sudo ln -sf /etc/nginx/sites-available/diwan /etc/nginx/sites-enabled/diwan
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
echo "    Nginx OK"

# 3. Install PM2 untuk menjalankan WA Gateway
echo "[3/6] Install PM2..."
sudo npm install -g pm2

# 4. Setup WA Gateway
echo "[4/6] Setup WA Gateway..."
cd /var/www/diwan/wa-gateway
npm install --production
pm2 start server.js --name diwan-wa --node-args="--experimental-vm-modules"
pm2 save
pm2 startup systemd -u $USER --hp $HOME | tail -1 | bash

# 5. Jalankan migrasi database
echo "[5/6] Menjalankan migrasi database..."
cd /var/www/diwan
php database/migrate.php

# 6. Fix permission akhir
echo "[6/6] Merapikan permission..."
find /var/www/diwan -type f -name "*.php" -exec chmod 644 {} \;
find /var/www/diwan -type d -exec chmod 755 {} \;
chown -R $USER:www-data /var/www/diwan

echo ""
echo "=== Setup Selesai ==="
echo "Langkah selanjutnya:"
echo "  1. Salin config/config.production.example.php → config/config.local.php"
echo "  2. Isi BASEURL, DB_USER, DB_PASS, DB_NAME di config.local.php"
echo "  3. Buka http://IP-SERVER/settings/whatsapp untuk konfigurasi WA Gateway"
echo "  4. Buka http://IP-SERVER:3001/qr untuk scan QR WhatsApp"
