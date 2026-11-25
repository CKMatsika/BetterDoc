<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ErpBridgeAgent;
use App\Services\Integration\ErpBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ErpBridgeApiController extends Controller
{
    public function __construct(
        private readonly ErpBridgeService $bridge,
    ) {}

    public function pair(Request $request): JsonResponse
    {
        /** @var array{pairing_code: string, hostname: string, agent_version?: string|null} $validated */
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'min:8', 'max:64'],
            'hostname' => ['required', 'string', 'min:1', 'max:160'],
            'agent_version' => ['nullable', 'string', 'max:32'],
        ]);

        $paired = $this->bridge->completePairing(
            pairingCode: $validated['pairing_code'],
            hostname: $validated['hostname'],
            agentVersion: $validated['agent_version'] ?? '1.0.0',
            ip: $request->ip(),
        );

        if ($paired === null) {
            return response()->json(['message' => 'Invalid or expired bridge pairing code.'], 401);
        }

        return response()->json([
            'agent_id' => $paired['agent']->id,
            'name' => $paired['agent']->name,
            'erp_target' => $paired['agent']->erp_target,
            'bridge_token' => $paired['bridge_token'],
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if ($agent === null) {
            return response()->json(['message' => 'Invalid or revoked bridge token.'], 401);
        }

        return response()->json([
            'status' => 'ok',
            'agent_id' => $agent->id,
            'erp_target' => $agent->erp_target,
            'server_time_utc' => now('UTC')->toIso8601String(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if ($agent === null) {
            return response()->json(['message' => 'Invalid or revoked bridge token.'], 401);
        }

        $job = $this->bridge->claimNextJob($agent);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'job_type' => $job->job_type,
                'payload' => $job->payload_json ?? [],
                'claimed_at' => $job->claimed_at?->toIso8601String(),
            ],
        ]);
    }

    public function completeJob(Request $request, string $jobId): JsonResponse
    {
        $agent = $this->authenticate($request);
        if ($agent === null) {
            return response()->json(['message' => 'Invalid or revoked bridge token.'], 401);
        }

        /** @var array{status: string, result?: array<string, mixed>|null, error_message?: string|null} $validated */
        $validated = $request->validate([
            'status' => ['required', Rule::in(['completed', 'failed'])],
            'result' => ['nullable', 'array'],
            'error_message' => ['nullable', 'string', 'max:500'],
        ]);

        $completed = $this->bridge->completeJob(
            agent: $agent,
            jobId: $jobId,
            status: $validated['status'],
            resultData: $validated['result'] ?? [],
            errorMessage: $validated['error_message'] ?? null,
        );

        if ($completed === null) {
            return response()->json(['message' => 'Bridge job not found.'], 404);
        }

        return response()->json([
            'job' => [
                'id' => $completed->id,
                'status' => $completed->status,
                'result' => $completed->result_json,
                'completed_at' => $completed->completed_at?->toIso8601String(),
            ],
        ]);
    }

    private function authenticate(Request $request): ?ErpBridgeAgent
    {
        $raw = (string) ($request->header('X-BetterDoc-Bridge-Token') ?: $request->bearerToken() ?: '');

        return $this->bridge->authenticateToken($raw, $request->ip());
    }
}
