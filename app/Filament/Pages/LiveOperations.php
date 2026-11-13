<?php

namespace App\Filament\Pages;

use App\Enums\TripStatus;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Rides\RideResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\DriverProfile;
use App\Models\Incident;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Services\Dispatch\DriverPresenceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Live operations console: online supply, active trips, searching requests and safety alerts. */
class LiveOperations extends Page
{
    protected string $view = 'filament.pages.live-operations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Live operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Live operations';

    public array $data = [];

    public string $filter = 'all';

    public static function canAccess(): bool
    {
        return (bool) auth('admin')->user()?->can('operations.view');
    }

    public function mount(): void
    {
        $this->data = $this->collect();
    }

    public function refreshData(): void
    {
        $this->data = $this->collect();
        $this->dispatch('ops-data', data: $this->data);
    }

    protected function collect(): array
    {
        $presence = app(DriverPresenceService::class);
        $activeTrips = Trip::query()->with('rideRequest', 'driver', 'customer', 'vehicle')->whereIn('status', array_merge(TripStatus::activeValues(), ['safety_hold']))->get();
        $busy = $activeTrips->pluck('driver_id')->all();

        $drivers = DriverProfile::query()->with('user', 'location', 'primaryVehicle.category')->where('is_online', true)->get()->map(function (DriverProfile $d) use ($presence, $busy) {
            $live = $presence->get($d->user_id);
            $lat = $live['lat'] ?? $d->location?->lat;
            $lng = $live['lng'] ?? $d->location?->lng;
            $ts = $live['ts'] ?? $d->location?->recorded_at?->timestamp;
            if (! $lat) {
                return null;
            }

            return [
                'id' => $d->user_id, 'name' => $d->full_name ?? $d->user->name, 'lat' => $lat, 'lng' => $lng,
                'vehicle' => $d->primaryVehicle?->category?->name.' · '.$d->primaryVehicle?->plate_number,
                'busy' => in_array($d->user_id, $busy, true),
                'age_s' => $ts ? now()->timestamp - $ts : null,
                'stale' => $ts ? (now()->timestamp - $ts) > config('femalerider.dispatch.location_stale_seconds') : true,
                'url' => DriverResource::getUrl('view', ['record' => $d]),
            ];
        })->filter()->values()->all();

        $trips = $activeTrips->map(fn (Trip $t) => [
            'id' => $t->uuid, 'status' => $t->status->value, 'label' => $t->status->label(),
            'driver' => $t->driver->name, 'customer' => $t->customer->name, 'plate' => $t->vehicle?->plate_number,
            'pickup' => ['lat' => $t->rideRequest->pickup_lat, 'lng' => $t->rideRequest->pickup_lng, 'address' => $t->rideRequest->pickup_address],
            'dropoff' => ['lat' => $t->rideRequest->dropoff_lat, 'lng' => $t->rideRequest->dropoff_lng, 'address' => $t->rideRequest->dropoff_address],
            'fare' => $t->agreed_fare,
            'since' => $t->assigned_at->diffForHumans(),
            'url' => TripResource::getUrl('view', ['record' => $t]),
        ])->values()->all();

        $searching = RideRequest::query()->with('customer', 'vehicleCategory')->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now())->get()
            ->map(fn (RideRequest $record) => [
                'id' => $record->uuid, 'lat' => $record->pickup_lat, 'lng' => $record->pickup_lng, 'customer' => $record->customer->name,
                'category' => $record->vehicleCategory->name, 'fare' => $record->proposed_fare, 'offers' => $record->offers()->where('status', 'pending')->count(),
                'radius_km' => $record->search_radius_km, 'expires_in' => now()->diffInSeconds($record->expires_at),
                'url' => RideResource::getUrl('view', ['record' => $record]),
            ])->values()->all();

        $alerts = Incident::query()->with('reporter')->whereIn('status', ['open', 'acknowledged', 'escalated'])->whereIn('severity', ['critical', 'high'])->latest('id')->limit(10)->get()
            ->map(fn (Incident $i) => [
                'reference' => $i->reference, 'type' => $i->type, 'severity' => $i->severity, 'status' => $i->status,
                'reporter' => $i->reporter->name, 'lat' => $i->lat, 'lng' => $i->lng, 'since' => $i->created_at->diffForHumans(),
                'url' => IncidentResource::getUrl('view', ['record' => $i]),
            ])->values()->all();

        return [
            'drivers' => $drivers, 'trips' => $trips, 'searching' => $searching, 'alerts' => $alerts,
            'counts' => [
                'online' => count($drivers),
                'available' => collect($drivers)->where('busy', false)->count(),
                'stale' => collect($drivers)->where('stale', true)->count(),
                'trips' => count($trips),
                'searching' => count($searching),
                'alerts' => count($alerts),
            ],
            'updated_at' => now()->format('H:i:s'),
        ];
    }
}
