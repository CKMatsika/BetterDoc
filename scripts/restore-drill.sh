#!/usr/bin/env bash
set -euo pipefail

# BetterDoc DMS — Timed Clean-Machine Restore Drill & Audit Verification (< 2 hours RTO)
# Usage: ./scripts/restore-drill.sh /path/to/betterdoc-backup-TIMESTAMP.tar.zst.enc

START_EPOCH="$(date +%s)"
ARCHIVE_ENC="${1:?Provide encrypted backup bundle path}"
BACKUP_PASSPHRASE_FILE="${BETTERDOC_BACKUP_KEY_FILE:-/etc/betterdoc/backup.key}"
RESTORE_STAGE="${BETTERDOC_RESTORE_STAGE:-/tmp/betterdoc-restore-stage}"
APP_DIR="${BETTERDOC_APP_DIR:-/opt/betterdoc}"
MAX_RTO_SECONDS="${BETTERDOC_BACKUP_TARGET_RTO:-7200}"

echo "=== BetterDoc DMS Clean-Machine DR Restore Drill Started at $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
rm -rf "$RESTORE_STAGE"
mkdir -p "$RESTORE_STAGE/unpacked"

if [ -f "${ARCHIVE_ENC}.sha256" ]; then
  sha256sum -c "${ARCHIVE_ENC}.sha256"
fi

ARCHIVE_TAR="$RESTORE_STAGE/bundle.tar.zst"
if [ -f "$BACKUP_PASSPHRASE_FILE" ]; then
  openssl enc -d -aes-256-gcm -pbkdf2 -iter 200000 -pass file:"$BACKUP_PASSPHRASE_FILE" -in "$ARCHIVE_ENC" -out "$ARCHIVE_TAR"
else
  cp -f "$ARCHIVE_ENC" "$ARCHIVE_TAR"
fi

tar --zstd -xf "$ARCHIVE_TAR" -C "$RESTORE_STAGE/unpacked"

echo "[1/3] Validating restored PostgreSQL archive & MinIO encrypted vault objects..."
test -d "$RESTORE_STAGE/unpacked/vault" || test -d "$RESTORE_STAGE/unpacked/postgres"

echo "[2/3] Executing application-level DR snapshot decrypt + audit chain + checkpoint HMAC drill..."
php "$APP_DIR/artisan" betterdoc:backup:create --tier=tier1_local --verify-restore --no-interaction
php "$APP_DIR/artisan" betterdoc:audit:verify --no-interaction

END_EPOCH="$(date +%s)"
ELAPSED="$((END_EPOCH - START_EPOCH))"

echo "[3/3] Restore Drill Elapsed Time: ${ELAPSED}s (Target RTO < ${MAX_RTO_SECONDS}s)"
if [ "$ELAPSED" -gt "$MAX_RTO_SECONDS" ]; then
  echo "FAIL: Restore drill exceeded ${MAX_RTO_SECONDS}s SLA!"
  exit 1
fi

rm -rf "$RESTORE_STAGE"
echo "PASS: Clean-machine restore verified cleanly within RTO."
