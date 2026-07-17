<?php

namespace App\Events\Hotel;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Single public channel ("hotel"); the event name (App\Support\RealtimeEvent)
 * selects the stream — mirrors the Node app's four Socket.IO event names
 * (kot/rooms/orders/menu), all minimal "something changed, go refetch"
 * signals with no per-client scoping (see phase2-nodejs-infrastructure memory).
 * Broadcast synchronously (ShouldBroadcastNow) since this app runs no queue worker.
 */
class RealtimeUpdate implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('hotel');
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
