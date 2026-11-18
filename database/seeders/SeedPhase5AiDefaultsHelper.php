<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProfileCode;
use App\Models\Document;
use App\Models\IdpExtraction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\ChunkIndexingService;
use App\Services\Ai\IdpExtractionService;

class SeedPhase5AiDefaultsHelper
{
    /**
     * Populate initial semantic chunks for cleared documents and sample IDP extractions on financial/corporate portals.
     */
    public static function seedDefaults(Tenant $tenant, User $actor): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        app(ChunkIndexingService::class)->syncMissingForTenant($tenant->id);

        if ($tenant->active_profile_code === ProfileCode::Financial) {
            $creditDoc = Document::query()->where('tenant_id', $tenant->id)->where('doc_number', 'GK/CRD/2026/210')->first();
            if ($creditDoc instanceof Document && IdpExtraction::query()->where('document_id', $creditDoc->id)->doesntExist()) {
                app(IdpExtractionService::class)->extractFromDocument($creditDoc, $actor);
            }
        } elseif ($tenant->active_profile_code === ProfileCode::Corporate) {
            $finDoc = Document::query()->where('tenant_id', $tenant->id)->where('doc_number', 'ZC/FIN/2026/042')->first();
            if ($finDoc instanceof Document && IdpExtraction::query()->where('document_id', $finDoc->id)->doesntExist()) {
                app(IdpExtractionService::class)->extractFromDocument($finDoc, $actor);
            }
        }
    }
}
