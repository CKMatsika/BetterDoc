#!/usr/bin/env bash
set -euo pipefail

PKI_DIR="${SENTINEL_PKI_DIR:-/var/lib/sentinel-pki}"
HSM_TOKEN="${SENTINEL_HSM_TOKEN:-sentinel-ca}"
SOFTHSM_PIN="${SENTINEL_HSM_PIN:-123456}"
SOFTHSM_SO_PIN="${SENTINEL_HSM_SO_PIN:-654321}"

mkdir -p "${PKI_DIR}/root" "${PKI_DIR}/intermediate" "${PKI_DIR}/crl"

if command -v softhsm2-util >/dev/null 2>&1; then
    if ! softhsm2-util --show-slots | grep -q "${HSM_TOKEN}"; then
        softhsm2-util --init-token --slot 0 --label "${HSM_TOKEN}" \
            --so-pin "${SOFTHSM_SO_PIN}" --pin "${SOFTHSM_PIN}"
    fi
fi

if [ ! -f "${PKI_DIR}/root/ca.crt" ]; then
    openssl genrsa -aes256 -passout "pass:${SOFTHSM_SO_PIN}" -out "${PKI_DIR}/root/ca.key.enc" 4096
    chmod 400 "${PKI_DIR}/root/ca.key.enc"
    openssl req -x509 -new -key "${PKI_DIR}/root/ca.key.enc" -passin "pass:${SOFTHSM_SO_PIN}" \
        -sha256 -days 3650 \
        -subj "/O=SENTINEL DMS/OU=Offline Trust Anchor/CN=SENTINEL Offline Root CA" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:1" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -out "${PKI_DIR}/root/ca.crt"
    echo "[sentinel-pki] Offline Root CA initialized at ${PKI_DIR}/root/ca.crt"
fi
