<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\BetterDocRole;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Exceptions\InsufficientClearanceException;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Collaboration\RealtimeNotificationService;
use App\Services\Dms\GovernanceService;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class WorkflowEngineService
{
    public function __construct(
        private readonly ClearancePolicyEngine $policyEngine,
        private readonly ImmutableAuditLogger $audit,
        private readonly RealtimeNotificationService $notifications,
        private readonly GovernanceService $governance,
    ) {}

    /**
     * Save a new version of a BPMN-lite visual workflow definition.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $bpmnGraph
     * @param  list<array<string, mixed>>|null  $formSchema
     */
    public function createDefinition(
        User $creator,
        string $key,
        string $name,
        array $bpmnGraph,
        ?string $description = null,
        ?array $formSchema = null,
        int $slaHours = 48,
        ?Request $request = null,
    ): WorkflowDefinition {
        $this->validateGraph($bpmnGraph);

        $latestVersion = WorkflowDefinition::query()
            ->where('tenant_id', $creator->tenant_id)
            ->where('key', $key)
            ->max('version');
        $nextVersion = (is_numeric($latestVersion) ? (int) $latestVersion : 0) + 1;

        WorkflowDefinition::query()
            ->where('tenant_id', $creator->tenant_id)
            ->where('key', $key)
            ->update(['is_active' => false]);

        /** @var WorkflowDefinition $definition */
        $definition = WorkflowDefinition::query()->create([
            'tenant_id' => $creator->tenant_id,
            'key' => $key,
            'name' => trim($name),
            'description' => $description,
            'version' => $nextVersion,
            'bpmn_graph_json' => $bpmnGraph,
            'form_schema_json' => $formSchema,
            'sla_hours' => max(1, $slaHours),
            'is_active' => true,
            'created_by' => $creator->id,
        ]);

        $this->audit->log(
            tenantId: $creator->tenant_id,
            eventType: 'workflow.definition_saved',
            resourceType: 'workflow_definition',
            resourceId: $definition->id,
            actor: $creator,
            classificationRank: $creator->clearance_rank,
            after: ['key' => $key, 'version' => $nextVersion, 'name' => $definition->name],
            request: $request,
        );

        return $definition;
    }

    /**
     * Start a workflow instance against a cleared document, routing from Start node.
     *
     * @param  array<string, mixed>  $variables
     */
    public function startInstance(
        WorkflowDefinition $definition,
        Document $document,
        User $starter,
        array $variables = [],
        ?Request $request = null,
    ): WorkflowInstance {
        if ($definition->tenant_id !== $document->tenant_id || ! $this->policyEngine->authorize($starter, 'view', $document, ['bypass_abac' => true])->allowed) {
            throw new RuntimeException('Cannot start workflow on an unauthorized document.');
        }

        $graph = $definition->bpmn_graph_json;
        $startNode = $this->findNodeByType($graph, 'start');
        if ($startNode === null) {
            throw new RuntimeException('Workflow definition is missing a start node.');
        }

        $startNodeId = is_string($startNode['id'] ?? null) ? $startNode['id'] : 'n_start';

        return DB::transaction(function () use ($definition, $document, $starter, $variables, $graph, $startNodeId, $request): WorkflowInstance {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'tenant_id' => $document->tenant_id,
                'definition_id' => $definition->id,
                'document_id' => $document->id,
                'started_by' => $starter->id,
                'current_nodes_json' => [$startNodeId],
                'variables_json' => $variables,
                'status' => WorkflowInstanceStatus::Running,
                'sla_due_at' => now()->addHours($definition->sla_hours),
            ]);

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'workflow.instance_started',
                resourceType: 'workflow_instance',
                resourceId: $instance->id,
                actor: $starter,
                classificationRank: $document->classification_rank,
                after: ['definition_key' => $definition->key, 'document_id' => $document->id],
                request: $request,
            );

            $this->advanceFromNode($instance, $graph, $startNodeId, $starter, $request);

            return $instance->refresh();
        });
    }

    /**
     * Record a Tier 3 cryptographic approval decision and advance the BPMN token(s).
     *
     * @param  array<string, mixed>|null  $formData
     */
    public function completeTask(
        WorkflowTask $task,
        User $actor,
        string $decision,
        ?string $comment = null,
        ?array $formData = null,
        ?Request $request = null,
    ): WorkflowTask {
        $instance = $task->instance;
        $document = $instance?->document;

        if ($instance === null || $document === null || $instance->status !== WorkflowInstanceStatus::Running && $instance->status !== WorkflowInstanceStatus::Escalated) {
            throw new RuntimeException('Workflow instance is no longer active.');
        }

        $isAuthorizedActor = $task->assignee_id === $actor->id || $task->delegated_to_id === $actor->id;
        if (! $isAuthorizedActor) {
            throw new RuntimeException('You are not assigned or delegated to this workflow task.');
        }

        if (! in_array($task->status, [WorkflowTaskStatus::Pending, WorkflowTaskStatus::Escalated], true)) {
            throw new RuntimeException('Workflow task has already been completed.');
        }

        if (! $this->policyEngine->authorize($actor, 'view', $document, ['bypass_abac' => true])->allowed) {
            throw new InsufficientClearanceException('Your current clearance no longer permits viewing this workflow record.');
        }

        $normalized = mb_strtolower($decision) === 'reject' ? 'reject' : 'approve';
        $decidedAt = now();

        // Tier 3 Workflow Approval Cryptographic Signature anchored into the immutable audit ledger
        $approvalHash = hash('sha256', json_encode([
            'task_id' => $task->id,
            'instance_id' => $instance->id,
            'document_id' => $document->id,
            'version_id' => $document->current_version_id,
            'actor_id' => $actor->id,
            'decision' => $normalized,
            'comment' => $comment,
            'form_data' => $formData ?? [],
            'decided_at' => $decidedAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($task, $instance, $document, $actor, $normalized, $comment, $formData, $approvalHash, $decidedAt, $request): WorkflowTask {
            $task->update([
                'status' => $normalized === 'approve' ? WorkflowTaskStatus::Approved : WorkflowTaskStatus::Rejected,
                'decision' => $normalized,
                'decision_comment' => $comment,
                'form_data_json' => $formData,
                'approval_hash' => $approvalHash,
                'completed_at' => $decidedAt,
            ]);

            $vars = is_array($instance->variables_json) ? $instance->variables_json : [];
            $vars['last_decision'] = $normalized;
            $vars['decision'] = $normalized;
            if (is_array($formData)) {
                $vars = array_merge($vars, $formData);
            }
            $instance->update(['variables_json' => $vars]);

            $this->audit->log(
                tenantId: $document->tenant_id,
                eventType: 'workflow.task_decided',
                resourceType: 'workflow_task',
                resourceId: $task->id,
                actor: $actor,
                classificationRank: $document->classification_rank,
                after: [
                    'instance_id' => $instance->id,
                    'document_id' => $document->id,
                    'decision' => $normalized,
                    'approval_hash' => $approvalHash,
                ],
                request: $request,
            );

            if ($normalized === 'reject') {
                WorkflowTask::query()
                    ->where('instance_id', $instance->id)
                    ->where('id', '!=', $task->id)
                    ->whereIn('status', [WorkflowTaskStatus::Pending->value, WorkflowTaskStatus::Escalated->value])
                    ->update(['status' => WorkflowTaskStatus::Skipped->value, 'completed_at' => $decidedAt]);

                $instance->update([
                    'status' => WorkflowInstanceStatus::Rejected,
                    'current_nodes_json' => [],
                    'completed_at' => $decidedAt,
                ]);

                if ($instance->starter !== null) {
                    $this->notifications->notifyUser(
                        recipient: $instance->starter,
                        type: 'workflow.rejected',
                        title: "Workflow Rejected: {$document->doc_number}",
                        body: "{$actor->name} rejected step '{$task->node_label}'.",
                        documentId: $document->id,
                        actionUrl: "/documents/{$document->id}",
                        classificationRank: $document->classification_rank,
                    );
                }

                return $task->refresh();
            }

            $graph = $instance->definition->bpmn_graph_json ?? ['nodes' => [], 'edges' => []];
            $this->advanceFromNode($instance->refresh(), $graph, $task->node_id, $actor, $request);

            return $task->refresh();
        });
    }

    /**
     * Delegate a pending workflow task strictly after MAC/Compartment authorization check.
     */
    public function delegateTask(
        WorkflowTask $task,
        User $actor,
        User $delegate,
        ?string $reason = null,
        ?Request $request = null,
    ): WorkflowTask {
        $instance = $task->instance;
        $document = $instance?->document;

        if ($instance === null || $document === null || $task->assignee_id !== $actor->id) {
            throw new RuntimeException('Cannot delegate a task you do not own.');
        }

        if (! $this->policyEngine->authorize($delegate, 'view', $document, ['bypass_abac' => true])->allowed) {
            throw new InsufficientClearanceException('Delegation denied: target officer lacks clearance or compartments for this document.');
        }

        $task->update(['delegated_to_id' => $delegate->id]);

        $this->audit->log(
            tenantId: $document->tenant_id,
            eventType: 'workflow.task_delegated',
            resourceType: 'workflow_task',
            resourceId: $task->id,
            actor: $actor,
            classificationRank: $document->classification_rank,
            after: ['delegate_id' => $delegate->id, 'reason' => $reason],
            request: $request,
        );

        $this->notifications->notifyUser(
            recipient: $delegate,
            type: 'workflow.task_delegated',
            title: "Delegated Task: {$document->doc_number}",
            body: "{$actor->name} delegated step '{$task->node_label}' to you.",
            documentId: $document->id,
            actionUrl: '/workflows/inbox',
            classificationRank: $document->classification_rank,
        );

        return $task->refresh();
    }

    /**
     * Scan overdue pending workflow tasks and escalate them automatically.
     */
    public function checkAndEscalateOverdueTasks(?string $tenantId = null): int
    {
        $query = WorkflowTask::query()
            ->with(['instance.document', 'instance.definition'])
            ->where('status', WorkflowTaskStatus::Pending->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $overdueTasks = $query->get();
        $escalatedCount = 0;

        foreach ($overdueTasks as $task) {
            $instance = $task->instance;
            $document = $instance?->document;
            if ($instance === null || $document === null) {
                continue;
            }

            $escalationUser = $this->resolveEscalationAssignee($task, $document);

            DB::transaction(function () use ($task, $instance, $document, $escalationUser): void {
                $task->update([
                    'status' => WorkflowTaskStatus::Escalated,
                    'decision_comment' => 'SLA timer elapsed; task escalated automatically.',
                ]);

                if ($escalationUser !== null && $escalationUser->id !== $task->assignee_id) {
                    $task->update(['delegated_to_id' => $escalationUser->id]);
                    $this->notifications->notifyUser(
                        recipient: $escalationUser,
                        type: 'workflow.escalated',
                        title: "SLA Escalation: {$document->doc_number}",
                        body: "Overdue step '{$task->node_label}' escalated to your queue.",
                        documentId: $document->id,
                        actionUrl: '/workflows/inbox',
                        classificationRank: $document->classification_rank,
                    );
                }

                $instance->update(['status' => WorkflowInstanceStatus::Escalated]);

                $this->audit->log(
                    tenantId: $document->tenant_id,
                    eventType: 'workflow.task_escalated',
                    resourceType: 'workflow_task',
                    resourceId: $task->id,
                    actor: $escalationUser ?? $task->assignee,
                    classificationRank: $document->classification_rank,
                    after: ['instance_id' => $instance->id, 'escalated_to' => $escalationUser?->id],
                );
            });

            $escalatedCount++;
        }

        return $escalatedCount;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    private function advanceFromNode(WorkflowInstance $instance, array $graph, string $fromNodeId, User $actor, ?Request $request = null, int $depth = 0): void
    {
        if ($depth > 25) {
            throw new RuntimeException('Workflow graph exceeded maximum traversal depth.');
        }

        $edges = $this->outgoingEdges($graph, $fromNodeId);
        $sourceNode = $this->findNodeById($graph, $fromNodeId);
        $document = $instance->document;

        if ($document === null) {
            return;
        }

        $selectedTargets = [];
        if (($sourceNode['type'] ?? '') === 'conditional_gateway') {
            $vars = is_array($instance->variables_json) ? $instance->variables_json : [];
            $matchedEdge = null;
            $defaultEdge = null;
            foreach ($edges as $edge) {
                $cond = is_string($edge['condition'] ?? null) ? trim($edge['condition']) : '';
                if (($edge['is_default'] ?? false) === true && $defaultEdge === null) {
                    $defaultEdge = $edge;
                }
                if ($cond !== '' && $this->evaluateCondition($cond, $document, $vars)) {
                    $matchedEdge = $edge;
                    break;
                }
            }
            $chosen = $matchedEdge ?? $defaultEdge ?? ($edges[0] ?? null);
            if ($chosen !== null && is_string($chosen['target'] ?? null)) {
                $selectedTargets[] = $chosen['target'];
            }
        } else {
            foreach ($edges as $edge) {
                if (is_string($edge['target'] ?? null)) {
                    $selectedTargets[] = $edge['target'];
                }
            }
        }

        foreach ($selectedTargets as $targetId) {
            $targetNode = $this->findNodeById($graph, $targetId);
            if ($targetNode === null) {
                continue;
            }

            $nodeType = is_string($targetNode['type'] ?? null) ? $targetNode['type'] : 'approval';

            if ($nodeType === 'parallel_fork' || $nodeType === 'conditional_gateway') {
                $this->advanceFromNode($instance, $graph, $targetId, $actor, $request, $depth + 1);

                continue;
            }

            if ($nodeType === 'parallel_join') {
                // Only advance out of join if zero sibling tasks remain open on the instance
                $openSiblingCount = WorkflowTask::query()
                    ->where('instance_id', $instance->id)
                    ->whereIn('status', [WorkflowTaskStatus::Pending->value, WorkflowTaskStatus::Escalated->value])
                    ->count();

                if ($openSiblingCount === 0) {
                    $this->advanceFromNode($instance, $graph, $targetId, $actor, $request, $depth + 1);
                }

                continue;
            }

            if ($nodeType === 'archive_worm') {
                $this->governance->applyWormLock($document, $actor, null, $request);
                $this->advanceFromNode($instance, $graph, $targetId, $actor, $request, $depth + 1);

                continue;
            }

            if ($nodeType === 'end') {
                $openCount = WorkflowTask::query()
                    ->where('instance_id', $instance->id)
                    ->whereIn('status', [WorkflowTaskStatus::Pending->value, WorkflowTaskStatus::Escalated->value])
                    ->count();

                if ($openCount === 0) {
                    $instance->update([
                        'status' => WorkflowInstanceStatus::Completed,
                        'current_nodes_json' => [$targetId],
                        'completed_at' => now(),
                    ]);

                    if ($instance->starter !== null) {
                        $this->notifications->notifyUser(
                            recipient: $instance->starter,
                            type: 'workflow.completed',
                            title: "Workflow Completed: {$document->doc_number}",
                            body: "All required steps for {$document->doc_number} have finished.",
                            documentId: $document->id,
                            actionUrl: "/documents/{$document->id}",
                            classificationRank: $document->classification_rank,
                        );
                    }
                }

                continue;
            }

            // Human action node: approval / signature_step
            $assignee = $this->resolveNodeAssignee($targetNode, $document, $actor);
            $slaHours = is_numeric($targetNode['sla_hours'] ?? null)
                ? max(1, (int) $targetNode['sla_hours'])
                : ($instance->definition->sla_hours ?? 48);

            $nodeLabel = is_string($targetNode['label'] ?? null) ? $targetNode['label'] : 'Review Step';

            $task = WorkflowTask::query()->create([
                'tenant_id' => $document->tenant_id,
                'instance_id' => $instance->id,
                'node_id' => $targetId,
                'node_label' => $nodeLabel,
                'node_type' => $nodeType,
                'assignee_id' => $assignee->id,
                'status' => WorkflowTaskStatus::Pending,
                'due_at' => now()->addHours($slaHours),
            ]);

            $currentNodes = $instance->current_nodes_json;
            $currentNodes[] = $targetId;
            $instance->update(['current_nodes_json' => array_values(array_unique($currentNodes))]);

            $this->notifications->notifyUser(
                recipient: $assignee,
                type: 'workflow.task_assigned',
                title: "Action Required: {$task->node_label}",
                body: "Workflow task awaiting your decision on {$document->doc_number}.",
                documentId: $document->id,
                actionUrl: '/workflows/inbox',
                metadata: ['task_id' => $task->id, 'instance_id' => $instance->id],
                classificationRank: $document->classification_rank,
            );
        }
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    private function validateGraph(array $graph): void
    {
        if ($this->findNodeByType($graph, 'start') === null || $this->findNodeByType($graph, 'end') === null) {
            throw new RuntimeException('BPMN graph must contain both a Start node and an End node.');
        }
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, mixed>|null
     */
    private function findNodeByType(array $graph, string $type): ?array
    {
        foreach ($graph['nodes'] as $node) {
            if (($node['type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, mixed>|null
     */
    private function findNodeById(array $graph, string $id): ?array
    {
        foreach ($graph['nodes'] as $node) {
            if (is_string($node['id'] ?? null) && $node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array<string, mixed>>
     */
    private function outgoingEdges(array $graph, string $sourceId): array
    {
        $out = [];
        foreach ($graph['edges'] as $edge) {
            if (is_string($edge['source'] ?? null) && $edge['source'] === $sourceId) {
                $out[] = $edge;
            }
        }

        return $out;
    }

    /**
     * Safe condition parser supporting `field operator value` without PHP eval().
     *
     * @param  array<string, mixed>  $vars
     */
    private function evaluateCondition(string $expr, Document $doc, array $vars): bool
    {
        if (preg_match('/^\s*([a-zA-Z0-9_]+)\s*(>=|<=|==|!=|>|<)\s*(.+?)\s*$/', $expr, $m) !== 1) {
            return false;
        }

        [$full, $field, $op, $rawTarget] = $m;
        $target = trim($rawTarget, " \t\n\r\0\x0B'\"");

        $actual = match ($field) {
            'classification_rank' => $doc->classification_rank,
            'status' => $doc->status->value,
            default => $vars[$field] ?? null,
        };

        if ($actual === null) {
            return false;
        }

        $actualStr = is_scalar($actual) ? (string) $actual : '';

        return match ($op) {
            '>=' => is_numeric($actual) && is_numeric($target) && (float) $actual >= (float) $target,
            '<=' => is_numeric($actual) && is_numeric($target) && (float) $actual <= (float) $target,
            '>' => is_numeric($actual) && is_numeric($target) && (float) $actual > (float) $target,
            '<' => is_numeric($actual) && is_numeric($target) && (float) $actual < (float) $target,
            '==' => mb_strtolower($actualStr) === mb_strtolower($target),
            '!=' => mb_strtolower($actualStr) !== mb_strtolower($target),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveNodeAssignee(array $node, Document $document, User $fallbackActor): User
    {
        $explicitId = is_string($node['assignee_user_id'] ?? null) ? trim($node['assignee_user_id']) : '';
        if ($explicitId !== '') {
            /** @var User|null $explicitUser */
            $explicitUser = User::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('id', $explicitId)
                ->first();
            if ($explicitUser !== null && $this->policyEngine->authorize($explicitUser, 'view', $document, ['bypass_abac' => true])->allowed) {
                return $explicitUser;
            }
        }

        $roleName = is_string($node['assignee_role'] ?? null) && $node['assignee_role'] !== ''
            ? $node['assignee_role']
            : BetterDocRole::Approver->value;

        app(PermissionRegistrar::class)->setPermissionsTeamId($document->tenant_id);

        $candidates = User::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('clearance_rank', '>=', $document->classification_rank)
            ->whereHas('roles', static fn ($q) => $q->where('name', $roleName))
            ->orderByDesc('clearance_rank')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->policyEngine->authorize($candidate, 'view', $document, ['bypass_abac' => true])->allowed) {
                return $candidate;
            }
        }

        return $fallbackActor;
    }

    private function resolveEscalationAssignee(WorkflowTask $task, Document $document): ?User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($document->tenant_id);

        $candidates = User::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('id', '!=', $task->assignee_id)
            ->where('clearance_rank', '>=', $document->classification_rank)
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', [BetterDocRole::Approver->value, BetterDocRole::RecordsOfficer->value]))
            ->orderByDesc('clearance_rank')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->policyEngine->authorize($candidate, 'view', $document, ['bypass_abac' => true])->allowed) {
                return $candidate;
            }
        }

        return null;
    }
}
