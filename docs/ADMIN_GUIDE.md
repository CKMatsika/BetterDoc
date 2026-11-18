# BetterDoc DMS — Administrator & Deployment Guide

## 1. Deployment Modes

BetterDoc DMS ships as one unified codebase configured via `.env` and Organization Configuration Profiles (`config/profiles/*.yaml`):

| Hosting Mode (`BETTERDOC_HOSTING_MODE`) | Egress Policy | Sync Gateway | Intended Profile |
| :--- | :--- | :--- | :--- |
| `air_gapped_on_prem` (`BETTERDOC_AIR_GAPPED=true`) | All public internet egress blocked at PHP (`BetterDocHttpClientGuard`), Docker (`internal: true`), and `k3s` (`NetworkPolicy`) | Hard-disabled (`AirGapViolationException`) | `defence`, `financial` |
| `lan_with_cloud_backup` | LAN default, optional outbound targets allowlisted via `BETTERDOC_EXTERNAL_ALLOWLIST` | Optional outbox sync with `max_classification_rank` ceiling | `corporate`, hybrid offices |
| `full_cloud` | Multi-tenant SaaS / Laravel Cloud | Allowed strictly for `cloud_sync_allowed=true` clearance tiers | Commercial SaaS |

---

## 2. Container & Multi-Node `k3s` Deployment

### Docker Compose (Single Server / Air-Gapped LAN)

```bash
docker compose -f docker-compose.yml -f docker-compose.airgap.yml up -d
docker compose exec app php artisan migrate --database=pgsql_admin --force
docker compose exec app php artisan db:seed --force
```

### Offline USB / ISO Installer Builder

On an online staging workstation, build the self-contained air-gapped USB bundle containing Docker image tarballs, compiled Vite assets (verified external-URL-free), Ollama models, ClamAV CVD files, and Tesseract traineddata:

```bash
./scripts/build-offline-bundle.sh /mnt/betterdoc_usb
```

Then deploy onto multi-node Ubuntu / RHEL servers using Ansible (`infra/ansible/site.yml`) and `k3s` Kustomize manifests (`infra/k3s/base`):

```bash
ansible-playbook -i infra/ansible/inventory.ini infra/ansible/site.yml
```

---

## 3. Organization Profiles & RBAC

- **Declarative Profile Switching:** Visit `/admin/profiles` or run `php artisan betterdoc:profile:apply <slug> <defence|financial|corporate>`.
- **Zero-Bypass PostgreSQL Least Privilege:** migrations run via `pgsql_admin`, while runtime web/worker requests connect strictly via `pgsql` (`betterdoc_app`, `NOBYPASSRLS`) with `SetTenantAndUserRlsContext` setting session GUCs.

---

## 4. Internal PKI, HSM & Print/Scan Station Pairing

1. Bootstrap Tenant Root CA at `/pki/certificates` (`InternalCaService`).
2. Issue user PAdES certificates in `hsm_managed` mode or `client_smartcard` PKCS#11 custody (`betterdoc-signer`).
3. Pair desktop TWAIN/WIA & smartcard bridges via `/print-scan` (`Initiate Pairing` -> `betterdoc_local_agent.py`) and manage CUPS/IPPS printers with `max_classification_rank` print ceilings.

---

## 5. Monitoring, Scheduled Jobs & Maintenance Commands

| Command | Schedule | Purpose |
| :--- | :--- | :--- |
| `betterdoc:audit:verify` | Every 15m / Daily | Verifies SHA-256 audit hash chain and checkpoint HMACs across all tenants |
| `betterdoc:contracts:scan-alerts` | Daily | Flags expiring contracts & overdue obligations and notifies owners |
| `betterdoc:zimra:sync-fiscal` | Every 5m (when connected) | Replays `queued_offline` ZIMRA FDMS invoices cleanly |
| `betterdoc:backup:create --verify-restore` | Nightly `02:00 UTC` | Creates 3-2-1 encrypted snapshot and verifies `< 2h` clean restore drill |
| `betterdoc:sync:process` | Every 5m (non-air-gapped) | Replicates cleared outbox payloads up to classification ceiling |
