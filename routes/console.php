<?php

use App\Models\IdempotencyKey;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — run locally with `php artisan schedule:work`
| (production: one cron entry `* * * * * php artisan schedule:run`).
*/

Schedule::command('fr:dispatch-tick')->everyTenSeconds()->withoutOverlapping()->runInBackground();
Schedule::command('fr:presence-sweep')->everyMinute()->withoutOverlapping();
Schedule::command('fr:document-expiry')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::call(fn () => OtpCode::query()->where('created_at', '<', now()->subDays(2))->delete())->daily()->name('prune-otps');
Schedule::call(fn () => IdempotencyKey::query()->where('created_at', '<', now()->subDays(2))->delete())->daily()->name('prune-idempotency');

// Shared hosting (no supervisor): keep a worker alive for ~55 s of every minute.
if (config('femalerider.shared_hosting')) {
    Schedule::command('queue:work --queue=realtime,notifications,default --sleep=1 --tries=3 --max-time=55 --memory=192')
        ->everyMinute()->withoutOverlapping(2)->runInBackground()->name('shared-queue-worker');
}
