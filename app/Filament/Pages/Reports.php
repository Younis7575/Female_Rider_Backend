<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\DriverOnlineSession;
use App\Models\DriverPayout;
use App\Models\Incident;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** Analytics & business KPIs with centrally defined metric formulas, plus restricted CSV export. */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Reports & analytics';

    protected static ?int $navigationSort = 9;

    public string $from = '';

    public string $until = '';

    public static function canAccess(): bool
    {
        return (bool) auth('admin')->user()?->can('reports.view');
    }

    public function mount(): void
    {
        $this->from = now()->subDays(29)->toDateString();
        $this->until = now()->toDateString();
    }

    protected function range(): array
    {
        return [Carbon::parse($this->from ?: now()->subDays(29))->startOfDay(), Carbon::parse($this->until ?: now())->endOfDay()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportTrips')->label('Export trips (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->requiresConfirmation()->modalDescription('Exports contain operational data. Exports are logged; handle according to data policy.')
                ->action(fn () => $this->exportTrips()),
        ];
    }

    public function exportTrips(): StreamedResponse
    {
        [$from, $to] = $this->range();
        AuditLog::record('report.trips_exported', null, [], [], ['from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['trip_id', 'created_at', 'status', 'city_zone', 'vehicle_class', 'agreed_fare', 'final_fare', 'discount', 'commission', 'driver_earning', 'payment_method', 'payment_status', 'distance_km', 'duration_min', 'cancelled_by']);
            Trip::query()->with('serviceZone', 'vehicleCategory')->whereBetween('created_at', [$from, $to])->orderBy('id')->chunk(500, function ($trips) use ($out) {
                foreach ($trips as $t) {
                    // Pseudonymised: no names or phone numbers in exports.
                    fputcsv($out, [$t->uuid, $t->created_at, $t->status->value, $t->serviceZone?->name, $t->vehicleCategory?->name, $t->agreed_fare, $t->final_fare, $t->discount_amount, $t->commission_amount, $t->driver_earning, $t->payment_method, $t->payment_status, round(($t->actual_distance_m ?? 0) / 1000, 2), round(($t->actual_duration_s ?? 0) / 60), $t->cancelled_by]);
                }
            });
            fclose($out);
        }, 'femalerider-trips-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function getReportProperty(): array
    {
        [$from, $to] = $this->range();
        $rides = RideRequest::query()->whereBetween('created_at', [$from, $to]);
        $trips = Trip::query()->whereBetween('created_at', [$from, $to]);
        $completed = (clone $trips)->where('status', 'completed');
        $requests = (clone $rides)->count();
        $matched = (clone $rides)->where('status', 'accepted')->count();

        $firstOffer = DB::table('ride_requests')->join('ride_offers', 'ride_offers.ride_request_id', '=', 'ride_requests.id')
            ->whereBetween('ride_requests.created_at', [$from, $to])
            ->selectRaw('ride_requests.id, TIMESTAMPDIFF(SECOND, ride_requests.created_at, MIN(ride_offers.created_at)) s')
            ->groupBy('ride_requests.id', 'ride_requests.created_at')->get()->avg('s');
        $offers = RideOffer::query()->whereHas('rideRequest', fn ($query) => $query->whereBetween('created_at', [$from, $to]))->where('proposed_by', 'driver');
        $offerCount = (clone $offers)->count();

        $gbv = (float) (clone $completed)->sum('final_fare');
        $onlineSecs = DriverOnlineSession::query()->whereBetween('online_at', [$from, $to])->get()->sum(fn ($state) => (($state->offline_at ?? now())->timestamp - $state->online_at->timestamp));
        $activeDrivers = (clone $completed)->distinct('driver_id')->count('driver_id');

        $driverBalances = LedgerEntry::query()->where('account', 'driver_payable')->groupBy('user_id')
            ->selectRaw("user_id, SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) bal")->get();

        $acks = Incident::query()->whereBetween('created_at', [$from, $to])->whereNotNull('acknowledged_at')->get()->map(fn ($i) => $i->acknowledged_at->diffInSeconds($i->created_at, true));

        return [
            'marketplace' => [
                'Ride requests' => number_format($requests),
                'Request → match rate' => $requests ? round($matched / $requests * 100, 1).'%' : '—',
                'Avg time to first offer' => $firstOffer ? round($firstOffer).' s' : '—',
                'Driver offers made' => number_format($offerCount),
                'Offer acceptance rate' => $offerCount ? round((clone $offers)->where('status', 'accepted')->count() / $offerCount * 100, 1).'%' : '—',
                'No driver found' => number_format((clone $rides)->where('status', 'no_driver_found')->count()),
                'Avg negotiated vs suggested' => ($avg = DB::table('trips')->join('ride_requests', 'ride_requests.id', '=', 'trips.ride_request_id')
                    ->where('trips.status', 'completed')->whereBetween('trips.created_at', [$from, $to])
                    ->selectRaw('AVG(trips.agreed_fare / ride_requests.suggested_fare) r')->value('r')) ? round($avg * 100).'%' : '—',
            ],
            'trips' => [
                'Trips assigned' => number_format((clone $trips)->count()),
                'Completed' => number_format((clone $completed)->count()),
                'Completion rate' => ($n = (clone $trips)->count()) ? round((clone $completed)->count() / $n * 100, 1).'%' : '—',
                'Cancelled by customer' => number_format((clone $trips)->where('status', 'cancelled_by_customer')->count()),
                'Cancelled by driver' => number_format((clone $trips)->where('status', 'cancelled_by_driver')->count()),
                'Cancelled by ops' => number_format((clone $trips)->where('status', 'cancelled_by_admin')->count()),
                'Avg trip distance' => ($d = (clone $completed)->avg('actual_distance_m')) ? round($d / 1000, 1).' km' : '—',
            ],
            'finance' => [
                'Gross booking value' => 'PKR '.number_format($gbv),
                'Commission (net revenue)' => 'PKR '.number_format((float) (clone $completed)->sum('commission_amount')),
                'Promo discounts' => 'PKR '.number_format((float) (clone $completed)->sum('discount_amount')),
                'Payment success' => ($p = Payment::query()->whereBetween('created_at', [$from, $to])->count()) ? round(Payment::query()->whereBetween('created_at', [$from, $to])->where('status', 'paid')->count() / $p * 100, 1).'%' : '—',
                'Refunds processed' => 'PKR '.number_format((float) Refund::query()->whereBetween('processed_at', [$from, $to])->sum('amount')),
                'Cash outstanding (unconfirmed)' => 'PKR '.number_format((float) Payment::query()->where('status', 'pending')->where('method', 'cash')->sum('amount')),
                'Payouts paid' => 'PKR '.number_format((float) DriverPayout::query()->whereBetween('paid_at', [$from, $to])->sum('amount')),
                'Payout liability (owed to drivers)' => 'PKR '.number_format((float) $driverBalances->where('bal', '>', 0)->sum('bal')),
                'Commission receivable (cash trips)' => 'PKR '.number_format(abs((float) $driverBalances->where('bal', '<', 0)->sum('bal'))),
            ],
            'supply' => [
                'Drivers with ≥1 trip' => number_format($activeDrivers),
                'Online hours' => number_format($onlineSecs / 3600, 1),
                'Earnings / online hour' => $onlineSecs ? 'PKR '.number_format((float) (clone $completed)->sum('driver_earning') / ($onlineSecs / 3600)) : '—',
                'Trips / active driver' => $activeDrivers ? round((clone $completed)->count() / $activeDrivers, 1) : '—',
            ],
            'safety' => [
                'Incidents reported' => number_format(Incident::query()->whereBetween('created_at', [$from, $to])->count()),
                'SOS alerts' => number_format(Incident::query()->whereBetween('created_at', [$from, $to])->where('type', 'sos')->count()),
                'Avg acknowledgement time' => $acks->count() ? round($acks->avg() / 60, 1).' min' : '—',
                'Incidents / 1,000 trips' => ($c = (clone $completed)->count()) ? round(Incident::query()->whereBetween('created_at', [$from, $to])->count() / $c * 1000, 1) : '—',
            ],
            'growth' => [
                'New sign-ups' => number_format(User::query()->whereBetween('created_at', [$from, $to])->count()),
                'Verified (approved)' => number_format(User::query()->whereBetween('created_at', [$from, $to])->where('eligibility_status', 'approved')->count()),
                'Repeat riders (2+ trips)' => number_format(Trip::query()->where('status', 'completed')->whereBetween('created_at', [$from, $to])->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) >= 2')->get()->count()),
            ],
        ];
    }
}
