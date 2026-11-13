<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic realtime event broadcast over authenticated private Reverb channels.
 *
 * Channel naming (without the "private-" prefix added by Laravel):
 *   user.{userId}, driver.{userId}, ride.{rideUuid}, trip.{tripUuid}, admin.operations
 */
class RealtimeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection = 'redis';

    public string $queue = 'realtime';

    /** @param array<int, string> $channelNames */
    public function __construct(
        public array $channelNames,
        public string $eventName,
        public array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $c) => new PrivateChannel($c), array_values(array_unique($this->channelNames)));
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return array_merge($this->payload, ['event' => $this->eventName, 'sent_at' => now()->toIso8601String()]);
    }

    public static function send(array $channels, string $event, array $payload = []): void
    {
        if (empty($channels)) {
            return;
        }
        try {
            event(new self($channels, $event, $payload));
        } catch (\Throwable $e) {
            // Realtime is best-effort; SQL remains authoritative and clients reconcile via REST.
            report($e);
        }
    }
}
