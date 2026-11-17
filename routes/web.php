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
    });
});
