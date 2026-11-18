<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'));

Route::get('/verify/{token}', [SignatureController::class, 'verifyToken'])->name('verify.token');
Route::get('/pki/ca/{tenantId}/root.crt', [PkiController::class, 'downloadRootCa'])->name('pki.root_ca');
Route::get('/pki/ca/{tenantId}/crl.der', [PkiController::class, 'downloadCrl'])->name('pki.crl');
Route::get('/pki/ocsp/{tenantId}/{serial}', [PkiController::class, 'ocspCheck'])->name('pki.ocsp');

// Collabora Online WOPI Host Endpoints (Token-authenticated server callbacks; MAC + PostgreSQL RLS enforced per token user)
Route::get('/wopi/files/{documentId}', [WopiController::class, 'checkFileInfo'])->name('wopi.check_file_info');
Route::post('/wopi/files/{documentId}', [WopiController::class, 'postFile'])->name('wopi.post_file');
Route::get('/wopi/files/{documentId}/contents', [WopiController::class, 'getFile'])->name('wopi.get_file');
Route::post('/wopi/files/{documentId}/contents', [WopiController::class, 'putFile'])->name('wopi.put_file');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::post('/demo-login', [AuthController::class, 'demoLogin'])->name('login.demo');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // MFA Enrollment & Verification routes
    Route::get('/security/mfa/setup', [MfaController::class, 'showSetup'])->name('security.mfa.setup');
    Route::post('/security/mfa/confirm', [MfaController::class, 'confirmSetup'])->name('security.mfa.confirm');
    Route::get('/mfa-challenge', [MfaController::class, 'showChallenge'])->name('mfa.challenge');
    Route::post('/mfa-challenge', [MfaController::class, 'verifyChallenge'])->name('mfa.verify');
    Route::post('/security/mfa/step-up', [MfaController::class, 'stepUp'])->name('security.mfa.step_up');

    // Protected SENTINEL DMS workspace routes (Session + MFA + PostgreSQL RLS + Policy Engine)
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
    });
});
