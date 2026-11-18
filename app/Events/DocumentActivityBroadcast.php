<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentActivityBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $eventType,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("document.{$this->documentId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'betterdoc.document.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Only emit non-sensitive IDs/types; full state is fetched over authenticated RLS endpoints.
        return [
            'document_id' => $this->documentId,
            'event_type' => $this->eventType,
            'metadata' => $this->metadata,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
