<?php

namespace App\Console\Commands;

use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Services\Driver\DriverOnboardingService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'fr:presence-sweep')]
class PresenceSweep extends Command
{
    protected $signature = 'fr:presence-sweep {--minutes=5}';

    protected $description = 'Mark drivers offline whose location heartbeat has been missing (app killed / network lost)';

    public function handle(DriverOnboardingService $onboarding): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $count = 0;
        DriverProfile::query()->where('is_online', true)->get()->each(function (DriverProfile $p) use ($cutoff, $onboarding, &$count) {
            $loc = DriverLocation::query()->where('driver_profile_id', $p->id)->first();
            if ((! $loc || $loc->recorded_at->lt($cutoff)) && ! $p->user->activeTrip()) {
                $onboarding->forceOffline($p, 'heartbeat_timeout');
                $count++;
            }
        });
        if ($count) {
            $this->info("Marked {$count} stale drivers offline.");
        }

        return self::SUCCESS;
    }
}
