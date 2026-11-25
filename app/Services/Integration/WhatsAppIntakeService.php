<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Exceptions\AirGapViolationException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppMessage;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\PrintScan\ScanIngestionService;
use App\Services\Search\DocumentSearchService;
use App\Services\Security\BetterDocHttpClientGuard;
use App\Services\Security\DbSecurityContext;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppIntakeService
{
    public function __construct(
        private readonly DbSecurityContext $dbContext,
        private readonly ScanIngestionService $scanIngestion,
        private readonly DocumentSearchService $search,
        private readonly BetterDocHttpClientGuard $httpGuard,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Register or update a tenant's WhatsApp Business Cloud API channel.
     *
     * @param  array{
     *     display_name: string,
     *     phone_number_id: string,
     *     business_account_id?: string|null,
     *     app_secret?: string|null,
     *     access_token?: string|null,
     *     default_cabinet_id: string,
     *     default_classification_level_id: string,
     *     auto_idp_invoices?: bool
     * }  $data
     * @return array{channel: WhatsAppChannel, verify_token: string}
     */
    public function configureChannel(User $actor, array $data, ?Request $request = null): array
    {
        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('tenant_id', $actor->tenant_id)->findOrFail($data['default_cabinet_id']);
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('rank', '<=', $actor->clearance_rank)
            ->findOrFail($data['default_classification_level_id']);

        $verifyToken = 'wa_verify_'.Str::random(24);

        /** @var WhatsAppChannel $channel */
        $channel = WhatsAppChannel::query()->create([
            'tenant_id' => $actor->tenant_id,
            'created_by' => $actor->id,
            'display_name' => trim($data['display_name']),
            'phone_number_id' => trim($data['phone_number_id']),
            'business_account_id' => $data['business_account_id'] ?? null,
            'verify_token_hash' => hash('sha256', $verifyToken),
            'app_secret_encrypted' => ! empty($data['app_secret']) ? trim($data['app_secret']) : null,
            'access_token_encrypted' => ! empty($data['access_token']) ? trim($data['access_token']) : null,
            'default_cabinet_id' => $cabinet->id,
            'default_classification_level_id' => $level->id,
            'auto_idp_invoices' => (bool) ($data['auto_idp_invoices'] ?? true),
            'is_active' => true,
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'whatsapp.channel_configured',
            resourceType: 'whatsapp_channel',
            resourceId: $channel->id,
            actor: $actor,
            classificationRank: $level->rank,
            after: [
                'display_name' => $channel->display_name,
                'phone_number_id' => $channel->phone_number_id,
            ],
            request: $request,
        );

        return [
            'channel' => $channel,
            'verify_token' => $verifyToken,
        ];
    }

    /**
     * Validate Meta WhatsApp webhook subscription challenge (`GET /api/v1/webhooks/whatsapp`).
     */
    public function verifyWebhookChallenge(string $verifyToken, string $challenge): ?string
    {
        $trimmed = trim($verifyToken);
        if ($trimmed === '') {
            return null;
        }

        $ref = $this->lookupChannelRef(null, hash('sha256', $trimmed));

        return $ref !== null ? $challenge : null;
    }

    /**
     * Process an inbound Meta WhatsApp Business Cloud API webhook payload (or LAN WhatsApp gateway relay).
     * Verifies X-Hub-Signature-256 HMAC when app_secret is configured, ingests media through Malware/OCR/IDP,
     * auto-tags Policy/Claim references from captions, and writes immutable audit events.
     *
     * @param  array<string, mixed>  $payload
     * @return array{processed: int, ingested_document_ids: list<string>, messages: list<array<string, mixed>>}
     */
    public function handleInboundWebhook(
        string $rawBody,
        ?string $hubSignatureHeader,
        array $payload,
        ?Request $request = null,
    ): array {
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        $processed = 0;
        $ingestedDocIds = [];
        $recordedMessages = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $value = $change['value'];
                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                $phoneNumberId = is_string($metadata['phone_number_id'] ?? null) ? trim($metadata['phone_number_id']) : '';

                if ($phoneNumberId === '') {
                    continue;
                }

                $ref = $this->lookupChannelRef($phoneNumberId, null);
                if ($ref === null) {
                    continue;
                }

                $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
                $senderName = 'WhatsApp Sender';
                if (is_array($contacts[0] ?? null) && is_array($contacts[0]['profile'] ?? null) && is_string($contacts[0]['profile']['name'] ?? null)) {
                    $senderName = trim($contacts[0]['profile']['name']);
                }

                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];

                $this->dbContext->runForTenant($ref['tenant_id'], function () use (
                    $ref,
                    $rawBody,
                    $hubSignatureHeader,
                    $messages,
                    $senderName,
                    $request,
                    &$processed,
                    &$ingestedDocIds,
                    &$recordedMessages,
                ): void {
                    /** @var WhatsAppChannel|null $channel */
                    $channel = WhatsAppChannel::query()
                        ->with(['creator', 'tenant'])
                        ->find($ref['channel_id']);

                    if ($channel === null || ! $channel->is_active) {
                        return;
                    }

                    // Fail-closed HMAC-SHA256 verification when channel has app_secret configured
                    if (is_string($channel->app_secret_encrypted) && $channel->app_secret_encrypted !== '') {
                        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $channel->app_secret_encrypted);
                        if (! is_string($hubSignatureHeader) || ! hash_equals($expected, trim($hubSignatureHeader))) {
                            throw new RuntimeException('Invalid WhatsApp X-Hub-Signature-256 signature.');
                        }
                    }

                    $creator = $channel->creator;
                    $tenant = $channel->tenant;

                    if (! $creator instanceof User || ! $tenant instanceof Tenant) {
                        return;
                    }

                    $this->dbContext->runAs($creator, function () use (
                        $channel,
                        $tenant,
                        $creator,
                        $messages,
                        $senderName,
                        $request,
                        &$processed,
                        &$ingestedDocIds,
                        &$recordedMessages,
                    ): void {
                        /** @var Cabinet|null $cabinet */
                        $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->find($channel->default_cabinet_id);
                        /** @var ClearanceLevel|null $level */
                        $level = ClearanceLevel::query()->where('tenant_id', $tenant->id)->find($channel->default_classification_level_id);
                        if ($cabinet === null || $level === null) {
                            return;
                        }

                        foreach ($messages as $rawMsg) {
                            if (! is_array($rawMsg)) {
                                continue;
                            }

                            /** @var array<string, mixed> $msg */
                            $msg = [];
                            foreach ($rawMsg as $mk => $mv) {
                                if (is_string($mk)) {
                                    $msg[$mk] = $mv;
                                }
                            }

                            $waId = is_string($msg['id'] ?? null) ? $msg['id'] : null;
                            $from = is_string($msg['from'] ?? null) ? $msg['from'] : 'unknown';
                            $type = is_string($msg['type'] ?? null) ? $msg['type'] : 'text';
                            $caption = $this->extractCaptionOrBody($msg, $type);
                            $docId = null;
                            $status = 'received';

                            $media = $this->extractMediaPayload($channel, $msg, $type);
                            if ($media !== null) {
                                $batch = $this->scanIngestion->ingestBatch(
                                    tenant: $tenant,
                                    actor: $creator,
                                    cabinet: $cabinet,
                                    defaultLevel: $level,
                                    sourceChannel: 'whatsapp_business',
                                    filename: $media['filename'],
                                    rawBytes: $media['bytes'],
                                    options: [
                                        'auto_split_barcode' => false,
                                        'auto_deskew' => true,
                                        'auto_idp' => $channel->auto_idp_invoices,
                                        'title_prefix' => $caption !== '' ? mb_substr($caption, 0, 120) : "WhatsApp from {$senderName}",
                                    ],
                                    request: $request,
                                );

                                $createdIds = is_array($batch->report_json['created_doc_ids'] ?? null)
                                    ? array_values(array_filter($batch->report_json['created_doc_ids'], 'is_string'))
                                    : [];

                                if ($createdIds !== []) {
                                    /** @var Document $doc */
                                    $doc = Document::query()->where('tenant_id', $tenant->id)->findOrFail($createdIds[0]);
                                    $refs = $this->parseInsuranceRefs($caption);
                                    $meta = is_array($doc->metadata_json) ? $doc->metadata_json : [];
                                    $meta['whatsapp_intake'] = [
                                        'channel_id' => $channel->id,
                                        'from_phone' => $from,
                                        'sender_name' => $senderName,
                                        'wa_message_id' => $waId,
                                        'caption' => $caption,
                                    ];

                                    $doc->update([
                                        'policy_number' => $refs['policy_number'] ?? $doc->policy_number,
                                        'claim_number' => $refs['claim_number'] ?? $doc->claim_number,
                                        'metadata_json' => $meta,
                                    ]);
                                    $this->search->indexDocument($doc->refresh());

                                    $docId = $doc->id;
                                    $status = 'ingested';
                                    $ingestedDocIds[] = $doc->id;
                                }
                            }

                            /** @var WhatsAppMessage $recorded */
                            $recorded = WhatsAppMessage::query()->create([
                                'tenant_id' => $tenant->id,
                                'whatsapp_channel_id' => $channel->id,
                                'direction' => 'inbound',
                                'wa_message_id' => $waId,
                                'from_phone' => $from,
                                'to_phone' => $channel->phone_number_id,
                                'sender_name' => $senderName,
                                'message_type' => $type,
                                'body_text' => $caption !== '' ? $caption : null,
                                'document_id' => $docId,
                                'status' => $status,
                                'metadata_json' => ['channel_name' => $channel->display_name],
                            ]);

                            $this->audit->log(
                                tenantId: $tenant->id,
                                eventType: $docId !== null ? 'whatsapp.document_ingested' : 'whatsapp.message_received',
                                resourceType: 'whatsapp_message',
                                resourceId: $recorded->id,
                                actor: $creator,
                                classificationRank: $level->rank,
                                after: [
                                    'from_phone' => $from,
                                    'message_type' => $type,
                                    'document_id' => $docId,
                                ],
                                request: $request,
                            );

                            $processed++;
                            $recordedMessages[] = [
                                'id' => $recorded->id,
                                'from_phone' => $recorded->from_phone,
                                'document_id' => $recorded->document_id,
                                'status' => $recorded->status,
                            ];
                        }

                        $channel->update(['last_inbound_at' => now()]);
                    });
                });
            }
        }

        return [
            'processed' => $processed,
            'ingested_document_ids' => $ingestedDocIds,
            'messages' => $recordedMessages,
        ];
    }

    /**
     * Send an executive approval alert or portal magic-link notification via WhatsApp Business Cloud API.
     */
    public function sendApprovalAlert(
        WhatsAppChannel $channel,
        User $actor,
        string $recipientPhone,
        string $subject,
        string $actionUrl,
        ?Request $request = null,
    ): WhatsAppMessage {
        if ($channel->tenant_id !== $actor->tenant_id) {
            throw new RuntimeException('Cross-tenant WhatsApp dispatch forbidden.');
        }

        $body = "BetterDoc Alert: {$subject}\nOpen securely: {$actionUrl}";
        $url = "https://graph.facebook.com/v20.0/{$channel->phone_number_id}/messages";
        $status = 'sent';
        $meta = ['action_url' => $actionUrl];

        try {
            $this->httpGuard->assertOutboundAllowed(new Psr7Request('POST', $url));

            if (is_string($channel->access_token_encrypted) && $channel->access_token_encrypted !== '') {
                $resp = Http::withToken($channel->access_token_encrypted)
                    ->timeout(15)
                    ->post($url, [
                        'messaging_product' => 'whatsapp',
                        'to' => preg_replace('/[^0-9]/', '', $recipientPhone),
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]);

                if (! $resp->successful()) {
                    $status = 'failed';
                    $meta['http_status'] = $resp->status();
                } else {
                    $meta['wa_response'] = $resp->json();
                }
            } else {
                $status = 'queued_no_token';
            }
        } catch (AirGapViolationException $e) {
            $status = 'blocked_airgap';
            $meta['error'] = $e->getMessage();
        }

        /** @var WhatsAppMessage $msg */
        $msg = WhatsAppMessage::query()->create([
            'tenant_id' => $actor->tenant_id,
            'whatsapp_channel_id' => $channel->id,
            'direction' => 'outbound',
            'from_phone' => $channel->phone_number_id,
            'to_phone' => trim($recipientPhone),
            'sender_name' => $actor->name,
            'message_type' => 'approval_alert',
            'body_text' => $body,
            'status' => $status,
            'metadata_json' => $meta,
        ]);

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'whatsapp.approval_alert_dispatched',
            resourceType: 'whatsapp_message',
            resourceId: $msg->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: [
                'to_phone' => $msg->to_phone,
                'status' => $msg->status,
            ],
            request: $request,
        );

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    private function extractCaptionOrBody(array $msg, string $type): string
    {
        if ($type === 'text' && is_array($msg['text'] ?? null) && is_string($msg['text']['body'] ?? null)) {
            return trim($msg['text']['body']);
        }
        if (in_array($type, ['image', 'document'], true) && is_array($msg[$type] ?? null) && is_string($msg[$type]['caption'] ?? null)) {
            return trim($msg[$type]['caption']);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $msg
     * @return array{filename: string, bytes: string}|null
     */
    private function extractMediaPayload(WhatsAppChannel $channel, array $msg, string $type): ?array
    {
        if (! in_array($type, ['image', 'document'], true) || ! is_array($msg[$type] ?? null)) {
            return null;
        }

        $node = $msg[$type];
        $filename = is_string($node['filename'] ?? null) && trim($node['filename']) !== ''
            ? trim($node['filename'])
            : ($type === 'image' ? 'whatsapp-photo.jpg' : 'whatsapp-document.pdf');

        // Option 1: Direct base64 media payload (used by LAN WhatsApp relays and deterministic testing)
        if (is_string($node['media_base64'] ?? null) && $node['media_base64'] !== '') {
            $decoded = base64_decode($node['media_base64'], true);
            if (is_string($decoded) && $decoded !== '') {
                return ['filename' => $filename, 'bytes' => $decoded];
            }
        }

        // Option 2: Meta Graph API media download (when air-gap allows graph.facebook.com)
        $mediaId = is_string($node['id'] ?? null) ? trim($node['id']) : '';
        if ($mediaId !== '' && is_string($channel->access_token_encrypted) && $channel->access_token_encrypted !== '') {
            $metaUrl = "https://graph.facebook.com/v20.0/{$mediaId}";
            $this->httpGuard->assertOutboundAllowed(new Psr7Request('GET', $metaUrl));
            $metaResp = Http::withToken($channel->access_token_encrypted)->timeout(15)->get($metaUrl);
            if ($metaResp->successful()) {
                $downloadUrl = $metaResp->json('url');
                if (is_string($downloadUrl) && $downloadUrl !== '') {
                    $this->httpGuard->assertOutboundAllowed(new Psr7Request('GET', $downloadUrl));
                    $binResp = Http::withToken($channel->access_token_encrypted)->timeout(20)->get($downloadUrl);
                    if ($binResp->successful() && $binResp->body() !== '') {
                        return ['filename' => $filename, 'bytes' => $binResp->body()];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{policy_number: string|null, claim_number: string|null}
     */
    private function parseInsuranceRefs(string $text): array
    {
        $policy = null;
        $claim = null;

        if (preg_match('/\b(POL[\-\/][A-Z0-9\-\/]{3,24})\b/i', $text, $m) === 1) {
            $policy = strtoupper($m[1]);
        }
        if (preg_match('/\b(CLM[\-\/][A-Z0-9\-\/]{3,24})\b/i', $text, $m) === 1) {
            $claim = strtoupper($m[1]);
        }

        return ['policy_number' => $policy, 'claim_number' => $claim];
    }

    /**
     * @return array{channel_id: string, tenant_id: string}|null
     */
    private function lookupChannelRef(?string $phoneNumberId, ?string $verifyTokenHash): ?array
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne('SELECT channel_id, tenant_id FROM resolve_whatsapp_channel(?, ?)', [$phoneNumberId, $verifyTokenHash]);
            if ($row === null) {
                return null;
            }

            /** @var object{channel_id: string, tenant_id: string} $row */
            return [
                'channel_id' => $row->channel_id,
                'tenant_id' => $row->tenant_id,
            ];
        }

        $query = WhatsAppChannel::query()->where('is_active', true);
        if ($phoneNumberId !== null && $phoneNumberId !== '') {
            $query->where('phone_number_id', $phoneNumberId);
        } elseif ($verifyTokenHash !== null && $verifyTokenHash !== '') {
            $query->where('verify_token_hash', $verifyTokenHash);
        } else {
            return null;
        }

        $found = $query->first();

        return $found !== null ? ['channel_id' => $found->id, 'tenant_id' => $found->tenant_id] : null;
    }
}
