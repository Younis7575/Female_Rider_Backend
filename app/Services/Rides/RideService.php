<?php

namespace App\Services\Rides;

use App\Enums\OfferStatus;
use App\Enums\RideStatus;
use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\RideOfferResource;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\ServiceZone;
use App\Models\SystemSetting;
use App\Models\TripEvent;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Services\Dispatch\DispatchService;
use App\Services\Finance\CouponService;
use App\Services\Maps\MapsService;
use App\Services\Notifications\NotificationService;
use App\Services\Pricing\FareCalculator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Ride request lifecycle: estimate → create (searching) → offers → accepted | cancelled | expired | no driver found.
 */
class RideService
{
    public function __construct(
        private MapsService $maps,
        private FareCalculator $fares,
        private CouponService $coupons,
        private NotificationService $notifications,
    ) {}

    /** Quote all active categories for a route (no persistence). */
    public function estimate(float $pLat, float $pLng, float $dLat, float $dLng): array
    {
        $zone = $this->assertServiceable($pLat, $pLng, $dLat, $dLng);
        $route = $this->maps->route($pLat, $pLng, $dLat, $dLng);

        $options = VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (VehicleCategory $c) use ($route, $zone) {
                $q = $this->fares->estimate($c, $route['distance_m'], $route['duration_s'], $zone);

                return [
                    'vehicle_category' => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon, 'capacity' => $c->capacity, 'description' => $c->description],
                    'suggested_fare' => $q['suggested_fare'],
                    'min_fare' => $q['min_fare'],
                    'max_fare' => $q['max_fare'],
                    'surge_multiplier' => $q['surge_multiplier'],
                    'currency' => $q['currency'],
                    'breakdown' => $q['breakdown'],
                ];
            })->values()->all();

        return [
            'distance_m' => $route['distance_m'],
            'duration_s' => $route['duration_s'],
            'polyline' => $route['polyline'],
            'route_provider' => $route['provider'],
            'service_zone' => ['id' => $zone->id, 'name' => $zone->name, 'city' => $zone->city->name],
            'options' => $options,
        ];
    }

    public function assertServiceable(float $pLat, float $pLng, ?float $dLat = null, ?float $dLng = null): ServiceZone
    {
        if (SystemSetting::get('platform.maintenance_mode', false)) {
            throw new BusinessRuleException('FemaleRider is undergoing maintenance. Please try again shortly.', 'maintenance', 503);
        }
        $zone = ServiceZone::locate($pLat, $pLng);
        if (! $zone) {
            throw new BusinessRuleException('Pickup location is outside our service area.', 'outside_service_area');
        }
        if ($dLat !== null && ! ServiceZone::locate($dLat, $dLng)) {
            throw new BusinessRuleException('Destination is outside our service area.', 'outside_service_area');
        }

        return $zone;
    }

    public function create(User $customer, array $data, ?string $idempotencyKey = null): RideRequest
    {
        if (! $customer->canUseCustomerMode()) {
            throw new BusinessRuleException('Your account is not approved to request rides yet.', 'customer_not_eligible', 403);
        }
        if ($customer->active_mode !== 'customer') {
            throw new BusinessRuleException('Switch to Customer mode to request a ride.', 'wrong_mode', 409);
        }
        if ($customer->driverProfile?->is_online) {
            throw new BusinessRuleException('Go offline as a driver before requesting a ride.', 'driver_online', 409);
        }
        if ($customer->activeTrip()) {
            throw new BusinessRuleException('You already have an active trip.', 'active_trip_exists', 409);
        }

        if ($idempotencyKey && ($existing = RideRequest::query()->where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        if (RideRequest::query()->where('customer_id', $customer->id)->whereIn('status', [RideStatus::Searching->value, RideStatus::OffersAvailable->value])->where('expires_at', '>', now())->exists()) {
            throw new BusinessRuleException('You already have an open ride request.', 'open_request_exists', 409);
        }

        $category = VehicleCategory::query()->where('is_active', true)->findOrFail($data['vehicle_category_id']);
        $zone = $this->assertServiceable($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']);
        $route = $this->maps->route($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']);
        $quote = $this->fares->estimate($category, $route['distance_m'], $route['duration_s'], $zone);

        // Never trust the client fare: validate against server-computed bounds.
        $proposed = Money::round((float) ($data['proposed_fare'] ?? $quote['suggested_fare']), 5);
        $this->fares->assertWithinBounds($proposed, $quote['min_fare'], $quote['max_fare']);

        $coupon = null;
        $discount = 0.0;
        if (! empty($data['coupon_code'])) {
            $coupon = $this->coupons->validate($data['coupon_code'], $customer, $proposed, $category->id, $zone->city_id);
            $discount = $this->coupons->discountFor($coupon, $proposed);
        }

        $ride = DB::transaction(function () use ($customer, $data, $category, $zone, $route, $quote, $proposed, $coupon, $discount, $idempotencyKey) {
            $ride = RideRequest::query()->create([
                'customer_id' => $customer->id,
                'vehicle_category_id' => $category->id,
                'service_zone_id' => $zone->id,
                'pickup_lat' => $data['pickup_lat'],
                'pickup_lng' => $data['pickup_lng'],
                'pickup_address' => $data['pickup_address'] ?? $this->maps->reverseGeocode($data['pickup_lat'], $data['pickup_lng']),
                'dropoff_lat' => $data['dropoff_lat'],
                'dropoff_lng' => $data['dropoff_lng'],
                'dropoff_address' => $data['dropoff_address'] ?? $this->maps->reverseGeocode($data['dropoff_lat'], $data['dropoff_lng']),
                'pickup_note' => $data['pickup_note'] ?? null,
                'distance_m' => $route['distance_m'],
                'duration_s' => $route['duration_s'],
                'route_polyline' => $route['polyline'],
                'suggested_fare' => $quote['suggested_fare'],
                'min_fare' => $quote['min_fare'],
                'max_fare' => $quote['max_fare'],
                'proposed_fare' => $proposed,
                'initial_proposed_fare' => $proposed,
                'surge_multiplier' => $quote['surge_multiplier'],
                'currency' => $quote['currency'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'coupon_id' => $coupon?->id,
                'discount_amount' => $discount,
                'status' => RideStatus::Searching,
                'expires_at' => now()->addSeconds((int) SystemSetting::get('dispatch.request_ttl_seconds', config('femalerider.dispatch.request_ttl_seconds'))),
                'idempotency_key' => $idempotencyKey,
            ]);
            if ($coupon) {
                $this->coupons->reserve($coupon, $customer, $ride, $discount);
            }
            TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'ride.created', 'to_status' => 'searching', 'actor_type' => 'customer', 'actor_id' => $customer->id, 'meta' => ['proposed_fare' => $proposed, 'suggested_fare' => $quote['suggested_fare']]]);

            return $ride;
        });

        app(DispatchService::class)->broadcast($ride);
        RealtimeEvent::send(['admin.operations'], 'ride.created', ['ride_id' => $ride->uuid]);

        return $ride->fresh();
    }

    /** Customer raises/lowers their proposed fare while searching (re-notifies already-dispatched drivers). */
    public function updateFare(User $customer, RideRequest $ride, float $amount): RideRequest
    {
        $this->assertOwner($customer, $ride);
        if (! $ride->isOpen()) {
            throw new BusinessRuleException('This ride request is no longer open.', 'ride_closed', 409);
        }
        $amount = Money::round($amount, 5);
        $this->fares->assertWithinBounds($amount, $ride->min_fare, $ride->max_fare);

        $old = $ride->proposed_fare;
        $ride->update(['proposed_fare' => $amount, 'expires_at' => now()->addSeconds((int) config('femalerider.dispatch.request_ttl_seconds'))]);
        TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'ride.fare_updated', 'actor_type' => 'customer', 'actor_id' => $customer->id, 'meta' => ['from' => $old, 'to' => $amount]]);

        $dispatch = app(DispatchService::class);
        foreach ($ride->dispatches()->whereIn('status', ['notified', 'seen'])->get() as $d) {
            $payload = $dispatch->driverPayload($ride, $d->distance_m, $d->eta_s);
            $mine = $ride->offers()->where('driver_id', $d->driver_id)->latest('id')->first();
            $payload['my_offer'] = $mine ? (new RideOfferResource($mine))->resolve() : null;
            RealtimeEvent::send(["driver.{$d->driver_id}"], 'ride.updated', ['ride' => $payload]);
        }
        $dispatch->broadcast($ride);

        return $ride->fresh();
    }

    public function cancel(User $customer, RideRequest $ride, ?string $reason = null): RideRequest
    {
        $this->assertOwner($customer, $ride);

        return DB::transaction(function () use ($customer, $ride, $reason) {
            $ride = RideRequest::query()->lockForUpdate()->findOrFail($ride->id);
            if (! $ride->status->isOpen()) {
                throw new BusinessRuleException('This ride request can no longer be cancelled here.', 'ride_closed', 409);
            }
            $ride->update(['status' => RideStatus::CancelledByCustomer, 'cancelled_at' => now(), 'cancelled_by' => 'customer', 'cancel_reason' => $reason]);
            $this->closeOffers($ride, OfferStatus::Closed);
            $this->coupons->release($ride);
            TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'ride.cancelled', 'from_status' => 'searching', 'to_status' => 'cancelled_by_customer', 'actor_type' => 'customer', 'actor_id' => $customer->id, 'meta' => ['reason' => $reason]]);

            DB::afterCommit(fn () => $this->notifyClosed($ride, 'ride.cancelled'));

            return $ride;
        });
    }

    public function expire(RideRequest $ride): void
    {
        DB::transaction(function () use ($ride) {
            $ride = RideRequest::query()->lockForUpdate()->find($ride->id);
            if (! $ride || ! $ride->status->isOpen()) {
                return;
            }
            $hadOffers = $ride->offers()->exists();
            $status = $hadOffers ? RideStatus::Expired : RideStatus::NoDriverFound;
            $ride->update(['status' => $status]);
            $this->closeOffers($ride, OfferStatus::Expired);
            $this->coupons->release($ride);
            TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'ride.expired', 'to_status' => $status->value, 'actor_type' => 'system']);

            DB::afterCommit(function () use ($ride, $status) {
                $this->notifyClosed($ride, 'ride.expired');
                $this->notifications->send($ride->customer, 'ride.expired',
                    $status === RideStatus::NoDriverFound ? 'No drivers available' : 'Ride request expired',
                    $status === RideStatus::NoDriverFound ? 'We could not find a driver nearby. Try raising your fare or try again shortly.' : 'Your request expired before an offer was accepted.',
                    ['ride_id' => $ride->uuid, 'screen' => 'home']);
            });
        });
    }

    public function expireStaleOffers(): int
    {
        $offers = RideOffer::query()->where('status', OfferStatus::Pending->value)->where('expires_at', '<=', now())->get();
        foreach ($offers as $offer) {
            $offer->update(['status' => OfferStatus::Expired]);
            RealtimeEvent::send(["ride.{$offer->rideRequest->uuid}", "driver.{$offer->driver_id}"], 'offer.expired', ['offer_id' => $offer->id, 'ride_id' => $offer->rideRequest->uuid]);
        }

        return $offers->count();
    }

    public function closeOffers(RideRequest $ride, OfferStatus $status, ?int $exceptOfferId = null): void
    {
        $ride->offers()->whereIn('status', [OfferStatus::Pending->value])
            ->when($exceptOfferId, fn ($q) => $q->where('id', '!=', $exceptOfferId))
            ->update(['status' => $status->value, 'responded_at' => now()]);
        $ride->dispatches()->whereIn('status', ['notified', 'seen', 'offered'])->update(['status' => 'closed']);
    }

    private function notifyClosed(RideRequest $ride, string $event): void
    {
        $channels = ["ride.{$ride->uuid}", 'admin.operations'];
        foreach ($ride->dispatches()->pluck('driver_id') as $driverId) {
            $channels[] = "driver.{$driverId}";
        }
        RealtimeEvent::send($channels, $event, ['ride_id' => $ride->uuid, 'status' => $ride->status->value]);
    }

    public function assertOwner(User $user, RideRequest $ride): void
    {
        if ($ride->customer_id !== $user->id) {
            throw new BusinessRuleException('Ride not found.', 'not_found', 404);
        }
    }
}
