# BetterDoc DMS — Complete User Training Manual

**Edition:** Enterprise & Sovereign LAN/Cloud Edition (Zimbabwe & Regional Compliance)  
**Audience:** All Users — Clerks, Officers, Approvers, Finance/AP Teams, HR Officers, Records Officers, Auditors, and System Administrators  
**Print / PDF Tip:** Open this file in VS Code, Typora, Obsidian, or GitHub and choose **Print → Save as PDF** (or run `pandoc BETTERDOC_USER_TRAINING_MANUAL.md -o BetterDoc_Training_Manual.pdf`) for a clean, paginated training binder.

---

## Table of Contents

1. [Quick-Start Role Matrix](#1-quick-start-role-matrix)
2. [Getting Started: Sign In, Password Policy & MFA](#2-getting-started-sign-in-password-policy--mfa)
3. [Document Vault & Encrypted File Management](#3-document-vault--encrypted-file-management)
4. [Correspondence Registry (Incoming / Outgoing Mail)](#4-correspondence-registry-incoming--outgoing-mail)
5. [Workflows, Approvals & Break-Glass Access](#5-workflows-approvals--break-glass-access)
6. [Templates & Document Merge](#6-templates--document-merge)
7. [Local AI Assistant, OCR, IDP & Semantic Search](#7-local-ai-assistant-ocr-idp--semantic-search)
8. [Print, Scan, Barcode Splitting & Workstation Local Agent](#8-print-scan-barcode-splitting--workstation-local-agent)
9. [Mobile Snap (PWA) & Flutter Mobile Companion App](#9-mobile-snap-pwa--flutter-mobile-companion-app)
10. [Accounts Payable, ZIMRA ITF263 (30% WHT), FDMS QR, PRAZ & RBZ FX](#10-accounts-payable-zimra-itf263-30-wht-fdms-qr-praz--rbz-fx)
11. [External Client / Broker / Supplier Portal & WhatsApp Business Intake](#11-external-client--broker--supplier-portal--whatsapp-business-intake)
12. [Contracts & Obligation Tracking](#12-contracts--obligation-tracking)
13. [HR Personnel Vault & Belina Payroll Sync](#13-hr-personnel-vault--belina-payroll-sync)
14. [Governance, Retention, Legal Hold & 7-Year WORM Lock](#14-governance-retention-legal-hold--7-year-worm-lock)
15. [PKI Certificates, PAdES Digital Signatures & Public Verification](#15-pki-certificates-pades-digital-signatures--public-verification)
16. [Immutable Audit Ledger & Compliance Packs](#16-immutable-audit-ledger--compliance-packs)
17. [ERP Integrations (Xero, NetSuite, Sage Pastel & Windows LAN Bridge)](#17-erp-integrations-xero-netsuite-sage-pastel--windows-lan-bridge)
18. [System Administration: Users, Roles, Branding & Backup/DR](#18-system-administration-users-roles-branding--backupdr)
19. [Troubleshooting & Security Best Practices](#19-troubleshooting--security-best-practices)

---

## 1. Quick-Start Role Matrix

| Role | Primary Modules Used | Security Clearance & Notes |
| :--- | :--- | :--- |
| **Clerk / Officer** | Document Vault, Registry, Mobile Snap (PWA/Flutter), Print & Scan, Templates | Can only view/upload documents at or below their assigned **Clearance Rank**. |
| **Approver / Manager** | Workflow Inbox, Approvals & Break-Glass, PAdES Signing, AP Invoice Approval | Can approve variance overrides (with mandatory written justification) and sign documents. |
| **Finance / AP Officer** | AP & ZIMRA Finance, Mobile Snap → ERP, Integrations & ERP (CSV/Sync) | Manages Vendors, ITF263 Tax Clearance, 30% WHT, POs, GRNs, FDMS QR, and RBZ ZWG/USD rates. |
| **HR Officer** | HR Personnel Vault, Belina CSV Import/Export, Templates | Accesses confidential employee files isolated by tenant & HR compartment. |
| **Records Officer** | Governance & WORM, Correspondence Registry, Print & Scan Sources | Assigns retention schedules, Legal Holds, 7-year WORM locks, and Two-Person disposition reviews. |
| **Auditor** | Audit Ledger, Compliance Packs, Read-Only Inspections | Verifies cryptographic SHA-256 hash chain integrity and exports SIEM/compliance bundles. |
| **System Admin** | Users & Roles, Integrations & ERP, Branding & Portal, Sync/Backup/DR, Profiles | Full tenant administration, API token issuance, LAN Bridge pairing, and DR drills. |

---

## 2. Getting Started: Sign In, Password Policy & MFA

### 2.1 Signing In
1. Open your organisation's BetterDoc URL (e.g. `https://dms.yourcompany.co.zw` or your LAN address `http://192.168.x.x:8090`).
2. Enter your **Work Email** and **Password**.
3. Passwords must be at least **12 characters** and contain uppercase, lowercase, numbers, and symbols.
4. After 5 failed login attempts, the account is temporarily rate-limited to protect against brute-force attacks.

### 2.2 Setting Up Multi-Factor Authentication (MFA)
1. In the left sidebar, click **MFA & Devices** (`/security/mfa/setup`).
2. Scan the QR code using Google Authenticator, Microsoft Authenticator, or Authy (works 100% offline).
3. Enter the 6-digit code shown on your phone and click **Enable MFA**.
4. Save your backup recovery codes in a safe place.

### 2.3 Understanding Security Clearances (Top Banner)
- The coloured banner at the very top of every screen shows your **active Security Clearance** (e.g., *Internal*, *Confidential*, *Secret*) and whether the system is in **Air-Gapped LAN Mode**.
- BetterDoc enforces **Mandatory Access Control (Bell-LaPadula)**: you can never view a file classified above your clearance rank, and you cannot accidentally leak a high-clearance document into a lower-clearance cabinet.

---

## 3. Document Vault & Encrypted File Management

**Route:** `/documents` (**Document Vault**)

### 3.1 Uploading a Document
1. Click **Upload Document** (supports drag-and-drop and resumable chunked uploads for large PDFs/scans).
2. Select the **Cabinet**, **Security Classification**, and enter a clear **Title** (and optional Policy/Claim number for insurance files).
3. Click **Upload**. BetterDoc automatically runs:
   - **ClamAV Malware Scan** (infected files are quarantined and rejected),
   - **OCR Text Extraction** (makes scanned PDFs full-text searchable),
   - **XChaCha20-Poly1305 Envelope Encryption** before saving to storage.

### 3.2 Managing Versions, Redaction & Editing
1. Click any document to open its **Document Detail** view.
2. **Check Out / Check In:** Click **Check Out** before editing to lock the file so colleagues cannot overwrite your changes. Upload the new file via **Check In** to create a new major/minor version while preserving full version history.
3. **Collabora Online Editing:** Click **Edit in Office** (if enabled) to edit DOCX/XLSX/PPTX directly in the browser.
4. **PDF Redaction:** Open the **Redaction** tool to permanently burn black redaction boxes over sensitive PII (National IDs, bank accounts, phone numbers) and generate a clean redacted copy.
5. **Comments & Mentions:** Use the side panel to post `@mentions` — tagged colleagues receive real-time Reverb notifications.

---

## 4. Correspondence Registry (Incoming / Outgoing Mail)

**Route:** `/registry` (**Correspondence Registry**)

1. Click **Register Correspondence** and choose **Incoming** or **Outgoing**.
2. Enter the **Sender / Recipient**, **Subject**, **Reference Number**, **Priority** (Normal, Urgent, Immediate), and attach the scanned letter.
3. BetterDoc assigns a sequential **Registry Number** (e.g., `REG/2026/00142`) and tracks SLA due dates.
4. Use **Minute / Route** to assign action officers with a deadline and track completion status.

---

## 5. Workflows, Approvals & Break-Glass Access

### 5.1 Acting on Your Workflow Inbox
**Route:** `/workflows/inbox`
1. The amber badge in the sidebar shows how many tasks are waiting for you.
2. Click a task to inspect the attached document and any prior comments.
3. Click **Approve**, **Reject** (comment required), or **Request Changes**. Every decision is cryptographically logged.

### 5.2 Designing a Reusable Workflow (Managers / Admins)
**Route:** `/workflows`
1. Click **New Workflow Definition**.
2. Add sequential or parallel approval stages (e.g., *Department Head → Financial Controller → Managing Director*) and SLA hours per stage.
3. Save and start the workflow from any document in the Vault.

### 5.3 Two-Person Rule & Emergency Break-Glass
**Route:** `/approvals`
- High-risk actions (such as destroying a record at end of retention or exporting classified packs) require **Two-Person Approval** — one officer initiates, a second distinct officer approves.
- In an emergency, an authorised officer can request **Break-Glass Access** with a written justification; access is time-limited and triggers an immediate high-priority audit alert.

---

## 6. Templates & Document Merge

**Route:** `/templates`

1. Upload a `.docx` template containing merge placeholders such as `{{client_name}}`, `{{policy_number}}`, or `{{contract_value}}`.
2. To generate a document, select the template, fill in the form fields, choose the target cabinet and classification, and click **Generate Document**.
3. BetterDoc merges the fields, converts to PDF if selected, encrypts it, and files it straight into the Vault.

---

## 7. Local AI Assistant, OCR, IDP & Semantic Search

**Route:** `/ai` (**AI Assistant & IDP**)

1. **Ask Your Vault (RAG Q&A):** Type a plain-English question (e.g. *"What is the termination notice period in the ZimCorp solar contract?"*). BetterDoc searches **only documents you have clearance to view** and returns an answer with clickable citations.
2. **Intelligent Document Processing (IDP):** Click **Run IDP Extraction** on any invoice, contract, or ID document. BetterDoc classifies the document type and extracts structured fields (`vendor_name`, `invoice_number`, `total_amount`, `zimra_tin`, `expiry_date`, `national_id_number`).
3. **Human-in-the-Loop Review:** Correct any field if needed and click **Approve Extraction** — verified fields are saved as searchable document metadata.
4. **Anomaly Alerts:** Review AI-flagged duplicate invoices or unusual access spikes in the Anomaly panel.

---

## 8. Print, Scan, Barcode Splitting & Workstation Local Agent

**Route:** `/print-scan` (**Print, Scan & Agent**)

### 8.1 Secure Pull-Printing (PIN / RFID Release)
1. Open the **Print** tab, select a document, a printer, and your **Cost Centre**, then click **Queue Print Job**.
2. BetterDoc checks that the printer's **Max Classification Rank** is high enough (preventing Secret documents from printing on public hallway printers) and applies a dynamic **user/timestamp watermark**.
3. Walk to the printer and enter your **Release PIN** (or tap your **RFID badge**) to release the pages. *(3 wrong PIN attempts automatically lock the job.)*

### 8.2 Bulk Scanning & Barcode Separator Sheets
1. Open the **Scan** tab and click **Generate Barcode Separator Sheet** to print QR/barcode cover pages.
2. Place separator sheets between different documents in your office scanner feeder and scan the entire stack as one PDF.
3. Upload the batch (or scan to a configured **Hot Folder**, **Mail-In Dropbox**, or **eSCL/AirScan Network MFP**).
4. BetterDoc automatically **deskews, crops, splits** the stack at every barcode separator, runs OCR + IDP, and files each document into its own cabinet.

### 8.3 Pairing a Desktop USB Scanner (Workstation Local Agent)
1. Open the **Local Agent** tab, enter your PC name (e.g., `Registry-Desk-PC-01`), and click **Generate Pairing Code**.
2. Enter the 8-character code in the BetterDoc Local Agent tray app on your Windows/Linux workstation to link your USB TWAIN/WIA scanner or PKCS#11 smartcard reader.

---

## 9. Mobile Snap (PWA) & Flutter Mobile Companion App

### 9.1 Using Mobile Snap (PWA) in Your Phone Browser
**Route:** `/mobile` (**Mobile Snap (PWA)**)
1. Open `/mobile` on Android Chrome or iOS Safari and tap **Install App** (or *Add to Home Screen*) for a 1-tap app icon.
2. **Snap & Process:**
   - Tap the big **Camera** area to take a photo of a receipt, supplier invoice, delivery note, or ID.
   - Choose the **Target Cabinet** and **Security Classification**, then tap **Upload, Scan & Detect with AI**.
3. **Automatic Invoice-to-ERP Flow:**
   - If BetterDoc AI detects an **Accounting Invoice**, it automatically opens the **Invoice Confirmation Card** pre-filled with Supplier, Invoice #, Date, Currency (`USD`/`ZWG`/`ZAR`), Subtotal, VAT, and Total.
   - Choose your **Accounting ERP Destination** (*Xero*, *Oracle NetSuite*, *Sage Pastel Windows LAN Bridge*, or *BetterDoc AP Ledger Only*).
   - Tick **Archive Photo After ERP Push** (and optionally **Apply 7-Year WORM Lock**) and tap **Post to Accounting ERP & Archive**.
4. **Working Offline:** If you lose signal or ZESA power cuts Wi-Fi, tap **Queue Photo Offline**. Your photo is saved in your phone's local encrypted IndexedDB queue; tap **Offline → Sync All** once back online.

### 9.2 Using the Native Flutter Mobile Companion App
1. Open **BetterDoc Mobile** on Android or iOS.
2. Enter your **BetterDoc Server URL** (LAN IP or Cloud HTTPS domain), your **Work Email**, and **Password**, then tap **Sign In to Vault**.
3. Use the bottom tabs:
   - **Snap & ERP:** Take Photo or pick from Gallery → AI detects invoice → confirm figures & ERP target → see instant **30% ZIMRA WHT** notice (if supplier lacks valid ITF263) and archive confirmation.
   - **Offline:** View and sync photos captured without internet.
   - **My Vault:** Search documents by title, doc number, policy/claim number, or archive with one tap.

---

## 10. Accounts Payable, ZIMRA ITF263 (30% WHT), FDMS QR, PRAZ & RBZ FX

**Route:** `/finance` (**AP & ZIMRA Finance**)

### 10.1 Managing Vendors & Statutory Compliance Packs (ITF263, PRAZ, NSSA)
1. Register suppliers in the **Vendors** tab (Code, Legal Name, ZIMRA TIN, VAT Number, Currency).
2. Switch to the **ZIMRA ITF263, PRAZ & RBZ FX** tab:
   - Select a vendor and record their **ZIMRA ITF263 Tax Clearance Certificate #**, **Expiry Date**, and link the scanned PDF from the Vault.
   - Also record **PRAZ Supplier Registration #/Expiry** and **NSSA Clearance #/Expiry**.
   - Each vendor card displays a live **Statutory Pack Completeness %** bar and a red **`30% WHT Required`** or green **`ITF263 Valid`** badge.

### 10.2 Automatic 30% ZIMRA Withholding Tax (WHT) Guard
- Whenever an AP invoice is captured (via Finance UI, Mobile PWA, or Flutter), BetterDoc automatically checks the vendor's ITF263 status on the invoice date:
  - **Valid ITF263:** `0%` WHT applied.
  - **Missing or Expired ITF263:** BetterDoc automatically calculates **30% WHT** on the subtotal, displays the WHT deduction and **Net Payable**, and flags it in the audit trail to protect your company from ZIMRA penalties.

### 10.3 Recording Daily RBZ ZWG / ZAR / USD Exchange Rates
1. On the **ZIMRA ITF263, PRAZ & RBZ FX** tab, select the currency pair (e.g., `USD / ZWG`), enter the official **RBZ Interbank Rate**, effective date, and click **Save Rate**.
2. Every non-USD invoice automatically locks the applicable rate on capture and stores the **USD Equivalent** for reporting and ERP export.

### 10.4 2-Way / 3-Way Matching, UBL 2.1 XML & ZIMRA FDMS QR Verification
1. Create a **Purchase Order (PO)** and record **Goods Received Notes (GRNs)** under the **POs** tab.
2. Capture the supplier's invoice linked to the PO. BetterDoc checks for **duplicate invoice numbers** (blocking duplicates automatically) and flags any quantity/price variance.
3. **Verify ZIMRA FDMS QR Receipt:** Paste or scan the supplier's fiscal receipt QR URL (`https://fdms.zimra.co.zw/...`) into the invoice row and click **Verify QR**. BetterDoc parses the Device ID, Receipt Global Number, Date, and Verification Code, and **blocks duplicate fiscal receipts** across the organisation.
4. Click **Approve** (Approver/Admin role; variance overrides require a written justification) and optionally click **UBL XML** or **Fiscalize ZIMRA**.

---

## 11. External Client / Broker / Supplier Portal & WhatsApp Business Intake

**Route:** `/external-portal` (**Client Portal & WhatsApp**)

### 11.1 Sending a Magic-Link Upload or E-Sign Request (Zero-Login Portal)
1. Click the **Magic-Link Portal** tab.
2. Enter the **Request Title**, **Recipient Name**, Phone/Email, **Mode** (*Upload Only*, *E-Sign Only*, or *Upload + E-Sign*), target **Cabinet**, and optional **Policy # / Claim #**.
3. Keep **Require 6-Digit Security PIN** checked for sensitive files and click **Generate Magic Link**.
4. Copy the one-time link and share it with your client, broker, or supplier (and send the 6-digit PIN separately via SMS/WhatsApp).
5. The external recipient opens the branded link on their phone, enters the PIN, uploads photos/PDFs (automatically virus-scanned and tagged with their Policy/Claim #), and/or **electronically signs** with their National ID to create a tamper-evident **SHA-256 cryptographic seal**.
6. Click **Revoke** at any time to immediately disable a link.

### 11.2 WhatsApp Business Intake & Executive Approval Alerts
1. Click the **WhatsApp Business** tab and copy your tenant's **Webhook Endpoint** (`/api/v1/webhooks/whatsapp`).
2. Enter your Meta **Phone Number ID**, **App Secret** (used for strict `X-Hub-Signature-256` HMAC verification), **Permanent Access Token**, and default Cabinet/Clearance, then click **Save WhatsApp Channel**.
3. Copy the generated **Verify Token** into your Meta Developer Console.
4. Whenever a client or staff member sends a photo/PDF via WhatsApp, BetterDoc automatically ingests it, runs OCR/IDP, extracts any `POL-*` or `CLM-*` reference from the caption, and files it in the Vault.
5. Use **Send WhatsApp Approval / Portal Alert** to notify executives or clients directly on WhatsApp (automatically respecting Air-Gap settings).

---

## 12. Contracts & Obligation Tracking

**Route:** `/contracts`

1. Click **Register Contract**, link the Vault PDF, and enter the Counterparty, Contract Value, Effective Date, and Expiry/Renewal Date.
2. Add **Key Clauses** and **Obligations** (e.g., quarterly SLA review, insurance certificate renewal) with owners and due dates.
3. Click **Scan Alerts** to trigger automated renewal and overdue obligation warnings before contracts auto-renew or lapse.

---

## 13. HR Personnel Vault & Belina Payroll Sync

**Route:** `/hr` (**HR Personnel Vault**)

1. Click **Add Employee** to create a confidential personnel file (Employee Number, Full Name, National ID, Department, Job Title).
2. Upload HR records (Offer Letters, Contracts, NSSA/ZIMRA forms, Disciplinary records, Leave forms) directly into the employee's restricted folder.
3. For bulk onboarding from **Belina Payroll**, use **Integrations & ERP → Belina CSV Import/Export** (see Section 17).

---

## 14. Governance, Retention, Legal Hold & 7-Year WORM Lock

**Route:** `/governance` (**Governance & WORM**)

1. **Retention Schedules:** Assign statutory retention periods (e.g., *7 Years — ZIMRA Tax Records*, *10 Years — Insurance Claims*).
2. **Legal Hold:** Toggle **Legal Hold** on any document involved in litigation or investigation — disposal is strictly blocked while held.
3. **WORM Lock (Write-Once Read-Many):** Apply a **WORM Compliance Lock** to make a record immutable with a tamper-evident lock manifest until its retention expiry date.
4. **Disposition Review:** When a record's retention expires, initiate a **Disposition Review** requiring **Two-Person Approval** before permanent destruction.

---

## 15. PKI Certificates, PAdES Digital Signatures & Public Verification

**Route:** `/pki/certificates`

1. **Issue Signing Certificate:** System Admins issue X.509 signing certificates from BetterDoc's internal Certificate Authority (CA).
2. **Sign a Document:** On any PDF in the Vault, click **Sign (PAdES)** to embed a cryptographic digital signature and QR verification stamp.
3. **Public Verification:** Anyone receiving the signed PDF can scan the QR code or visit `/verify/{token}` to confirm the document is authentic and unaltered — without needing a BetterDoc login.
4. **Certificate Revocation:** If a staff member leaves or a key is compromised, revoke their certificate immediately; the CRL and OCSP endpoints update in real time.

---

## 16. Immutable Audit Ledger & Compliance Packs

**Route:** `/audit` (**Audit Ledger**)

1. Every view, download, upload, print, approval, ERP sync, and failed access attempt is recorded in an **append-only cryptographic SHA-256 hash chain** (each event hashes the previous event).
2. Filter events by Date, User, Event Type, or Resource ID.
3. Click **Generate Compliance Pack** to export a signed ZIP bundle (audit ledger, hash-chain proof, and document manifests) for ZIMRA, RBZ, POTRAZ, or external auditors, or export **Syslog** for your SIEM.

---

## 17. ERP Integrations (Xero, NetSuite, Sage Pastel & Windows LAN Bridge)

**Route:** `/admin/integrations` (**Integrations & ERP** — System Admin)

### 17.1 Cloud Accounting ERPs (Xero & Oracle NetSuite)
1. Click **Add ERP Connection** and select **Xero (OAuth 2.0)** or **Oracle NetSuite (OAuth 1.0a TBA)**.
2. Enter your API credentials and map your **Default Expense Account Code** and **Tax Type**.
3. Add `api.xero.com` or your NetSuite account host to the tenant's **External Allowlist** if running in hybrid air-gap mode.
4. Click **Test Connection**, **Pull Vendors**, **Pull POs**, or **Push Approved Invoices**.

### 17.2 On-Premise Sage Pastel Evolution / Partner & Belina Payroll (Windows LAN Bridge)
1. In the **Windows LAN Bridge Agents** panel, enter the server name (e.g., `Harare-HQ-Pastel-Server`), choose the target (`Sage Pastel Evolution`, `Sage Pastel Partner`, or `Belina Payroll`), and click **Generate Pairing Code**.
2. Click **Download Config** and run `services/betterdoc-erp-bridge/bridge.py` on your Windows LAN server.
3. The agent makes **outbound-only HTTPS polls** (no inbound firewall ports required!) to pull vendors/employees and push approved AP invoices directly into Sage Pastel or export CSV batches.

### 17.3 CSV Batch Hub & Scoped REST API Tokens
- Use **Import/Export CSV** for one-click Belina Employee and Sage Pastel Vendor/AP Invoice batches (with downloadable CSV templates).
- Issue **Scoped API Tokens** (`documents:read`, `documents:write`, `finance:sync`, `hr:sync`) and signed **HMAC-SHA256 Outbound Webhooks** for custom insurance or ERP systems.

---

## 18. System Administration: Users, Roles, Branding & Backup/DR

1. **Users & Roles (`/admin/users`):** Create departments (`Org Units`), onboard users, assign roles (`Clerk`, `Officer`, `Approver`, `RecordsOfficer`, `Auditor`, `SystemAdmin`), set **Clearance Ranks** and **Compartments**, or **Suspend** a user (instantly revokes all active web sessions).
2. **Branding & Public Portal (`/settings/branding`):** Upload your organisation's light/dark logos and favicon, pick brand colours (with automatic WCAG contrast checking), apply sector presets (*Corporate*, *Financial*, *Government*, *Defence*), and set Landing Mode (`portal`, `demo`, or `off`).
3. **Sync, Backup & DR (`/admin/operations`):** Configure 3-2-1 encrypted backup snapshots, run a **Timed Restore Drill** to verify recoverability, and manage outbox sync targets for multi-site replication.

---

## 19. Troubleshooting & Security Best Practices

| Issue | Resolution |
| :--- | :--- |
| **"Blocked by Air-Gap Policy" during ERP or WhatsApp sync** | System Admin must add the target host (e.g., `api.xero.com` or `graph.facebook.com`) to the tenant's External Allowlist, or use the **Windows LAN Bridge / CSV Hub** for offline sites. |
| **30% WHT unexpectedly applied to an AP invoice** | Check the supplier's **ZIMRA ITF263 Certificate** in `/finance` → *ZIMRA ITF263, PRAZ & RBZ FX*. Update the certificate number & future expiry date. |
| **Cannot see a document a colleague uploaded** | Verify your **Clearance Rank** and **Org Unit / Compartment** with your System Admin; BetterDoc hides documents above your clearance by design. |
| **Mobile photos not uploading in poor signal** | Switch to the **Offline** tab — photos are safely stored on your phone and will sync automatically when you tap **Sync All** back on Wi-Fi. |
| **Printer refuses to print a confidential file** | Choose a printer whose **Max Classification Rank** is equal to or higher than the document's classification, and release with your PIN or RFID badge. |

---

*Document maintained inside BetterDoc DMS (`docs/training/BETTERDOC_USER_TRAINING_MANUAL.md`). Authorised for internal printing and PDF distribution.*
