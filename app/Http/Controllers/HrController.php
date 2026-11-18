<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\HrEmployeeFile;
use App\Models\User;
use App\Services\Hr\HrVaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HrController extends Controller
{
    public function __construct(
        private readonly HrVaultService $hr,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $canManageHr = $user->hasAnyRole([
            BetterDocRole::SystemAdmin->value,
            BetterDocRole::RecordsOfficer->value,
            BetterDocRole::Approver->value,
        ]);

        $employeeFiles = HrEmployeeFile::query()
            ->with(['subjectUser'])
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('employee_number')
            ->get()
            ->map(static fn (HrEmployeeFile $f): array => [
                'id' => $f->id,
                'subject_user_id' => $f->subject_user_id,
                'subject_name' => $f->subjectUser->name ?? '',
                'subject_email' => $f->subjectUser->email ?? '',
                'employee_number' => $f->employee_number,
                'department_name' => $f->department_name,
                'job_title' => $f->job_title,
                'hire_date' => $f->hire_date?->toDateString(),
                'status' => $f->status,
            ])
            ->values()
            ->all();

        // PostgreSQL RLS automatically shields hr_restricted_from_subject=true rows from the subject user!
        $hrDocuments = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNotNull('hr_subject_user_id')
            ->latest()
            ->limit(50)
            ->get()
            ->map(static fn (Document $d): array => [
                'id' => $d->id,
                'doc_number' => $d->doc_number,
                'title' => $d->title,
                'classification_rank' => $d->classification_rank,
                'hr_subject_user_id' => $d->hr_subject_user_id,
                'hr_restricted_from_subject' => $d->hr_restricted_from_subject,
                'category' => is_array($d->metadata_json) ? ($d->metadata_json['hr_category'] ?? 'record') : 'record',
            ])
            ->values()
            ->all();

        $tenantUsers = $canManageHr
            ? User::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name', 'email'])->toArray()
            : [];

        $cabinets = Cabinet::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])->toArray();
        $levels = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('rank', '<=', $user->clearance_rank)
            ->orderBy('rank')
            ->get(['id', 'rank', 'name'])
            ->toArray();

        return Inertia::render('Hr/Index', [
            'employeeFiles' => $employeeFiles,
            'hrDocuments' => $hrDocuments,
            'tenantUsers' => $tenantUsers,
            'cabinets' => $cabinets,
            'allowedClearances' => $levels,
            'canManageHr' => $canManageHr,
        ]);
    }

    public function storeEmployee(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]), 403);

        /** @var array{subject_user_id: string, cabinet_id: string, employee_number: string, department_name: string, job_title: string, hire_date?: string|null} $validated */
        $validated = $request->validate([
            'subject_user_id' => ['required', 'uuid'],
            'cabinet_id' => ['required', 'uuid'],
            'employee_number' => ['required', 'string', 'max:48'],
            'department_name' => ['required', 'string', 'max:120'],
            'job_title' => ['required', 'string', 'max:120'],
            'hire_date' => ['nullable', 'date'],
        ]);

        /** @var User $employee */
        $employee = User::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['subject_user_id'])->firstOrFail();
        /** @var Cabinet $cab */
        $cab = Cabinet::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['cabinet_id'])->firstOrFail();

        $profile = $this->hr->ensureEmployeeProfile(
            $user,
            $employee,
            $cab,
            $validated['employee_number'],
            $validated['department_name'],
            $validated['job_title'],
            $validated['hire_date'] ?? null,
        );

        if ($request->wantsJson()) {
            return response()->json(['employee_file' => $profile], 201);
        }

        return back()->with('success', "HR Personnel folder ready for {$employee->name}.");
    }

    public function storeRecord(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value, BetterDocRole::Approver->value]), 403);

        /** @var array{hr_employee_file_id: string, cabinet_id: string, classification_level_id: string, category: string, title: string, content_text: string, restricted_from_subject?: bool} $validated */
        $validated = $request->validate([
            'hr_employee_file_id' => ['required', 'uuid'],
            'cabinet_id' => ['required', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'category' => ['required', 'string', 'max:48'],
            'title' => ['required', 'string', 'max:180'],
            'content_text' => ['required', 'string', 'max:20000'],
            'restricted_from_subject' => ['nullable', 'boolean'],
        ]);

        /** @var HrEmployeeFile $empFile */
        $empFile = HrEmployeeFile::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['hr_employee_file_id'])->firstOrFail();
        /** @var Cabinet $cab */
        $cab = Cabinet::query()->where('tenant_id', $user->tenant_id)->where('id', $validated['cabinet_id'])->firstOrFail();
        /** @var ClearanceLevel $level */
        $level = ClearanceLevel::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', $validated['classification_level_id'])
            ->where('rank', '<=', $user->clearance_rank)
            ->firstOrFail();

        $restricted = (bool) ($validated['restricted_from_subject'] ?? in_array(strtolower($validated['category']), ['disciplinary', 'medical', 'investigation'], true));

        $doc = $this->hr->fileHrDocument(
            hrActor: $user,
            employeeFile: $empFile,
            hrCabinet: $cab,
            level: $level,
            category: $validated['category'],
            title: $validated['title'],
            contentText: $validated['content_text'],
            restrictedFromSubject: $restricted,
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['document' => $doc], 201);
        }

        return back()->with('success', "HR record {$doc->doc_number} filed (".($restricted ? 'Shielded from Subject' : 'Visible to Employee').').');
    }
}
