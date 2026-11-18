<?php

declare(strict_types=1);

use App\Enums\AnnotationKind;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentComment;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Dms\TemplateGenerationService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Support\Facades\Broadcast;
use Smalot\PdfParser\Parser as PdfParser;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('executes true destructive server-side PyMuPDF redaction removing all redacted text from PDF content stream', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $cabinet = $dbContext->runAs($wgCdr, static fn (): Cabinet => Cabinet::query()->where('tenant_id', $wgCdr->tenant_id)->firstOrFail());
    $level = ClearanceLevel::query()->where('tenant_id', $wgCdr->tenant_id)->where('rank', 2)->firstOrFail();

    $doc = $dbContext->runAs($wgCdr, function () use ($wgCdr, $cabinet, $level): Document {
        /** @var DocumentTemplate $tpl */
        $tpl = DocumentTemplate::query()->where('tenant_id', $wgCdr->tenant_id)->firstOrFail();

        return app(TemplateGenerationService::class)->generateDocument(
            template: $tpl,
            actor: $wgCdr,
            cabinet: $cabinet,
            level: $level,
            title: 'Redaction Proof Brief',
            variables: [
                'recipient_name' => 'Command Ops',
                'unit_code' => 'OPS-WING',
                'subject' => 'PUBLIC_HEADER_VISIBLE',
                'body_text' => 'SECRET_LAUNCH_TOKEN_987654 should be completely destroyed on the PDF page.',
            ],
        );
    });

    // Mark full lower body region of page 1 (y: 0.20..0.95) for redaction so target string falls inside box
    $this->signIn($wgCdr);
    $this->post("/documents/{$doc->id}/annotations", [
        'version_id' => $doc->current_version_id,
        'page_number' => 1,
        'kind' => AnnotationKind::RedactionMark->value,
        'x' => 0.05,
        'y' => 0.22,
        'width' => 0.90,
        'height' => 0.65,
        'content' => 'Remove sensitive secret launch token',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $mark = $dbContext->runAs($wgCdr, static fn (): DocumentAnnotation => DocumentAnnotation::query()->where('document_id', $doc->id)->firstOrFail());
    expect($mark->kind)->toBe(AnnotationKind::RedactionMark);

    $this->post("/documents/{$doc->id}/redactions/apply")
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, static fn () => $doc->refresh());
    $redactedStreamRes = $this->get("/documents/{$doc->id}/versions/{$doc->current_version_id}/stream")->assertOk();
    ob_start();
    $redactedStreamRes->sendContent();
    $redactedPdfBytes = (string) ob_get_clean();

    expect(str_starts_with($redactedPdfBytes, '%PDF-'))->toBeTrue();

    // Parse resulting PDF independently via smalot/pdfparser to prove redacted text is zero-extractable
    $parsedText = (new PdfParser)->parseContent($redactedPdfBytes)->getText();
    expect($parsedText)->not->toContain('SECRET_LAUNCH_TOKEN_987654')
        ->and($parsedText)->not->toContain('completely destroyed');

    $dbContext->runAs($wgCdr, function () use ($mark, $doc): void {
        expect($mark->refresh()->redacted_in_version_id)->toBe($doc->current_version_id);
    });
});

it('filters @mentions by MAC clearance BEFORE generating notifications or Reverb broadcasts and blocks unauthorized WS auth', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3, OPS+INTEL
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4, OPS+INTEL (cleared!)
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1 (uncleared!)

    $dbContext = app(DbSecurityContext::class);
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());

    $this->signIn($wgCdr);
    $this->post("/documents/{$secretDoc->id}/comments", [
        'body' => 'Review @avm.chikwanda please verify patrol corridors, and @cpl.sibanda test suppression.',
        'mentioned_user_ids' => [$avm->id, $cpl->id],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext->runAs($wgCdr, function () use ($secretDoc, $avm, $cpl): void {
        /** @var DocumentComment $comment */
        $comment = DocumentComment::query()->where('document_id', $secretDoc->id)->latest()->firstOrFail();
        expect($comment->mentioned_user_ids_json)->toContain($avm->id)
            ->and($comment->mentioned_user_ids_json)->not->toContain($cpl->id)
            ->and($comment->suppressed_mention_ids_json)->toContain($cpl->id);
    });

    // Cleared AVM received notification; uncleared CPL received ZERO notifications
    $avmNotifCount = $dbContext->runAs($avm, static fn (): int => UserNotification::query()->where('user_id', $avm->id)->where('document_id', $secretDoc->id)->count());
    $cplNotifCount = $dbContext->runAs($cpl, static fn (): int => UserNotification::query()->where('user_id', $cpl->id)->count());

    expect($avmNotifCount)->toBeGreaterThanOrEqual(1)
        ->and($cplNotifCount)->toBe(0);

    // Enable Reverb broadcaster and re-bind channel authorizations cleanly for test verification
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'betterdoc-test-key',
        'secret' => 'betterdoc-test-secret',
        'app_id' => 'betterdoc-test',
        'options' => ['host' => '127.0.0.1', 'port' => 8081, 'scheme' => 'http', 'useTLS' => false],
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    // Lower-cleared CPL rejected (403) subscribing to secret presence & private document channels
    $this->signIn($cpl);
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-document.{$secretDoc->id}",
    ])->assertForbidden();
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-document.{$secretDoc->id}",
    ])->assertForbidden();

    // Cleared Wing Commander authorized cleanly
    $this->signIn($wgCdr);
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-document.{$secretDoc->id}",
    ])->assertOk()->assertJsonStructure(['auth', 'channel_data']);
});
