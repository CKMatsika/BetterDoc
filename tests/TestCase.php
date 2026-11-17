<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Run DDL migrations using the elevated admin connection so runtime queries
     * continue executing as least-privilege `sentinel_app` (NOBYPASSRLS).
     *
     * @return array<string, string>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_admin',
        ];
    }

    protected function signIn(User $user, bool $mfaVerified = true, bool $stepUpFresh = true, string $ip = '127.0.0.1'): static
    {
        $session = $this->app['session']->driver();
        $session->invalidate();
        $session->regenerate();
        $session->save();

        $mfaTime = null;
        if ($mfaVerified) {
            $mfaTime = $stepUpFresh ? now() : now()->subMinutes(30);
        }

        UserSession::query()->create([
            'user_id' => $user->id,
            'session_id' => $session->getId(),
            'ip_address' => $ip,
            'user_agent_hash' => hash('sha256', 'SentinelPestAgent/1.0'),
            'device_label' => 'Pest Workstation',
            'mfa_verified_at' => $mfaTime,
            'last_seen_at' => now(),
        ]);

        return $this->withCredentials()
            ->withHeaders(['User-Agent' => 'SentinelPestAgent/1.0'])
            ->withCookie($session->getName(), $session->getId())
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->actingAs($user);
    }

    /**
     * Send a raw binary PUT chunk request preserving the authenticated session cookies and headers.
     *
     * @return TestResponse<Response>
     */
    protected function putChunk(string $uri, string $bytes, ?string $chunkSha256 = null): TestResponse
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/octet-stream',
        ];
        if ($chunkSha256 !== null) {
            $headers['X-Chunk-Sha256'] = $chunkSha256;
        }

        return $this->call(
            'PUT',
            $uri,
            [],
            $this->prepareCookiesForRequest(),
            [],
            $this->transformHeadersToServerVars($headers),
            $bytes,
        );
    }
}
