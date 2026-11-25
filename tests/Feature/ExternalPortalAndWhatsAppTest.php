<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\CorporateSeeder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExternalPortalAndWhatsAppTest extends TestCase
{
    public function test_external_magic_link_enforces_otp_ingests_upload_with_insurance_refs_and_seals_esignature(): void
    {
        $this->seed([CorporateSeeder::class]);

        $tenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
        $admin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'admin@zimcorp.co.zw')
            ->firstOrFail();

        /** @var array{cabinet: Cabinet, level: ClearanceLevel, doc: Document} $setup */
        $setup = app(DbSecurityContext::class)->runAs($admin, function () use ($tenant, $admin): array {
            $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $level = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->where('rank', '<=', $admin->clearance_rank)
                ->orderBy('rank')
                ->firstOrFail();

            $doc = Document::query()->create([
                'tenant_id' => $tenant->id,
                'cabinet_id' => $cabinet->id,
                'org_unit_id' => $admin->org_unit_id,
                'owner_id' => $admin->id,
                'classification_level_id' => $level->id,
                'classification_rank' => $level->rank,
                'doc_number' => 'CLM/2026/PORTAL-1',
                'title' => 'Motor Claim Discharge Agreement',
            ]);

            return ['cabinet' => $cabinet, 'level' => $level, 'doc' => $doc];
        });

        // 1. Staff creates OTP-protected magic-link request
        $createResp = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson('/external-portal', [
                'cabinet_id' => $setup['cabinet']->id,
                'classification_level_id' => $setup['level']->id,
                'document_id' => $setup['doc']->id,
                'request_type' => 'upload_and_esign',
                'title' => 'Motor Claim KYC & Discharge Form',
                'recipient_name' => 'Tendai Moyo',
                'recipient_phone' => '+263771234567',
                'policy_number' => 'POL-2026-8812',
                'claim_number' => 'CLM-2026-4410',
                'require_otp' => true,
                'max_uploads' => 3,
                'expires_in_hours' => 48,
            ])
            ->assertCreated();

        $token = (string) $createResp->json('plain_token');
        $otp = (string) $createResp->json('plain_otp');
        $this->assertNotSame('', $token);
        $this->assertSame(6, strlen($otp));

        // 2. Unauthenticated external user views branded portal
        auth()->forgetGuards();
        $this->get("/portal/{$token}")->assertOk();

        // 3. Wrong PIN is rejected
        $file = UploadedFile::fake()->createWithContent('police-report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->postJson("/portal/{$token}/upload", [
            'file' => $file,
            'otp' => '000000',
        ])->assertStatus(422);

        // 4. Correct PIN uploads & indexes with Policy + Claim numbers
        $uploadResp = $this->postJson("/portal/{$token}/upload", [
            'file' => UploadedFile::fake()->createWithContent('police-report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
            'otp' => $otp,
            'uploader_note' => 'ZRP Accident Report',
        ])->assertCreated();

        $uploadResp->assertJsonPath('policy_number', 'POL-2026-8812');
        $uploadResp->assertJsonPath('claim_number', 'CLM-2026-4410');

        // 5. External client electronically signs with National ID -> tamper-evident SHA-256 seal
        $signResp = $this->postJson("/portal/{$token}/sign", [
            'signer_name' => 'Tendai Moyo',
            'signer_id_number' => '63-123456-A-42',
            'otp' => $otp,
        ])->assertOk();

        $signResp->assertJsonPath('status', 'completed');
        $signResp->assertJsonPath('signed_by_name', 'Tendai Moyo');
        $this->assertSame(64, strlen((string) $signResp->json('signature_hash')));
    }

    public function test_whatsapp_webhook_verifies_challenge_hmac_signature_and_ingests_media_with_policy_ref(): void
    {
        $this->seed([CorporateSeeder::class]);

        $tenant = Tenant::query()->where('slug', 'zimcorp-corporate')->firstOrFail();
        $admin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'admin@zimcorp.co.zw')
            ->firstOrFail();

        /** @var array{cabinet: Cabinet, level: ClearanceLevel} $setup */
        $setup = app(DbSecurityContext::class)->runAs($admin, function () use ($tenant, $admin): array {
            $cabinet = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $level = ClearanceLevel::query()
                ->where('tenant_id', $tenant->id)
                ->where('rank', '<=', $admin->clearance_rank)
                ->orderBy('rank')
                ->firstOrFail();

            return ['cabinet' => $cabinet, 'level' => $level];
        });

        $appSecret = 'meta-test-secret-zim-2026';
        $phoneNumberId = '109988776655';

        // 1. Configure tenant WhatsApp Business channel
        $chanResp = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson('/external-portal/whatsapp/channels', [
                'display_name' => 'ZimCorp Claims Desk',
                'phone_number_id' => $phoneNumberId,
                'app_secret' => $appSecret,
                'default_cabinet_id' => $setup['cabinet']->id,
                'default_classification_level_id' => $setup['level']->id,
                'auto_idp_invoices' => true,
            ])
            ->assertCreated();

        $verifyToken = (string) $chanResp->json('verify_token');
        $channelId = (string) $chanResp->json('channel_id');

        // 2. Meta webhook subscription challenge verification
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token='.$verifyToken.'&hub_challenge=CHALLENGE-991')
            ->assertOk()
            ->assertSeeText('CHALLENGE-991');

        // 3. Inbound WhatsApp media webhook with valid HMAC-SHA256 signature
        $pdfBase64 = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $webhookPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => ['phone_number_id' => $phoneNumberId],
                                'contacts' => [['profile' => ['name' => 'Chipo Ndlovu']]],
                                'messages' => [
                                    [
                                        'id' => 'wamid.test.001',
                                        'from' => '263772998877',
                                        'type' => 'document',
                                        'document' => [
                                            'filename' => 'accident-invoice.pdf',
                                            'caption' => 'Attached quotation for POL-2026-5501 CLM-2026-9910',
                                            'media_base64' => $pdfBase64,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
        $validSig = 'sha256='.hash_hmac('sha256', $rawJson, $appSecret);

        // Invalid signature rejected fail-closed
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
        ], $rawJson)->assertStatus(403);

        // Valid signature ingests & auto-indexes Policy + Claim numbers
        $intakeResp = $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $validSig,
        ], $rawJson)->assertOk();

        $intakeResp->assertJsonPath('processed', 1);
        $ingestedDocId = (string) $intakeResp->json('ingested_document_ids.0');
        $this->assertNotSame('', $ingestedDocId);

        app(DbSecurityContext::class)->runAs($admin, function () use ($ingestedDocId): void {
            /** @var Document $doc */
            $doc = Document::query()->findOrFail($ingestedDocId);
            $this->assertSame('POL-2026-5501', $doc->policy_number);
            $this->assertSame('CLM-2026-9910', $doc->claim_number);
        });

        // 4. Outbound executive WhatsApp approval alert honors air-gap guard cleanly
        $alertResp = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true])
            ->postJson("/external-portal/whatsapp/channels/{$channelId}/alert", [
                'recipient_phone' => '+263772000111',
                'subject' => 'High-value AP Invoice awaiting sign-off',
                'action_url' => 'https://dms.zimcorp.co.zw/workflows/inbox',
            ])
            ->assertOk();

        $alertResp->assertJsonPath('message.status', 'blocked_airgap');
    }
}
