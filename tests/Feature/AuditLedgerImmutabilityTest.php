<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('forbids UPDATE and DELETE on audit_events at both privilege and PostgreSQL trigger layers', function (): void {
    /** @var AuditEvent $firstEvent */
    $firstEvent = AuditEvent::query()->orderBy('seq_id')->firstOrFail();

    // 1. Runtime role betterdoc_app lacks UPDATE/DELETE privileges
    expect(fn () => DB::table('audit_events')->where('seq_id', $firstEvent->seq_id)->update(['event_type' => 'tampered']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->where('seq_id', $firstEvent->seq_id)->delete())
        ->toThrow(QueryException::class);

    // 2. Even elevated pgsql_admin is blocked by trg_audit_events_immutable trigger
    expect(fn () => DB::connection('pgsql_admin')->table('audit_events')->where('seq_id', $firstEvent->seq_id)->update(['event_type' => 'tampered']))
        ->toThrow(QueryException::class, 'append-only');
});

it('verifies complete cryptographic hash chain integrity', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    $audit = app(ImmutableAuditLogger::class);

    $result = $audit->verifyChain($tenant);

    expect($result['valid'])->toBeTrue()
        ->and($result['total_events'])->toBeGreaterThanOrEqual(5)
        ->and($result['broken_at_seq_id'])->toBeNull();
});

it('masks classified audit event payloads above an auditors own clearance rank', function (): void {
    /** @var User $auditor */
    $auditor = User::query()->where('email', 'gpcapt.dube@afz.mil.zw')->firstOrFail(); // Auditor, Rank 2

    $this->signIn($auditor);

    $this->get('/audit')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $events = $page->toArray()['props']['events'];
            $topSecretEvent = collect($events)->firstWhere('classification_rank', 4);
            expect($topSecretEvent)->not->toBeNull()
                ->and($topSecretEvent['masked'])->toBeTrue()
                ->and($topSecretEvent['after']['redacted'])->toContain('REDACTED - CLEARANCE RANK 4');
        });
});
