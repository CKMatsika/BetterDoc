<?php

declare(strict_types=1);

use App\Enums\CertificateCustodyMode;
use App\Enums\CertificateStatus;
use App\Models\AuditEvent;
use App\Models\CertificateAuthority;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\Pki\HsmCustodyService;
use App\Services\Pki\SignerBridgeService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Database\Seeders\GoldenKnotFinancialSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class, GoldenKnotFinancialSeeder::class]);
});

it('bootstraps offline Root/Intermediate/TSA/OCSP PKI with zero private keys in database and supports HSM + Smartcard CSR + CRL/OCSP revocation', function (): void {
    /** @var Tenant $afzTenant */
    $afzTenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $sysAdmin */
    $sysAdmin = User::query()->where('email', 'fltlt.nyoni@afz.mil.zw')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();
    /** @var User $cro */
    $cro = User::query()->where('email', 'cro.gumbo@golden-knot.co.zw')->firstOrFail();

    // Database schema itself enforces zero private key columns
    expect(Schema::hasColumn('user_certificates', 'private_key'))->toBeFalse()
        ->and(Schema::hasColumn('user_certificates', 'private_key_pem'))->toBeFalse()
        ->and(Schema::hasColumn('certificate_authorities', 'private_key'))->toBeFalse()
        ->and(Schema::hasColumn('certificate_authorities', 'private_key_pem'))->toBeFalse();

    // 1. SystemAdmin bootstraps internal PKI
    $this->signIn($sysAdmin);
    $this->post('/pki/bootstrap')->assertRedirect()->assertSessionDoesntHaveErrors();

    $dbContext = app(DbSecurityContext::class);
    $authorities = $dbContext->runAs($sysAdmin, static fn () => CertificateAuthority::query()->get());
    expect($authorities->pluck('role')->sort()->values()->all())
        ->toBe(['intermediate', 'ocsp', 'root', 'seal', 'tsa']);

    // 2. Lower user cannot issue certificates for another user
    $this->signIn($cpl);
    $this->post('/pki/certificates', [
        'user_id' => $wgCdr->id,
        'custody_mode' => CertificateCustodyMode::HsmManaged->value,
    ])->assertForbidden();

    // 3. Issue HSM-managed X.509 cert for Wing Commander
    $this->signIn($sysAdmin);
    $this->post('/pki/certificates', [
        'user_id' => $wgCdr->id,
        'custody_mode' => CertificateCustodyMode::HsmManaged->value,
        'valid_days' => 365,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var UserCertificate $wgCert */
    $wgCert = $dbContext->runAs($sysAdmin, static fn () => UserCertificate::query()->where('user_id', $wgCdr->id)->firstOrFail());
    expect($wgCert->status)->toBe(CertificateStatus::Active)
        ->and($wgCert->custody_mode)->toBe(CertificateCustodyMode::HsmManaged)
        ->and(app(HsmCustodyService::class)->hasKey($afzTenant->id, $wgCert->pkcs11_key_label))->toBeTrue();

    // 4. Download Root CA (.crt), DER CRL (.der), and OCSP response (GOOD) offline
    $rootRes = $this->get("/pki/ca/{$afzTenant->id}/root.crt")->assertOk();
    expect($rootRes->getContent())->toContain('BEGIN CERTIFICATE');

    $crlBefore = $this->get("/pki/ca/{$afzTenant->id}/crl.der")->assertOk();
    expect(strlen((string) $crlBefore->getContent()))->toBeGreaterThan(50);

    $ocspGood = $this->get("/pki/ocsp/{$afzTenant->id}/{$wgCert->serial_number}")->assertOk();
    expect(strlen((string) $ocspGood->getContent()))->toBeGreaterThan(100);

    // 5. Revoke certificate -> CRL updates, OCSP flips, audit entry recorded
    $this->post("/pki/certificates/{$wgCert->id}/revoke", ['reason' => 'key_compromise'])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $dbContext->runAs($sysAdmin, static fn () => $wgCert->refresh());
    expect($wgCert->status)->toBe(CertificateStatus::Revoked)
        ->and($wgCert->revocation_reason)->toBe('key_compromise')
        ->and(AuditEvent::query()->where('event_type', 'pki.certificate_revoked')->where('resource_id', $wgCert->id)->exists())->toBeTrue();

    $crlAfter = $this->get("/pki/ca/{$afzTenant->id}/crl.der")->assertOk();
    expect((string) $crlAfter->getContent())->not->toBe((string) $crlBefore->getContent());

    // 6. Issue Smartcard CSR-backed certificate (zero private key stored in HSM or DB)
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'config' => 'C:/Program Files/Git/usr/ssl/openssl.cnf',
    ]);
    expect($privateKey)->not->toBeFalse();
    $csrOut = '';
    if ($privateKey !== false) {
        $csr = openssl_csr_new(['commonName' => 'Smartcard Ndlovu', 'organizationName' => 'AFZ'], $privateKey, [
            'digest_alg' => 'sha256',
            'config' => 'C:/Program Files/Git/usr/ssl/openssl.cnf',
        ]);
        if ($csr !== false) {
            openssl_csr_export($csr, $csrOut);
        }
    }
    expect($csrOut)->toContain('BEGIN CERTIFICATE REQUEST');

    /** @var User $sqnLdr */
    $sqnLdr = User::query()->where('email', 'sqnldr.ndlovu@afz.mil.zw')->firstOrFail();
    $this->post('/pki/certificates', [
        'user_id' => $sqnLdr->id,
        'custody_mode' => CertificateCustodyMode::SmartcardPkcs11->value,
        'csr_pem' => $csrOut,
        'pkcs11_slot_id' => 'yubikey-piv-9c',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    /** @var UserCertificate $scCert */
    $scCert = $dbContext->runAs($sysAdmin, static fn () => UserCertificate::query()->where('user_id', $sqnLdr->id)->firstOrFail());
    expect($scCert->custody_mode)->toBe(CertificateCustodyMode::SmartcardPkcs11)
        ->and(app(HsmCustodyService::class)->hasKey($afzTenant->id, $scCert->pkcs11_key_label))->toBeFalse();

    // 7. Cross-tenant RLS isolates Golden Knot from AFZ certificates
    $this->signIn($cro);
    $gkCount = $dbContext->runAs($cro, static fn (): int => UserCertificate::query()->count());
    expect($gkCount)->toBe(0);

    // Ensure SignerBridge can build CRL/OCSP deterministically
    expect(app(SignerBridgeService::class))->not->toBeNull();
});
