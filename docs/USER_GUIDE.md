# BetterDoc DMS — User Operations Manual

## 1. Classification Banner, Compartments & Zero-Leak Search

- Your active clearance rank and compartments appear permanently in the **Classification Banner** at the top of every page.
- Documents classified above your clearance rank or assigned to compartments you do not hold never appear in the Vault (`/documents`), Correspondence Registry (`/registry`), Global Search, Document Previews, Print Queues, Notifications, or AI RAG citations.
- Under `strict` write-down profiles (Defence & Financial), you cannot assign a classification below your clearance level without explicit Two-Person approval.

## 2. Document Capture, Co-Editing & Redaction

- **Chunked Resumable Uploads:** Upload large files (`200MB+`) reliably over unstable LAN links; interrupted uploads resume from the last confirmed chunk without re-uploading completed parts.
- **Malware & CDR:** Every uploaded file passes antivirus scanning and Content Disarm & Reconstruction (`PDF/A`) before entering the encrypted vault.
- **Collabora Online & Destructive Redaction:** Open Office/PDF documents via `/documents/{id}/edit-office`, add annotations/comments, or apply permanent **Destructive PDF Redaction** (rasterizing & purging underlying text layers).

## 3. Digital Signatures (PAdES / Envelope Workflows)

- Create single or sequential/parallel **Signature Envelopes** (`/documents/{id}`).
- Sign with HSM-managed server certificates (`betterdoc-signer`) or workstation USB smartcards (`betterdoc_local_agent`), embedding QR + verification URL (`/verify/{token}`) for offline validation in Poppler `pdfsig`, LibreOffice, and Adobe Reader.

## 4. Workflows, AI Assistant & Print/Scan

- **Workflow Inbox (`/workflows/inbox`):** Approve, reject, or delegate visual BPMN-lite task assignments with automatic SLA escalation.
- **Local Air-Gapped AI (`/ai`):** Query documents via pre-filtered Hybrid RAG (`pgvector` + FTS), process IDP invoice/letter extractions through Human-in-the-Loop review, and monitor anomaly alerts. Session High-Water-Mark blocks exporting higher-rank AI responses into lower-rank documents.
- **Secure PIN/RFID Printing & Offline Scanning (`/print-scan`):** Release watermarked print jobs at secure printers using 6-digit PINs (3-attempt lockout) or RFID badges. Queue camera/scanner uploads offline via IndexedDB (`PWA`) and reconcile automatically on reconnect.

## 5. Finance, Contracts & HR Personnel Vault

- **AP Automation & ZIMRA FDMS (`/finance`):** Auto-match invoices (2-way or 3-way PO + GRN + Invoice), flag quantity/price variances (requiring Approver override), prevent duplicate vendor invoices, download UBL 2.1 XML, and submit ZIMRA FDMS fiscal receipts.
- **Contracts (`/contracts`):** Track renewal notice periods, mark obligations fulfilled, and search the standard clause library.
- **HR Vault (`/hr`):** Employees can access their own Payslips & Contracts; shielded disciplinary/medical records (`hr_restricted_from_subject`) are invisible to the subject across all views and search/AI queries.
