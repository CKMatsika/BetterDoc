<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyStatus;
use App\Enums\BetterDocRole;
use App\Models\AiAnomalyAlert;
use App\Models\AuditEvent;
use App\Models\IdpExtraction;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use stdClass;

class AnomalyDetectionService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
    ) {}

    /**
     * Scan tenant audit ledger & IDP extractions for suspicious bulk downloads,
     * after-hours Secret+ accesses, and duplicate invoice anomalies.
     *
     * @return list<AiAnomalyAlert>
     */
    public function scanTenant(string $tenantId, ?User $triggeredBy = null): array
    {
        $createdAlerts = [];

        $windowMinutes = max(1, config()->integer('betterdoc.ai.anomaly.window_minutes', 15));
        $downloadThreshold = max(2, config()->integer('betterdoc.ai.anomaly.bulk_download_threshold', 5));
        $viewThreshold = max(5, config()->integer('betterdoc.ai.anomaly.bulk_view_threshold', 25));
        $bizStart = config()->string('betterdoc.ai.anomaly.business_hours_start', '06:00');
        $bizEnd = config()->string('betterdoc.ai.anomaly.business_hours_end', '19:00');
        $since = now()->subMinutes($windowMinutes);

        // 1. Bulk Downloads / Views in sliding window
        $bulkDownloads = AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'document.downloaded')
            ->whereNotNull('actor_user_id')
            ->where('occurred_at', '>=', $since)
            ->select(['actor_user_id', DB::raw('COUNT(*) AS event_count')])
            ->groupBy('actor_user_id')
            ->get();

        foreach ($bulkDownloads as $row) {
            $actorId = is_string($row->getAttribute('actor_user_id')) ? $row->getAttribute('actor_user_id') : '';
            $cntRaw = $row->getAttribute('event_count');
            $cnt = is_numeric($cntRaw) ? (int) $cntRaw : 0;
            if ($actorId !== '' && $cnt >= $downloadThreshold) {
                $alert = $this->createAlertIfNew(
                    tenantId: $tenantId,
                    userId: $actorId,
                    documentId: null,
                    alertType: 'bulk_download_spike',
                    severity: AnomalySeverity::High,
                    title: "Unusual Bulk Downloads Detected ({$cnt} files in {$windowMinutes}m)",
                    details: ['actor_user_id' => $actorId, 'download_count' => $cnt, 'window_minutes' => $windowMinutes],
                );
                if ($alert !== null) {
                    $createdAlerts[] = $alert;
                }
            }
        }

        $bulkViews = AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', ['document.viewed', 'document.viewed_break_glass'])
            ->whereNotNull('actor_user_id')
            ->where('occurred_at', '>=', $since)
            ->select(['actor_user_id', DB::raw('COUNT(*) AS event_count')])
            ->groupBy('actor_user_id')
            ->get();

        foreach ($bulkViews as $row) {
            $actorId = is_string($row->getAttribute('actor_user_id')) ? $row->getAttribute('actor_user_id') : '';
            $cntRaw = $row->getAttribute('event_count');
            $cnt = is_numeric($cntRaw) ? (int) $cntRaw : 0;
            if ($actorId !== '' && $cnt >= $viewThreshold) {
                $alert = $this->createAlertIfNew(
                    tenantId: $tenantId,
                    userId: $actorId,
                    documentId: null,
                    alertType: 'bulk_view_spike',
                    severity: AnomalySeverity::Medium,
                    title: "High-Volume Document Browsing Detected ({$cnt} views in {$windowMinutes}m)",
                    details: ['actor_user_id' => $actorId, 'view_count' => $cnt, 'window_minutes' => $windowMinutes],
                );
                if ($alert !== null) {
                    $createdAlerts[] = $alert;
                }
            }
        }

        // 2. After-Hours Secret+ Accesses (Rank >= 3)
        $secretEvents = AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', ['document.viewed', 'document.downloaded', 'document.viewed_break_glass'])
            ->where('classification_rank', '>=', 3)
            ->where('occurred_at', '>=', now('UTC')->subDay())
            ->limit(100)
            ->get();

        foreach ($secretEvents as $ev) {
            $timeStr = $ev->occurred_at->copy()->timezone('UTC')->format('H:i');
            if ($timeStr < $bizStart || $timeStr > $bizEnd) {
                $docId = is_string($ev->resource_id) && $ev->resource_type === 'document' && $this->documentRowExists($tenantId, $ev->resource_id)
                    ? $ev->resource_id
                    : null;

                $alert = $this->createAlertIfNew(
                    tenantId: $tenantId,
                    userId: $ev->actor_user_id,
                    documentId: $docId,
                    alertType: 'after_hours_secret_access',
                    severity: AnomalySeverity::High,
                    title: "After-Hours Secret+ Record Access at {$timeStr} UTC",
                    details: [
                        'audit_seq_id' => $ev->seq_id,
                        'event_type' => $ev->event_type,
                        'classification_rank' => $ev->classification_rank,
                        'resource_id' => $ev->resource_id,
                        'occurred_at' => $ev->occurred_at->toIso8601String(),
                    ],
                );
                if ($alert !== null) {
                    $createdAlerts[] = $alert;
                }
            }
        }

        // 3. Suspicious / Duplicate Invoice IDP Pattern
        if (DB::connection()->getDriverName() === 'pgsql') {
            $dupRows = DB::select('SELECT vendor_name, invoice_number, first_document_id, duplicate_document_id FROM betterdoc_scan_duplicate_invoices(?)', [$tenantId]);
            foreach ($dupRows as $dup) {
                if (! $dup instanceof stdClass) {
                    continue;
                }
                $vendorRaw = $dup->vendor_name ?? null;
                $invNoRaw = $dup->invoice_number ?? null;
                $firstDocRaw = $dup->first_document_id ?? null;
                $dupDocRaw = $dup->duplicate_document_id ?? null;
                $vendor = is_string($vendorRaw) ? $vendorRaw : 'unknown';
                $invNo = is_string($invNoRaw) ? $invNoRaw : '';
                $firstDocId = is_string($firstDocRaw) ? $firstDocRaw : '';
                $dupDocId = is_string($dupDocRaw) ? $dupDocRaw : '';
                if ($invNo === '' || $dupDocId === '') {
                    continue;
                }

                $alert = $this->createAlertIfNew(
                    tenantId: $tenantId,
                    userId: $triggeredBy?->id,
                    documentId: $dupDocId,
                    alertType: 'duplicate_invoice_pattern',
                    severity: AnomalySeverity::Critical,
                    title: "Duplicate Invoice Detected: {$invNo} ({$vendor})",
                    details: [
                        'invoice_number' => $invNo,
                        'vendor' => $vendor,
                        'first_document_id' => $firstDocId,
                        'duplicate_document_id' => $dupDocId,
                    ],
                );
                if ($alert !== null) {
                    $createdAlerts[] = $alert;
                }
            }
        } else {
            $invoices = IdpExtraction::query()
                ->where('tenant_id', $tenantId)
                ->where('detected_doc_type', 'invoice')
                ->latest()
                ->limit(100)
                ->get();

            /** @var array<string, IdpExtraction> $seenInvoices */
            $seenInvoices = [];
            foreach ($invoices as $inv) {
                $fields = $inv->extracted_fields_json;
                $invNo = mb_strtolower(trim($fields['invoice_number']['value'] ?? ''));
                if ($invNo === '') {
                    continue;
                }
                $vendor = mb_strtolower(trim($fields['vendor_name']['value'] ?? 'unknown'));
                $fp = "{$vendor}|{$invNo}";

                if (isset($seenInvoices[$fp]) && $seenInvoices[$fp]->document_id !== $inv->document_id) {
                    $alert = $this->createAlertIfNew(
                        tenantId: $tenantId,
                        userId: $triggeredBy?->id,
                        documentId: $inv->document_id,
                        alertType: 'duplicate_invoice_pattern',
                        severity: AnomalySeverity::Critical,
                        title: "Duplicate Invoice Detected: {$invNo} ({$vendor})",
                        details: [
                            'invoice_number' => $invNo,
                            'vendor' => $vendor,
                            'first_document_id' => $seenInvoices[$fp]->document_id,
                            'duplicate_document_id' => $inv->document_id,
                        ],
                    );
                    if ($alert !== null) {
                        $createdAlerts[] = $alert;
                    }
                } else {
                    $seenInvoices[$fp] = $inv;
                }
            }
        }

        if ($createdAlerts !== []) {
            $this->notifyWatchers($tenantId, $createdAlerts);
            if ($triggeredBy !== null) {
                $this->audit->log(
                    tenantId: $tenantId,
                    eventType: 'ai.anomalies_detected',
                    resourceType: 'tenant',
                    resourceId: $tenantId,
                    actor: $triggeredBy,
                    classificationRank: 3,
                    after: ['alerts_created' => count($createdAlerts)],
                );
            }
        }

        return $createdAlerts;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function createAlertIfNew(
        string $tenantId,
        ?string $userId,
        ?string $documentId,
        string $alertType,
        AnomalySeverity $severity,
        string $title,
        array $details,
    ): ?AiAnomalyAlert {
        $exists = AiAnomalyAlert::query()
            ->where('tenant_id', $tenantId)
            ->where('alert_type', $alertType)
            ->where('status', AnomalyStatus::Open->value)
            ->when($userId !== null, static fn ($q) => $q->where('user_id', $userId))
            ->when($documentId !== null, static fn ($q) => $q->where('document_id', $documentId))
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();

        if ($exists) {
            return null;
        }

        /** @var AiAnomalyAlert $alert */
        $alert = AiAnomalyAlert::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'document_id' => $documentId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'status' => AnomalyStatus::Open,
            'title' => $title,
            'details_json' => $details,
        ]);

        return $alert;
    }

    private function documentRowExists(string $tenantId, string $documentId): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne('SELECT betterdoc_document_exists(?, ?) AS present', [$tenantId, $documentId]);

            return $row instanceof stdClass && (bool) ($row->present ?? false);
        }

        return DB::table('documents')->where('tenant_id', $tenantId)->where('id', $documentId)->exists();
    }

    /**
     * @param  list<AiAnomalyAlert>  $alerts
     */
    private function notifyWatchers(string $tenantId, array $alerts): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $watchers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', static function ($q): void {
                $q->whereIn('name', [BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value]);
            })
            ->get();

        $topAlert = $alerts[0];
        foreach ($watchers as $watcher) {
            $this->notifications->notifyUser(
                recipient: $watcher,
                type: 'ai.anomaly_alert',
                title: 'AI Security Anomaly Alert',
                body: $topAlert->title.' ('.count($alerts).' new alert(s))',
                documentId: $topAlert->document_id,
                actionUrl: '/ai',
                metadata: ['alert_id' => $topAlert->id, 'severity' => $topAlert->severity->value],
                classificationRank: 3,
            );
        }
    }
}
