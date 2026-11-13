<?php

namespace App\Services\Chat;

use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendChatPush;
use App\Models\Trip;
use App\Models\TripMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Private customer ⇄ driver chat. Only the two participants of a trip can read it,
 * and it is writable only while the trip is active (from the moment an offer is
 * accepted until the trip is completed or cancelled). Afterwards it is read-only.
 */
class TripChatService
{
    public const MAX_LENGTH = 500;

    public const MAX_PER_TRIP = 300;

    public function assertParticipant(User $user, Trip $trip): string
    {
        $role = $trip->roleOf($user);
        if ($role === null) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }

        return $role;
    }

    public function canSend(Trip $trip): bool
    {
        return $trip->status->isActive();
    }

    /** @return Collection<int, TripMessage> */
    public function messages(User $user, Trip $trip, ?int $afterSeq = null, int $limit = 100): Collection
    {
        $this->assertParticipant($user, $trip);

        $q = $trip->messages()->orderByDesc('id')->limit(min(max($limit, 1), 200));
        if ($afterSeq) {
            $q->where('id', '>', $afterSeq);
        }

        return $q->get()->sortBy('id')->values()->each->setRelation('trip', $trip);
    }

    public function unreadCount(User $user, Trip $trip): int
    {
        return $trip->messages()->where('sender_id', '!=', $user->id)->whereNull('read_at')->count();
    }

    public function send(User $user, Trip $trip, string $body, ?string $clientId = null): TripMessage
    {
        $role = $this->assertParticipant($user, $trip);
        $body = trim(preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}]+/u', '', $body) ?? '');
        if ($body === '') {
            throw new BusinessRuleException('Type a message first.', 'message_empty', 422);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new BusinessRuleException('Messages can be up to '.self::MAX_LENGTH.' characters.', 'message_too_long', 422);
        }
        if (! $this->canSend($trip)) {
            throw new BusinessRuleException('Chat is closed because this trip has ended.', 'chat_closed', 409);
        }

        if ($clientId && ($existing = $trip->messages()->where('sender_id', $user->id)->where('client_id', $clientId)->first())) {
            return $existing->setRelation('trip', $trip);
        }
        if ($trip->messages()->count() >= self::MAX_PER_TRIP) {
            throw new BusinessRuleException('Message limit reached for this trip. Please call support if you need help.', 'chat_limit', 429);
        }

        try {
            $message = DB::transaction(fn () => $trip->messages()->create([
                'sender_id' => $user->id,
                'sender_role' => $role,
                'body' => $body,
                'client_id' => $clientId,
            ]));
        } catch (QueryException $e) {
            // Concurrent retry with the same client id.
            $existing = $clientId ? $trip->messages()->where('sender_id', $user->id)->where('client_id', $clientId)->first() : null;
            if (! $existing) {
                throw $e;
            }

            return $existing->setRelation('trip', $trip);
        }
        $message->setRelation('trip', $trip);

        RealtimeEvent::send(["trip.{$trip->uuid}"], 'trip.message', ['message' => $message->toPayload()]);

        $recipientId = $role === 'customer' ? $trip->driver_id : $trip->customer_id;
        SendChatPush::dispatch($message->id, $recipientId)->onQueue('notifications');

        return $message;
    }

    /** Marks every message from the other participant as read; returns how many changed. */
    public function markRead(User $user, Trip $trip): int
    {
        $this->assertParticipant($user, $trip);
        $now = now();
        $count = $trip->messages()->where('sender_id', '!=', $user->id)->whereNull('read_at')->update(['read_at' => $now]);
        if ($count > 0) {
            RealtimeEvent::send(["trip.{$trip->uuid}"], 'trip.messages_read', [
                'trip_id' => $trip->uuid,
                'reader_role' => $trip->roleOf($user),
                'read_at' => $now->toIso8601String(),
            ]);
        }

        return $count;
    }
}
