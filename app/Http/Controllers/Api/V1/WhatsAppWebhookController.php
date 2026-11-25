<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integration\WhatsAppIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppIntakeService $whatsapp,
    ) {}

    /**
     * Meta WhatsApp Business Cloud API webhook challenge verification.
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', (string) $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', (string) $request->query('hub.challenge', ''));

        if ($mode !== 'subscribe' || $token === '' || $challenge === '') {
            return response('Forbidden', 403);
        }

        $verified = $this->whatsapp->verifyWebhookChallenge($token, $challenge);
        if ($verified === null) {
            return response('Invalid verify token', 403);
        }

        return response($verified, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Inbound WhatsApp Business webhook intake (HMAC-SHA256 verified per tenant channel).
     */
    public function receive(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');
        $decoded = json_decode($rawBody, true);
        $rawPayload = is_array($decoded) ? $decoded : $request->all();
        /** @var array<string, mixed> $payload */
        $payload = [];
        foreach ($rawPayload as $k => $v) {
            if (is_string($k)) {
                $payload[$k] = $v;
            }
        }

        try {
            $result = $this->whatsapp->handleInboundWebhook(
                rawBody: $rawBody,
                hubSignatureHeader: is_string($signature) ? $signature : null,
                payload: $payload,
                request: $request,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($result);
    }
}
