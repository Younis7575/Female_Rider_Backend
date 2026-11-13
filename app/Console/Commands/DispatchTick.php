<?php

namespace App\Console\Commands;

use App\Services\Dispatch\DispatchService;
use App\Services\Rides\RideService;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'fr:dispatch-tick')]
class DispatchTick extends Command
{
    protected $signature = 'fr:dispatch-tick';

    protected $description = 'Expand dispatch radius for searching rides and expire stale rides/offers';

    public function handle(DispatchService $dispatch, RideService $rides): int
    {
        $stats = $dispatch->tick($rides);
        if ($stats['rebroadcast'] || $stats['expired']) {
            $this->info("Rebroadcast to {$stats['rebroadcast']} drivers, expired {$stats['expired']} rides.");
        }

        return self::SUCCESS;
    }
}
