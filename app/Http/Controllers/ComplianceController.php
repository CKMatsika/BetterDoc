<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BetterDocRole;
use App\Models\CompliancePack;
use App\Models\User;
use App\Services\Compliance\CompliancePackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    public function __construct(
        private readonly CompliancePackService $compliance,
    ) {}

    public function generatePack(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        /** @var array{regulation_standard: string, pack_name?: string|null} $validated */
        $validated = $request->validate([
            'regulation_standard' => ['required', Rule::in(['ZIMRA', 'RBZ_AML', 'ISO_27001', 'CYBER_DATA_ACT', 'ISO_15489'])],
            'pack_name' => ['nullable', 'string', 'max:180'],
        ]);

        $pack = $this->compliance->generateRegulatorPack(
            tenant: $tenant,
            auditor: $user,
            regulationStandard: $validated['regulation_standard'],
            packName: $validated['pack_name'] ?? '',
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['pack' => $pack], 201);
        }

        return back()->with('success', "Signed regulator pack '{$pack->pack_name}' generated ({$pack->event_count} events).");
    }

    public function verifyPack(Request $request, CompliancePack $compliancePack): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($compliancePack->tenant_id === $user->tenant_id, 404);
        abort_unless($user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value, BetterDocRole::RecordsOfficer->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $verified = $this->compliance->verifyAndReadPack($compliancePack, $tenant);

        return response()->json([
            'pack_id' => $compliancePack->id,
            'manifest_valid' => $verified['manifest_valid'],
            'files_valid' => $verified['files_valid'],
            'chain_valid' => $verified['chain_valid'],
            'checkpoints_valid' => $verified['checkpoints_valid'],
            'files' => $verified['files'],
        ]);
    }

    public function downloadPack(Request $request, CompliancePack $compliancePack): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($compliancePack->tenant_id === $user->tenant_id, 404);
        abort_unless($user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $verified = $this->compliance->verifyAndReadPack($compliancePack, $tenant);

        return response($verified['zip_bytes'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="compliance-pack-'.substr($compliancePack->id, 0, 8).'.zip"',
        ]);
    }

    public function exportSyslog(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyRole([BetterDocRole::Auditor->value, BetterDocRole::SystemAdmin->value]), 403);

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $res = $this->compliance->exportRfc5424Syslog($tenant, 100);

        return response(implode("\n", $res['lines'])."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="betterdoc-audit-rfc5424.syslog"',
        ]);
    }
}
