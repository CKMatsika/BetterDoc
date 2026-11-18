<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\CompliancePack;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Crypto\EnvelopeEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class CompliancePackService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly EnvelopeEncryptionService $crypto,
    ) {}

    /**
     * Build a signed, cryptographic Regulator Export Bundle (ZIP encrypted at rest with manifest SHA-256 & checkpoint HMAC).
     */
    public function generateRegulatorPack(
        Tenant $tenant,
        User $auditor,
        string $regulationStandard,
        string $packName = '',
        ?Request $request = null,
    ): CompliancePack {
        $chainCheck = $this->audit->verifyChain($tenant);
        if (! $chainCheck['valid']) {
            throw new RuntimeException('Refusing compliance pack generation: audit chain integrity failure ('.($chainCheck['reason'] ?? 'unknown').').');
        }

        $checkpointCheck = $this->audit->verifyCheckpoints($tenant);
        if (! $checkpointCheck['valid']) {
            throw new RuntimeException('Refusing compliance pack generation: checkpoint signature verification failed.');
        }

        $checkpoint = AuditCheckpoint::query()->where('tenant_id', $tenant->id)->latest()->first();
        $maxSeqRaw = AuditEvent::query()->where('tenant_id', $tenant->id)->max('seq_id');
        $latestSeq = is_numeric($maxSeqRaw) ? (int) $maxSeqRaw : 0;
        if ($checkpoint === null || $checkpoint->last_seq_id < $latestSeq) {
            $checkpoint = $this->audit->createCheckpoint($tenant);
        }

        $events = AuditEvent::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('seq_id')
            ->get();

        $jsonlLines = [];
        foreach ($events as $ev) {
            $jsonlLines[] = json_encode($this->audit->formatEventForViewer($ev, $auditor), JSON_THROW_ON_ERROR);
        }
        $jsonlContent = implode("\n", $jsonlLines);

        $checkpointsJson = json_encode(
            AuditCheckpoint::query()->where('tenant_id', $tenant->id)->orderBy('created_at')->get()->toArray(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );

        $verificationJson = json_encode([
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'regulation_standard' => $regulationStandard,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'generated_by' => ['id' => $auditor->id, 'email' => $auditor->email],
            'chain_verification' => $chainCheck,
            'checkpoints_verification' => $this->audit->verifyCheckpoints($tenant),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $fileHashes = [
            'audit_events.jsonl' => hash('sha256', $jsonlContent),
            'audit_checkpoints.json' => hash('sha256', $checkpointsJson),
            'chain_verification.json' => hash('sha256', $verificationJson),
        ];

        $headHash = $chainCheck['head_hash'] ?? str_repeat('0', 64);
        $manifestPayload = [
            'product' => 'BetterDoc DMS',
            'tenant_id' => $tenant->id,
            'standard' => $regulationStandard,
            'event_count' => $chainCheck['total_events'],
            'head_hash' => $headHash,
            'checkpoint_id' => $checkpoint?->id,
            'checkpoint_hmac' => $checkpoint?->signature_hmac,
            'files' => $fileHashes,
        ];
        $manifestJson = json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $manifestSha = hash('sha256', $manifestJson);
        $packHmac = hash_hmac('sha256', $manifestSha.'|'.$headHash, config()->string('app.key'));

        $tmpZip = tempnam(sys_get_temp_dir(), 'bdoc_pack_').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create regulator compliance ZIP bundle.');
        }

        $zip->addFromString('audit_events.jsonl', $jsonlContent);
        $zip->addFromString('audit_checkpoints.json', $checkpointsJson);
        $zip->addFromString('chain_verification.json', $verificationJson);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->close();

        $packId = (string) Str::uuid7();
        $storageKey = "tenants/{$tenant->id}/compliance/{$packId}.zip.enc";

        try {
            $enc = $this->crypto->encryptFileToDisk($tmpZip, 'betterdoc_vault', $storageKey);
        } finally {
            @unlink($tmpZip);
        }

        $resolvedName = trim($packName) !== ''
            ? trim($packName)
            : "{$regulationStandard} Compliance Pack — ".now('UTC')->format('Y-m-d H:i');

        $pack = new CompliancePack([
            'tenant_id' => $tenant->id,
            'generated_by' => $auditor->id,
            'pack_name' => $resolvedName,
            'regulation_standard' => $regulationStandard,
            'event_count' => $chainCheck['total_events'],
            'head_hash' => $headHash,
            'checkpoint_id' => $checkpoint?->id,
            'storage_disk' => 'betterdoc_vault',
            'storage_key' => $storageKey,
            'wrapped_dek' => $enc['wrapped_dek'],
            'kek_key_id' => $enc['kek_key_id'],
            'manifest_sha256' => $manifestSha,
            'signature_hmac' => $packHmac,
        ]);
        $pack->id = $packId;
        $pack->save();

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'compliance.pack_generated',
            resourceType: 'compliance_pack',
            resourceId: $pack->id,
            actor: $auditor,
            classificationRank: $auditor->clearance_rank,
            after: [
                'standard' => $regulationStandard,
                'event_count' => $pack->event_count,
                'manifest_sha256' => $manifestSha,
            ],
            request: $request,
        );

        return $pack->refresh();
    }

    /**
     * Decrypt a CompliancePack bundle and cryptographically verify its manifest, file SHA-256s, hash chain, and checkpoint HMACs.
     *
     * @return array{zip_bytes: string, manifest_valid: bool, files_valid: bool, chain_valid: bool, checkpoints_valid: bool, files: list<string>}
     */
    public function verifyAndReadPack(CompliancePack $pack, Tenant $tenant): array
    {
        $handle = $this->crypto->decryptKeyToTempHandle(
            $pack->storage_disk,
            $pack->storage_key,
            $pack->wrapped_dek,
            $pack->kek_key_id,
        );
        $zipBytes = stream_get_contents($handle);
        fclose($handle);

        if (! is_string($zipBytes) || $zipBytes === '') {
            throw new RuntimeException('Unable to read encrypted compliance bundle.');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'bdoc_vpk_').'.zip';
        file_put_contents($tmpZip, $zipBytes);

        $manifestValid = false;
        $filesValid = false;
        $filesList = [];

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmpZip) !== true) {
                throw new RuntimeException('Corrupted compliance ZIP archive.');
            }

            $manifestRaw = $zip->getFromName('manifest.json');
            if (is_string($manifestRaw)) {
                $manifestSha = hash('sha256', $manifestRaw);
                $expectedHmac = hash_hmac('sha256', $manifestSha.'|'.$pack->head_hash, config()->string('app.key'));
                $manifestValid = hash_equals($pack->manifest_sha256, $manifestSha)
                    && hash_equals($pack->signature_hmac, $expectedHmac);

                /** @var mixed $decoded */
                $decoded = json_decode($manifestRaw, true);
                if (is_array($decoded) && is_array($decoded['files'] ?? null)) {
                    $allFilesOk = true;
                    foreach ($decoded['files'] as $fname => $expectedHash) {
                        if (! is_string($fname) || ! is_string($expectedHash)) {
                            continue;
                        }
                        $filesList[] = $fname;
                        $content = $zip->getFromName($fname);
                        if (! is_string($content) || ! hash_equals($expectedHash, hash('sha256', $content))) {
                            $allFilesOk = false;
                        }
                    }
                    $filesValid = $allFilesOk && $filesList !== [];
                }
            }
            $zip->close();
        } finally {
            @unlink($tmpZip);
        }

        $chainRes = $this->audit->verifyChain($tenant);
        $cpRes = $this->audit->verifyCheckpoints($tenant);

        return [
            'zip_bytes' => $zipBytes,
            'manifest_valid' => $manifestValid,
            'files_valid' => $filesValid,
            'chain_valid' => $chainRes['valid'],
            'checkpoints_valid' => $cpRes['valid'],
            'files' => $filesList,
        ];
    }

    /**
     * Export recent immutable audit events formatted strictly in RFC 5424 Syslog format (and optionally stream UDP to SIEM).
     *
     * @return array{lines: list<string>, transmitted_udp: int}
     */
    public function exportRfc5424Syslog(Tenant $tenant, int $limit = 50): array
    {
        $events = AuditEvent::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('seq_id')
            ->limit(min(500, max(1, $limit)))
            ->get()
            ->reverse()
            ->values();

        $appName = config()->string('betterdoc.compliance.siem_app_name', 'betterdoc-dms');
        $lines = [];

        foreach ($events as $ev) {
            $severity = $ev->classification_rank >= 3 ? 4 : 6; // warning for Secret+ / info otherwise (facility 16 local0 -> pri 132 or 134)
            $pri = (16 * 8) + $severity;
            $ts = $ev->occurred_at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z');
            $sd = sprintf(
                '[betterdoc@59999 tenant="%s" seq="%d" rank="%d" resource="%s:%s" sha256="%s"]',
                $tenant->slug,
                $ev->seq_id,
                $ev->classification_rank,
                $ev->resource_type,
                $ev->resource_id ?? 'none',
                $ev->entry_hash
            );
            $lines[] = "<{$pri}>1 {$ts} betterdoc.lan {$appName} - {$ev->event_type} {$sd} Audit event {$ev->event_type} recorded";
        }

        $transmitted = 0;
        if (config()->boolean('betterdoc.compliance.siem_syslog_enabled', false)) {
            $host = config()->string('betterdoc.compliance.siem_syslog_host', '127.0.0.1');
            $port = config()->integer('betterdoc.compliance.siem_syslog_port', 5140);
            $sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, 1);
            if (is_resource($sock)) {
                foreach ($lines as $l) {
                    @fwrite($sock, $l."\n");
                    $transmitted++;
                }
                fclose($sock);
            }
        }

        return [
            'lines' => $lines,
            'transmitted_udp' => $transmitted,
        ];
    }
}
