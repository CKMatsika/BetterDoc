<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('enforces exclusive check-out locks, computes version diffs, and restores prior versions', function (): void {
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $doc = $dbContext->runAs($sqnLdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/014')->firstOrFail());

    // 1. Squadron Leader (Owner) checks out document exclusively
    $this->signIn($sqnLdr);
    $this->post("/documents/{$doc->id}/checkout", ['note' => 'Updating sector frequencies'])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    // Another cleared editor (Wing Commander, ancestor RecordsOfficer) cannot hijack checkout while locked
    $this->signIn($wgCdr);
    $this->post("/documents/{$doc->id}/checkout")->assertSessionHasErrors('checkout');

    // 2. Lock holder uploads v1.1 revision
    $this->signIn($sqnLdr);
    $v2Text = "AFZ/SIG/2026/014 — VHF Radio Frequency Maintenance Schedule\n\nUpdated Corridor North-3 and scramble window.";
    $init = $this->postJson('/uploads/init', [
        'filename' => 'freq_v2.txt',
        'mime_type' => 'text/plain',
        'total_size' => strlen($v2Text),
        'document_id' => $doc->id,
    ])->assertCreated();

    $sid = (string) $init->json('session_id');
    $this->putChunk("/uploads/{$sid}/chunks/0", $v2Text)->assertOk();
    $res = $this->postJson("/uploads/{$sid}/complete", ['major' => false, 'version_note' => 'Updated North-3 corridor'])->assertCreated();
    expect($res->json('version_label'))->toBe('v1.1');

    // Release lock
    $this->post("/documents/{$doc->id}/checkin")->assertRedirect()->assertSessionDoesntHaveErrors();

    // 3. Line-by-line diff between v1.0 and v1.1
    [$v1, $v2] = $dbContext->runAs($sqnLdr, fn (): array => [
        DocumentVersion::query()->where('document_id', $doc->id)->where('version_major', 1)->where('version_minor', 0)->firstOrFail(),
        DocumentVersion::query()->where('document_id', $doc->id)->where('version_major', 1)->where('version_minor', 1)->firstOrFail(),
    ]);

    $diffRes = $this->getJson("/documents/{$doc->id}/compare?from_version_id={$v1->id}&to_version_id={$v2->id}")->assertOk();
    $diffLines = collect($diffRes->json('diff'));
    expect($diffLines->contains(fn ($l) => $l['type'] === 'added' && str_contains($l['text'], 'North-3')))->toBeTrue();

    // 4. Restore v1.0 as new major v2.0
    $this->post("/documents/{$doc->id}/versions/{$v1->id}/restore")->assertRedirect()->assertSessionDoesntHaveErrors();
    $dbContext->runAs($sqnLdr, function () use ($doc): void {
        $current = $doc->refresh()->currentVersion;
        expect($current?->label())->toBe('v2.0');
    });
});

it('streams decrypted PDFs via HTTP 206 Range requests, hides streams as 404 from lower users, and blocks Top Secret downloads', function (): void {
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());
    $topSecretDoc = $dbContext->runAs($avm, static fn (): Document => Document::query()->where('doc_number', 'AFZ/CMD/2026/003')->firstOrFail());

    // Cleared Wing Commander receives 206 Partial Content starting with %PDF-
    $this->signIn($wgCdr);
    $streamRes = $this->get("/documents/{$secretDoc->id}/versions/{$secretDoc->current_version_id}/stream", [
        'Range' => 'bytes=0-15',
    ]);
    $streamRes->assertStatus(206);
    expect($streamRes->streamedContent())->toStartWith('%PDF-1.4');

    // Lower-cleared Corporal gets 404 on Secret stream & download
    $this->signIn($cpl);
    $this->get("/documents/{$secretDoc->id}/versions/{$secretDoc->current_version_id}/stream")->assertNotFound();
    $this->get("/documents/{$secretDoc->id}/versions/{$secretDoc->current_version_id}/download")->assertNotFound();

    // Top Secret policy (dynamic_no_download) blocks raw file download even for cleared AVM while permitting viewer stream
    $this->signIn($avm);
    $this->get("/documents/{$topSecretDoc->id}/versions/{$topSecretDoc->current_version_id}/stream")->assertOk();
    $this->get("/documents/{$topSecretDoc->id}/versions/{$topSecretDoc->current_version_id}/download")->assertForbidden();
});
