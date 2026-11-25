<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\DocumentStatus;
use App\Enums\PrincipalType;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\Document;
use App\Models\DocumentAclGrant;
use App\Models\Folder;
use App\Models\HrEmployeeFile;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Upload\ResumableUploadService;
use Illuminate\Http\Request;
use RuntimeException;

class HrVaultService
{
    public function __construct(
        private readonly ResumableUploadService $uploadService,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    public function ensureEmployeeProfile(
        User $hrActor,
        User $employee,
        Cabinet $hrCabinet,
        string $employeeNumber,
        string $departmentName,
        string $jobTitle,
        ?string $hireDate = null,
    ): HrEmployeeFile {
        $existing = HrEmployeeFile::query()
            ->where('tenant_id', $hrActor->tenant_id)
            ->where('subject_user_id', $employee->id)
            ->first();

        if ($existing instanceof HrEmployeeFile) {
            $existing->update([
                'employee_number' => $employeeNumber,
                'department_name' => $departmentName,
                'job_title' => $jobTitle,
                'hire_date' => $hireDate ?? $existing->hire_date,
            ]);

            return $existing->refresh();
        }

        $empFolder = Folder::query()->firstOrCreate(
            [
                'tenant_id' => $hrActor->tenant_id,
                'cabinet_id' => $hrCabinet->id,
                'name' => "{$employeeNumber} — {$employee->name}",
            ],
            [
                'org_unit_id' => $employee->org_unit_id,
                'path' => 'hr.emp.'.preg_replace('/[^a-z0-9_]/', '_', strtolower($employeeNumber)),
                'default_classification_rank' => min(2, $hrActor->clearance_rank),
            ]
        );

        /** @var HrEmployeeFile $profile */
        $profile = HrEmployeeFile::query()->create([
            'tenant_id' => $hrActor->tenant_id,
            'subject_user_id' => $employee->id,
            'employee_number' => trim($employeeNumber),
            'department_name' => trim($departmentName),
            'job_title' => trim($jobTitle),
            'folder_id' => $empFolder->id,
            'hire_date' => $hireDate,
            'status' => 'active',
        ]);

        return $profile;
    }

    /**
     * Store an HR personnel document cleanly under mandatory hr_subject_user_id + hr_restricted_from_subject policy.
     * Restricted categories (disciplinary/medical) remain invisible (404 / zero search/RAG hits) to the subject employee.
     */
    public function fileHrDocument(
        User $hrActor,
        HrEmployeeFile $employeeFile,
        Cabinet $hrCabinet,
        ClearanceLevel $level,
        string $category,
        string $title,
        string $contentText,
        bool $restrictedFromSubject,
        ?Request $request = null,
    ): Document {
        if ($level->rank > $hrActor->clearance_rank) {
            throw new RuntimeException('Cannot classify HR record above your active clearance rank.');
        }

        $subject = User::query()->findOrFail($employeeFile->subject_user_id);
        $docNo = sprintf('HR/%s/%s', strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $category) ?: 'REC', 0, 4)), now('UTC')->format('Ymd-His'));

        /** @var Document $document */
        $document = Document::query()->create([
            'tenant_id' => $hrActor->tenant_id,
            'cabinet_id' => $hrCabinet->id,
            'folder_id' => $employeeFile->folder_id,
            'org_unit_id' => $hrActor->org_unit_id,
            'owner_id' => $hrActor->id,
            'classification_level_id' => $level->id,
            'classification_rank' => $level->rank,
            'doc_number' => $docNo,
            'title' => trim($title),
            'summary' => "HR Personnel Record ({$category}) — Subject: {$subject->name}",
            'status' => DocumentStatus::Active,
            'unit_wide_readable' => false,
            'hr_subject_user_id' => $subject->id,
            'hr_restricted_from_subject' => $restrictedFromSubject,
            'metadata_json' => [
                'hr_category' => $category,
                'employee_number' => $employeeFile->employee_number,
                'restricted_from_subject' => $restrictedFromSubject,
            ],
        ]);

        if (! $restrictedFromSubject && $subject->clearance_rank >= $level->rank) {
            DocumentAclGrant::query()->create([
                'tenant_id' => $hrActor->tenant_id,
                'document_id' => $document->id,
                'principal_type' => PrincipalType::User,
                'principal_id' => $subject->id,
                'perm_view' => true,
                'perm_download' => true,
                'perm_print' => true,
                'granted_by' => $hrActor->id,
            ]);
        }

        $tmpTxt = tempnam(sys_get_temp_dir(), 'bdoc_hr_').'.txt';
        file_put_contents($tmpTxt, "{$title}\nEmployee: {$subject->name} ({$employeeFile->employee_number})\nCategory: {$category}\n\n{$contentText}");

        try {
            $this->uploadService->ingestFileIntoDocumentVersion(
                sourceFilePath: $tmpTxt,
                filename: strtolower($category).'-'.substr($document->id, 0, 8).'.txt',
                mimeType: 'text/plain',
                document: $document,
                actor: $hrActor,
                major: true,
                versionNote: 'HR Personnel File upload',
                request: $request,
            );
        } finally {
            @unlink($tmpTxt);
        }

        $this->audit->log(
            tenantId: $hrActor->tenant_id,
            eventType: 'hr.record_filed',
            resourceType: 'document',
            resourceId: $document->id,
            actor: $hrActor,
            classificationRank: $level->rank,
            after: [
                'subject_user_id' => $subject->id,
                'category' => $category,
                'restricted_from_subject' => $restrictedFromSubject,
            ],
            request: $request,
        );

        return $document->refresh();
    }
}
