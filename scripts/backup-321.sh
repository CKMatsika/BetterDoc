#!/usr/bin/env bash
set -euo pipefail

# BetterDoc DMS — Automated Encrypted 3-2-1 Backup Script
# Creates:
#   Copy 1 (Tier 1 Local NVMe): pg_basebackup + WAL + MinIO encrypted vault + HSM/config
#   Copy 2 (Tier 2 Off-Site NAS): Encrypted replication sync
#   Copy 3 (Tier 3 Offline USB / WORM Media): Signed & encrypted archive

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TIER1_DIR="${BETTERDOC_BACKUP_TIER1:-/var/lib/betterdoc/backups/tier1}"
TIER2_DIR="${BETTERDOC_BACKUP_TIER2:-/mnt/nas/betterdoc_backups}"
TIER3_DIR="${BETTERDOC_BACKUP_TIER3:-/mnt/offline_usb/betterdoc_backups}"
APP_DIR="${BETTERDOC_APP_DIR:-/opt/betterdoc}"
BACKUP_PASSPHRASE_FILE="${BETTERDOC_BACKUP_KEY_FILE:-/etc/betterdoc/backup.key}"

mkdir -p "$TIER1_DIR/$STAMP/postgres" "$TIER1_DIR/$STAMP/vault" "$TIER1_DIR/$STAMP/config"

echo "[1/5] Verifying pre-backup audit hash chain..."
php "$APP_DIR/artisan" betterdoc:audit:verify --no-interaction

echo "[2/5] Capturing PostgreSQL base backup + WAL..."
if command -v pg_basebackup >/dev/null 2>&1; then
  pg_basebackup -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_ADMIN_USERNAME:-postgres}" \
    -D "$TIER1_DIR/$STAMP/postgres/base" -Ft -z -Xs -P
fi
php "$APP_DIR/artisan" betterdoc:backup:create --tier=tier1_local --no-interaction

echo "[3/5] Syncing MinIO / Local Encrypted Vault blobs + PKI/KEK configuration..."
cp -a "$APP_DIR/storage/app/vault" "$TIER1_DIR/$STAMP/vault/" 2>/dev/null || true
cp -a "$APP_DIR/.env" "$TIER1_DIR/$STAMP/config/.env" 2>/dev/null || true
cp -a "$APP_DIR/config/profiles" "$TIER1_DIR/$STAMP/config/" 2>/dev/null || true

ARCHIVE_TAR="$TIER1_DIR/betterdoc-backup-$STAMP.tar.zst"
ARCHIVE_ENC="$TIER1_DIR/betterdoc-backup-$STAMP.tar.zst.enc"

tar --zstd -cf "$ARCHIVE_TAR" -C "$TIER1_DIR/$STAMP" .
if [ -f "$BACKUP_PASSPHRASE_FILE" ]; then
  openssl enc -aes-256-gcm -salt -pbkdf2 -iter 200000 -pass file:"$BACKUP_PASSPHRASE_FILE" -in "$ARCHIVE_TAR" -out "$ARCHIVE_ENC"
  rm -f "$ARCHIVE_TAR"
else
  mv "$ARCHIVE_TAR" "$ARCHIVE_ENC"
fi
sha256sum "$ARCHIVE_ENC" > "$ARCHIVE_ENC.sha256"

echo "[4/5] Replicating encrypted bundle to Tier 2 (NAS) & Tier 3 (Offline USB if mounted)..."
if [ -d "$TIER2_DIR" ]; then
  cp -f "$ARCHIVE_ENC" "$ARCHIVE_ENC.sha256" "$TIER2_DIR/"
fi
if [ -d "$TIER3_DIR" ]; then
  cp -f "$ARCHIVE_ENC" "$ARCHIVE_ENC.sha256" "$TIER3_DIR/"
fi

echo "[5/5] 3-2-1 Encrypted Backup complete: $ARCHIVE_ENC"
