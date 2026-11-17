<?php

declare(strict_types=1);

namespace App\Services\Dms;

use App\Enums\CorrespondenceDirection;
use App\Enums\RegistryStatus;
use App\Models\CorrespondenceRegistry;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorrespondenceRegistryService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function register(
        User $actor,
        Document $document,
        CorrespondenceDirection $direction,
        ?string $externalParty = null,
        ?string $securityFolioNo = null,
        ?string $physicalFileBarcode = null,
        ?string $dispatchMethod = null,
        ?string $bringUpDate = null,
        ?Request $request = null,
    ): CorrespondenceRegistry {
        return DB::transaction(function () use (
            $actor,
            $document,
            $direction,
            $externalParty,
            $securityFolioNo,
            $physicalFileBarcode,
            $dispatchMethod,
            $bringUpDate,
            $request,
        ): CorrespondenceRegistry {
            $year = now()->format('Y');
            $unitCode = $actor->orgUnit->code ?? 'HQ';
            $dirCode = match ($direction) {
                CorrespondenceDirection::Incoming => 'IN',
                CorrespondenceDirection::Outgoing => 'OUT',
                CorrespondenceDirection::Internal => 'INT',
            };

            $count = CorrespondenceRegistry::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('direction', $direction->value)
                ->count();

            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $refNo = "{$unitCode}/{$dirCode}/{$year}/{$seq}";

            /** @var CorrespondenceRegistry $entry */
            $entry = CorrespondenceRegistry::query()->create([
                'tenant_id' => $document->tenant_id,
                'document_id' => $document->id,
                'direction' => $direction,
                'registry_ref_no' => $refNo,
                'external_party' => $externalParty,
                'security_folio_no' => $securityFolioNo,
                'physical_file_barcode' => $physicalFileBarcode,
                'dispatch_method' => $dispatchMethod,
                'bring_up_date' => $bringUpDate,
                'registered_by' => $actor->id,
                'status' => RegistryStatus::Registered,
            ]);

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'registry.entry_created',
                resourceType: 'correspondence_registry',
                resourceId: $entry->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: ['registry_ref_no' => $refNo, 'document_id' => $document->id],
                request: $request,
            );

            return $entry;
        });
    }
}
