<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Enums\ApInvoiceStatus;
use App\Enums\BetterDocRole;
use App\Enums\UserStatus;
use App\Models\ApInvoice;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\ExternalMapping;
use App\Models\HrEmployeeFile;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Hr\HrVaultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ErpBatchSyncService
{
    public function __construct(
        private readonly HrVaultService $hr,
        private readonly ImmutableAuditLogger $audit,
    ) {}

    /**
     * Import Belina Payroll / HR employee master CSV rows into HrEmployeeFile + ExternalMapping.
     * Expected columns: employee_number, email, name, department_name, job_title, hire_date, status.
     *
     * @return array{imported: int, created_users: int, skipped: int, errors: list<string>}
     */
    public function importBelinaEmployeesCsv(User $actor, string $csvContent, ?Request $request = null): array
    {
        $rows = $this->parseCsvAssoc($csvContent);
        $imported = 0;
        $createdUsers = 0;
        $skipped = 0;
        $errors = [];

        /** @var Tenant $tenant */
        $tenant = $actor->tenant()->firstOrFail();

        $hrCabinet = Cabinet::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('default_classification_rank')
            ->firstOrFail();

        /** @var ClearanceLevel $baseLevel */
        $baseLevel = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('rank')
            ->firstOrFail();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        foreach ($rows as $rowIndex => $row) {
            $empNo = trim($row['employee_number'] ?? '');
            $email = strtolower(trim($row['email'] ?? ''));
            $name = trim($row['name'] ?? '');
            $dept = trim($row['department_name'] ?? 'General');
            $jobTitle = trim($row['job_title'] ?? 'Staff');
            $hireDate = trim($row['hire_date'] ?? '');

            if ($empNo === '' || $email === '' || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = 'Row '.($rowIndex + 2).': missing valid employee_number, name, or email.';

                continue;
            }

            /** @var User|null $employee */
            $employee = User::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->first();

            if ($employee === null) {
                if (User::query()->where('email', $email)->exists()) {
                    $skipped++;
                    $errors[] = 'Row '.($rowIndex + 2).": email {$email} belongs to another tenant.";

                    continue;
                }

                /** @var User $employee */
                $employee = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'org_unit_id' => $actor->org_unit_id,
                    'clearance_level_id' => $baseLevel->id,
                    'clearance_rank' => $baseLevel->rank,
                    'service_number' => $empNo,
                    'rank_title' => substr($jobTitle, 0, 60),
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::password(24)),
                    'status' => UserStatus::Active,
                    'mfa_enforced' => false,
                ]);
                $employee->syncRoles([BetterDocRole::Reader->value]);
                $createdUsers++;
            }

            $profile = $this->hr->ensureEmployeeProfile(
                hrActor: $actor,
                employee: $employee,
                hrCabinet: $hrCabinet,
                employeeNumber: $empNo,
                departmentName: $dept !== '' ? $dept : 'General',
                jobTitle: $jobTitle !== '' ? $jobTitle : 'Staff',
                hireDate: $hireDate !== '' ? $hireDate : null,
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'external_system' => 'belina',
                    'entity_type' => 'hr_employee',
                    'external_id' => $empNo,
                ],
                [
                    'internal_id' => $profile->id,
                    'metadata_json' => ['email' => $email, 'department' => $dept],
                ]
            );

            $imported++;
        }

        $this->audit->log(
            tenantId: $tenant->id,
            eventType: 'integration.belina_csv_imported',
            resourceType: 'hr_employee_file',
            resourceId: $tenant->id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['imported' => $imported, 'created_users' => $createdUsers, 'skipped' => $skipped],
            request: $request,
        );

        return [
            'imported' => $imported,
            'created_users' => $createdUsers,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import Sage Pastel Supplier/Vendor CSV rows into Vendor + ExternalMapping.
     * Expected columns: vendor_code, name, tax_id_tin, vat_number, currency, email.
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importSageVendorsCsv(User $actor, string $csvContent, ?Request $request = null): array
    {
        $rows = $this->parseCsvAssoc($csvContent);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            $code = strtoupper(trim($row['vendor_code'] ?? ''));
            $name = trim($row['name'] ?? '');
            $tin = trim($row['tax_id_tin'] ?? '');
            $vat = trim($row['vat_number'] ?? '');
            $currency = strtoupper(trim($row['currency'] ?? 'USD'));
            $email = trim($row['email'] ?? '');

            if ($code === '' || $name === '') {
                $skipped++;
                $errors[] = 'Row '.($rowIndex + 2).': missing vendor_code or name.';

                continue;
            }

            /** @var Vendor $vendor */
            $vendor = Vendor::query()->updateOrCreate(
                ['tenant_id' => $actor->tenant_id, 'code' => $code],
                [
                    'name' => $name,
                    'tax_id_tin' => $tin !== '' ? $tin : null,
                    'vat_number' => $vat !== '' ? $vat : null,
                    'currency' => $currency !== '' ? substr($currency, 0, 8) : 'USD',
                    'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'is_active' => true,
                ]
            );

            ExternalMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'external_system' => 'sage_pastel',
                    'entity_type' => 'vendor',
                    'external_id' => $code,
                ],
                [
                    'internal_id' => $vendor->id,
                    'metadata_json' => ['name' => $vendor->name, 'currency' => $vendor->currency],
                ]
            );

            $imported++;
        }

        $this->audit->log(
            tenantId: $actor->tenant_id,
            eventType: 'integration.sage_vendors_imported',
            resourceType: 'vendor',
            resourceId: $actor->tenant_id,
            actor: $actor,
            classificationRank: $actor->clearance_rank,
            after: ['imported' => $imported, 'skipped' => $skipped],
            request: $request,
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export Matched/Approved AP Invoices in CSV-formula-safe batch format for Sage Pastel / NetSuite / Xero.
     */
    public function exportApprovedInvoicesCsv(User $actor, string $externalSystem = 'sage_pastel'): string
    {
        $mappings = ExternalMapping::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('external_system', $externalSystem)
            ->where('entity_type', 'vendor')
            ->get()
            ->keyBy('internal_id');

        $invoices = ApInvoice::query()
            ->with(['vendor', 'purchaseOrder'])
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('status', [ApInvoiceStatus::Matched->value, ApInvoiceStatus::Approved->value, ApInvoiceStatus::Exported->value])
            ->orderBy('invoice_date')
            ->get();

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, [
            'external_vendor_id',
            'vendor_code',
            'vendor_name',
            'invoice_number',
            'invoice_date',
            'due_date',
            'currency',
            'subtotal',
            'vat',
            'total',
            'po_number',
            'status',
            'zimra_fiscal_status',
            'document_id',
        ]);

        /** @var ApInvoice $inv */
        foreach ($invoices as $inv) {
            $extVendor = $mappings->get($inv->vendor_id);
            fputcsv($stream, [
                $this->csvSafe((string) ($extVendor->external_id ?? $inv->vendor->code ?? '')),
                $this->csvSafe((string) ($inv->vendor->code ?? '')),
                $this->csvSafe((string) ($inv->vendor->name ?? '')),
                $this->csvSafe($inv->invoice_number),
                $inv->invoice_date->toDateString(),
                $inv->due_date?->toDateString() ?? '',
                $this->csvSafe($inv->currency),
                number_format($inv->subtotal_cents / 100, 2, '.', ''),
                number_format($inv->vat_cents / 100, 2, '.', ''),
                number_format($inv->total_cents / 100, 2, '.', ''),
                $this->csvSafe((string) ($inv->purchaseOrder->po_number ?? '')),
                $inv->status->value,
                $inv->zimra_fiscal_status->value,
                $inv->document_id,
            ]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public function exportBelinaEmployeesCsv(User $actor): string
    {
        $employees = HrEmployeeFile::query()
            ->with(['subjectUser'])
            ->where('tenant_id', $actor->tenant_id)
            ->orderBy('employee_number')
            ->get();

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, ['employee_number', 'name', 'email', 'department_name', 'job_title', 'hire_date', 'status']);

        /** @var HrEmployeeFile $emp */
        foreach ($employees as $emp) {
            fputcsv($stream, [
                $this->csvSafe($emp->employee_number),
                $this->csvSafe((string) ($emp->subjectUser->name ?? '')),
                $this->csvSafe((string) ($emp->subjectUser->email ?? '')),
                $this->csvSafe($emp->department_name),
                $this->csvSafe($emp->job_title),
                $emp->hire_date?->toDateString() ?? '',
                $this->csvSafe($emp->status),
            ]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsvAssoc(string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }

        fwrite($stream, str_replace("\r\n", "\n", trim($csvContent)));
        rewind($stream);

        $headers = fgetcsv($stream);
        if (! is_array($headers)) {
            fclose($stream);

            return [];
        }

        $normalizedHeaders = array_map(static fn (mixed $h): string => strtolower(trim((string) $h)), $headers);
        $rows = [];

        while (($line = fgetcsv($stream)) !== false) {
            if ($line === [null]) {
                continue;
            }

            $assoc = [];
            foreach ($normalizedHeaders as $idx => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = trim((string) ($line[$idx] ?? ''));
            }
            if ($assoc !== []) {
                $rows[] = $assoc;
            }
        }

        fclose($stream);

        return $rows;
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
