<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganisationSetting;
use App\Services\Branding\BrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LandingController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
    ) {}

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        $setting = $this->branding->findActiveSetting();
        $mode = $this->branding->effectiveLandingMode($setting);

        if ($mode === 'off') {
            return redirect()->route('login');
        }

        return Inertia::render('Landing/Show', [
            'mode' => $mode,
        ]);
    }

    public function asset(OrganisationSetting $setting, string $slot): Response
    {
        $path = match ($slot) {
            'logo' => $setting->logo_path,
            'logo_dark' => $setting->logo_dark_path,
            'favicon' => $setting->favicon_path,
            default => null,
        };

        if (! is_string($path) || $path === '' || ! Storage::disk('betterdoc_branding')->exists($path)) {
            abort(404);
        }

        $bytes = Storage::disk('betterdoc_branding')->get($path);
        if (! is_string($bytes) || $bytes === '') {
            abort(404);
        }

        $isSvg = str_ends_with(strtolower($path), '.svg');
        $headers = [
            'Content-Type' => $isSvg ? 'image/svg+xml; charset=UTF-8' : 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ];

        if ($isSvg) {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox;";
        }

        return response($bytes, 200, $headers);
    }
}
