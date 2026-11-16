<?php

namespace App\Jobs;

use App\Models\GuardianAlert;
use App\Services\Guardian\GuardianAlertService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendGuardianAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [15, 60, 300];

    public function __construct(public int $alertId) {}

    public function handle(GuardianAlertService $guardians, WhatsAppService $whatsapp): void
    {
        $alert = GuardianAlert::query()->with('driverProfile.primaryVehicle', 'driverProfile.user')->find($this->alertId);
        if (! $alert || in_array($alert->status, ['sent', 'skipped'], true)) {
            return;
        }
        $to = $guardians->recipient($alert->driverProfile);
        if (! $to) {
            $alert->update(['status' => 'skipped', 'error' => 'No valid guardian WhatsApp number']);

            return;
        }

        $result = $whatsapp->sendTemplate($to, $guardians->templateName($alert->event), $guardians->templateParams($alert));
        $alert->increment('attempts');

        if ($result['ok']) {
            $alert->update(['status' => 'sent', 'provider_message_id' => $result['id'], 'error' => null, 'sent_at' => now()]);

            return;
        }
        $alert->update(['status' => 'failed', 'error' => $result['error']]);
        if (! $result['permanent'] && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
