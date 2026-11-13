<?php

namespace App\Jobs;

use App\Models\TripMessage;
use App\Models\User;
use App\Services\Notifications\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Push for a new chat message. Chat messages are not copied into the notification
 * inbox (the conversation itself is the record); notifications are grouped per trip.
 */
class SendChatPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [5];

    public function __construct(public int $messageId, public int $recipientId) {}

    public function handle(FcmService $fcm): void
    {
        $message = TripMessage::query()->with('trip', 'sender')->find($this->messageId);
        $recipient = User::query()->with('deviceTokens')->find($this->recipientId);
        if (! $message || ! $recipient || $message->read_at || ! $fcm->isConfigured()) {
            return;
        }

        $name = Str::of($message->sender?->name ?? '')->explode(' ')->first() ?: ($message->sender_role === 'driver' ? 'Your driver' : 'Your rider');
        $title = $message->sender_role === 'driver' ? "{$name} (driver)" : "{$name} (rider)";
        $data = [
            'type' => 'trip.message',
            'trip_id' => $message->trip->uuid,
            'screen' => 'trip_chat',
        ];

        foreach ($recipient->deviceTokens->whereNull('revoked_at') as $token) {
            $result = $fcm->sendToToken($token->token, $title, Str::limit($message->body, 140), $data, 'femalerider_default', 'chat-'.$message->trip->uuid);
            if (! $result['ok'] && $result['invalid_token']) {
                $fcm->revoke($token);
            }
        }
    }
}
