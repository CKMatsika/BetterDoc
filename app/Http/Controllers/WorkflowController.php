<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SentinelRole;
use App\Enums\WorkflowTaskStatus;
use App\Exceptions\InsufficientClearanceException;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Workflow\WorkflowEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowEngineService $engine,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $definitions = WorkflowDefinition::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (WorkflowDefinition $d): array => [
                'id' => $d->id,
                'key' => $d->key,
                'name' => $d->name,
                'description' => $d->description,
                'version' => $d->version,
                'sla_hours' => $d->sla_hours,
                'bpmn_graph_json' => $d->bpmn_graph_json,
                'form_schema_json' => $d->form_schema_json ?? [],
            ])
            ->values()
            ->all();

        $instances = WorkflowInstance::query()
            ->with(['definition', 'document', 'starter', 'tasks.assignee'])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(static fn (WorkflowInstance $inst): array => [
                'id' => $inst->id,
                'definition_name' => $inst->definition?->name,
                'document_id' => $inst->document_id,
                'doc_number' => $inst->document?->doc_number,
                'document_title' => $inst->document?->title,
                'starter_name' => $inst->starter?->name,
                'status' => $inst->status->value,
                'sla_due_at' => $inst->sla_due_at?->toIso8601String(),
                'completed_at' => $inst->completed_at?->toIso8601String(),
                'tasks' => $inst->tasks->map(static fn (WorkflowTask $t): array => [
                    'id' => $t->id,
                    'node_label' => $t->node_label,
                    'assignee_name' => $t->assignee?->name,
                    'status' => $t->status->value,
                    'decision' => $t->decision,
                    'approval_hash' => $t->approval_hash,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $documents = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(50)
            ->get(['id', 'doc_number', 'title', 'classification_rank'])
            ->toArray();

        $users = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('clearance_rank')
            ->get(['id', 'name', 'rank_title', 'clearance_rank'])
            ->toArray();

        return Inertia::render('Workflows/Index', [
            'definitions' => $definitions,
            'instances' => $instances,
            'documents' => $documents,
            'users' => $users,
            'roles' => SentinelRole::values(),
        ]);
    }

    public function storeDefinition(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{key: string, name: string, description?: string|null, sla_hours: int, bpmn_graph_json: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, form_schema_json?: list<array<string, mixed>>|null} $validated */
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'alpha_dash'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'bpmn_graph_json' => ['required', 'array'],
            'bpmn_graph_json.nodes' => ['required', 'array', 'min:2'],
            'bpmn_graph_json.edges' => ['required', 'array', 'min:1'],
            'form_schema_json' => ['nullable', 'array'],
        ]);

        try {
            $def = $this->engine->createDefinition(
                creator: $user,
                key: $validated['key'],
                name: $validated['name'],
                bpmnGraph: $validated['bpmn_graph_json'],
                description: $validated['description'] ?? null,
                formSchema: $validated['form_schema_json'] ?? null,
                slaHours: (int) $validated['sla_hours'],
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['bpmn_graph_json' => $e->getMessage()]);
        }

        return back()->with('success', "Workflow '{$def->name}' v{$def->version} published.");
    }

    public function startInstance(Request $request, Document $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $document);

        /** @var array{definition_id: string, variables?: array<string, mixed>} $validated */
        $validated = $request->validate([
            'definition_id' => ['required', 'uuid'],
            'variables' => ['nullable', 'array'],
        ]);

        /** @var WorkflowDefinition $definition */
        $definition = WorkflowDefinition::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['definition_id'])
            ->firstOrFail();

        try {
            $instance = $this->engine->startInstance(
                definition: $definition,
                document: $document,
                starter: $user,
                variables: $validated['variables'] ?? [],
                request: $request,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['workflow' => $e->getMessage()]);
        }

        return back()->with('success', "Workflow started (Instance {$instance->id}).");
    }

    public function inbox(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tasks = WorkflowTask::query()
            ->with(['instance.definition', 'instance.document', 'assignee', 'delegatedTo'])
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($q) use ($user): void {
                $q->where('assignee_id', $user->id)
                    ->orWhere('delegated_to_id', $user->id);
            })
            ->orderByRaw("CASE WHEN status IN ('pending', 'escalated') THEN 0 ELSE 1 END")
            ->latest()
            ->limit(50)
            ->get()
            ->map(static fn (WorkflowTask $t): array => [
                'id' => $t->id,
                'node_label' => $t->node_label,
                'node_type' => $t->node_type,
                'status' => $t->status->value,
                'decision' => $t->decision,
                'decision_comment' => $t->decision_comment,
                'approval_hash' => $t->approval_hash,
                'due_at' => $t->due_at?->toIso8601String(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                'is_actionable' => in_array($t->status, [WorkflowTaskStatus::Pending, WorkflowTaskStatus::Escalated], true),
                'workflow_name' => $t->instance?->definition?->name,
                'form_schema' => ($t->instance !== null && $t->instance->definition !== null)
                    ? ($t->instance->definition->form_schema_json ?? [])
                    : [],
                'document' => $t->instance?->document ? [
                    'id' => $t->instance->document->id,
                    'doc_number' => $t->instance->document->doc_number,
                    'title' => $t->instance->document->title,
                    'classification_rank' => $t->instance->document->classification_rank,
                ] : null,
                'assignee_name' => $t->assignee?->name,
                'delegated_to_name' => $t->delegatedTo?->name,
            ])
            ->values()
            ->all();

        $delegateUsers = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->orderByDesc('clearance_rank')
            ->get(['id', 'name', 'rank_title', 'clearance_rank'])
            ->toArray();

        return Inertia::render('Workflows/Inbox', [
            'tasks' => $tasks,
            'delegateUsers' => $delegateUsers,
        ]);
    }

    public function decideTask(Request $request, WorkflowTask $workflowTask): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{decision: string, comment?: string|null, form_data?: array<string, mixed>|null} $validated */
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:1000'],
            'form_data' => ['nullable', 'array'],
        ]);

        try {
            $completed = $this->engine->completeTask(
                task: $workflowTask,
                actor: $user,
                decision: $validated['decision'],
                comment: $validated['comment'] ?? null,
                formData: $validated['form_data'] ?? null,
                request: $request,
            );
        } catch (InsufficientClearanceException|RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return back()->with('success', "Task decision '{$completed->decision}' recorded (Tier 3 Attestation Hash: ".substr((string) $completed->approval_hash, 0, 12).'...).');
    }

    public function delegateTask(Request $request, WorkflowTask $workflowTask): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{delegate_user_id: string, reason?: string|null} $validated */
        $validated = $request->validate([
            'delegate_user_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $delegate */
        $delegate = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['delegate_user_id'])
            ->firstOrFail();

        try {
            $this->engine->delegateTask($workflowTask, $user, $delegate, $validated['reason'] ?? null, $request);
        } catch (InsufficientClearanceException|RuntimeException $e) {
            throw ValidationException::withMessages(['delegate_user_id' => $e->getMessage()]);
        }

        return back()->with('success', "Task delegated cleanly to {$delegate->name}.");
    }

    public function runEscalations(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->engine->checkAndEscalateOverdueTasks($user->tenant_id);

        return back()->with('success', "SLA check completed: {$count} overdue task(s) escalated.");
    }
}
