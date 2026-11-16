<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends approved WhatsApp template messages through the Meta WhatsApp Business Cloud API.
 * Business-initiated messages must use a template approved in WhatsApp Manager.
 * Driver "log" (default) only writes the message to the log — no account needed.
 */
class WhatsAppService
{
    /** Pakistani numbers in any common format → E.164 digits without "+" (e.g. 923001234567). */
    public static function normalize(?string $phone, string $defaultCountry = '92'): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = $defaultCountry.substr($d, 1);
        } elseif (strlen($d) === 10 && str_starts_with($d, '3')) {
            $d = $defaultCountry.$d;
        }

        return preg_match('/^[1-9]\d{9,14}$/', $d) ? $d : null;
    }

    public function isLive(): bool
    {
        return config('services.whatsapp.driver') === 'cloud'
            && filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * @param  array<int, string>  $bodyParams  values for {{1}}, {{2}}, … in the template body
     * @return array{ok: bool, id: ?string, error: ?string, permanent: bool}
     */
    public function sendTemplate(string $to, string $template, array $bodyParams = []): array
    {
        if (! $this->isLive()) {
            Log::info('WhatsApp (log driver)', ['to' => substr($to, 0, 4).'•••'.substr($to, -3), 'template' => $template, 'params' => $bodyParams]);

            return ['ok' => true, 'id' => 'log-'.Str::uuid(), 'error' => null, 'permanent' => false];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => config('services.whatsapp.language', 'en')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => Str::limit((string) $v, 900)], $bodyParams),
                ]],
            ],
        ];

        try {
            $res = Http::withToken(config('services.whatsapp.token'))->acceptJson()->timeout(15)
                ->post(sprintf('https://graph.facebook.com/%s/%s/messages', config('services.whatsapp.api_version', 'v21.0'), config('services.whatsapp.phone_number_id')), $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 300), 'permanent' => false];
        }

        if ($res->successful()) {
            return ['ok' => true, 'id' => $res->json('messages.0.id'), 'error' => null, 'permanent' => false];
        }
        $code = (int) $res->json('error.code');
        // 131026 undeliverable/not on WhatsApp, 132001 template missing, 100 bad params, 131030 not in allowed list (test mode)
        $permanent = in_array($code, [100, 131026, 131030, 132000, 132001, 132005, 132007, 132012], true) || $res->status() === 400;

        return ['ok' => false, 'id' => null, 'error' => Str::limit(($res->json('error.message') ?? 'HTTP '.$res->status()).' ['.$code.']', 300), 'permanent' => $permanent];
    }
}
