<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CorrespondenceDirection;
use App\Enums\RegistryStatus;
use App\Models\CorrespondenceRegistry;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Dms\CorrespondenceRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegistryController extends Controller
{
    public function __construct(
        private readonly CorrespondenceRegistryService $registry,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $direction = $request->query('direction');
        $bringUpOnly = $request->boolean('bring_up_due');

        $query = CorrespondenceRegistry::query()
            ->with(['document.classificationLevel', 'registrar'])
            ->latest();

        if (is_string($direction) && $direction !== '') {
            $query->where('direction', $direction);
        }

        if ($bringUpOnly) {
            $query->whereNotNull('bring_up_date')
                ->where('bring_up_date', '<=', now()->addDays(7)->toDateString());
        }

        $entries = $query->get()
            ->map(static fn (CorrespondenceRegistry $entry): array => [
                'id' => $entry->id,
                'registry_ref_no' => $entry->registry_ref_no,
                'direction' => $entry->direction->value,
                'external_party' => $entry->external_party,
                'security_folio_no' => $entry->security_folio_no,
                'physical_file_barcode' => $entry->physical_file_barcode,
                'dispatch_method' => $entry->dispatch_method,
                'bring_up_date' => $entry->bring_up_date?->toDateString(),
                'status' => $entry->status->value,
                'registrar_name' => $entry->registrar?->name,
                'document' => $entry->document ? [
                    'id' => $entry->document->id,
                    'doc_number' => $entry->document->doc_number,
                    'title' => $entry->document->title,
                    'classification_rank' => $entry->document->classification_rank,
                    'classification_name' => $entry->document->classificationLevel?->name,
                    'classification_color' => $entry->document->classificationLevel?->color_hex,
                ] : null,
            ])
            ->values()
            ->all();

        $documents = Document::query()
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'doc_number', 'title'])
            ->toArray();

        return Inertia::render('Registry/Index', [
            'entries' => $entries,
            'documents' => $documents,
            'filters' => [
                'direction' => is_string($direction) ? $direction : '',
                'bring_up_due' => $bringUpOnly,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'document_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::enum(CorrespondenceDirection::class)],
            'external_party' => ['nullable', 'string', 'max:255'],
            'security_folio_no' => ['nullable', 'string', 'max:64'],
            'physical_file_barcode' => ['nullable', 'string', 'max:96'],
            'dispatch_method' => ['nullable', 'string', 'max:48'],
            'bring_up_date' => ['nullable', 'date'],
        ]);

        /** @var Document $doc */
        $doc = Document::query()->findOrFail($request->string('document_id')->toString());
        Gate::authorize('view', $doc);

        $extParty = $request->input('external_party');
        $folio = $request->input('security_folio_no');
        $barcode = $request->input('physical_file_barcode');
        $dispatch = $request->input('dispatch_method');
        $bringUp = $request->input('bring_up_date');

        $entry = $this->registry->register(
            actor: $user,
            document: $doc,
            direction: CorrespondenceDirection::from($request->string('direction')->toString()),
            externalParty: is_string($extParty) && $extParty !== '' ? $extParty : null,
            securityFolioNo: is_string($folio) && $folio !== '' ? $folio : null,
            physicalFileBarcode: is_string($barcode) && $barcode !== '' ? $barcode : null,
            dispatchMethod: is_string($dispatch) && $dispatch !== '' ? $dispatch : null,
            bringUpDate: is_string($bringUp) && $bringUp !== '' ? $bringUp : null,
            request: $request,
        );

        return back()->with('success', "Correspondence registered as {$entry->registry_ref_no}.");
    }

    public function updateStatus(Request $request, CorrespondenceRegistry $correspondenceRegistry): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'status' => ['required', Rule::enum(RegistryStatus::class)],
        ]);

        $newStatus = RegistryStatus::from($request->string('status')->toString());
        $correspondenceRegistry->update(['status' => $newStatus]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'registry.status_updated',
            resourceType: 'correspondence_registry',
            resourceId: $correspondenceRegistry->id,
            actor: $user,
            classificationRank: $correspondenceRegistry->document->classification_rank ?? 0,
            after: ['status' => $newStatus->value],
            request: $request,
        );

        return back()->with('success', "Registry entry updated to {$newStatus->value}.");
    }
}
