<?php

namespace App\Services\Rides;

use App\Enums\OfferStatus;
use App\Enums\RideStatus;
use App\Enums\TripStatus;
use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\RideOfferResource;
use App\Models\RideDispatch;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\User;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\DriverPresenceService;
use App\Services\Notifications\NotificationService;
use App\Services\Pricing\FareCalculator;
use App\Support\Geo;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * inDrive-style fare negotiation.
 *
 * Each (ride, driver) pair forms a thread of offers with increasing sequence numbers:
 *   driver offer → customer accept | reject | counter
 *   customer counter → driver accept | reject | counter
 * Accepting assigns the driver atomically: row locks + distributed lock + unique DB
 * constraints (one trip per ride, one active trip per driver/customer).
 */
class NegotiationService
{
    public function __construct(
        private FareCalculator $fares,
        private RideService $rides,
        private DriverPresenceService $presence,
        private NotificationService $notifications,
    ) {}

    private function offerTtl(): int
    {
        return (int) config('femalerider.dispatch.offer_ttl_seconds');
    }

    /** Keep the request alive while a negotiation is active (new bid or re-bid). */
    private function extendRideForNegotiation(RideRequest $ride): void
    {
        $until = now()->addSeconds($this->offerTtl() + 30);
        if ($ride->expires_at->lt($until)) {
            $ride->forceFill(['expires_at' => $until])->save();
        }
    }

    // ---- Driver actions ---------------------------------------------------

    /**
     * Driver bid: the customer's current price or higher (never lower). When answering a
     * customer's re-bid the floor is that re-bid amount instead.
     */
    public function driverOffer(User $driver, RideRequest $ride, ?float $amount = null, ?float $floor = null): RideOffer
    {
        $this->assertDriverCanNegotiate($driver, $ride);
        $floor = $floor ?? $ride->proposed_fare;
        $amount = Money::round($amount ?? $floor, 5);
        if ($amount < $floor) {
            throw new BusinessRuleException(
                'You can offer PKR '.number_format($floor).' (the customer\'s price) or more.',
                'offer_below_customer_fare', 422, ['min_offer' => $floor, 'max_fare' => $ride->max_fare],
            );
        }
        $this->fares->assertWithinBounds($amount, $ride->min_fare, $ride->max_fare);

        $offer = DB::transaction(function () use ($driver, $ride, $amount) {
            $ride = RideRequest::query()->lockForUpdate()->findOrFail($ride->id);
            if (! $ride->isOpen()) {
                throw new BusinessRuleException('This ride is no longer available.', 'ride_closed', 409);
            }
            $thread = $ride->offers()->where('driver_id', $driver->id);
            if ((clone $thread)->where('status', OfferStatus::Pending->value)->where('proposed_by', 'driver')->where('expires_at', '>', now())->exists()) {
                throw new BusinessRuleException('You already have a pending offer on this ride.', 'offer_pending', 409);
            }
            if ($ride->offers()->where('status', OfferStatus::Pending->value)->count() >= config('femalerider.dispatch.max_pending_offers_per_ride')) {
                throw new BusinessRuleException('This ride has received the maximum number of offers.', 'too_many_offers', 409);
            }

            // A fresh driver offer supersedes any pending customer counter in this thread.
            (clone $thread)->where('status', OfferStatus::Pending->value)->update(['status' => OfferStatus::Countered->value, 'responded_at' => now()]);

            $dispatch = RideDispatch::query()->where('ride_request_id', $ride->id)->where('driver_id', $driver->id)->first();
            $loc = $this->presence->get($driver->id);
            $distance = $loc ? Geo::distanceM($loc['lat'], $loc['lng'], $ride->pickup_lat, $ride->pickup_lng) : $dispatch?->distance_m;

            $offer = RideOffer::query()->create([
                'ride_request_id' => $ride->id,
                'driver_id' => $driver->id,
                'vehicle_id' => $driver->driverProfile->primaryVehicle?->id,
                'amount' => $amount,
                'proposed_by' => 'driver',
                'status' => OfferStatus::Pending,
                'parent_offer_id' => (clone $thread)->max('id'),
                'sequence' => ((int) (clone $thread)->max('sequence')) + 1,
                'distance_to_pickup_m' => $distance,
                'eta_s' => $distance ? Geo::etaSeconds($distance) : null,
                'expires_at' => now()->addSeconds($this->offerTtl()),
            ]);

            $dispatch?->update(['status' => 'offered', 'responded_at' => now()]);
            $this->extendRideForNegotiation($ride);
            if ($ride->status === RideStatus::Searching) {
                $ride->update(['status' => RideStatus::OffersAvailable]);
            }
            TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'offer.created', 'actor_type' => 'driver', 'actor_id' => $driver->id, 'meta' => ['offer_id' => $offer->id, 'amount' => $amount, 'sequence' => $offer->sequence]]);

            return $offer;
        });

        $this->broadcastOffer($offer, 'offer.created');
        $this->notifications->send($ride->customer, 'ride.offer', 'New offer received',
            $driver->firstName().' offered '.$ride->currency.' '.number_format($amount), ['ride_id' => $ride->uuid, 'offer_id' => $offer->id, 'screen' => 'ride_offers']);

        return $offer;
    }

    public function driverDecline(User $driver, RideRequest $ride): void
    {
        $dispatch = RideDispatch::query()->where('ride_request_id', $ride->id)->where('driver_id', $driver->id)->first();
        if (! $dispatch) {
            throw new BusinessRuleException('Ride not found.', 'not_found', 404);
        }
        // Declines are recorded for transparency only; they never trigger penalties.
        $dispatch->update(['status' => 'declined', 'responded_at' => now()]);
        TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'driver.declined', 'actor_type' => 'driver', 'actor_id' => $driver->id]);
    }

    public function driverWithdraw(User $driver, RideOffer $offer): RideOffer
    {
        if ($offer->driver_id !== $driver->id || $offer->proposed_by !== 'driver') {
            throw new BusinessRuleException('Offer not found.', 'not_found', 404);
        }
        $updated = RideOffer::query()->whereKey($offer->id)->where('status', OfferStatus::Pending->value)
            ->update(['status' => OfferStatus::Withdrawn->value, 'responded_at' => now()]);
        if (! $updated) {
            throw new BusinessRuleException('This offer can no longer be withdrawn.', 'offer_not_pending', 409);
        }
        $offer->refresh();
        $this->broadcastOffer($offer, 'offer.withdrawn');

        return $offer;
    }

    /** Driver responds to a customer's counter-offer. */
    public function driverRespondToCounter(User $driver, RideOffer $counter, string $action, ?float $amount = null): RideOffer|Trip
    {
        if ($counter->driver_id !== $driver->id || $counter->proposed_by !== 'customer') {
            throw new BusinessRuleException('Offer not found.', 'not_found', 404);
        }
        if (! $counter->isLive()) {
            throw new BusinessRuleException('This counter-offer is no longer active.', 'offer_not_pending', 409);
        }

        return match ($action) {
            'accept' => $this->assign($counter, $driver, 'driver'),
            'reject' => $this->reject($counter, 'driver'),
            'counter' => $this->driverOffer($driver, $counter->rideRequest, $amount ?? throw new BusinessRuleException('Amount is required.', 'validation'), $counter->amount),
            default => throw new BusinessRuleException('Unknown action.', 'validation'),
        };
    }

    // ---- Customer actions -------------------------------------------------

    public function customerAccept(User $customer, RideOffer $offer): Trip
    {
        $this->assertCustomerOwnsOffer($customer, $offer);
        if ($offer->proposed_by !== 'driver') {
            throw new BusinessRuleException('You can only accept offers made by drivers.', 'invalid_offer', 422);
        }

        return $this->assign($offer, $customer, 'customer');
    }

    public function customerReject(User $customer, RideOffer $offer): RideOffer
    {
        $this->assertCustomerOwnsOffer($customer, $offer);
        if ($offer->proposed_by !== 'driver') {
            throw new BusinessRuleException('Invalid offer.', 'invalid_offer', 422);
        }

        return $this->reject($offer, 'customer');
    }

    public function customerCounter(User $customer, RideOffer $offer, float $amount): RideOffer
    {
        $this->assertCustomerOwnsOffer($customer, $offer);
        $ride = $offer->rideRequest;
        if ($offer->proposed_by !== 'driver' || ! $offer->isLive()) {
            throw new BusinessRuleException('This offer is no longer active.', 'offer_not_pending', 409);
        }
        $amount = Money::round($amount, 5);
        $this->fares->assertWithinBounds($amount, $ride->min_fare, $ride->max_fare);
        if ($amount >= $offer->amount) {
            throw new BusinessRuleException(
                'Your counter-offer must be lower than this driver\'s offer of PKR '.number_format($offer->amount).'. To pay that, just accept the offer.',
                'counter_not_lower', 422, ['max_counter' => $offer->amount - 5, 'min_fare' => $ride->min_fare],
            );
        }

        $counters = $ride->offers()->where('driver_id', $offer->driver_id)->where('proposed_by', 'customer')->count();
        if ($counters >= config('femalerider.dispatch.max_counter_rounds')) {
            throw new BusinessRuleException('Maximum counter-offers reached for this driver.', 'counter_limit', 409);
        }

        $counter = DB::transaction(function () use ($offer, $amount, $ride, $customer) {
            $locked = RideOffer::query()->lockForUpdate()->findOrFail($offer->id);
            if (! $locked->isLive()) {
                throw new BusinessRuleException('This offer is no longer active.', 'offer_not_pending', 409);
            }
            $locked->update(['status' => OfferStatus::Countered, 'responded_at' => now()]);

            $counter = RideOffer::query()->create([
                'ride_request_id' => $ride->id,
                'driver_id' => $locked->driver_id,
                'vehicle_id' => $locked->vehicle_id,
                'amount' => $amount,
                'proposed_by' => 'customer',
                'status' => OfferStatus::Pending,
                'parent_offer_id' => $locked->id,
                'sequence' => $locked->sequence + 1,
                'distance_to_pickup_m' => $locked->distance_to_pickup_m,
                'eta_s' => $locked->eta_s,
                'expires_at' => now()->addSeconds($this->offerTtl()),
            ]);
            $this->extendRideForNegotiation($ride);
            TripEvent::query()->create(['ride_request_id' => $ride->id, 'event' => 'offer.countered', 'actor_type' => 'customer', 'actor_id' => $customer->id, 'meta' => ['offer_id' => $counter->id, 'parent_offer_id' => $locked->id, 'amount' => $amount]]);

            return $counter;
        });

        $this->broadcastOffer($counter, 'offer.countered');
        $this->notifications->send($counter->driver, 'ride.counter', 'Counter-offer received',
            $customer->firstName().' countered with '.$ride->currency.' '.number_format($amount), ['ride_id' => $ride->uuid, 'offer_id' => $counter->id, 'screen' => 'driver_requests']);

        return $counter;
    }

    // ---- Core -------------------------------------------------------------

    private function reject(RideOffer $offer, string $by): RideOffer
    {
        $updated = RideOffer::query()->whereKey($offer->id)->where('status', OfferStatus::Pending->value)
            ->update(['status' => OfferStatus::Rejected->value, 'responded_at' => now()]);
        if (! $updated) {
            throw new BusinessRuleException('This offer is no longer active.', 'offer_not_pending', 409);
        }
        $offer->refresh();
        TripEvent::query()->create(['ride_request_id' => $offer->ride_request_id, 'event' => 'offer.rejected', 'actor_type' => $by, 'actor_id' => $by === 'driver' ? $offer->driver_id : $offer->rideRequest->customer_id, 'meta' => ['offer_id' => $offer->id]]);
        $this->broadcastOffer($offer, 'offer.rejected');

        return $offer;
    }

    /**
     * Atomically assign the offer's driver to the ride and create the trip.
     * Safe under concurrent acceptance: only one caller can win.
     */
    public function assign(RideOffer $offer, User $actor, string $actorRole): Trip
    {
        $lock = Cache::lock("assign:ride:{$offer->ride_request_id}", 10);
        if (! $lock->get()) {
            throw new BusinessRuleException('This ride is being assigned. Please wait.', 'assignment_in_progress', 409);
        }

        try {
            $trip = DB::transaction(function () use ($offer, $actor, $actorRole) {
                $ride = RideRequest::query()->lockForUpdate()->findOrFail($offer->ride_request_id);
                $offer = RideOffer::query()->lockForUpdate()->findOrFail($offer->id);

                if (! $ride->status->isOpen() || $ride->expires_at->isPast()) {
                    throw new BusinessRuleException('This ride is no longer available.', 'ride_closed', 409);
                }
                if (! $offer->isLive()) {
                    throw new BusinessRuleException('This offer is no longer active.', 'offer_not_pending', 409);
                }

                $driver = User::query()->with(['roles', 'driverProfile.primaryVehicle', 'driverProfile.documents'])->findOrFail($offer->driver_id);
                if (! $driver->isApprovedDriver() || ! $driver->driverProfile->is_online || $driver->driverProfile->complianceIssues()) {
                    throw new BusinessRuleException('This driver is no longer available.', 'driver_unavailable', 409);
                }
                if ($driver->id === $ride->customer_id) {
                    throw new BusinessRuleException('You cannot accept your own ride.', 'self_assignment', 422);
                }
                if (Trip::query()->where('driver_id', $driver->id)->whereIn('status', TripStatus::activeValues())->lockForUpdate()->exists()) {
                    throw new BusinessRuleException('This driver is already on another trip.', 'driver_busy', 409);
                }

                $commissionRate = $this->fares->commissionRate($ride->vehicleCategory, $ride->serviceZone);

                try {
                    $trip = Trip::query()->create([
                        'ride_request_id' => $ride->id,
                        'ride_offer_id' => $offer->id,
                        'customer_id' => $ride->customer_id,
                        'driver_id' => $driver->id,
                        'vehicle_id' => $offer->vehicle_id ?? $driver->driverProfile->primaryVehicle?->id,
                        'vehicle_category_id' => $ride->vehicle_category_id,
                        'service_zone_id' => $ride->service_zone_id,
                        'status' => TripStatus::DriverAssigned,
                        'agreed_fare' => $offer->amount,
                        'commission_rate' => $commissionRate,
                        'currency' => $ride->currency,
                        'payment_method' => $ride->payment_method,
                        'payment_status' => 'pending',
                        'pickup_pin' => str_pad((string) random_int(0, 9999), (int) config('femalerider.trip.pin_length'), '0', STR_PAD_LEFT),
                        'assigned_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    // Unique constraint (ride already has a trip / driver or customer already active).
                    throw new BusinessRuleException('This ride has already been assigned.', 'already_assigned', 409);
                }

                $offer->update(['status' => OfferStatus::Accepted, 'responded_at' => now()]);
                $ride->update(['status' => RideStatus::Accepted, 'accepted_offer_id' => $offer->id, 'driver_id' => $driver->id]);
                $this->rides->closeOffers($ride, OfferStatus::Closed, $offer->id);

                TripEvent::query()->create(['trip_id' => $trip->id, 'ride_request_id' => $ride->id, 'event' => 'trip.assigned', 'to_status' => 'driver_assigned', 'actor_type' => $actorRole, 'actor_id' => $actor->id, 'meta' => ['offer_id' => $offer->id, 'fare' => $offer->amount]]);

                return $trip;
            });
        } finally {
            $lock->release();
        }

        $this->afterAssignment($trip);

        return $trip;
    }

    private function afterAssignment(Trip $trip): void
    {
        $trip->load('rideRequest.dispatches', 'customer', 'driver');
        $ride = $trip->rideRequest;
        $this->presence->markBusy($trip->driver_id);

        RealtimeEvent::send(["ride.{$ride->uuid}", "user.{$trip->customer_id}"], 'ride.accepted', ['ride_id' => $ride->uuid, 'trip_id' => $trip->uuid]);
        RealtimeEvent::send(["driver.{$trip->driver_id}"], 'offer.accepted', ['ride_id' => $ride->uuid, 'trip_id' => $trip->uuid]);
        $losers = $ride->dispatches->pluck('driver_id')->reject(fn ($id) => $id === $trip->driver_id)->map(fn ($id) => "driver.{$id}")->values()->all();
        RealtimeEvent::send($losers, 'ride.closed', ['ride_id' => $ride->uuid, 'reason' => 'assigned_to_another_driver']);
        RealtimeEvent::send(['admin.operations'], 'trip.assigned', ['trip_id' => $trip->uuid]);

        $this->notifications->send($trip->customer, 'trip.assigned', 'Driver assigned',
            $trip->driver->firstName().' is on the way. Share your PIN only when you board.', ['trip_id' => $trip->uuid, 'screen' => 'trip']);
        $this->notifications->send($trip->driver, 'ride.accepted', 'Offer accepted',
            'Head to pickup: '.$ride->pickup_address, ['trip_id' => $trip->uuid, 'screen' => 'driver_trip']);
    }

    private function broadcastOffer(RideOffer $offer, string $event): void
    {
        $offer->loadMissing('rideRequest', 'driver.driverProfile', 'vehicle');
        $payload = ['offer' => (new RideOfferResource($offer))->resolve(), 'ride_id' => $offer->rideRequest->uuid];
        RealtimeEvent::send(["ride.{$offer->rideRequest->uuid}", "driver.{$offer->driver_id}"], $event, $payload);
    }

    private function assertDriverCanNegotiate(User $driver, RideRequest $ride): void
    {
        $driver->loadMissing('roles', 'driverProfile.primaryVehicle', 'driverProfile.documents');
        if (! $driver->isApprovedDriver()) {
            throw new BusinessRuleException('Your driver account is not approved.', 'driver_not_approved', 403);
        }
        if ($driver->active_mode !== 'driver' || ! $driver->driverProfile->is_online) {
            throw new BusinessRuleException('Go online in Driver mode to make offers.', 'driver_offline', 409);
        }
        if ($driver->id === $ride->customer_id) {
            throw new BusinessRuleException('You cannot make an offer on your own ride.', 'self_offer', 422);
        }
        $dispatched = fn () => RideDispatch::query()->where('ride_request_id', $ride->id)->where('driver_id', $driver->id)->whereIn('status', ['notified', 'seen', 'offered'])->exists();
        if (! $dispatched()) {
            app(DispatchService::class)->discoverForDriver($driver, true);
        }
        if (! $dispatched()) {
            throw new BusinessRuleException('This ride was not offered to you.', 'not_dispatched', 403);
        }
        if ($driver->activeTrip()) {
            throw new BusinessRuleException('Finish your current trip first.', 'driver_busy', 409);
        }
        if (! $ride->isOpen()) {
            throw new BusinessRuleException('This ride is no longer available.', 'ride_closed', 409);
        }
    }

    private function assertCustomerOwnsOffer(User $customer, RideOffer $offer): void
    {
        if ($offer->rideRequest->customer_id !== $customer->id) {
            throw new BusinessRuleException('Offer not found.', 'not_found', 404);
        }
    }
}
