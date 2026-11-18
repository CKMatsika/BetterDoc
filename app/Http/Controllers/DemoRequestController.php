<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use App\Services\Branding\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DemoRequestController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
    ) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $setting = $this->branding->findActiveSetting();
        $mode = $this->branding->effectiveLandingMode($setting);
        abort_unless($mode === 'demo', 404);

        /** @var array{name: string, organisation: string, work_email: string, phone?: string|null, message?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'organisation' => ['required', 'string', 'min:2', 'max:160'],
            'work_email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $demo = DemoRequest::query()->create([
            'tenant_id' => $setting?->tenant_id,
            'name' => trim($validated['name']),
            'organisation' => trim($validated['organisation']),
            'work_email' => strtolower(trim($validated['work_email'])),
            'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
            'message' => isset($validated['message']) ? trim($validated['message']) : null,
            'source_ip' => $request->ip(),
            'status' => 'new',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Thank you. Your BetterDoc DMS demonstration request has been recorded.',
                'demo_request_id' => $demo->id,
            ], 201);
        }

        return back()->with('success', 'Thank you. Your demonstration request has been recorded and our engineering team will follow up.');
    }
}
