<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Services\Notifications\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Idempotent FCM fan-out to all active devices of the notification's recipient. */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public int $notificationId) {}

    public function handle(FcmService $fcm): void
    {
        $notification = AppNotification::query()->with('user.deviceTokens')->find($this->notificationId);
        if (! $notification || in_array($notification->push_status, ['sent', 'skipped'], true)) {
            return;
        }

        if (! $fcm->isConfigured()) {
            $notification->forceFill(['push_status' => 'skipped', 'push_error' => 'fcm_not_configured'])->save();

            return;
        }

        $tokens = $notification->user->deviceTokens->whereNull('revoked_at');
        if ($tokens->isEmpty()) {
            $notification->forceFill(['push_status' => 'skipped', 'push_error' => 'no_devices'])->save();

            return;
        }

        $data = array_merge($notification->data ?? [], [
            'type' => $notification->type,
            'notification_id' => (string) $notification->id,
        ]);

        $sent = 0;
        $lastError = null;
        foreach ($tokens as $token) {
            $result = $fcm->sendToToken($token->token, $notification->title, $notification->body, $data);
            if ($result['ok']) {
                $sent++;
                $token->forceFill(['failure_count' => 0, 'last_seen_at' => now()])->save();
            } else {
                $lastError = $result['error'];
                if ($result['invalid_token']) {
                    $fcm->revoke($token);
                } else {
                    $token->increment('failure_count');
                }
            }
        }

        $notification->forceFill([
            'push_attempts' => $notification->push_attempts + 1,
            'push_status' => $sent === $tokens->count() ? 'sent' : ($sent > 0 ? 'partial' : 'failed'),
            'push_error' => $lastError,
        ])->save();
    }
}
