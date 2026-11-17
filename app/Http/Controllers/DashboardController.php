<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TwoPersonStatus;
use App\Models\AuditEvent;
use App\Models\BreakGlassSession;
use App\Models\Cabinet;
use App\Models\Document;
use App\Models\OrgUnit;
use App\Models\TwoPersonRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $accessibleDocsCount = Document::query()->count();
        $recentDocuments = Document::query()
            ->with(['classificationLevel', 'orgUnit', 'compartments'])
            ->latest('updated_at')
            ->limit(6)
            ->get()
            ->map(static fn (Document $doc): array => [
                'id' => $doc->id,
                'doc_number' => $doc->doc_number,
                'title' => $doc->title,
                'status' => $doc->status->value,
                'classification_rank' => $doc->classification_rank,
                'classification' => [
                    'name' => $doc->classificationLevel?->name,
                    'color_hex' => $doc->classificationLevel?->color_hex,
                ],
                'org_unit_code' => $doc->orgUnit?->code,
                'compartments' => $doc->compartments->pluck('code')->values()->all(),
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $cabinetsCount = Cabinet::query()->count();
        $pendingTwoPerson = TwoPersonRequest::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', TwoPersonStatus::Pending->value)
            ->count();

        $activeBreakGlass = BreakGlassSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $orgTree = OrgUnit::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('path')
            ->get()
            ->map(static fn (OrgUnit $u): array => [
                'id' => $u->id,
                'code' => $u->code,
                'name' => $u->name,
                'unit_type' => $u->unit_type,
                'path' => $u->path,
                'is_current' => $u->id === $user->org_unit_id,
            ])
            ->values()
            ->all();

        $auditCount = AuditEvent::query()
            ->where('tenant_id', $user->tenant_id)
            ->count();

        return Inertia::render('Dashboard', [
            'stats' => [
                'accessible_documents' => $accessibleDocsCount,
                'accessible_cabinets' => $cabinetsCount,
                'pending_approvals' => $pendingTwoPerson,
                'active_break_glass' => $activeBreakGlass,
                'audit_events_count' => $auditCount,
            ],
            'recentDocuments' => $recentDocuments,
            'orgTree' => $orgTree,
        ]);
    }
}
