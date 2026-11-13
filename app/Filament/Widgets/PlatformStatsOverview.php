<?php

namespace App\Filament\Widgets;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Models\DriverPayout;
use App\Models\DriverProfile;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\RideRequest;
use App\Models\SupportTicket;
use App\Models\Trip;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '15s';

    protected ?string $heading = 'Platform overview';

    public static function canView(): bool
    {
        return (bool) auth('admin')->user()?->can('dashboard.view');
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $completedToday = Trip::query()->where('status', 'completed')->where('completed_at', '>=', $today);
        $spark = fn ($query, string $col = 'id', string $agg = 'count') => collect(range(6, 0))->map(fn ($d) => (float) (clone $query)->whereDate('created_at', now()->subDays($d))->{$agg}($col))->all();
        $canFinance = auth('admin')->user()?->can('finance.view');

        $stats = [
            Stat::make('Customers', number_format(User::query()->where('eligibility_status', 'approved')->count()))
                ->description(User::query()->whereIn('eligibility_status', ['submitted', 'under_review', 'appealed'])->count().' awaiting verification')
                ->descriptionIcon('heroicon-m-user-group')->color('primary')
                ->chart($spark(User::query())),
            Stat::make('Drivers', number_format(DriverProfile::query()->where('status', DriverStatus::Approved->value)->count()).' approved')
                ->description(DriverProfile::query()->whereIn('status', ['pending', 'under_review'])->count().' applications pending · '.DriverProfile::query()->count().' total')
                ->descriptionIcon('heroicon-m-identification')->color('warning'),
            Stat::make('Online drivers', DriverProfile::query()->where('is_online', true)->count())
                ->description('Live supply right now')->descriptionIcon('heroicon-m-signal')->color('success'),
            Stat::make('Active rides', Trip::query()->whereIn('status', TripStatus::activeValues())->count())
                ->description(RideRequest::query()->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now())->count().' requests searching')
                ->descriptionIcon('heroicon-m-map')->color('info'),
            Stat::make('Completed today', (clone $completedToday)->count())
                ->description(Trip::query()->where('status', 'completed')->count().' all time')
                ->descriptionIcon('heroicon-m-check-badge')->color('success')
                ->chart($spark(Trip::query()->where('status', 'completed'))),
            Stat::make('Cancelled today', Trip::query()->whereIn('status', ['cancelled_by_customer', 'cancelled_by_driver', 'cancelled_by_admin'])->where('cancelled_at', '>=', $today)->count())
                ->description(RideRequest::query()->where('status', 'no_driver_found')->where('created_at', '>=', $today)->count().' no-driver-found today')
                ->descriptionIcon('heroicon-m-x-circle')->color('gray'),
            Stat::make('Open safety incidents', Incident::query()->whereIn('status', ['open', 'acknowledged', 'investigating', 'escalated'])->count())
                ->description(Incident::query()->where('type', 'sos')->where('status', 'open')->count().' unacknowledged SOS')
                ->descriptionIcon('heroicon-m-shield-exclamation')->color('danger'),
            Stat::make('Support backlog', SupportTicket::query()->whereIn('status', ['open', 'pending'])->count())
                ->description('Open tickets')->descriptionIcon('heroicon-m-lifebuoy')->color('gray'),
        ];

        if ($canFinance) {
            $stats[] = Stat::make('Gross bookings today', 'PKR '.number_format((float) (clone $completedToday)->sum('final_fare')))
                ->description('PKR '.number_format((float) Trip::query()->where('status', 'completed')->sum('final_fare')).' all time')
                ->descriptionIcon('heroicon-m-banknotes')->color('success')
                ->chart($spark(Trip::query()->where('status', 'completed'), 'final_fare', 'sum'));
            $stats[] = Stat::make('Commission today', 'PKR '.number_format((float) (clone $completedToday)->sum('commission_amount')))
                ->description('PKR '.number_format((float) Trip::query()->where('status', 'completed')->sum('commission_amount')).' all time')
                ->descriptionIcon('heroicon-m-currency-dollar')->color('primary');
            $stats[] = Stat::make('Cash pending', 'PKR '.number_format((float) Payment::query()->where('status', 'pending')->where('method', 'cash')->sum('amount')))
                ->description(Payment::query()->where('status', 'paid')->count().' payments settled')
                ->descriptionIcon('heroicon-m-clock')->color('warning');
            $stats[] = Stat::make('Payout exceptions', DriverPayout::query()->whereIn('status', ['requested', 'approved', 'processing', 'failed'])->count())
                ->description('Awaiting approval or payment')->descriptionIcon('heroicon-m-wallet')->color('gray');
        }

        return $stats;
    }
}
