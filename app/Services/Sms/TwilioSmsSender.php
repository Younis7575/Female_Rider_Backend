<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwilioSmsSender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        $cfg = config('services.sms.twilio');
        if (empty($cfg['sid']) || empty($cfg['token']) || empty($cfg['from'])) {
            Log::error('Twilio SMS driver selected but TWILIO_* credentials are missing.');

            return false;
        }

        $response = Http::asForm()->withBasicAuth($cfg['sid'], $cfg['token'])->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['sid']}/Messages.json", [
                'To' => $phone, 'From' => $cfg['from'], 'Body' => $message,
            ]);

        if (! $response->successful()) {
            Log::warning('Twilio SMS failed', ['status' => $response->status(), 'body' => $response->json('message')]);
        }

        return $response->successful();
    }
}
