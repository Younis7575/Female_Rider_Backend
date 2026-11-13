<?php

namespace App\Services\Dispatch;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\TripStatus;
use App\Events\RealtimeEvent;
use App\Models\DriverProfile;
use App\Models\RideDispatch;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Notifications\NotificationService;
use App\Services\Rides\RideService;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Finds eligible nearby drivers and broadcasts ride requests in progressively
 * expanding radius rounds. Eligibility is re-validated server-side for every driver.
 */
class DispatchService
{
    public function __construct(
        private DriverPresenceService $presence,
        private NotificationService $notifications,
    ) {}

    /** Run one broadcast round. Returns number of newly notified drivers. */
    public function broadcast(RideRequest $ride): int
    {
        if (! $ride->status->isOpen() || $ride->expires_at->isPast()) {
            return 0;
        }

        // Prevent concurrent rounds for the same ride.
        $lock = Cache::lock("dispatch:ride:{$ride->id}", 10);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $cfg = config('femalerider.dispatch');
            $round = $ride->broadcast_round + 1;
            $radius = min($cfg['initial_radius_km'] + ($round - 1) * $cfg['radius_step_km'], $cfg['max_radius_km']);

            $alreadyNotified = $ride->dispatches()->pluck('driver_id')->all();
            $candidates = collect($this->presence->nearby($ride->pickup_lat, $ride->pickup_lng, $radius, 100))
                ->reject(fn ($c) => in_array($c['driver_id'], $alreadyNotified, true) || $c['driver_id'] === $ride->customer_id);

            $eligible = $this->filterEligible($ride, $candidates->pluck('driver_id')->all());
            $selected = $candidates->filter(fn ($c) => isset($eligible[$c['driver_id']]))->take($cfg['max_drivers_per_round']);

            foreach ($selected as $c) {
                $this->notifyDriver($ride, $eligible[$c['driver_id']], $c['distance_m'], $round);
            }

            $ride->forceFill(['broadcast_round' => $round, 'search_radius_km' => $radius])->save();

            if ($selected->isNotEmpty()) {
                RealtimeEvent::send(["ride.{$ride->uuid}"], 'ride.matching', [
                    'ride_id' => $ride->uuid,
                    'drivers_notified' => $ride->dispatches()->count(),
                    'radius_km' => $radius,
                ]);
            }

            return $selected->count();
        } finally {
            $lock->release();
        }
    }

    /** Create the dispatch record (once per ride/driver) and push the request to the driver. */
    private function notifyDriver(RideRequest $ride, DriverProfile $driver, int $distanceM, int $round): bool
    {
        $eta = Geo::etaSeconds($distanceM);
        $dispatch = RideDispatch::query()->firstOrCreate(
            ['ride_request_id' => $ride->id, 'driver_id' => $driver->user_id],
            ['round' => max(1, $round), 'distance_m' => $distanceM, 'eta_s' => $eta, 'status' => 'notified', 'notified_at' => now()],
        );
        if (! $dispatch->wasRecentlyCreated) {
            return false;
        }

        $payload = $this->driverPayload($ride, $distanceM, $eta) + ['my_offer' => null];
        RealtimeEvent::send(["driver.{$driver->user_id}"], 'ride.requested', ['ride' => $payload]);
        $this->notifications->send(
            $driver->user,
            'ride.new_request',
            'New ride request',
            'Pickup '.round($distanceM / 1000, 1).' km away · offered '.$ride->currency.' '.number_format($ride->proposed_fare),
            ['ride_id' => $ride->uuid, 'screen' => 'driver_requests'],
        );

        return true;
    }

    /**
     * Pull model: give an online driver every open, matching request within the maximum
     * dispatch radius that she has not been sent yet (e.g. she came online after the request
     * was broadcast, or was outside the radius of earlier rounds). Throttled per driver.
     */
    public function discoverForDriver(User $driver, bool $force = false): int
    {
        if (! $force && ! Cache::add("dispatch:discover:{$driver->id}", 1, 8)) {
            return 0;
        }
        $profile = $driver->driverProfile;
        if (! $profile || ! $profile->is_online) {
            return 0;
        }
        $vehicle = $profile->primaryVehicle;
        $loc = $this->presence->get($driver->id);
        if (! $vehicle || ! $loc) {
            return 0;
        }

        $maxKm = (float) config('femalerider.dispatch.max_radius_km');
        $delta = $maxKm / 111;
        $rides = RideRequest::query()
            ->whereIn('status', [RideStatus::Searching->value, RideStatus::OffersAvailable->value])
            ->where('expires_at', '>', now())
            ->where('vehicle_category_id', $vehicle->vehicle_category_id)
            ->where('customer_id', '!=', $driver->id)
            ->whereBetween('pickup_lat', [$loc['lat'] - $delta, $loc['lat'] + $delta])
            ->whereBetween('pickup_lng', [$loc['lng'] - $delta * 1.2, $loc['lng'] + $delta * 1.2])
            ->whereDoesntHave('dispatches', fn ($q) => $q->where('driver_id', $driver->id))
            ->get();

        $sent = 0;
        foreach ($rides as $ride) {
            $distance = Geo::distanceM($loc['lat'], $loc['lng'], $ride->pickup_lat, $ride->pickup_lng);
            if ($distance > $maxKm * 1000) {
                continue;
            }
            $eligible = $this->filterEligible($ride, [$driver->id]);
            if (! isset($eligible[$driver->id])) {
                continue;
            }
            if ($this->notifyDriver($ride, $eligible[$driver->id], $distance, max(1, $ride->broadcast_round))) {
                $sent++;
                RealtimeEvent::send(["ride.{$ride->uuid}"], 'ride.matching', [
                    'ride_id' => $ride->uuid, 'drivers_notified' => $ride->dispatches()->count(), 'radius_km' => $ride->search_radius_km,
                ]);
            }
        }

        return $sent;
    }

    /**
     * @param  array<int, int>  $driverIds
     * @return array<int, DriverProfile> keyed by driver user id
     */
    public function filterEligible(RideRequest $ride, array $driverIds): array
    {
        if (empty($driverIds)) {
            return [];
        }

        $busy = Trip::query()->whereIn('driver_id', $driverIds)->whereIn('status', TripStatus::activeValues())->pluck('driver_id')->all();
        $blocked = UserBlock::query()
            ->where(fn ($q) => $q->where('blocker_id', $ride->customer_id)->whereIn('blocked_id', $driverIds))
            ->orWhere(fn ($q) => $q->where('blocked_id', $ride->customer_id)->whereIn('blocker_id', $driverIds))
            ->get()->flatMap(fn ($b) => [$b->blocker_id, $b->blocked_id])->all();

        return DriverProfile::query()
            ->with(['user.roles', 'primaryVehicle', 'documents'])
            ->whereIn('user_id', $driverIds)
            ->where('status', DriverStatus::Approved->value)
            ->where('is_online', true)
            ->get()
            ->filter(fn (DriverProfile $d) => ! in_array($d->user_id, $busy, true)
                && ! in_array($d->user_id, $blocked, true)
                && $d->user->isApprovedDriver()
                && $d->primaryVehicle?->status === 'approved'
                && $d->primaryVehicle->vehicle_category_id === $ride->vehicle_category_id
                && empty($d->complianceIssues()))
            ->keyBy('user_id')
            ->all();
    }

    /** Ride details visible to a driver before acceptance (pickup area only, no exact drop-off). */
    public function driverPayload(RideRequest $ride, ?int $distanceM = null, ?int $etaS = null): array
    {
        $ride->loadMissing('customer', 'vehicleCategory');

        return [
            'id' => $ride->uuid,
            'status' => $ride->status->value,
            'pickup' => [
                'lat' => round($ride->pickup_lat, 4),
                'lng' => round($ride->pickup_lng, 4),
                'address' => $ride->pickup_address,
            ],
            'dropoff_area' => RideRequest::areaName($ride->dropoff_address),
            'distance_m' => $ride->distance_m,
            'duration_s' => $ride->duration_s,
            'pickup_distance_m' => $distanceM,
            'pickup_eta_s' => $etaS,
            'proposed_fare' => $ride->proposed_fare,
            'suggested_fare' => $ride->suggested_fare,
            'min_fare' => $ride->min_fare,
            'max_fare' => $ride->max_fare,
            'currency' => $ride->currency,
            'payment_method' => $ride->payment_method,
            'vehicle_category' => $ride->vehicleCategory->name,
            'customer' => [
                'first_name' => $ride->customer->firstName(),
                'rating' => (float) $ride->customer->rating_avg,
                'rides' => $ride->customer->customerTrips()->where('status', 'completed')->count(),
            ],
            'pickup_note' => $ride->pickup_note,
            'expires_at' => $ride->expires_at->toIso8601String(),
        ];
    }

    /** Called by scheduler: expand radius for searching rides, expire stale rides & offers. */
    public function tick(RideService $rides): array
    {
        $stats = ['rebroadcast' => 0, 'expired' => 0];
        $interval = (int) config('femalerider.dispatch.round_interval_seconds');

        RideRequest::query()
            ->whereIn('status', [RideStatus::Searching->value, RideStatus::OffersAvailable->value])
            ->where('expires_at', '<=', now())
            ->each(function (RideRequest $ride) use ($rides, &$stats) {
                $rides->expire($ride);
                $stats['expired']++;
            });

        RideRequest::query()
            ->whereIn('status', [RideStatus::Searching->value, RideStatus::OffersAvailable->value])
            ->where('expires_at', '>', now())
            ->where('updated_at', '<=', now()->subSeconds($interval))
            ->where('search_radius_km', '<', config('femalerider.dispatch.max_radius_km'))
            ->each(function (RideRequest $ride) use (&$stats) {
                $stats['rebroadcast'] += $this->broadcast($ride);
            });

        $rides->expireStaleOffers();

        return $stats;
    }
}
