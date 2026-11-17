<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TemplateCategory;
use App\Exceptions\InsufficientClearanceException;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\DocumentTemplate;
use App\Models\Folder;
use App\Models\User;
use App\Services\Dms\TemplateGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TemplateController extends Controller
{
    public function __construct(
        private readonly TemplateGenerationService $templates,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $templates = DocumentTemplate::query()
            ->with('defaultClassification')
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (DocumentTemplate $t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category->value,
                'body_html' => $t->body_html,
                'placeholders' => $t->placeholders_json,
                'auto_number_prefix' => $t->auto_number_prefix,
                'next_sequence' => $t->next_sequence,
                'default_classification_level_id' => $t->default_classification_level_id,
            ])
            ->values()
            ->all();

        $allowedClearances = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'code', 'name', 'color_hex'])
            ->toArray();

        $cabinets = Cabinet::query()
            ->orderBy('name')
            ->get(['id', 'name', 'default_classification_rank'])
            ->toArray();

        $compartments = $user->compartments()->get(['compartments.id', 'compartments.code', 'compartments.name'])->toArray();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'allowedClearances' => $allowedClearances,
            'cabinets' => $cabinets,
            'userCompartments' => $compartments,
            'categories' => array_column(TemplateCategory::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{code: string, name: string, category: string, auto_number_prefix: string, body_html: string, placeholders: list<array{key: string, label: string, required?: bool}>, default_classification_level_id?: string|null} $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_column(TemplateCategory::cases(), 'value'))],
            'auto_number_prefix' => ['required', 'string', 'max:32'],
            'body_html' => ['required', 'string', 'max:20000'],
            'placeholders' => ['required', 'array'],
            'placeholders.*.key' => ['required', 'string', 'alpha_dash'],
            'placeholders.*.label' => ['required', 'string', 'max:100'],
            'default_classification_level_id' => ['nullable', 'uuid'],
        ]);

        DocumentTemplate::query()->updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'code' => strtoupper($validated['code'])],
            [
                'name' => $validated['name'],
                'category' => TemplateCategory::from($validated['category']),
                'body_html' => $validated['body_html'],
                'placeholders_json' => $validated['placeholders'],
                'default_classification_level_id' => $validated['default_classification_level_id'] ?? null,
                'auto_number_prefix' => strtoupper($validated['auto_number_prefix']),
                'is_active' => true,
                'created_by' => $user->id,
            ]
        );

        return back()->with('success', 'Mail-merge document template saved.');
    }

    public function generate(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($documentTemplate->tenant_id === $user->tenant_id, 404);

        /** @var array{cabinet_id: string, folder_id?: string|null, classification_level_id: string, title: string, variables: array<string, string>, compartment_ids?: list<string>} $validated */
        $validated = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'folder_id' => ['nullable', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'variables' => ['required', 'array'],
            'variables.*' => ['nullable', 'string', 'max:4000'],
            'compartment_ids' => ['nullable', 'array'],
            'compartment_ids.*' => ['uuid'],
        ]);

        /** @var Cabinet $cabinet */
        $cabinet = Cabinet::query()->where('id', $validated['cabinet_id'])->firstOrFail();

        $folder = null;
        if (! empty($validated['folder_id'])) {
            $folder = Folder::query()->where('id', $validated['folder_id'])->where('cabinet_id', $cabinet->id)->firstOrFail();
        }

        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['classification_level_id'])
            ->firstOrFail();

        $vars = [];
        foreach ($validated['variables'] as $k => $v) {
            $vars[$k] = $v;
        }

        try {
            $doc = $this->templates->generateDocument(
                template: $documentTemplate,
                actor: $user,
                cabinet: $cabinet,
                level: $level,
                title: $validated['title'],
                variables: $vars,
                folder: $folder,
                compartmentIds: $validated['compartment_ids'] ?? [],
                request: $request,
            );
        } catch (InsufficientClearanceException|RuntimeException $e) {
            throw ValidationException::withMessages(['classification_level_id' => $e->getMessage()]);
        }

        return redirect()->route('documents.show', $doc->id)
            ->with('success', "Generated official record {$doc->doc_number} from template {$documentTemplate->code}.");
    }
}
