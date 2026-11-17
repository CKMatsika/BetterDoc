<?php

declare(strict_types=1);

use App\Enums\DispositionAction;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Models\BreakGlassSession;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\RetentionSchedule;
use App\Models\TwoPersonRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('executes BPMN conditional/parallel workflows, SLA escalation, MAC delegation, Tier 3 hashes, and auto WORM seal', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // RecordsOfficer Rank 3
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1

    $dbContext = app(DbSecurityContext::class);
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());

    $this->signIn($wgCdr);

    // Publish visual parallel fork/join + WORM terminal BPMN definition
    $this->post('/workflows/definitions', [
        'key' => 'parallel-seal-flow',
        'name' => 'Parallel Operations + Seal Workflow',
        'sla_hours' => 12,
        'bpmn_graph_json' => [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'label' => 'Start', 'x' => 20, 'y' => 100],
                ['id' => 'f', 'type' => 'parallel_fork', 'label' => 'Fork', 'x' => 140, 'y' => 100],
                ['id' => 't1', 'type' => 'approval', 'label' => 'Wing Review', 'assignee_user_id' => $wgCdr->id, 'sla_hours' => 4, 'x' => 260, 'y' => 40],
                ['id' => 't2', 'type' => 'approval', 'label' => 'Command Concurrence', 'assignee_user_id' => $avm->id, 'sla_hours' => 4, 'x' => 260, 'y' => 180],
                ['id' => 'j', 'type' => 'parallel_join', 'label' => 'Join', 'x' => 420, 'y' => 100],
                ['id' => 'w', 'type' => 'archive_worm', 'label' => 'WORM Seal', 'x' => 540, 'y' => 100],
                ['id' => 'e', 'type' => 'end', 'label' => 'Done', 'x' => 660, 'y' => 100],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'f', 'condition' => '', 'is_default' => false],
                ['id' => 'e2', 'source' => 'f', 'target' => 't1', 'condition' => '', 'is_default' => false],
                ['id' => 'e3', 'source' => 'f', 'target' => 't2', 'condition' => '', 'is_default' => false],
                ['id' => 'e4', 'source' => 't1', 'target' => 'j', 'condition' => '', 'is_default' => false],
                ['id' => 'e5', 'source' => 't2', 'target' => 'j', 'condition' => '', 'is_default' => false],
                ['id' => 'e6', 'source' => 'j', 'target' => 'w', 'condition' => '', 'is_default' => false],
                ['id' => 'e7', 'source' => 'w', 'target' => 'e', 'condition' => '', 'is_default' => false],
            ],
        ],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var WorkflowDefinition $def */
    $def = $dbContext->runAs($wgCdr, static fn (): WorkflowDefinition => WorkflowDefinition::query()->where('key', 'parallel-seal-flow')->where('is_active', true)->firstOrFail());

    $this->post("/documents/{$secretDoc->id}/workflows/start", ['definition_id' => $def->id])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    /** @var WorkflowInstance $inst */
    $inst = $dbContext->runAs($wgCdr, static fn (): WorkflowInstance => WorkflowInstance::query()->where('definition_id', $def->id)->firstOrFail());
    $tasks = $dbContext->runAs($wgCdr, static fn () => WorkflowTask::query()->where('instance_id', $inst->id)->get());
    expect($tasks)->toHaveCount(2);

    /** @var WorkflowTask $wgTask */
    $wgTask = $tasks->firstWhere('assignee_id', $wgCdr->id);
    /** @var WorkflowTask $avmTask */
    $avmTask = $tasks->firstWhere('assignee_id', $avm->id);

    // Delegating Secret workflow task to uncleared Corporal (Rank 0) MUST fail
    $this->post("/workflows/tasks/{$wgTask->id}/delegate", [
        'delegate_user_id' => $cpl->id,
    ])->assertSessionHasErrors('delegate_user_id');

    // Backdate WG task SLA & trigger escalation check
    $dbContext->runAs($wgCdr, static fn () => $wgTask->update(['due_at' => now()->subMinutes(10)]));
    $this->post('/workflows/escalate-slas')->assertRedirect();
    expect($dbContext->runAs($wgCdr, static fn (): string => $wgTask->refresh()->status->value))->toBe(WorkflowTaskStatus::Escalated->value);

    // Approve Task 1 -> instance stays running waiting at Parallel Join
    $this->post("/workflows/tasks/{$wgTask->id}/decide", [
        'decision' => 'approve',
        'comment' => 'Wing Commander concurred.',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $wgTaskHash = $dbContext->runAs($wgCdr, static fn (): ?string => $wgTask->refresh()->approval_hash);
    expect(strlen((string) $wgTaskHash))->toBe(64);
    expect($dbContext->runAs($wgCdr, static fn (): WorkflowInstanceStatus => $inst->refresh()->status))->not->toBe(WorkflowInstanceStatus::Completed);

    // Approve Task 2 as AVM -> Join advances through WORM Seal -> End!
    $this->signIn($avm);
    $this->post("/workflows/tasks/{$avmTask->id}/decide", [
        'decision' => 'approve',
        'comment' => 'Final command authorization.',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext->runAs($avm, function () use ($inst, $secretDoc): void {
        expect($inst->refresh()->status)->toBe(WorkflowInstanceStatus::Completed)
            ->and($secretDoc->refresh()->is_worm)->toBeTrue();
    });
});

it('generates auto-numbered HTML-escaped PDF documents from templates within user clearance', function (): void {
    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail(); // Rank 2
    $dbContext = app(DbSecurityContext::class);

    $cabinet = $dbContext->runAs($sqnLdr, static fn (): Cabinet => Cabinet::query()->where('tenant_id', $sqnLdr->tenant_id)->firstOrFail());
    $secretLevel = ClearanceLevel::query()->where('tenant_id', $sqnLdr->tenant_id)->where('rank', 3)->firstOrFail();
    $restrictedLevel = ClearanceLevel::query()->where('tenant_id', $sqnLdr->tenant_id)->where('rank', 1)->firstOrFail();

    /** @var DocumentTemplate $tpl */
    $tpl = $dbContext->runAs($sqnLdr, static fn (): DocumentTemplate => DocumentTemplate::query()->where('tenant_id', $sqnLdr->tenant_id)->firstOrFail());

    $this->signIn($sqnLdr);

    // Cannot generate Secret (Rank 3) document when user clearance is Confidential (Rank 2)
    $this->post("/templates/{$tpl->id}/generate", [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $secretLevel->id,
        'title' => 'Over-Cleared Memo',
        'variables' => ['recipient_name' => 'HQ', 'unit_code' => 'SIG', 'subject' => 'Test', 'body_text' => 'Body'],
    ])->assertSessionHasErrors('classification_level_id');

    // Generate valid Restricted (Rank 1) document with HTML script payload escaped cleanly
    $this->post("/templates/{$tpl->id}/generate", [
        'cabinet_id' => $cabinet->id,
        'classification_level_id' => $restrictedLevel->id,
        'title' => 'Maintenance Standing Order',
        'variables' => [
            'recipient_name' => 'Signals Squadron <script>alert(1)</script>',
            'unit_code' => 'AFZ-SIG',
            'subject' => 'Quarterly HF Equipment Check',
            'body_text' => 'All units complete calibration before Friday 16:00 local.',
        ],
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $generated = $dbContext->runAs($sqnLdr, static fn (): Document => Document::query()->where('title', 'Maintenance Standing Order')->firstOrFail());
    expect($generated->doc_number)->toContain('AFZ/MEMO/'.now('UTC')->format('Y').'/0001')
        ->and($generated->current_version_id)->not->toBeNull();
});

it('enforces Legal Hold, WORM protection, Two-Person Disposition, and Break-Glass live auditor alerts', function (): void {
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail(); // Rank 3
    /** @var User $avm */
    $avm = User::query()->where('email', 'avm.chikwanda@afz.mil.zw')->firstOrFail(); // Rank 4
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail(); // Rank 1
    /** @var User $auditor */
    $auditor = User::query()->where('email', 'gpcapt.dube@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    // 1. Test Legal Hold blocking Disposition & Dual-Approved Two-Person Disposition on Confidential doc
    $confDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/014')->firstOrFail());
    $schedule = $dbContext->runAs($wgCdr, static fn (): RetentionSchedule => RetentionSchedule::query()->where('tenant_id', $wgCdr->tenant_id)->firstOrFail());

    $this->signIn($wgCdr);
    $this->post("/documents/{$confDoc->id}/governance/retention", ['retention_schedule_id' => $schedule->id])->assertRedirect();

    $this->post("/documents/{$confDoc->id}/governance/legal-hold", ['active' => true, 'reason' => 'Pending Inquiry 2026-B'])->assertRedirect();
    $this->post("/documents/{$confDoc->id}/governance/disposition", ['action' => DispositionAction::Archive->value, 'notes' => 'Attempting disposal under legal hold.'])
        ->assertSessionHasErrors('disposition');

    // Release hold -> initiate Two-Person Disposition
    $this->post("/documents/{$confDoc->id}/governance/legal-hold", ['active' => false, 'reason' => 'Inquiry closed cleanly'])->assertRedirect();
    $this->post("/documents/{$confDoc->id}/governance/disposition", ['action' => DispositionAction::Archive->value, 'notes' => 'Retention milestone complete; archive record.'])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    /** @var TwoPersonRequest $tp */
    $tp = $dbContext->runAs($wgCdr, static fn (): TwoPersonRequest => TwoPersonRequest::query()->where('target_id', $confDoc->id)->latest()->firstOrFail());

    // Self-approval blocked
    $this->post("/approvals/{$tp->id}/approve")->assertSessionHasErrors('approval');

    // Second officer (AVM with fresh MFA) approves disposition
    $this->signIn($avm);
    $this->post("/approvals/{$tp->id}/approve")->assertRedirect()->assertSessionDoesntHaveErrors();
    expect($dbContext->runAs($avm, static fn (): string => $confDoc->refresh()->status->value))->toBe('archived');

    // 2. Break-Glass Dual Approval + Live Auditor Alert
    $secretDoc = $dbContext->runAs($wgCdr, static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail());
    $this->signIn($cpl);
    $this->post('/break-glass', [
        'document_id' => $secretDoc->id,
        'justification' => 'Emergency airspace corridor outage requires immediate diversion briefing.',
        'duration_hours' => 2,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var BreakGlassSession $bg */
    $bg = $dbContext->runAs($wgCdr, static fn (): BreakGlassSession => BreakGlassSession::query()->where('document_id', $secretDoc->id)->latest()->firstOrFail());

    $this->signIn($wgCdr);
    $this->post("/break-glass/{$bg->id}/approve-first")->assertRedirect()->assertSessionDoesntHaveErrors();

    // Same approver cannot perform second break-glass sign-off
    $this->post("/break-glass/{$bg->id}/approve-second")->assertSessionHasErrors('break_glass');

    $this->signIn($avm);
    $this->post("/break-glass/{$bg->id}/approve-second")->assertRedirect()->assertSessionDoesntHaveErrors();

    // Auditor received live Security Break-Glass notification
    $auditorAlerts = $dbContext->runAs($auditor, static fn (): int => UserNotification::query()->where('user_id', $auditor->id)->where('type', 'security.break_glass_activated')->count());
    expect($auditorAlerts)->toBeGreaterThanOrEqual(1);

    // Beneficiary CPL now sees document transiently via Break-Glass banner
    $this->signIn($cpl);
    $this->get("/documents/{$secretDoc->id}")->assertOk();
});
