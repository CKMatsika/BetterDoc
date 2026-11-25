<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ErpBridgeApiController;
use App\Http\Controllers\Api\V1\IntegrationApiController;
use App\Http\Controllers\Api\V1\MobileCaptureApiController;
use Illuminate\Support\Facades\Route;

// Stage 3: Outbound-Only Windows LAN ERP Bridge Agent Endpoints (Sage Pastel Evolution / Partner & On-Prem Belina)
Route::prefix('v1/bridge')
    ->middleware('throttle:60,1')
    ->group(function (): void {
        Route::post('/pair', [ErpBridgeApiController::class, 'pair'])
            ->middleware('throttle:20,1')
            ->name('api.v1.bridge.pair');
        Route::post('/heartbeat', [ErpBridgeApiController::class, 'heartbeat'])->name('api.v1.bridge.heartbeat');
        Route::post('/poll', [ErpBridgeApiController::class, 'poll'])->name('api.v1.bridge.poll');
        Route::post('/jobs/{jobId}/complete', [ErpBridgeApiController::class, 'completeJob'])->name('api.v1.bridge.complete');
    });

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'api.pg', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/health', [IntegrationApiController::class, 'health'])->name('api.v1.health');
        Route::get('/me', [IntegrationApiController::class, 'me'])->name('api.v1.me');

        // Documents & Insurance Policy/Claim Registry
        Route::get('/documents', [IntegrationApiController::class, 'listDocuments'])
            ->middleware('api.ability:documents:read')
            ->name('api.v1.documents.index');
        Route::get('/documents/{document}', [IntegrationApiController::class, 'showDocument'])
            ->middleware('api.ability:documents:read')
            ->name('api.v1.documents.show');
        Route::post('/documents', [IntegrationApiController::class, 'storeDocument'])
            ->middleware('api.ability:documents:write')
            ->name('api.v1.documents.store');

        // ERP Finance Vendors & Approved AP Invoices (Sage Pastel / Xero / NetSuite)
        Route::get('/finance/vendors', [IntegrationApiController::class, 'listVendors'])
            ->middleware('api.ability:finance:sync')
            ->name('api.v1.finance.vendors.index');
        Route::post('/finance/vendors', [IntegrationApiController::class, 'upsertVendor'])
            ->middleware('api.ability:finance:sync')
            ->name('api.v1.finance.vendors.upsert');
        Route::get('/finance/invoices', [IntegrationApiController::class, 'listInvoices'])
            ->middleware('api.ability:finance:sync')
            ->name('api.v1.finance.invoices.index');

        // Payroll & HR Master Sync (Belina / HRIS)
        Route::post('/hr/employees', [IntegrationApiController::class, 'upsertHrEmployee'])
            ->middleware('api.ability:hr:sync')
            ->name('api.v1.hr.employees.upsert');

        // Mobile Companion & PWA Smart Capture API (Ready for Flutter Phase 2)
        Route::get('/mobile/bootstrap', [MobileCaptureApiController::class, 'bootstrap'])
            ->middleware('api.ability:documents:read')
            ->name('api.v1.mobile.bootstrap');
        Route::post('/mobile/capture', [MobileCaptureApiController::class, 'capture'])
            ->middleware('api.ability:documents:write')
            ->name('api.v1.mobile.capture');
        Route::post('/mobile/documents/{document}/process-invoice', [MobileCaptureApiController::class, 'processInvoice'])
            ->middleware('api.ability:finance:sync')
            ->name('api.v1.mobile.process_invoice');
        Route::patch('/mobile/documents/{document}', [MobileCaptureApiController::class, 'updateDocument'])
            ->middleware('api.ability:documents:write')
            ->name('api.v1.mobile.documents.update');
    });
