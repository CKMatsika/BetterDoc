<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\RegisterOrganisationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'show'])->name('landing');
Route::get('/branding/assets/{setting}/{slot}', [LandingController::class, 'asset'])
    ->whereIn('slot', ['logo', 'logo_dark', 'favicon'])
    ->name('branding.asset');
Route::post('/demo-request', [DemoRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('demo.request');

Route::get('/verify/{token}', [SignatureController::class, 'verifyToken'])
    ->middleware('throttle:public-verify')
    ->name('verify.token');
Route::get('/pki/ca/{tenantId}/root.crt', [PkiController::class, 'downloadRootCa'])
    ->middleware('throttle:public-verify')
    ->name('pki.root_ca');
Route::get('/pki/ca/{tenantId}/crl.der', [PkiController::class, 'downloadCrl'])
    ->middleware('throttle:public-verify')
    ->name('pki.crl');
Route::get('/pki/ocsp/{tenantId}/{serial}', [PkiController::class, 'ocspCheck'])
    ->middleware('throttle:public-verify')
    ->name('pki.ocsp');

// Collabora Online WOPI Host Endpoints (Token-authenticated server callbacks; MAC + PostgreSQL RLS enforced per token user)
Route::get('/wopi/files/{documentId}', [WopiController::class, 'checkFileInfo'])->name('wopi.check_file_info');
Route::post('/wopi/files/{documentId}', [WopiController::class, 'postFile'])->name('wopi.post_file');
Route::get('/wopi/files/{documentId}/contents', [WopiController::class, 'getFile'])->name('wopi.get_file');
Route::post('/wopi/files/{documentId}/contents', [WopiController::class, 'putFile'])->name('wopi.put_file');

// Phase 6: Workstation BetterDoc Local Agent Token API (127.0.0.1 TWAIN/WIA & PKCS#11 bridge)
Route::post('/local-agent/pair', [PrintScanController::class, 'completeAgentPairing'])
    ->middleware('throttle:30,1')
    ->name('local_agent.pair');
Route::post('/local-agent/scan', [PrintScanController::class, 'ingestAgentScan'])
    ->middleware('throttle:30,1')
    ->name('local_agent.scan');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
    Route::post('/demo-login', [AuthController::class, 'demoLogin'])
        ->middleware('throttle:login')
        ->name('login.demo');
    Route::get('/register', [RegisterOrganisationController::class, 'show'])->name('register');
    Route::post('/register', [RegisterOrganisationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // MFA Enrollment & Verification routes
    Route::get('/security/mfa/setup', [MfaController::class, 'showSetup'])->name('security.mfa.setup');
    Route::post('/security/mfa/confirm', [MfaController::class, 'confirmSetup'])
        ->middleware('throttle:mfa')
        ->name('security.mfa.confirm');
    Route::get('/mfa-challenge', [MfaController::class, 'showChallenge'])->name('mfa.challenge');
    Route::post('/mfa-challenge', [MfaController::class, 'verifyChallenge'])
        ->middleware('throttle:mfa')
        ->name('mfa.verify');
    Route::post('/security/mfa/step-up', [MfaController::class, 'stepUp'])
        ->middleware('throttle:mfa')
        ->name('security.mfa.step_up');

    // Protected BetterDoc DMS workspace routes (Session + MFA + PostgreSQL RLS + Policy Engine)
    Route::middleware('mfa.enforced')->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Documents, Versions & Search
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/search/autocomplete', [DocumentController::class, 'autocomplete'])->name('search.autocomplete');
        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/versions/{version}/stream', [DocumentController::class, 'streamVersion'])->name('documents.versions.stream');
        Route::get('/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion'])->name('documents.versions.download');
        Route::post('/documents/{document}/checkout', [DocumentController::class, 'checkout'])->name('documents.checkout');
        Route::post('/documents/{document}/checkin', [DocumentController::class, 'checkin'])->name('documents.checkin');
        Route::get('/documents/{document}/compare', [DocumentController::class, 'compareVersions'])->name('documents.compare');
        Route::post('/documents/{document}/versions/{version}/restore', [DocumentController::class, 'restoreVersion'])->name('documents.versions.restore');
        Route::post('/documents/{document}/tags', [DocumentController::class, 'attachTag'])->name('documents.tags.attach');
        Route::post('/documents/{document}/share', [DocumentController::class, 'share'])->name('documents.share');
        Route::post('/documents/{document}/reclassify', [DocumentController::class, 'reclassify'])->name('documents.reclassify');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        // Chunked Resumable Uploads
        Route::post('/uploads/init', [UploadController::class, 'initiate'])->name('uploads.init');
        Route::put('/uploads/{uploadSession}/chunks/{partIndex}', [UploadController::class, 'uploadChunk'])->name('uploads.chunk');
        Route::get('/uploads/{uploadSession}/status', [UploadController::class, 'status'])->name('uploads.status');
        Route::post('/uploads/{uploadSession}/complete', [UploadController::class, 'complete'])->name('uploads.complete');

        // Saved Smart Views & Correspondence Registry
        Route::post('/smart-views', [SmartViewController::class, 'store'])->name('smart_views.store');
        Route::delete('/smart-views/{smartView}', [SmartViewController::class, 'destroy'])->name('smart_views.destroy');

        Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
        Route::post('/registry', [RegistryController::class, 'store'])->name('registry.store');
        Route::patch('/registry/{correspondenceRegistry}/status', [RegistryController::class, 'updateStatus'])->name('registry.status');

        // Internal PKI & Digital Signatures (PAdES + Envelopes)
        Route::get('/pki/certificates', [PkiController::class, 'index'])->name('pki.index');
        Route::post('/pki/bootstrap', [PkiController::class, 'bootstrap'])->name('pki.bootstrap');
        Route::post('/pki/certificates', [PkiController::class, 'issue'])->name('pki.issue');
        Route::post('/pki/certificates/{certificate}/revoke', [PkiController::class, 'revoke'])->name('pki.revoke');

        Route::post('/documents/{document}/envelopes', [SignatureController::class, 'storeEnvelope'])->name('signatures.envelopes.store');
        Route::post('/signatures/requests/{signatureRequest}/sign', [SignatureController::class, 'sign'])->name('signatures.requests.sign');
        Route::post('/signatures/requests/{signatureRequest}/prepare-external', [SignatureController::class, 'prepareExternal'])->name('signatures.requests.prepare_external');
        Route::post('/signatures/requests/{signatureRequest}/finalize-external', [SignatureController::class, 'finalizeExternal'])->name('signatures.requests.finalize_external');
        Route::post('/signatures/requests/{signatureRequest}/decline', [SignatureController::class, 'decline'])->name('signatures.requests.decline');
        Route::post('/signatures/requests/{signatureRequest}/reassign', [SignatureController::class, 'reassign'])->name('signatures.requests.reassign');
        Route::post('/signatures/requests/{signatureRequest}/remind', [SignatureController::class, 'remind'])->name('signatures.requests.remind');
        Route::get('/signatures/envelopes/{envelope}/certificate', [SignatureController::class, 'downloadCertificate'])->name('signatures.envelopes.certificate');
        Route::post('/documents/{document}/versions/{version}/verify', [SignatureController::class, 'verifyVersion'])->name('documents.versions.verify');

        // Approvals & Break-Glass
        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{twoPersonRequest}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{twoPersonRequest}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
        Route::post('/break-glass', [ApprovalController::class, 'requestBreakGlass'])->name('break_glass.request');
        Route::post('/break-glass/{breakGlassSession}/approve-first', [ApprovalController::class, 'approveBreakGlassFirst'])->name('break_glass.approve_first');
        Route::post('/break-glass/{breakGlassSession}/approve-second', [ApprovalController::class, 'approveBreakGlassSecond'])->name('break_glass.approve_second');

        // Audit & Profiles
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::post('/audit/checkpoints', [AuditController::class, 'checkpoint'])->name('audit.checkpoint');

        Route::get('/admin/profiles', [AdminProfileController::class, 'index'])->name('admin.profiles');
        Route::post('/admin/profiles/apply', [AdminProfileController::class, 'apply'])->name('admin.profiles.apply');

        // Phase 4: Collaboration, Annotations, Destructive PDF Redaction & Collabora Co-Editing
        Route::post('/documents/{document}/comments', [CollaborationController::class, 'storeComment'])->name('documents.comments.store');
        Route::post('/documents/{document}/comments/{comment}/resolve', [CollaborationController::class, 'resolveComment'])->name('documents.comments.resolve');
        Route::post('/documents/{document}/annotations', [CollaborationController::class, 'storeAnnotation'])->name('documents.annotations.store');
        Route::delete('/documents/{document}/annotations/{annotation}', [CollaborationController::class, 'destroyAnnotation'])->name('documents.annotations.destroy');
        Route::post('/documents/{document}/redactions/apply', [CollaborationController::class, 'applyRedactions'])->name('documents.redactions.apply');
        Route::get('/documents/{document}/edit-office', [CollaborationController::class, 'editOffice'])->name('documents.edit_office');
        Route::get('/notifications', [CollaborationController::class, 'notifications'])->name('notifications.index');
        Route::post('/notifications/{notification}/read', [CollaborationController::class, 'markNotificationRead'])->name('notifications.read');
        Route::post('/notifications/read-all', [CollaborationController::class, 'markAllNotificationsRead'])->name('notifications.read_all');

        // Phase 4: Visual BPMN-Lite Workflows & Task Inbox
        Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::post('/workflows/definitions', [WorkflowController::class, 'storeDefinition'])->name('workflows.definitions.store');
        Route::post('/documents/{document}/workflows/start', [WorkflowController::class, 'startInstance'])->name('workflows.start');
        Route::get('/workflows/inbox', [WorkflowController::class, 'inbox'])->name('workflows.inbox');
        Route::post('/workflows/tasks/{workflowTask}/decide', [WorkflowController::class, 'decideTask'])->name('workflows.tasks.decide');
        Route::post('/workflows/tasks/{workflowTask}/delegate', [WorkflowController::class, 'delegateTask'])->name('workflows.tasks.delegate');
        Route::post('/workflows/escalate-slas', [WorkflowController::class, 'runEscalations'])->name('workflows.escalate');

        // Phase 4: Document Templates & Mail-Merge
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::post('/templates/{documentTemplate}/generate', [TemplateController::class, 'generate'])->name('templates.generate');

        // Phase 4: Records Governance, Legal Hold, WORM & User Clearance Revocation
        Route::get('/governance', [GovernanceController::class, 'index'])->name('governance.index');
        Route::post('/governance/schedules', [GovernanceController::class, 'storeSchedule'])->name('governance.schedules.store');
        Route::post('/documents/{document}/governance/retention', [GovernanceController::class, 'assignRetention'])->name('documents.governance.retention');
        Route::post('/documents/{document}/governance/legal-hold', [GovernanceController::class, 'toggleLegalHold'])->name('documents.governance.legal_hold');
        Route::post('/documents/{document}/governance/worm-lock', [GovernanceController::class, 'lockWorm'])->name('documents.governance.worm_lock');
        Route::post('/documents/{document}/governance/disposition', [GovernanceController::class, 'initiateDisposition'])->name('documents.governance.disposition');
        Route::post('/admin/users/{targetUser}/clearance', [GovernanceController::class, 'updateUserClearance'])->name('admin.users.clearance');

        // Phase 5: Local-First AI, IDP Review Queue, Pre-Filtered Hybrid RAG & Anomaly Alerts
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::post('/ai/chat', [AiController::class, 'chat'])->name('ai.chat');
        Route::post('/ai/sessions/{aiChatSession}/export-draft', [AiController::class, 'exportDraft'])->name('ai.export_draft');
        Route::post('/documents/{document}/ai/idp', [AiController::class, 'runIdp'])->name('documents.ai.idp');
        Route::post('/ai/idp/{idpExtraction}/review', [AiController::class, 'reviewIdp'])->name('ai.idp.review');
        Route::post('/documents/{document}/ai/action', [AiController::class, 'documentAction'])->name('documents.ai.action');
        Route::post('/ai/anomalies/scan', [AiController::class, 'scanAnomalies'])->name('ai.anomalies.scan');
        Route::post('/ai/anomalies/{aiAnomalyAlert}/status', [AiController::class, 'updateAnomalyStatus'])->name('ai.anomalies.status');

        // Phase 6: Printing (CUPS/IPPS, Secure PIN/RFID Release), Scanning, Barcode Separators, PWA & Local Agent
        Route::get('/print-scan', [PrintScanController::class, 'index'])->name('print_scan.index');
        Route::post('/print-scan/printers', [PrintScanController::class, 'storePrinter'])->name('print_scan.printers.store');
        Route::post('/print-scan/printers/discover', [PrintScanController::class, 'discoverPrinters'])->name('print_scan.printers.discover');
        Route::post('/print-scan/cost-centres', [PrintScanController::class, 'storeCostCentre'])->name('print_scan.cost_centres.store');
        Route::post('/documents/{document}/print', [PrintScanController::class, 'queuePrint'])->name('documents.print');
        Route::post('/print-scan/jobs/{printJob}/release', [PrintScanController::class, 'releaseWithPin'])->name('print_scan.jobs.release');
        Route::post('/print-scan/release-rfid', [PrintScanController::class, 'releaseWithRfid'])->name('print_scan.release_rfid');
        Route::get('/print-scan/jobs/{printJob}/preview', [PrintScanController::class, 'previewWatermarkedSpool'])->name('print_scan.jobs.preview');
        Route::get('/print-scan/separator-sheet', [PrintScanController::class, 'downloadSeparatorSheet'])->name('print_scan.separator');
        Route::post('/print-scan/scan-batches', [PrintScanController::class, 'uploadScanBatch'])->name('print_scan.batches.store');
        Route::post('/print-scan/scan-sources', [PrintScanController::class, 'storeScanSource'])->name('print_scan.sources.store');
        Route::post('/print-scan/scan-sources/{scanSource}/poll', [PrintScanController::class, 'pollScanSource'])->name('print_scan.sources.poll');
        Route::post('/print-scan/agents/pair-init', [PrintScanController::class, 'initiateAgentPairing'])->name('print_scan.agents.pair_init');
        Route::post('/print-scan/agents/{localAgent}/revoke', [PrintScanController::class, 'revokeAgent'])->name('print_scan.agents.revoke');
        Route::get('/print-scan/agents/download-script', [PrintScanController::class, 'downloadAgentScript'])->name('print_scan.agents.script');

        // Phase 7: Finance / AP Automation, ZIMRA E-Invoicing, Contracts, HR Vault & Compliance Packs
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::post('/finance/vendors', [FinanceController::class, 'storeVendor'])->name('finance.vendors.store');
        Route::post('/finance/purchase-orders', [FinanceController::class, 'storePurchaseOrder'])->name('finance.pos.store');
        Route::post('/finance/purchase-orders/{purchaseOrder}/receipts', [FinanceController::class, 'storeGoodsReceipt'])->name('finance.receipts.store');
        Route::post('/finance/invoices', [FinanceController::class, 'storeInvoice'])->name('finance.invoices.store');
        Route::post('/finance/invoices/{apInvoice}/approve', [FinanceController::class, 'approveInvoice'])->name('finance.invoices.approve');
        Route::get('/finance/invoices/{apInvoice}/ubl', [FinanceController::class, 'exportUbl'])->name('finance.invoices.ubl');
        Route::post('/finance/invoices/{apInvoice}/fiscalize', [FinanceController::class, 'fiscalizeZimra'])->name('finance.invoices.fiscalize');

        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::post('/contracts/clauses', [ContractController::class, 'storeClause'])->name('contracts.clauses.store');
        Route::post('/contracts/{contract}/obligations', [ContractController::class, 'storeObligation'])->name('contracts.obligations.store');
        Route::post('/contracts/obligations/{contractObligation}/complete', [ContractController::class, 'completeObligation'])->name('contracts.obligations.complete');
        Route::post('/contracts/scan-alerts', [ContractController::class, 'scanAlerts'])->name('contracts.scan_alerts');

        Route::get('/hr', [HrController::class, 'index'])->name('hr.index');
        Route::post('/hr/employees', [HrController::class, 'storeEmployee'])->name('hr.employees.store');
        Route::post('/hr/records', [HrController::class, 'storeRecord'])->name('hr.records.store');

        Route::post('/compliance/packs', [ComplianceController::class, 'generatePack'])->name('compliance.packs.generate');
        Route::get('/compliance/packs/{compliancePack}/verify', [ComplianceController::class, 'verifyPack'])->name('compliance.packs.verify');
        Route::get('/compliance/packs/{compliancePack}/download', [ComplianceController::class, 'downloadPack'])->name('compliance.packs.download');
        Route::get('/compliance/syslog', [ComplianceController::class, 'exportSyslog'])->name('compliance.syslog');

        // Phase 8: Optional Outbox Sync Gateway, 3-2-1 Encrypted Backup & Timed DR Drill
        Route::get('/admin/operations', [OperationsController::class, 'index'])->name('admin.operations');
        Route::post('/admin/operations/sync-targets', [OperationsController::class, 'storeSyncTarget'])->name('admin.operations.sync_targets.store');
        Route::post('/admin/operations/sync-enqueue', [OperationsController::class, 'enqueueSync'])->name('admin.operations.sync_enqueue');
        Route::post('/admin/operations/sync-process', [OperationsController::class, 'processSync'])->name('admin.operations.sync_process');
        Route::post('/admin/operations/backups', [OperationsController::class, 'createBackup'])->name('admin.operations.backups.create');
        Route::post('/admin/operations/backups/{backupSnapshot}/restore-drill', [OperationsController::class, 'restoreDrill'])->name('admin.operations.backups.restore_drill');

        // Configurable Branding, Presets & Demo Requests (SystemAdmin manage-branding permission)
        Route::get('/settings/branding', [BrandingSettingsController::class, 'index'])->name('settings.branding');
        Route::post('/settings/branding', [BrandingSettingsController::class, 'update'])->name('settings.branding.update');
        Route::post('/settings/branding/preset', [BrandingSettingsController::class, 'applyPreset'])->name('settings.branding.preset');
        Route::post('/settings/branding/reset', [BrandingSettingsController::class, 'reset'])->name('settings.branding.reset');

        // Super Admin Users, Roles & Granular Permissions
        Route::get('/admin/users', [UserManagementController::class, 'index'])->name('admin.users');
        Route::post('/admin/org-units', [UserManagementController::class, 'storeOrgUnit'])->name('admin.org_units.store');
        Route::post('/admin/users', [UserManagementController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{targetUser}', [UserManagementController::class, 'updateAccess'])->name('admin.users.update');

        // ERP Integration Hub (Stage 1: Scoped API Tokens, HMAC Webhooks, Insurance & Belina/Sage CSV Hub)
        Route::get('/admin/integrations', [IntegrationHubController::class, 'index'])->name('admin.integrations');
        Route::post('/admin/integrations/tokens', [IntegrationHubController::class, 'storeToken'])->name('admin.integrations.tokens.store');
        Route::delete('/admin/integrations/tokens/{tokenId}', [IntegrationHubController::class, 'destroyToken'])->name('admin.integrations.tokens.destroy');
        Route::post('/admin/integrations/connections', [IntegrationHubController::class, 'storeConnection'])->name('admin.integrations.connections.store');
        Route::post('/admin/integrations/connections/{connection}/sync', [IntegrationHubController::class, 'runConnectionSync'])->name('admin.integrations.connections.sync');
        Route::patch('/admin/integrations/connections/{connection}/mapping', [IntegrationHubController::class, 'updateConnectionMapping'])->name('admin.integrations.connections.mapping');
        Route::post('/admin/integrations/webhooks', [IntegrationHubController::class, 'storeWebhook'])->name('admin.integrations.webhooks.store');
        Route::post('/admin/integrations/webhooks/test', [IntegrationHubController::class, 'testWebhook'])->name('admin.integrations.webhooks.test');
        Route::post('/admin/integrations/import/belina-employees', [IntegrationHubController::class, 'importBelinaCsv'])->name('admin.integrations.import.belina');
        Route::post('/admin/integrations/import/sage-vendors', [IntegrationHubController::class, 'importSageVendorsCsv'])->name('admin.integrations.import.sage');
        Route::get('/admin/integrations/export/ap-invoices', [IntegrationHubController::class, 'exportInvoicesCsv'])->name('admin.integrations.export.invoices');
        Route::get('/admin/integrations/export/belina-employees', [IntegrationHubController::class, 'exportBelinaCsv'])->name('admin.integrations.export.belina');
        Route::get('/admin/integrations/templates/{kind}', [IntegrationHubController::class, 'downloadTemplate'])->name('admin.integrations.template');
        Route::post('/admin/integrations/bridge-agents', [IntegrationHubController::class, 'storeBridgeAgent'])->name('admin.integrations.bridge.store');
        Route::post('/admin/integrations/bridge-agents/{agent}/jobs', [IntegrationHubController::class, 'enqueueBridgeJob'])->name('admin.integrations.bridge.jobs.store');
        Route::post('/admin/integrations/bridge-agents/{agent}/revoke', [IntegrationHubController::class, 'revokeBridgeAgent'])->name('admin.integrations.bridge.revoke');
        Route::get('/admin/integrations/bridge-agents/{agent}/download-config', [IntegrationHubController::class, 'downloadBridgeConfig'])->name('admin.integrations.bridge.config');

        // Mobile PWA — Snap & Manage + Auto-Invoice to Accounting ERP
        Route::get('/mobile', [MobilePwaController::class, 'index'])->name('mobile.index');
        Route::post('/mobile/capture', [MobilePwaController::class, 'capture'])->name('mobile.capture');
        Route::post('/mobile/documents/{document}/process-invoice', [MobilePwaController::class, 'processInvoice'])->name('mobile.process_invoice');
        Route::post('/mobile/documents/{document}/manage', [MobilePwaController::class, 'manageDocument'])->name('mobile.manage_document');
    });
});
