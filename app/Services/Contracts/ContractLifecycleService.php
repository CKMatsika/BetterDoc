<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\BetterDocRole;
use App\Enums\ContractStatus;
use App\Enums\ObligationStatus;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\ContractObligation;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class ContractLifecycleService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    /**
     * @param  array{contract_number: string, title: string, counterparty_name: string, effective_date: string, expiry_date: string, renewal_notice_days?: int, value_cents?: int, currency?: string, auto_renew?: bool}  $data
     */
    public function registerContract(
        User $actor,
        Document $document,
        ?Vendor $vendor,
        array $data,
        ?Request $request = null,
    ): Contract {
        $expiry = now()->parse($data['expiry_date'])->startOfDay();
        $noticeDays = max(1, (int) ($data['renewal_notice_days'] ?? 30));

        $status = ContractStatus::Active;
        if ($expiry->isPast()) {
            $status = ContractStatus::Expired;
        } elseif (now()->startOfDay()->diffInDays($expiry, false) <= $noticeDays) {
            $status = ContractStatus::ExpiringSoon;
        }

        /** @var Contract $contract */
        $contract = Contract::query()->create([
            'tenant_id' => $actor->tenant_id,
            'document_id' => $document->id,
            'vendor_id' => $vendor?->id,
            'contract_number' => trim($data['contract_number']),
            'title' => trim($data['title']),
            'counterparty_name' => trim($data['counterparty_name']),
            'effective_date' => $data['effective_date'],
            'expiry_date' => $data['expiry_date'],
            'renewal_notice_days' => $noticeDays,
            'value_cents' => max(0, (int) ($data['value_cents'] ?? 0)),
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'status' => $status,
            'auto_renew' => (bool) ($data['auto_renew'] ?? false),
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'contract.registered',
            resourceType: 'contract',
            resourceId: $contract->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: [
                'contract_number' => $contract->contract_number,
                'expiry_date' => $contract->expiry_date->toDateString(),
                'status' => $status->value,
            ],
            request: $request,
        );

        return $contract;
    }

    public function addClause(
        User $actor,
        ?Contract $contract,
        string $title,
        string $category,
        string $clauseText,
        bool $isStandardTemplate = false,
    ): ContractClause {
        /** @var ContractClause $clause */
        $clause = ContractClause::query()->create([
            'tenant_id' => $actor->tenant_id,
            'contract_id' => $contract?->id,
            'title' => trim($title),
            'category' => trim($category),
            'clause_text' => trim($clauseText),
            'is_standard_template' => $isStandardTemplate,
        ]);

        return $clause;
    }

    public function addObligation(
        User $actor,
        Contract $contract,
        string $title,
        string $dueDate,
        ?User $owner = null,
    ): ContractObligation {
        $due = now()->parse($dueDate)->startOfDay();
        $status = $due->isPast() ? ObligationStatus::Overdue : ObligationStatus::Open;

        /** @var ContractObligation $ob */
        $ob = ContractObligation::query()->create([
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'owner_id' => $owner instanceof User ? $owner->id : $actor->id,
            'title' => trim($title),
            'due_date' => $dueDate,
            'status' => $status,
        ]);

        return $ob;
    }

    public function completeObligation(ContractObligation $obligation, User $actor): ContractObligation
    {
        $obligation->update([
            'status' => ObligationStatus::Fulfilled,
            'completed_at' => now(),
        ]);

        $this->audit->log(
            tenantId: $obligation->tenant_id,
            eventType: 'contract.obligation_fulfilled',
            resourceType: 'contract_obligation',
            resourceId: $obligation->id,
            actor: $actor,
            classificationRank: 1,
            after: ['title' => $obligation->title],
        );

        return $obligation->refresh();
    }

    /**
     * Evaluate expiry windows and overdue contract obligations across a tenant and dispatch alerts.
     *
     * @return array{expiring_soon: int, expired: int, overdue_obligations: int}
     */
    public function scanAlerts(string $tenantId): array
    {
        $today = now('UTC')->startOfDay();
        $expiringCount = 0;
        $expiredCount = 0;
        $overdueCount = 0;

        $contracts = Contract::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', [ContractStatus::Terminated->value, ContractStatus::Expired->value])
            ->get();

        foreach ($contracts as $c) {
            if ($c->expiry_date->isBefore($today)) {
                $c->update(['status' => ContractStatus::Expired]);
                $expiredCount++;
                $this->notifyWatchers($tenantId, $c, "Contract Expired: {$c->contract_number} — {$c->title}");
            } elseif ($today->diffInDays($c->expiry_date, false) <= $c->renewal_notice_days && $c->status !== ContractStatus::ExpiringSoon) {
                $c->update(['status' => ContractStatus::ExpiringSoon]);
                $expiringCount++;
                $this->notifyWatchers($tenantId, $c, "Contract Renewal Notice ({$c->expiry_date->toDateString()}): {$c->contract_number}");
            }
        }

        $openObligations = ContractObligation::query()
            ->with('owner')
            ->where('tenant_id', $tenantId)
            ->where('status', ObligationStatus::Open->value)
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        foreach ($openObligations as $ob) {
            $ob->update(['status' => ObligationStatus::Overdue]);
            $overdueCount++;
            if ($ob->owner instanceof User) {
                $this->notifications->notifyUser(
                    recipient: $ob->owner,
                    type: 'contract.obligation_overdue',
                    title: 'Overdue Contract Obligation',
                    body: "{$ob->title} (due {$ob->due_date->toDateString()}) requires completion.",
                    documentId: null,
                    actionUrl: '/contracts',
                    metadata: ['obligation_id' => $ob->id],
                    classificationRank: 1,
                );
            }
        }

        return [
            'expiring_soon' => $expiringCount,
            'expired' => $expiredCount,
            'overdue_obligations' => $overdueCount,
        ];
    }

    private function notifyWatchers(string $tenantId, Contract $contract, string $msg): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        $watchers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', [BetterDocRole::Approver->value, BetterDocRole::RecordsOfficer->value]))
            ->get();

        foreach ($watchers as $w) {
            $this->notifications->notifyUser(
                recipient: $w,
                type: 'contract.expiry_alert',
                title: 'Contract Lifecycle Alert',
                body: $msg,
                documentId: $contract->document_id,
                actionUrl: '/contracts',
                metadata: ['contract_id' => $contract->id],
                classificationRank: 1,
            );
        }
    }
}
