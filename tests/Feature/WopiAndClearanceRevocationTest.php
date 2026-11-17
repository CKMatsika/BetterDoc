<?php

declare(strict_types=1);

use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentLock;
use App\Models\User;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('enforces WOPI locking and revokes active Collabora WOPI sessions within <1s when user clearance is downgraded', function (): void {
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'fltlt.nyoni@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());

    // 1. Wing Commander launches WOPI co-editing session
    $this->signIn($wgCdr);
    $pageRes = $this->get("/documents/{$secretDoc->id}/edit-office")->assertOk();
    /** @var array<string, mixed> $wopiProps */
    $wopiProps = $pageRes->viewData('page')['props']['wopiSession'] ?? [];
    $accessToken = (string) ($wopiProps['access_token'] ?? '');
    expect(strlen($accessToken))->toBeGreaterThan(20);

    // 2. CheckFileInfo, LOCK, conflicting LOCK -> 409, and PutFile -> v1.1
    $this->getJson("/wopi/files/{$secretDoc->id}?access_token={$accessToken}")
        ->assertOk()
        ->assertJsonPath('UserCanWrite', true);

    $this->withHeaders([
        'X-WOPI-Override' => 'LOCK',
        'X-WOPI-Lock' => 'collabora-lock-001',
    ])->post("/wopi/files/{$secretDoc->id}?access_token={$accessToken}")
        ->assertOk()
        ->assertHeader('X-WOPI-Lock', 'collabora-lock-001');

    // Different lock ID gets 409 conflict
    $this->withHeaders([
        'X-WOPI-Override' => 'LOCK',
        'X-WOPI-Lock' => 'rogue-lock-999',
    ])->post("/wopi/files/{$secretDoc->id}?access_token={$accessToken}")
        ->assertStatus(409)
        ->assertHeader('X-WOPI-Lock', 'collabora-lock-001');

    // PutFile saves new encrypted version
    $updatedBytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";
    $this->call(
        'POST',
        "/wopi/files/{$secretDoc->id}/contents?access_token={$accessToken}",
        [],
        [],
        [],
        ['HTTP_X_WOPI_LOCK' => 'collabora-lock-001'],
        $updatedBytes
    )->assertOk();

    // 3. SystemAdmin downgrades Wing Commander to Restricted (Rank 1)
    $restrictedLevel = ClearanceLevel::query()
        ->where('tenant_id', $wgCdr->tenant_id)
        ->where('rank', 1)
        ->firstOrFail();

    $this->signIn($sysAdmin);
    $startedAt = microtime(true);
    $this->post("/admin/users/{$wgCdr->id}/clearance", [
        'clearance_level_id' => $restrictedLevel->id,
        'reason' => 'Security clearance downgraded pending vetting renewal.',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    // 4. Within < 1 second, WOPI CheckFileInfo & GetFile & PutFile reject immediately (401) and lock is cleared
    $this->getJson("/wopi/files/{$secretDoc->id}?access_token={$accessToken}")->assertStatus(401);
    $this->get("/wopi/files/{$secretDoc->id}/contents?access_token={$accessToken}")->assertStatus(401);
    $elapsedMs = (microtime(true) - $startedAt) * 1000.0;

    expect($elapsedMs)->toBeLessThan(1000.0);

    $lockCount = $dbContext->runAs($sysAdmin, static fn (): int => DocumentLock::query()->where('document_id', $secretDoc->id)->count());
    expect($lockCount)->toBe(0);
});
