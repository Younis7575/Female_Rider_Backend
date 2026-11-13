<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging HTTP v1 sender using OAuth2 service-account credentials.
 * Credentials live only on the server (never in Flutter or Git).
 */
class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function isConfigured(): bool
    {
        return (bool) config('services.fcm.enabled') && is_readable($this->credentialsPath());
    }

    public function credentialsPath(): string
    {
        $path = (string) config('services.fcm.credentials');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function projectId(): string
    {
        return (string) config('services.fcm.project_id');
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:access_token', 3000, function () {
            $json = json_decode((string) file_get_contents($this->credentialsPath()), true, flags: JSON_THROW_ON_ERROR);
            $creds = new ServiceAccountCredentials(self::SCOPE, $json);
            $token = $creds->fetchAuthToken();

            return $token['access_token'] ?? throw new \RuntimeException('Unable to obtain FCM access token.');
        });
    }

    /**
     * Send to one device token.
     *
     * @return array{ok: bool, invalid_token: bool, error: ?string, message_id: ?string}
     */
    public function sendToToken(string $token, string $title, string $body, array $data = [], string $channel = 'femalerider_default', ?string $tag = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'invalid_token' => false, 'error' => 'fcm_not_configured', 'message_id' => null];
        }

        $message = [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', array_filter($data, fn ($v) => ! is_array($v) && $v !== null)),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => array_filter(['channel_id' => $channel, 'sound' => 'default', 'tag' => $tag]),
                ],
                'apns' => [
                    'headers' => array_filter(['apns-priority' => '10', 'apns-collapse-id' => $tag]),
                    'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send", $message);
        } catch (\Throwable $e) {
            Log::warning('FCM transport error', ['error' => $e->getMessage()]);

            return ['ok' => false, 'invalid_token' => false, 'error' => $e->getMessage(), 'message_id' => null];
        }

        if ($response->successful()) {
            return ['ok' => true, 'invalid_token' => false, 'error' => null, 'message_id' => $response->json('name')];
        }

        $status = $response->json('error.status');
        $errorCode = collect($response->json('error.details', []))->pluck('errorCode')->filter()->first();
        // Our payload is fixed, so INVALID_ARGUMENT / NOT_FOUND / UNREGISTERED mean the device token is bad.
        $invalid = $response->status() === 404 || in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true) || in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT'], true);

        if ($response->status() === 401) {
            Cache::forget('fcm:access_token');
        }

        return ['ok' => false, 'invalid_token' => $invalid, 'error' => $errorCode ?? $status ?? 'http_'.$response->status(), 'message_id' => null];
    }

    /** Mark a device token invalid after a permanent delivery failure. */
    public function revoke(DeviceToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
