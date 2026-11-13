<?php

namespace App\Console\Commands;

use App\Enums\DriverStatus;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Services\Driver\DriverOnboardingService;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'fr:document-expiry')]
class DocumentExpiryCheck extends Command
{
    protected $signature = 'fr:document-expiry';

    protected $description = 'Send document expiry reminders and take drivers with expired mandatory documents offline';

    public function handle(NotificationService $notifications, DriverOnboardingService $onboarding): int
    {
        $reminded = 0;
        DriverDocument::query()->with('driverProfile.user')->where('status', 'approved')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->where(fn ($q) => $q->whereNull('expiry_reminded_at')->orWhere('expiry_reminded_at', '<', now()->subDays(7)))
            ->each(function (DriverDocument $doc) use ($notifications, &$reminded) {
                $notifications->send($doc->driverProfile->user, 'driver.document_expiry', 'Document expiring soon',
                    $doc->label().' expires on '.$doc->expiry_date->format('d M Y').'. Upload a renewed copy to keep driving.', ['screen' => 'driver_documents']);
                $doc->update(['expiry_reminded_at' => now()]);
                $reminded++;
            });

        $expired = 0;
        DriverProfile::query()->with('user.roles', 'documents', 'primaryVehicle')->where('status', DriverStatus::Approved->value)->each(function (DriverProfile $p) use ($onboarding, &$expired) {
            $expiredDocs = $p->latestDocuments()->filter(fn ($d) => in_array($d->type, DriverProfile::REQUIRED_DOCUMENTS, true) && $d->expiry_date?->isPast());
            $licenseExpired = $p->license_expiry?->isPast();
            if ($expiredDocs->isNotEmpty() || $licenseExpired) {
                $p->forceFill(['status' => DriverStatus::Expired, 'user_facing_reason' => 'One or more mandatory documents have expired.'])->save();
                $p->user->roles()->where('role', 'driver')->update(['status' => 'suspended']);
                if ($p->is_online) {
                    $onboarding->forceOffline($p, 'documents_expired');
                }
                AuditLog::record('driver.expired', $p, [], [], ['documents' => $expiredDocs->pluck('type')->all(), 'license' => $licenseExpired]);
                $expired++;
            }
        });

        $this->info("Reminders: {$reminded}; drivers expired: {$expired}");

        return self::SUCCESS;
    }
}
