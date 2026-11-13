<?php

namespace App\Providers;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, fn () => match (config('services.sms.driver')) {
            'twilio' => new TwilioSmsSender,
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Event::listen(Login::class, function ($event) {
            if ($event->user instanceof AdminUser) {
                $event->user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
                AuditLog::record('admin.login', $event->user, [], [], [], $event->user);
            }
        });
        Event::listen(Failed::class, function ($event) {
            if ($event->guard === 'admin') {
                AuditLog::record('admin.login_failed', null, [], [], ['email' => $event->credentials['email'] ?? null]);
            }
        });

        // Super admins bypass permission checks; everyone else is least-privilege via roles.
        Gate::before(fn ($user) => $user instanceof AdminUser && $user->hasRole('super_admin') ? true : null);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(5)->by('otp-ip:'.$r->ip()),
            Limit::perMinute(3)->by('otp-phone:'.$r->input('phone')),
        ]);
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('otp-verify:'.$r->ip().'|'.$r->input('phone')));
        RateLimiter::for('booking', fn (Request $r) => Limit::perMinute(10)->by('book:'.$r->user()?->id));
        RateLimiter::for('location', fn (Request $r) => Limit::perMinute(40)->by('loc:'.$r->user()?->id));
        RateLimiter::for('chat', fn (Request $r) => Limit::perMinute(30)->by('chat:'.$r->user()?->id));
    }
}
