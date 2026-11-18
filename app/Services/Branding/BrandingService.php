<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\OrganisationSetting;
use App\Models\Tenant;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandingService
{
    /**
     * Default curated organisation profiles selectable in first-run wizard or reset.
     *
     * @return array<string, array{profile_key: string, label: string, name: string, short_name: string, tagline: string, primary_color: string, accent_color: string, welcome_title: string, welcome_text: string, notice_text: string, notice_enabled: bool, support_email: string, support_phone: string, support_hours: string, address: string, website: string, show_powered_by: bool}>
     */
    public function presets(): array
    {
        return [
            'betterdoc' => [
                'profile_key' => 'betterdoc',
                'label' => 'BetterDoc DMS (Default)',
                'name' => 'BetterDoc DMS',
                'short_name' => 'BetterDoc',
                'tagline' => "Document management that works when the internet doesn't.",
                'primary_color' => '#0E7C66',
                'accent_color' => '#F59E0B',
                'welcome_title' => 'Organisation Document Portal',
                'welcome_text' => 'Access controlled files, approvals, PAdES digital signatures, and records archives securely on your local network.',
                'notice_text' => '',
                'notice_enabled' => false,
                'support_email' => 'helpdesk@betterdoc.lan',
                'support_phone' => '+263 24 200 0100',
                'support_hours' => 'Mon–Fri 07:30–17:00 CAT',
                'address' => 'On-Premise Sovereign Vault Appliance',
                'website' => '',
                'show_powered_by' => true,
            ],
            'defence' => [
                'profile_key' => 'defence',
                'label' => 'Defence & National Security',
                'name' => 'Air Force Command Document Registry',
                'short_name' => 'AFZ BetterDoc',
                'tagline' => 'Air-Gapped Classified Signals, Registry & Mission Records Vault',
                'primary_color' => '#0F172A',
                'accent_color' => '#0284C7',
                'welcome_title' => 'Classified Records Gateway',
                'welcome_text' => 'Authorised personnel only. All access is governed by Bell-LaPadula mandatory clearance levels and hardware MFA.',
                'notice_text' => 'Routine cryptographic audit checkpoint scheduled Saturday 18:00 CAT.',
                'notice_enabled' => true,
                'support_email' => 'signals-desk@afz.mil.zw',
                'support_phone' => 'Ext. 4410 (HQ Signals)',
                'support_hours' => '24/7 Duty Signals Officer',
                'address' => 'Command Headquarters, Harare',
                'website' => '',
                'show_powered_by' => true,
            ],
            'financial' => [
                'profile_key' => 'financial',
                'label' => 'Financial & Banking Services',
                'name' => 'Golden Knot Financial Holdings',
                'short_name' => 'Golden Knot DMS',
                'tagline' => 'Regulated KYC/AML Dossiers, Credit Appraisals & ZIMRA Fiscal Records',
                'primary_color' => '#0E7C66',
                'accent_color' => '#D97706',
                'welcome_title' => 'Regulated Financial Records Portal',
                'welcome_text' => 'Sign in with corporate credentials and authenticator MFA to review KYC dossiers, credit approvals, and audit packs.',
                'notice_text' => 'Quarter-end RBZ compliance archival freeze begins Friday at 17:30 CAT.',
                'notice_enabled' => true,
                'support_email' => 'it-risk@golden-knot.co.zw',
                'support_phone' => '+263 24 270 8820',
                'support_hours' => 'Mon–Sat 07:30–18:00 CAT',
                'address' => 'Borrowdale Financial Park, Harare',
                'website' => 'https://golden-knot.lan',
                'show_powered_by' => true,
            ],
            'corporate' => [
                'profile_key' => 'corporate',
                'label' => 'Corporate & Enterprise',
                'name' => 'ZimCorp Commercial Holdings',
                'short_name' => 'ZimCorp Hub',
                'tagline' => 'Enterprise Contracts, Accounts Payable Automation & HR Vault',
                'primary_color' => '#18181B',
                'accent_color' => '#0D9488',
                'welcome_title' => 'Corporate Document Workspace',
                'welcome_text' => 'Manage supplier POs, ZIMRA e-invoicing, contract obligations, and confidential employee records from one hub.',
                'notice_text' => '',
                'notice_enabled' => false,
                'support_email' => 'servicedesk@zimcorp.co.zw',
                'support_phone' => '+263 24 233 9100',
                'support_hours' => 'Mon–Fri 08:00–17:00 CAT',
                'address' => 'Workington Industrial Hub, Harare',
                'website' => 'https://zimcorp.lan',
                'show_powered_by' => true,
            ],
            'government' => [
                'profile_key' => 'government',
                'label' => 'Government & Public Sector',
                'name' => 'Ministry / Public Agency Document Registry',
                'short_name' => 'GOV Registry',
                'tagline' => 'Official Records, Gazette Dispatch & Statutory Contracts Vault',
                'primary_color' => '#0F172A',
                'accent_color' => '#0E7C66',
                'welcome_title' => 'Official Records & Registry Portal',
                'welcome_text' => 'Authorised public officers may sign in to manage ministerial files, correspondence registries, and signed approvals.',
                'notice_text' => '',
                'notice_enabled' => false,
                'support_email' => '',
                'support_phone' => '',
                'support_hours' => 'Mon–Fri 08:00–16:30 CAT',
                'address' => 'Government Complex',
                'website' => '',
                'show_powered_by' => true,
            ],
        ];
    }

    /**
     * Resolve effective landing mode respecting DB admin override or .env LANDING_MODE.
     */
    public function effectiveLandingMode(?OrganisationSetting $setting = null): string
    {
        $resolved = $setting ?? $this->findActiveSetting();
        $mode = $resolved?->landing_mode;

        if (! is_string($mode) || ! in_array($mode, ['portal', 'demo', 'off'], true)) {
            $mode = strtolower(config()->string('betterdoc.landing_mode', 'portal'));
        }

        return in_array($mode, ['portal', 'demo', 'off'], true) ? $mode : 'portal';
    }

    public function findActiveSetting(?User $user = null, ?Tenant $tenant = null): ?OrganisationSetting
    {
        if ($tenant instanceof Tenant) {
            $row = OrganisationSetting::query()->where('tenant_id', $tenant->id)->first();
            if ($row instanceof OrganisationSetting) {
                return $row;
            }
        }

        if ($user instanceof User && $user->tenant_id !== '') {
            $row = OrganisationSetting::query()->where('tenant_id', $user->tenant_id)->first();
            if ($row instanceof OrganisationSetting) {
                return $row;
            }
        }

        return OrganisationSetting::query()
            ->orderByDesc('is_public_default')
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END')
            ->first();
    }

    /**
     * Public-safe branding payload shared to landing page, login page, emails, PDF watermarks & HTML shell.
     * Exposes zero stack details, internal hostnames, or usernames.
     *
     * @return array{
     *   id: string|null,
     *   profile_key: string,
     *   name: string,
     *   short_name: string,
     *   tagline: string,
     *   logo_url: string|null,
     *   logo_dark_url: string|null,
     *   favicon_url: string,
     *   primary_color: string,
     *   primary_fg: string,
     *   accent_color: string,
     *   accent_fg: string,
     *   welcome_title: string,
     *   welcome_text: string,
     *   notice_text: string,
     *   notice_enabled: bool,
     *   support_email: string,
     *   support_phone: string,
     *   support_hours: string,
     *   address: string,
     *   website: string,
     *   show_powered_by: bool,
     *   landing_mode_setting: string|null,
     *   effective_landing_mode: string,
     *   contrast: array{primary_fg: string, primary_button_ratio: float, primary_on_light_ratio: float, accent_fg: string, accent_button_ratio: float, accent_on_light_ratio: float, warnings: list<string>}
     * }
     */
    public function toPublicPayload(?User $user = null, ?Tenant $tenant = null): array
    {
        $defaults = $this->presets()['betterdoc'];
        $setting = $this->findActiveSetting($user, $tenant);

        $primary = $this->normalizeHex($setting->primary_color ?? $defaults['primary_color'], '#0E7C66');
        $accent = $this->normalizeHex($setting->accent_color ?? $defaults['accent_color'], '#F59E0B');
        $contrast = $this->evaluateContrast($primary, $accent);

        $ver = $setting?->updated_at?->timestamp;
        $vSuffix = is_int($ver) ? '?v='.$ver : '';

        return [
            'id' => $setting?->id,
            'profile_key' => $setting->profile_key ?? $defaults['profile_key'],
            'name' => $setting->name ?? $defaults['name'],
            'short_name' => $setting->short_name ?? $defaults['short_name'],
            'tagline' => $setting->tagline ?? $defaults['tagline'],
            'logo_url' => ($setting instanceof OrganisationSetting && is_string($setting->logo_path) && $setting->logo_path !== '')
                ? route('branding.asset', ['setting' => $setting->id, 'slot' => 'logo'], false).$vSuffix
                : null,
            'logo_dark_url' => ($setting instanceof OrganisationSetting && is_string($setting->logo_dark_path) && $setting->logo_dark_path !== '')
                ? route('branding.asset', ['setting' => $setting->id, 'slot' => 'logo_dark'], false).$vSuffix
                : null,
            'favicon_url' => ($setting instanceof OrganisationSetting && is_string($setting->favicon_path) && $setting->favicon_path !== '')
                ? route('branding.asset', ['setting' => $setting->id, 'slot' => 'favicon'], false).$vSuffix
                : '/betterdoc-icon.svg',
            'primary_color' => $primary,
            'primary_fg' => $contrast['primary_fg'],
            'accent_color' => $accent,
            'accent_fg' => $contrast['accent_fg'],
            'welcome_title' => $setting->welcome_title ?? $defaults['welcome_title'],
            'welcome_text' => $setting->welcome_text ?? $defaults['welcome_text'],
            'notice_text' => $setting->notice_text ?? '',
            'notice_enabled' => $setting !== null && $setting->notice_enabled && trim((string) $setting->notice_text) !== '',
            'support_email' => $setting->support_email ?? $defaults['support_email'],
            'support_phone' => $setting->support_phone ?? $defaults['support_phone'],
            'support_hours' => $setting->support_hours ?? $defaults['support_hours'],
            'address' => $setting->address ?? $defaults['address'],
            'website' => $setting->website ?? '',
            'show_powered_by' => $setting !== null ? $setting->show_powered_by : true,
            'landing_mode_setting' => $setting?->landing_mode,
            'effective_landing_mode' => $this->effectiveLandingMode($setting),
            'contrast' => $contrast,
        ];
    }

    /**
     * Compute WCAG relative luminance contrast ratios and warn if any pairing falls under 4.5:1.
     *
     * @return array{primary_fg: string, primary_button_ratio: float, primary_on_light_ratio: float, accent_fg: string, accent_button_ratio: float, accent_on_light_ratio: float, warnings: list<string>}
     */
    public function evaluateContrast(string $primaryHex, string $accentHex): array
    {
        $pFg = $this->pickForeground($primaryHex);
        $aFg = $this->pickForeground($accentHex);
        $pSurface = $this->contrastRatio($primaryHex, '#F7F8FA');
        $aSurface = $this->contrastRatio($accentHex, '#F7F8FA');

        $warnings = [];
        if ($pFg['ratio'] < 4.5) {
            $warnings[] = "Primary colour {$primaryHex} only reaches {$pFg['ratio']}:1 text contrast on filled buttons (minimum 4.5:1).";
        }
        if ($pSurface < 4.5) {
            $warnings[] = "Primary colour {$primaryHex} on light surfaces (#F7F8FA) is {$pSurface}:1 (< 4.5:1 for small text/links).";
        }
        if ($aFg['ratio'] < 4.5) {
            $warnings[] = "Accent colour {$accentHex} only reaches {$aFg['ratio']}:1 text contrast on badges (minimum 4.5:1).";
        }

        return [
            'primary_fg' => $pFg['fg'],
            'primary_button_ratio' => round($pFg['ratio'], 2),
            'primary_on_light_ratio' => round($pSurface, 2),
            'accent_fg' => $aFg['fg'],
            'accent_button_ratio' => round($aFg['ratio'], 2),
            'accent_on_light_ratio' => round($aSurface, 2),
            'warnings' => $warnings,
        ];
    }

    public function activatePublicDefault(OrganisationSetting $setting): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT betterdoc_set_public_default_branding(?)', [$setting->id]);
        } else {
            OrganisationSetting::query()->update(['is_public_default' => false]);
            $setting->update(['is_public_default' => true]);
        }
        $setting->refresh();
    }

    public function normalizeHex(?string $hex, string $fallback): string
    {
        $clean = strtoupper(trim((string) $hex));

        return preg_match('/^#[0-9A-F]{6}$/', $clean) === 1 ? $clean : $fallback;
    }

    /**
     * Validate, sanitize SVG or re-encode/resize raster image via GD, and persist onto configured disk.
     */
    public function sanitizeAndStoreUpload(UploadedFile $file, string $slot, ?string $tenantId): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $bytes = file_get_contents($file->getRealPath());

        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages([$slot => 'Uploaded branding file could not be read.']);
        }

        $folder = $tenantId !== null && $tenantId !== '' ? "tenants/{$tenantId}" : 'global';
        $prefix = "{$folder}/{$slot}_".Str::random(12);

        $isSvg = $ext === 'svg' || str_contains($mime, 'svg') || str_starts_with(ltrim($bytes), '<svg');
        if ($isSvg) {
            $cleanSvg = $this->sanitizeSvg($bytes, $slot);
            $path = "{$prefix}.svg";
            Storage::disk('betterdoc_branding')->put($path, $cleanSvg);

            return $path;
        }

        if (! in_array($ext, ['png', 'jpg', 'jpeg'], true) && ! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages([$slot => 'Only PNG, JPG, or clean SVG uploads are allowed.']);
        }

        $maxDim = $slot === 'favicon' ? 64 : 512;
        $cleanPng = $this->reencodeRasterToPng($bytes, $maxDim, $slot);
        $path = "{$prefix}.png";
        Storage::disk('betterdoc_branding')->put($path, $cleanPng);

        return $path;
    }

    private function sanitizeSvg(string $rawSvg, string $slot): string
    {
        $lower = strtolower($rawSvg);
        foreach (['<?php', '<script', '<!entity', '<!doctype', 'javascript:', 'vbscript:', 'data:text/html'] as $bad) {
            if (str_contains($lower, $bad)) {
                throw ValidationException::withMessages([$slot => 'SVG contains disallowed active script or entity markup.']);
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($rawSvg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded || ! $dom->documentElement instanceof DOMElement || strtolower($dom->documentElement->tagName) !== 'svg') {
            throw ValidationException::withMessages([$slot => 'Uploaded SVG is malformed or missing root <svg> tag.']);
        }

        $blockedTags = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset', 'link', 'meta', 'base', 'audio', 'video'];
        $toRemove = [];

        foreach ($dom->getElementsByTagName('*') as $node) {
            if (in_array(strtolower($node->tagName), $blockedTags, true)) {
                $toRemove[] = $node;

                continue;
            }

            if (strtolower($node->tagName) === 'style' && preg_match('/url\s*\(|@import|expression\s*\(/i', $node->textContent) === 1) {
                $toRemove[] = $node;

                continue;
            }

            $attrNames = [];
            foreach ($node->attributes as $attr) {
                $attrNames[] = $attr->nodeName;
            }

            foreach ($attrNames as $attrName) {
                $aLower = strtolower($attrName);
                $val = trim($node->getAttribute($attrName));

                if (str_starts_with($aLower, 'on')) {
                    $node->removeAttribute($attrName);

                    continue;
                }

                if (in_array($aLower, ['href', 'xlink:href', 'src'], true)) {
                    if (! str_starts_with($val, '#')) {
                        $node->removeAttribute($attrName);
                    }

                    continue;
                }

                if ($aLower === 'style' && preg_match('/url\s*\(|@import|expression\s*\(|javascript:/i', $val) === 1) {
                    $node->removeAttribute($attrName);
                }
            }
        }

        foreach ($toRemove as $el) {
            $el->parentNode?->removeChild($el);
        }

        $clean = $dom->saveXML($dom->documentElement);
        if (! is_string($clean) || $clean === '') {
            throw ValidationException::withMessages([$slot => 'Failed serializing sanitized SVG.']);
        }

        return $clean;
    }

    private function reencodeRasterToPng(string $bytes, int $maxDim, string $slot): string
    {
        if (! extension_loaded('gd')) {
            throw ValidationException::withMessages([$slot => 'Server GD extension required to safely re-encode images.']);
        }

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages([$slot => 'File is not a valid PNG or JPG image.']);
        }

        $srcW = (int) $info[0];
        $srcH = (int) $info[1];
        if ($srcW < 1 || $srcH < 1 || $srcW > 8192 || $srcH > 8192) {
            throw ValidationException::withMessages([$slot => 'Image dimensions must be between 1x1 and 8192x8192 pixels.']);
        }

        $srcImg = @imagecreatefromstring($bytes);
        if ($srcImg === false) {
            throw ValidationException::withMessages([$slot => 'Unable to decode image bitmap safely.']);
        }

        $scale = min(1.0, $maxDim / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dstImg = imagecreatetruecolor($dstW, $dstH);
        if ($dstImg === false) {
            imagedestroy($srcImg);
            throw ValidationException::withMessages([$slot => 'Unable to allocate target canvas.']);
        }

        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        }
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        imagepng($dstImg, null, 6);
        $out = ob_get_clean();

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        if (! is_string($out) || $out === '') {
            throw ValidationException::withMessages([$slot => 'Failed encoding sanitized PNG output.']);
        }

        return $out;
    }

    /**
     * @return array{fg: string, ratio: float}
     */
    private function pickForeground(string $bgHex): array
    {
        $whiteRatio = $this->contrastRatio($bgHex, '#FFFFFF');
        $darkRatio = $this->contrastRatio($bgHex, '#0F172A');

        return $whiteRatio >= $darkRatio
            ? ['fg' => '#FFFFFF', 'ratio' => $whiteRatio]
            : ['fg' => '#0F172A', 'ratio' => $darkRatio];
    }

    private function contrastRatio(string $hexA, string $hexB): float
    {
        $lA = $this->relativeLuminance($hexA);
        $lB = $this->relativeLuminance($hexB);
        $lighter = max($lA, $lB);
        $darker = min($lA, $lB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $clean = ltrim($this->normalizeHex($hex, '#0F172A'), '#');
        $r = $this->linearChannel((int) hexdec(substr($clean, 0, 2)));
        $g = $this->linearChannel((int) hexdec(substr($clean, 2, 2)));
        $b = $this->linearChannel((int) hexdec(substr($clean, 4, 2)));

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }

    private function linearChannel(int $val): float
    {
        $s = $val / 255.0;

        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }
}
