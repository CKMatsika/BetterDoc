<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\ContractObligation;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Contracts\ContractLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractLifecycleService $contracts,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $clauseSearch = trim($request->string('q')->toString());

        $contractRows = Contract::query()
            ->with(['vendor', 'document', 'obligations.owner'])
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('expiry_date')
            ->get()
            ->map(static fn (Contract $c): array => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'title' => $c->title,
                'counterparty_name' => $c->counterparty_name,
                'vendor_name' => $c->vendor?->name,
                'document_id' => $c->document_id,
                'doc_number' => $c->document->doc_number ?? '',
                'effective_date' => $c->effective_date->toDateString(),
                'expiry_date' => $c->expiry_date->toDateString(),
                'renewal_notice_days' => $c->renewal_notice_days,
                'value_cents' => $c->value_cents,
                'currency' => $c->currency,
                'status' => $c->status->value,
                'auto_renew' => $c->auto_renew,
                'obligations' => $c->obligations->map(static fn (ContractObligation $o): array => [
                    'id' => $o->id,
                    'title' => $o->title,
                    'due_date' => $o->due_date->toDateString(),
                    'status' => $o->status->value,
                    'owner_name' => $o->owner?->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $clausesQuery = ContractClause::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest();

        if ($clauseSearch !== '') {
            $like = '%'.mb_strtolower($clauseSearch).'%';
            $clausesQuery->where(static function ($q) use ($like): void {
                $q->whereRaw('lower(title) LIKE ?', [$like])
                    ->orWhereRaw('lower(category) LIKE ?', [$like])
                    ->orWhereRaw('lower(clause_text) LIKE ?', [$like]);
            });
        }

        $clauses = $clausesQuery->limit(50)->get()->toArray();
        $documents = Document::query()->where('tenant_id', $user->tenant_id)->latest()->limit(40)->get(['id', 'doc_number', 'title'])->toArray();
        $vendors = Vendor::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])->toArray();

        return Inertia::render('Contracts/Index', [
            'contracts' => $contractRows,
            'clauses' => $clauses,
            'clearedDocuments' => $documents,
            'vendors' => $vendors,
            'clauseQuery' => $clauseSearch,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{document_id: string, vendor_id?: string|null, contract_number: string, title: string, counterparty_name: string, effective_date: string, expiry_date: string, renewal_notice_days?: int, value_cents?: int, currency?: string, auto_renew?: bool} $validated */
        $validated = $request->validate([
            'document_id' => ['required', 'uuid'],
            'vendor_id' => ['nullable', 'uuid'],
            'contract_number' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:200'],
            'counterparty_name' => ['required', 'string', 'max:160'],
            'effective_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date'],
            'renewal_notice_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'value_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'auto_renew' => ['nullable', 'boolean'],
        ]);

        /** @var Document $document */
        $document = Document::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['document_id'])->firstOrFail();
        Gate::authorize('view', $document);

        $vendor = ! empty($validated['vendor_id'])
            ? Vendor::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['vendor_id'])->first()
            : null;

        $contract = $this->contracts->registerContract($user, $document, $vendor, $validated, $request);

        if ($request->wantsJson()) {
            return response()->json(['contract' => $contract], 201);
        }

        return back()->with('success', "Contract {$contract->contract_number} registered.");
    }

    public function storeClause(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{contract_id?: string|null, title: string, category: string, clause_text: string, is_standard_template?: bool} $validated */
        $validated = $request->validate([
            'contract_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:48'],
            'clause_text' => ['required', 'string', 'max:5000'],
            'is_standard_template' => ['nullable', 'boolean'],
        ]);

        $contract = ! empty($validated['contract_id'])
            ? Contract::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['contract_id'])->first()
            : null;

        $this->contracts->addClause(
            actor: $user,
            contract: $contract,
            title: $validated['title'],
            category: $validated['category'],
            clauseText: $validated['clause_text'],
            isStandardTemplate: (bool) ($validated['is_standard_template'] ?? true),
        );

        return back()->with('success', "Clause '{$validated['title']}' saved to library.");
    }

    public function storeObligation(Request $request, Contract $contract): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($contract->tenant_id === $user->tenant_id, 404);

        /** @var array{title: string, due_date: string} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_date' => ['required', 'date'],
        ]);

        $this->contracts->addObligation($user, $contract, $validated['title'], $validated['due_date']);

        return back()->with('success', 'Contract obligation scheduled.');
    }

    public function completeObligation(Request $request, ContractObligation $contractObligation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($contractObligation->tenant_id === $user->tenant_id, 404);

        $this->contracts->completeObligation($contractObligation, $user);

        return back()->with('success', 'Obligation marked fulfilled.');
    }

    public function scanAlerts(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $res = $this->contracts->scanAlerts($user->tenant_id);

        return back()->with('success', "Contract scan complete: {$res['expiring_soon']} expiring soon, {$res['expired']} expired, {$res['overdue_obligations']} overdue obligations.");
    }
}
