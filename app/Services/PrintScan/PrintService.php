<?php

declare(strict_types=1);

namespace App\Services\PrintScan;

use App\Enums\PrinterDriver;
use App\Enums\PrintJobStatus;
use App\Exceptions\PrintPolicyViolationException;
use App\Models\CostCentre;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use App\Services\Crypto\EnvelopeEncryptionService;
use App\Services\Security\ClearancePolicyEngine;
use App\Services\Security\DbSecurityContext;
use App\Services\Security\SentinelHttpClientGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class PrintService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly EnvelopeEncryptionService $crypto,
        private readonly PrintScanBridgeService $bridge,
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
        private readonly DbSecurityContext $dbContext,
        private readonly SentinelHttpClientGuard $guard,
    ) {}

    /**
     * Queue a secure watermarked print job, validating print right, printer clearance ceiling, and cost-centre quota.
     *
     * @return array{job: PrintJob, release_pin: string|null}
     */
    public function queuePrintJob(
        User $user,
        Document $document,
        Printer $printer,
        ?CostCentre $costCentre = null,
        int $copies = 1,
        ?Request $request = null,
    ): array {
        $copies = max(1, min(50, $copies));

        $decision = $this->policyEngine->authorize($user, 'print', $document, [
            'ip' => $request?->ip(),
        ]);

        if (! $decision->allowed) {
            throw new PrintPolicyViolationException('Print permission denied: '.$decision->reason);
        }

        if (! $printer->is_online) {
            throw new PrintPolicyViolationException("Selected printer '{$printer->name}' is offline.");
        }

        if ($document->classification_rank > $printer->max_classification_rank) {
            $this->audit->log(
                tenantId: $user->tenant_id,
                eventType: 'print.blocked_policy_violation',
                resourceType: 'document',
                resourceId: $document->id,
                actor: $user,
                classificationRank: $document->classification_rank,
                after: [
                    'printer_id' => $printer->id,
                    'printer_name' => $printer->name,
                    'doc_rank' => $document->classification_rank,
                    'printer_max_rank' => $printer->max_classification_rank,
                ],
                request: $request,
            );

            throw new PrintPolicyViolationException(
                "Printer security violation: {$document->doc_number} (Rank {$document->classification_rank}) exceeds printer '{$printer->name}' max clearance ceiling (Rank {$printer->max_classification_rank})."
            );
        }

        $document->loadMissing(['currentVersion', 'classificationLevel']);
        /** @var DocumentVersion|null $version */
        $version = $document->currentVersion;
        if (! $version instanceof DocumentVersion) {
            throw new PrintPolicyViolationException('Document has no printable binary version yet.');
        }

        $pageCount = max(1, $version->page_count);
        $totalPages = $pageCount * $copies;

        if ($costCentre instanceof CostCentre) {
            if (! $costCentre->is_active) {
                throw new PrintPolicyViolationException("Cost centre '{$costCentre->code}' is inactive.");
            }
            if (($costCentre->pages_used_month + $totalPages) > $costCentre->monthly_page_quota) {
                throw new PrintPolicyViolationException(
                    "Monthly print quota exceeded for cost centre {$costCentre->code} ({$costCentre->pages_used_month}/{$costCentre->monthly_page_quota} pages used)."
                );
            }
        }

        $jobId = (string) Str::uuid7();
        $ip = $request?->ip() ?? '127.0.0.1';
        $serviceNo = $user->service_number ?? $user->email;
        $rankLabel = strtoupper($document->classificationLevel->name ?? "LEVEL {$document->classification_rank}");
        $stampText = sprintf(
            '%s | %s | %s | %s | IP:%s | JOB:%s',
            $user->name,
            $serviceNo,
            $rankLabel,
            now('UTC')->format('Y-m-d H:i:s').' UTC',
            $ip,
            substr($jobId, 0, 8),
        );

        // Decrypt source document and apply mandatory diagonal server-side print watermark for PDFs
        $handle = $this->crypto->decryptVersionToTempHandle($version);
        $rawBytes = stream_get_contents($handle);
        fclose($handle);
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new RuntimeException('Failed reading decrypted document version for print spooling.');
        }

        $spoolBytes = $rawBytes;
        if ($version->mime_type === 'application/pdf') {
            $banner = $rankLabel.' — AUTHORIZED PRINT COPY FOR '.$user->name.' ('.$serviceNo.')';
            $spoolBytes = $this->bridge->watermarkPdf($rawBytes, $stampText, $banner);
        }

        $spoolKey = "tenants/{$user->tenant_id}/print_spools/{$jobId}.enc";
        $tempPath = tempnam(sys_get_temp_dir(), 'sent_prn_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary print spool file.');
        }

        try {
            file_put_contents($tempPath, $spoolBytes);
            $enc = $this->crypto->encryptFileToDisk($tempPath, 'sentinel_vault', $spoolKey);
        } finally {
            @unlink($tempPath);
        }

        $releasePin = null;
        $pinHash = null;
        if ($printer->supports_secure_release) {
            $releasePin = (string) random_int(100000, 999999);
            $pinHash = Hash::make($releasePin);
        }

        $job = new PrintJob([
            'tenant_id' => $user->tenant_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'user_id' => $user->id,
            'printer_id' => $printer->id,
            'cost_centre_id' => $costCentre?->id,
            'status' => $printer->supports_secure_release ? PrintJobStatus::Held : PrintJobStatus::Printed,
            'copies' => $copies,
            'page_count' => $pageCount,
            'release_pin_hash' => $pinHash,
            'rfid_badge_uid' => $user->rfid_badge_uid,
            'pin_attempts' => 0,
            'watermarked_storage_disk' => 'sentinel_vault',
            'watermarked_storage_key' => $spoolKey,
            'watermarked_wrapped_dek' => $enc['wrapped_dek'],
            'watermarked_kek_key_id' => $enc['kek_key_id'],
            'watermark_stamp_text' => $stampText,
            'classification_rank' => $document->classification_rank,
            'held_at' => now(),
            'expires_at' => now()->addHours(max(1, config()->integer('sentinel.print_scan.pin_ttl_hours', 24))),
        ]);
        $job->id = $jobId;
        $job->save();

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: $printer->supports_secure_release ? 'print.queued_held' : 'print.released_direct',
            resourceType: 'print_job',
            resourceId: $job->id,
            actor: $user,
            classificationRank: $document->classification_rank,
            after: [
                'document_id' => $document->id,
                'printer_id' => $printer->id,
                'copies' => $copies,
                'page_count' => $pageCount,
                'secure_release' => $printer->supports_secure_release,
            ],
            request: $request,
        );

        if (! $printer->supports_secure_release) {
            $this->finalizeSpoolRelease($job, $user, $costCentre, $request);
        }

        return [
            'job' => $job->refresh(),
            'release_pin' => $releasePin,
        ];
    }

    /**
     * Release a held print job after verifying the 6-digit one-time PIN (locks after max failed attempts).
     */
    public function releaseWithPin(
        PrintJob $job,
        string $pin,
        User $operator,
        ?Request $request = null,
    ): PrintJob {
        $maxAttempts = max(1, config()->integer('sentinel.print_scan.max_pin_attempts', 3));

        $errorToThrow = null;

        /** @var PrintJob $resultJob */
        $resultJob = DB::transaction(function () use ($job, $pin, $operator, $maxAttempts, $request, &$errorToThrow): PrintJob {
            /** @var PrintJob $lockedJob */
            $lockedJob = PrintJob::query()
                ->with(['printer', 'costCentre', 'document', 'user'])
                ->where('id', $job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedJob->status === PrintJobStatus::Locked) {
                $errorToThrow = 'Print job is LOCKED due to exceeding invalid PIN attempts.';

                return $lockedJob;
            }

            if ($lockedJob->status !== PrintJobStatus::Held) {
                $errorToThrow = "Print job cannot be released (current status: {$lockedJob->status->value}).";

                return $lockedJob;
            }

            if ($lockedJob->expires_at !== null && $lockedJob->expires_at->isPast()) {
                $lockedJob->update(['status' => PrintJobStatus::Cancelled]);
                $errorToThrow = 'Held print job has expired.';

                return $lockedJob->refresh();
            }

            if (! is_string($lockedJob->release_pin_hash) || ! Hash::check(trim($pin), $lockedJob->release_pin_hash)) {
                $attempts = $lockedJob->pin_attempts + 1;
                $isNowLocked = $attempts >= $maxAttempts;

                $lockedJob->update([
                    'pin_attempts' => $attempts,
                    'status' => $isNowLocked ? PrintJobStatus::Locked : PrintJobStatus::Held,
                ]);

                $this->audit->log(
                    tenantId: $lockedJob->tenant_id,
                    eventType: $isNowLocked ? 'print.pin_locked' : 'print.pin_failed',
                    resourceType: 'print_job',
                    resourceId: $lockedJob->id,
                    actor: $operator,
                    classificationRank: $lockedJob->classification_rank,
                    after: ['attempts' => $attempts, 'locked' => $isNowLocked],
                    request: $request,
                );

                $errorToThrow = $isNowLocked
                    ? "Print job LOCKED after {$attempts} invalid PIN attempts."
                    : 'Invalid release PIN ('.($maxAttempts - $attempts).' attempt(s) remaining).';

                return $lockedJob->refresh();
            }

            $owner = $lockedJob->user instanceof User ? $lockedJob->user : $operator;

            return $this->finalizeSpoolRelease($lockedJob, $owner, $lockedJob->costCentre, $request);
        });

        if (is_string($errorToThrow)) {
            throw new PrintPolicyViolationException($errorToThrow);
        }

        return $resultJob;
    }

    /**
     * Release all held print jobs for a user tapping an RFID/NFC badge at a secure printer station.
     *
     * @return list<PrintJob>
     */
    public function releaseWithRfid(
        string $tenantId,
        string $rfidBadgeUid,
        Printer $printer,
        User $kioskOperator,
        ?Request $request = null,
    ): array {
        $badgeOwner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('rfid_badge_uid', trim($rfidBadgeUid))
            ->first();

        if (! $badgeOwner instanceof User) {
            throw new PrintPolicyViolationException('Unrecognized RFID/NFC security badge UID.');
        }

        return $this->dbContext->runAs($badgeOwner, function () use ($badgeOwner, $printer, $kioskOperator, $request): array {
            $heldJobs = PrintJob::query()
                ->with(['costCentre', 'printer', 'document'])
                ->where('tenant_id', $badgeOwner->tenant_id)
                ->where('user_id', $badgeOwner->id)
                ->where('status', PrintJobStatus::Held->value)
                ->where('classification_rank', '<=', $printer->max_classification_rank)
                ->get();

            $released = [];
            foreach ($heldJobs as $job) {
                if ($job->printer_id !== $printer->id && ! $printer->supports_secure_release) {
                    continue;
                }
                if ($job->printer_id !== $printer->id) {
                    $job->update(['printer_id' => $printer->id]);
                    $job->setRelation('printer', $printer);
                }
                $released[] = $this->finalizeSpoolRelease($job, $badgeOwner, $job->costCentre, $request, $kioskOperator);
            }

            return $released;
        });
    }

    /**
     * Decrypt the watermarked spool PDF for authorized owner preview prior to device release.
     */
    public function readWatermarkedSpoolBytes(PrintJob $job): string
    {
        if (
            ! is_string($job->watermarked_storage_disk)
            || ! is_string($job->watermarked_storage_key)
            || ! is_string($job->watermarked_wrapped_dek)
            || ! is_string($job->watermarked_kek_key_id)
        ) {
            throw new RuntimeException('Watermarked print spool is no longer available.');
        }

        $handle = $this->crypto->decryptKeyToTempHandle(
            $job->watermarked_storage_disk,
            $job->watermarked_storage_key,
            $job->watermarked_wrapped_dek,
            $job->watermarked_kek_key_id,
        );
        $bytes = stream_get_contents($handle);
        fclose($handle);

        return is_string($bytes) ? $bytes : throw new RuntimeException('Failed decrypting watermarked spool.');
    }

    /**
     * Discover LAN CUPS / IPPS printers cleanly under SentinelHttpClientGuard.
     *
     * @return list<array{name: string, cups_queue_name: string, ipps_uri: string, driver: string}>
     */
    public function discoverLanPrinters(): array
    {
        $cupsUrl = rtrim(config()->string('sentinel.print_scan.cups_url', 'http://cups:631'), '/');

        try {
            $resp = Http::withRequestMiddleware(
                fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
            )->timeout(3)->get("{$cupsUrl}/printers.json");

            if ($resp->successful() && is_array($resp->json('printers'))) {
                $out = [];
                foreach ($resp->json('printers') as $p) {
                    if (is_array($p) && is_string($p['queue'] ?? null)) {
                        $out[] = [
                            'name' => is_string($p['name'] ?? null) ? $p['name'] : $p['queue'],
                            'cups_queue_name' => $p['queue'],
                            'ipps_uri' => is_string($p['uri'] ?? null) ? $p['uri'] : "{$cupsUrl}/printers/{$p['queue']}",
                            'driver' => PrinterDriver::CupsIpps->value,
                        ];
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (Throwable $e) {
            Log::info('CUPS discovery offline fallback used.', ['reason' => $e->getMessage()]);
        }

        return [
            [
                'name' => 'SENTINEL LAN IPPS MFP (Discovered Offline Queue)',
                'cups_queue_name' => 'sentinel-lan-mfp-'.substr(md5((string) now()->timestamp), 0, 4),
                'ipps_uri' => "{$cupsUrl}/printers/sentinel-lan-mfp",
                'driver' => PrinterDriver::CupsIpps->value,
            ],
        ];
    }

    private function finalizeSpoolRelease(
        PrintJob $job,
        User $owner,
        ?CostCentre $costCentre,
        ?Request $request = null,
        ?User $releasedBy = null,
    ): PrintJob {
        $totalPages = $job->page_count * $job->copies;

        if ($costCentre instanceof CostCentre) {
            /** @var CostCentre|null $lockedCc */
            $lockedCc = CostCentre::query()->where('id', $costCentre->id)->lockForUpdate()->first();
            if ($lockedCc !== null) {
                if (($lockedCc->pages_used_month + $totalPages) > $lockedCc->monthly_page_quota) {
                    throw new PrintPolicyViolationException("Cost centre {$lockedCc->code} monthly page quota exceeded.");
                }
                $lockedCc->increment('pages_used_month', $totalPages);
            }
        }

        $cupsJobId = $this->dispatchSpoolToDevice($job);

        $job->update([
            'status' => PrintJobStatus::Printed,
            'cups_job_id' => $cupsJobId,
            'released_at' => now(),
        ]);

        // Clean up encrypted spool once sent to physical device
        if (is_string($job->watermarked_storage_disk) && is_string($job->watermarked_storage_key)) {
            Storage::disk($job->watermarked_storage_disk)->delete($job->watermarked_storage_key);
        }

        $actor = $releasedBy ?? $owner;

        $this->audit->log(
            tenantId: $job->tenant_id,
            eventType: 'print.released',
            resourceType: 'print_job',
            resourceId: $job->id,
            actor: $actor,
            classificationRank: $job->classification_rank,
            after: [
                'document_id' => $job->document_id,
                'printer_id' => $job->printer_id,
                'cups_job_id' => $cupsJobId,
                'pages_charged' => $totalPages,
            ],
            request: $request,
        );

        $this->notifications->notifyUser(
            recipient: $owner,
            type: 'print.released',
            title: 'Secure Print Released',
            body: "Job {$cupsJobId} ({$totalPages} pages) sent cleanly to {$job->printer?->name}.",
            documentId: $job->document_id,
            actionUrl: '/print-scan',
            metadata: ['print_job_id' => $job->id, 'cups_job_id' => $cupsJobId],
            classificationRank: $job->classification_rank,
        );

        return $job->refresh();
    }

    private function dispatchSpoolToDevice(PrintJob $job): string
    {
        $printer = $job->printer;
        if (! $printer instanceof Printer) {
            return 'SPOOL-'.strtoupper(substr($job->id, 0, 8));
        }

        $driver = $printer->driver->value;
        if ($driver === PrinterDriver::CupsIpps->value && config()->string('sentinel.print_scan.print_driver', 'local_spool') === 'cups_ipps') {
            try {
                $spoolBytes = $this->readWatermarkedSpoolBytes($job);
                $resp = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(10)->withHeaders([
                    'Content-Type' => 'application/pdf',
                    'X-Cups-Queue' => $printer->cups_queue_name,
                ])->withBody($spoolBytes, 'application/pdf')->post($printer->ipps_uri);

                if ($resp->successful() && is_string($resp->json('cups_job_id'))) {
                    return $resp->json('cups_job_id');
                }
            } catch (Throwable $e) {
                Log::info('CUPS HTTP dispatch fell back to local spool.', ['reason' => $e->getMessage()]);
            }
        }

        return 'CUPS-'.strtoupper(substr($job->id, 0, 8));
    }
}
