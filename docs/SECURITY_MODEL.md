# BetterDoc DMS — Security Architecture & Threat Controls

## 1. Bell-LaPadula Mandatory Access Control & PostgreSQL RLS

Every query executed on runtime web/API/worker requests uses the unprivileged PostgreSQL role `betterdoc_app` (`NOBYPASSRLS`). Middleware `SetTenantAndUserRlsContext` injects transaction-scoped PostgreSQL settings (`betterdoc.tenant_id`, `betterdoc.user_id`, `betterdoc.clearance_rank`, `betterdoc.compartment_ids`, `betterdoc.org_unit_path`, `betterdoc.break_glass_doc_ids`) inside `FORCE ROW LEVEL SECURITY` policies.

- **No Read-Up:** Rows where `classification_rank > betterdoc.clearance_rank` or missing required compartments are filtered at the PostgreSQL row layer before reaching Laravel or Eloquent.
- **Strict No Write-Down:** Higher-clearance sessions cannot reclassify/export/copy content below their High-Water-Mark without Two-Person Integrity workflow (`TwoPersonRequest`).
- **HR Subject Isolation:** Documents with `hr_restricted_from_subject = true` check `hr_subject_user_id <> betterdoc.user_id` inside `documents` RLS so subjects cannot discover their own shielded inquiries via REST endpoints, search, exports, or vector RAG.

## 2. Fail-Closed Antivirus, CDR & Envelope Encryption

- **Antivirus & Magic-Byte Inspection:** Quarantine files fail closed on ClamAV/malware mismatch before reaching permanent object storage.
- **Content Disarm & Reconstruction (CDR):** Active scripts, macros, and embedded threats are stripped (`PDF/A` normalization).
- **Streaming XChaCha20-Poly1305 Envelope Encryption:** `EnvelopeEncryptionService` encrypts every version and backup archive in 64KB AEAD chunks with a fresh random DEK wrapped by Master KEK (`betterdoc-kek-v1` / PKCS#11 HSM).

## 3. Tamper-Evident Audit Ledger & Air-Gap Egress Protection

- **Append-Only Partitioned Ledger:** `audit_events` denies `UPDATE`/`DELETE` via triggers/permissions and chains every row with `SHA-256(prev_hash | canonical_payload)`. Periodic `AuditCheckpoint` records seal `first_seq_id..last_seq_id` with HMAC-SHA256 signatures.
- **Three-Layer Air-Gap Enforcement:**
  1. Application HTTP client middleware (`BetterDocHttpClientGuard`) blocks unauthorized outbound hosts with `AirGapViolationException`.
  2. Vite air-gap gate (`scripts/verify-airgap-build.mjs`) guarantees `0` external CDN/font/API URLs in frontend bundles.
  3. Kubernetes `NetworkPolicy` (`infra/k3s/base/networkpolicy-airgap.yaml`) and Docker `internal: true` networks block non-RFC1918 egress at runtime.
