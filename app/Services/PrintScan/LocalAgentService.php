<?php

declare(strict_types=1);

namespace App\Services\PrintScan;

use App\Enums\LocalAgentStatus;
use App\Models\LocalAgent;
use App\Models\User;
use App\Services\Audit\ImmutableAuditLogger;
use App\Services\Security\DbSecurityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use stdClass;

class LocalAgentService
{
    public function __construct(
        private readonly ImmutableAuditLogger $audit,
        private readonly DbSecurityContext $dbContext,
    ) {}

    /**
     * Initiate pairing for a desktop BetterDoc Local Agent (USB TWAIN/WIA scanner & PKCS#11 bridge).
     *
     * @return array{agent: LocalAgent, pairing_code: string}
     */
    public function initiatePairing(
        User $user,
        string $workstationName,
        ?Request $request = null,
    ): array {
        $pairingCode = strtoupper(bin2hex(random_bytes(4))); // 8-char one-time pairing secret
        $placeholderHash = hash('sha256', 'pending|'.bin2hex(random_bytes(16)));

        /** @var LocalAgent $agent */
        $agent = LocalAgent::query()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'workstation_name' => trim($workstationName),
            'pairing_code_hash' => Hash::make($pairingCode),
            'token_hash' => $placeholderHash,
            'status' => LocalAgentStatus::PendingPair,
            'capabilities_json' => ['twain_scanners' => [], 'wia_scanners' => [], 'pkcs11_tokens' => []],
        ]);

        $this->audit->log(
            tenantId: $user->tenant_id,
            eventType: 'agent.pairing_initiated',
            resourceType: 'local_agent',
            resourceId: $agent->id,
            actor: $user,
            classificationRank: $user->clearance_rank,
            after: ['workstation_name' => $agent->workstation_name],
            request: $request,
        );

        return ['agent' => $agent, 'pairing_code' => $pairingCode];
    }

    /**
     * Exchange an active pairing code from localhost agent daemon for an irrevocable SHA-256 hashed bearer token.
     *
     * @param  array<string, mixed>  $capabilities
     * @return array{agent: LocalAgent, bearer_token: string}
     */
    public function completePairing(
        string $agentId,
        string $pairingCode,
        string $agentVersion = '1.0.0',
        array $capabilities = [],
        ?Request $request = null,
    ): array {
        $lookup = $this->lookupAgentById($agentId);
        if ($lookup === null) {
            throw new RuntimeException('Unknown workstation agent ID.');
        }

        if ($lookup['status'] !== LocalAgentStatus::PendingPair->value) {
            throw new RuntimeException('Pairing code is no longer pending.');
        }

        if (! is_string($lookup['pairing_code_hash']) || ! Hash::check(strtoupper(trim($pairingCode)), $lookup['pairing_code_hash'])) {
            throw new RuntimeException('Invalid workstation pairing code.');
        }

        $owner = User::query()->findOrFail($lookup['user_id']);
        $rawToken = 'sent_agt_'.bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);

        $agent = $this->dbContext->runAs($owner, function () use ($agentId, $agentVersion, $capabilities, $tokenHash, $owner, $request): LocalAgent {
            /** @var LocalAgent $row */
            $row = LocalAgent::query()->findOrFail($agentId);
            $row->update([
                'agent_version' => $agentVersion,
                'capabilities_json' => $capabilities,
                'pairing_code_hash' => null,
                'token_hash' => $tokenHash,
                'status' => LocalAgentStatus::Paired,
                'last_seen_at' => now(),
                'paired_ip' => $request?->ip() ?? '127.0.0.1',
            ]);

            $this->audit->log(
                tenantId: $owner->tenant_id,
                eventType: 'agent.paired',
                resourceType: 'local_agent',
                resourceId: $row->id,
                actor: $owner,
                classificationRank: $owner->clearance_rank,
                after: ['workstation_name' => $row->workstation_name, 'paired_ip' => $row->paired_ip],
                request: $request,
            );

            return $row->refresh();
        });

        return ['agent' => $agent, 'bearer_token' => $rawToken];
    }

    /**
     * Resolve an authenticated User & LocalAgent from an incoming X-BetterDoc-Agent-Token or Bearer token.
     *
     * @return array{agent: LocalAgent, user: User}|null
     */
    public function authenticateBearerToken(string $rawToken): ?array
    {
        $clean = trim($rawToken);
        if ($clean === '') {
            return null;
        }
        $hash = hash('sha256', $clean);

        $lookup = $this->lookupAgentByTokenHash($hash);
        if ($lookup === null || $lookup['status'] !== LocalAgentStatus::Paired->value) {
            return null;
        }

        $user = User::query()->find($lookup['user_id']);
        if (! $user instanceof User) {
            return null;
        }

        return $this->dbContext->runAs($user, static function () use ($lookup, $user): ?array {
            $agent = LocalAgent::query()->find($lookup['id']);
            if (! $agent instanceof LocalAgent || $agent->status !== LocalAgentStatus::Paired) {
                return null;
            }
            $agent->update(['last_seen_at' => now()]);

            return ['agent' => $agent, 'user' => $user];
        });
    }

    public function buildWorkstationAgentScript(string $serverUrl): string
    {
        $safeUrl = rtrim($serverUrl, '/');

        return <<<PYTHON
#!/usr/bin/env python3
"""
BetterDoc DMS Local Workstation Agent (127.0.0.1 TWAIN/WIA Scanner & PKCS#11 Smartcard Bridge)
Runs strictly locally on 127.0.0.1:48921 with zero external network calls.
"""
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
import platform
import urllib.request

SERVER_URL = "{$safeUrl}"
BIND_HOST = "127.0.0.1"
BIND_PORT = 48921

class AgentHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            payload = {
                "status": "ok",
                "os": platform.system(),
                "scanners": ["WIA/TWAIN Workstation Bridge"] if platform.system() == "Windows" else ["SANE Local USB Scanner"],
                "pkcs11_module": os.environ.get("BETTERDOC_PKCS11_LIB", "SoftHSM2 / OpenSC"),
            }
            body = json.dumps(payload).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_error(404)

if __name__ == "__main__":
    print(f"BetterDoc Local Agent listening strictly on http://{BIND_HOST}:{BIND_PORT} -> {SERVER_URL}")
    HTTPServer((BIND_HOST, BIND_PORT), AgentHandler).serve_forever()
PYTHON;
    }

    /**
     * @return array{id: string, tenant_id: string, user_id: string, pairing_code_hash: string|null, status: string}|null
     */
    private function lookupAgentById(string $agentId): ?array
    {
        $row = DB::connection()->getDriverName() === 'pgsql'
            ? DB::selectOne('SELECT id, tenant_id, user_id, pairing_code_hash, status FROM betterdoc_lookup_local_agent_by_id(?)', [$agentId])
            : DB::table('local_agents')->where('id', $agentId)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => is_string($row->id ?? null) ? $row->id : '',
            'tenant_id' => is_string($row->tenant_id ?? null) ? $row->tenant_id : '',
            'user_id' => is_string($row->user_id ?? null) ? $row->user_id : '',
            'pairing_code_hash' => is_string($row->pairing_code_hash ?? null) ? $row->pairing_code_hash : null,
            'status' => is_string($row->status ?? null) ? $row->status : '',
        ];
    }

    /**
     * @return array{id: string, tenant_id: string, user_id: string, status: string}|null
     */
    private function lookupAgentByTokenHash(string $tokenHash): ?array
    {
        $row = DB::connection()->getDriverName() === 'pgsql'
            ? DB::selectOne('SELECT id, tenant_id, user_id, status FROM betterdoc_lookup_local_agent_by_token(?)', [$tokenHash])
            : DB::table('local_agents')->where('token_hash', $tokenHash)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => is_string($row->id ?? null) ? $row->id : '',
            'tenant_id' => is_string($row->tenant_id ?? null) ? $row->tenant_id : '',
            'user_id' => is_string($row->user_id ?? null) ? $row->user_id : '',
            'status' => is_string($row->status ?? null) ? $row->status : '',
        ];
    }
}
