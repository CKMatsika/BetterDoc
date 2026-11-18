<?php

declare(strict_types=1);

namespace App\Services\Pki;

use App\Services\Security\BetterDocHttpClientGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class SignerBridgeService
{
    public function __construct(
        private readonly BetterDocHttpClientGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(string $action, array $payload): array
    {
        $driver = config()->string('betterdoc.pki.signer_driver', 'local_pyhanko');

        if ($driver === 'http') {
            $baseUrl = rtrim(config()->string('betterdoc.pki.signer_url', 'http://betterdoc-signer:8003'), '/');
            if ($action === 'sign_pades') {
                $response = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(60)->post("{$baseUrl}/sign/pades", $payload);

                if (! $response->successful()) {
                    throw new RuntimeException('betterdoc-signer HTTP error: '.$response->body());
                }

                /** @var mixed $decoded */
                $decoded = $response->json();
                if (! is_array($decoded)) {
                    throw new RuntimeException('betterdoc-signer HTTP response was not a JSON object.');
                }

                /** @var array<string, mixed> $typedHttp */
                $typedHttp = $decoded;

                return $typedHttp;
            }
        }

        $pythonBin = config()->string('betterdoc.pki.python_bin', 'python');
        $signerDir = base_path('services/betterdoc-signer');

        $payloadFile = tempnam(sys_get_temp_dir(), 'betterdoc_sig_in_');
        if ($payloadFile === false) {
            throw new RuntimeException('Unable to allocate signer IPC payload file.');
        }

        try {
            file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));

            $result = Process::path($signerDir)
                ->timeout(60)
                ->run([$pythonBin, '-m', 'app.cli', $action, $payloadFile]);

            if (! $result->successful()) {
                throw new RuntimeException("Signer engine [{$action}] failed: ".$result->errorOutput()."\n".$result->output());
            }

            /** @var mixed $decoded */
            $decoded = json_decode(trim($result->output()), true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Signer engine [{$action}] returned malformed JSON.");
            }

            /** @var array<string, mixed> $typed */
            $typed = $decoded;

            return $typed;
        } finally {
            @unlink($payloadFile);
        }
    }
}
