<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\IntegrationApiController;
use Illuminate\Support\Facades\Route;

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
    });
