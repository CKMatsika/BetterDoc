<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TotpMfaService
{
    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisionSvgQrCode(User $user, string $secret): string
    {
        $issuer = config()->string('app.name', 'BetterDoc DMS');
        $otpAuthUrl = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString($otpAuthUrl);
    }

    public function verifyCode(string $encryptedOrPlainSecret, string $code, bool $isEncrypted = true): bool
    {
        $secret = $isEncrypted ? Crypt::decryptString($encryptedOrPlainSecret) : $encryptedOrPlainSecret;
        $normalized = preg_replace('/\s+/', '', $code) ?? '';

        return (bool) $this->google2fa->verifyKey($secret, $normalized, 1);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }
}
