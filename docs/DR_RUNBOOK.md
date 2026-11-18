# BetterDoc DMS — Disaster Recovery Runbook (< 2 Hour RTO)

## 1. 3-2-1 Encrypted Backup Topology

| Tier | Medium | Contents | Encryption |
| :--- | :--- | :--- | :--- |
| **Tier 1 (Local Fast)** | Dedicated NVMe volume | PostgreSQL base backup + continuous WAL segments (`wal_level=replica`), local MinIO encrypted vault, signed `backup_manifest.json` | Libsodium XChaCha20-Poly1305 + OpenSSL AES-256-GCM |
| **Tier 2 (Off-Site LAN / NAS)** | Synology / TrueNAS SMB/S3 replica | Nightly encrypted archives + SHA-256 manifests | Same offline wrapped DEK + passphrase keyfile |
| **Tier 3 (Offline Air-Gapped)** | Encrypted USB / Removable SSD stored in physical safe | Weekly full 3-2-1 bundle + offline installer ISO/USB + HSM/KEK escrow split | Shamir/offline passphrase split |

## 2. Recovery Objectives

- **Target RPO:** `<= 15 minutes` (continuous WAL archiving + sync outbox snapshots).
- **Target RTO:** `< 2 hours` (`7,200 seconds`), verified nightly by automated drill.

## 3. Timed Clean-Machine Restore Procedure

1. Provision replacement hardware with OS + container runtime (or deploy `infra/ansible/site.yml` using the offline USB installer).
2. Restore HSM/KEK environment key material (`.env` `BETTERDOC_MASTER_KEK_B64` / `APP_KEY` / PKCS#11 HSM slot PIN).
3. Run the automated timed clean-machine restore script:
   ```bash
   ./scripts/restore-drill.sh /mnt/offline_usb/betterdoc_backups/betterdoc-backup-LATEST.tar.zst.enc
   ```
4. Verify inside the admin dashboard (`/admin/operations` -> **Run Timed Restore Drill**) or CLI:
   ```bash
   php artisan betterdoc:backup:create --tier=tier1_local --verify-restore
   php artisan betterdoc:audit:verify
   ```
5. Confirm all tenant audit chains return `[PASS]`, MinIO object hashes match `backup_manifest.json`, and elapsed drill time is recorded `< 7,200,000 ms`.
