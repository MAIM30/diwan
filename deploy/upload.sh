#!/bin/bash
# Upload project DIWAN ke server
# Usage: bash deploy/upload.sh

SERVER="depdatin_wi@103.193.177.46"
REMOTE_DIR="/var/www/diwan"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== Upload DIWAN ke Server ==="
echo "Dari: $LOCAL_DIR"
echo "Ke:   $SERVER:$REMOTE_DIR"
echo ""

rsync -avz --progress \
  --exclude='wa-gateway/node_modules/' \
  --exclude='wa-gateway/auth_state/' \
  --exclude='config/config.local.php' \
  --exclude='storage/cache/' \
  --exclude='storage/sk/' \
  --exclude='public/uploads/people/' \
  --exclude='.claude/' \
  --exclude='.git/' \
  --exclude='*.log' \
  --exclude='deploy/upload.sh' \
  --exclude='deploy/upload.ps1' \
  "$LOCAL_DIR/" "$SERVER:$REMOTE_DIR/"

echo ""
echo "=== Upload selesai! ==="
echo "Selanjutnya SSH ke server dan jalankan:"
echo "  bash /var/www/diwan/deploy/setup-server.sh"
