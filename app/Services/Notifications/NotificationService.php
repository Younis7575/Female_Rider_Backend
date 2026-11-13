<?php

namespace App\Services\Notifications;

use App\Events\RealtimeEvent;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\User;

/**
 * Backend notification authority: persists an in-app inbox entry, emits a realtime
 * event, and queues FCM push delivery (respecting user preferences).
 */
class NotificationService
{
    /** Notification types that are transactional and always pushed regardless of preferences. */
    private const TRANSACTIONAL = [
        'ride.offer', 'ride.counter', 'ride.accepted', 'trip.assigned', 'trip.arrived', 'trip.started',
        'trip.completed', 'trip.cancelled', 'ride.new_request', 'safety.sos', 'account.verification', 'driver.status',
    ];

    public function send(User $user, string $type, string $title, string $body, array $data = [], bool $push = true): AppNotification
    {
        $notification = AppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'push_status' => $push && $this->wantsPush($user, $type) ? 'pending' : 'skipped',
        ]);

        RealtimeEvent::send(["user.{$user->id}"], 'notification.created', [
            'notification' => [
                'id' => $notification->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'created_at' => $notification->created_at?->toIso8601String(),
            ],
        ]);

        if ($notification->push_status === 'pending') {
            SendPushNotification::dispatch($notification->id)->onQueue('notifications');
        }

        return $notification;
    }

    private function wantsPush(User $user, string $type): bool
    {
        if (in_array($type, self::TRANSACTIONAL, true)) {
            return true;
        }
        $prefs = $user->notification_preferences ?? [];
        $group = explode('.', $type)[0];

        return (bool) ($prefs[$group] ?? $prefs['push'] ?? true);
    }
}
