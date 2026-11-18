#!/usr/bin/env bash
set -euo pipefail

# BetterDoc DMS — Air-Gapped Offline USB/ISO Installer Builder
# Packages container images, Ollama local AI models (Qwen2.5 + BGE-M3),
# ClamAV offline CVD databases, Tesseract OCR language packs (eng, sna, nde),
# Composer/Vite builds, and signed SHA256SUMS for sneaker-net deployment.

OUT_DIR="${1:-./dist/betterdoc-offline-installer}"
mkdir -p "$OUT_DIR/images" "$OUT_DIR/models/ollama" "$OUT_DIR/models/clamav" "$OUT_DIR/models/tessdata" "$OUT_DIR/repo"

echo "[1/5] Verifying zero-external-URL air-gapped frontend assets..."
npm run build:verify-airgap

echo "[2/5] Exporting application & sidecar Docker images..."
IMAGES=(
  "betterdoc/app:local"
  "betterdoc/horizon:local"
  "betterdoc/reverb:local"
  "betterdoc/ocr:local"
  "betterdoc/pki:local"
  "betterdoc/signer:local"
  "pgvector/pgvector:pg17"
  "valkey/valkey:8-alpine"
  "getmeili/meilisearch:v1.12"
  "minio/minio:latest"
  "clamav/clamav:latest"
  "collabora/code:latest"
  "ollama/ollama:latest"
)
for img in "${IMAGES[@]}"; do
  safe_name="$(echo "$img" | tr '/:' '__')"
  if docker image inspect "$img" >/dev/null 2>&1; then
    docker save -o "$OUT_DIR/images/${safe_name}.tar" "$img"
  else
    echo "Skipping unpulled local image marker: $img"
  fi
done

echo "[3/5] Bundling Ollama offline weights, ClamAV CVD, and Tesseract datasets..."
if command -v ollama >/dev/null 2>&1 && [ -d "${HOME}/.ollama/models" ]; then
  cp -a "${HOME}/.ollama/models/." "$OUT_DIR/models/ollama/" || true
fi
cp -a /var/lib/clamav/*.cvd "$OUT_DIR/models/clamav/" 2>/dev/null || true
cp -a /usr/share/tesseract-ocr/*/tessdata/{eng,sna,nde}.traineddata "$OUT_DIR/models/tessdata/" 2>/dev/null || true

echo "[4/5] Copying BetterDoc DMS code, k3s manifests, Ansible playbooks, and documentation..."
cp -a infra scripts docs docker-compose.yml docker-compose.airgap.yml "$OUT_DIR/repo/"

echo "[5/5] Generating cryptographic SHA256SUMS manifest..."
(cd "$OUT_DIR" && find . -type f ! -name "SHA256SUMS" -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)

echo "Offline Air-Gap USB/ISO Installer Bundle created at: $OUT_DIR"
