<?php

namespace App\Services\Trips;

use App\Enums\TripStatus;
use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\SystemSetting;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripLocation;
use App\Models\User;
use App\Services\Dispatch\DriverPresenceService;
use App\Services\Finance\CouponService;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PaymentService;
use App\Services\Notifications\NotificationService;
use App\Support\Geo;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-authoritative trip execution: en route → arrived → PIN-verified start → completion,
 * cancellation policies, live location and trip sharing.
 */
class TripService
{
    public function __construct(
        private DriverPresenceService $presence,
        private NotificationService $notifications,
        private CouponService $coupons,
        private PaymentService $payments,
    ) {}

    public function markEnRoute(User $driver, Trip $trip): Trip
    {
        $this->assertDriver($driver, $trip);

        return $this->transition($trip, TripStatus::DriverEnRoute, 'driver', $driver->id, ['en_route_at' => now()]);
    }

    public function arrive(User $driver, Trip $trip): Trip
    {
        $this->assertDriver($driver, $trip);
        $meta = [];
        if ($loc = $this->presence->get($driver->id)) {
            $ride = $trip->rideRequest;
            $meta['distance_from_pickup_m'] = Geo::distanceM($loc['lat'], $loc['lng'], $ride->pickup_lat, $ride->pickup_lng);
            if (SystemSetting::get('trip.enforce_arrival_radius', false) && $meta['distance_from_pickup_m'] > config('femalerider.trip.arrival_radius_m')) {
                throw new BusinessRuleException('You are too far from the pickup point to mark arrival.', 'not_at_pickup', 422, $meta);
            }
        }
        $trip = $this->transition($trip, TripStatus::Arrived, 'driver', $driver->id, ['arrived_at' => now()], $meta);
        $this->notifications->send($trip->customer, 'trip.arrived', 'Your driver has arrived',
            'Meet '.$driver->firstName().' at the pickup point. Verify the vehicle before boarding.', ['trip_id' => $trip->uuid, 'screen' => 'trip']);

        return $trip;
    }

    /** Start requires the customer's pickup PIN (prevents wrong-passenger / wrong-trip starts). */
    public function start(User $driver, Trip $trip, string $pin): Trip
    {
        $this->assertDriver($driver, $trip);

        // Returns the started trip, or an array describing a failed PIN attempt. Failed attempts are
        // committed (not rolled back) so the lockout cannot be bypassed by retrying.
        $result = DB::transaction(function () use ($driver, $trip, $pin) {
            $trip = Trip::query()->lockForUpdate()->findOrFail($trip->id);
            if ($trip->status !== TripStatus::Arrived) {
                throw new BusinessRuleException('Mark arrival at pickup before starting the trip.', 'invalid_transition', 409);
            }
            if ($trip->pin_attempts >= config('femalerider.trip.max_pin_attempts')) {
                throw new BusinessRuleException('Too many incorrect PIN attempts. Contact support.', 'pin_locked', 423);
            }
            if (! hash_equals((string) $trip->pickup_pin, trim($pin))) {
                $trip->increment('pin_attempts');
                TripEvent::query()->create(['trip_id' => $trip->id, 'event' => 'trip.pin_failed', 'actor_type' => 'driver', 'actor_id' => $driver->id, 'meta' => ['attempts' => $trip->pin_attempts]]);

                return ['attempts_left' => max(0, config('femalerider.trip.max_pin_attempts') - $trip->pin_attempts)];
            }

            $trip = $this->transition($trip, TripStatus::InProgress, 'driver', $driver->id, ['started_at' => now()], [], false);
            DB::afterCommit(fn () => $this->notifications->send($trip->customer, 'trip.started', 'Trip started', 'Enjoy your ride. SOS is always available in the app.', ['trip_id' => $trip->uuid, 'screen' => 'trip']));

            return $trip;
        });

        if (is_array($result)) {
            throw new BusinessRuleException('Incorrect PIN. Ask the passenger for her 4-digit trip PIN.', 'pin_invalid', 422, $result);
        }

        return $result;
    }

    public function complete(User $driver, Trip $trip): Trip
    {
        $this->assertDriver($driver, $trip);

        $trip = DB::transaction(function () use ($driver, $trip) {
            $trip = Trip::query()->with('rideRequest', 'vehicleCategory')->lockForUpdate()->findOrFail($trip->id);
            if ($trip->status !== TripStatus::InProgress) {
                throw new BusinessRuleException($trip->status === TripStatus::Completed ? 'Trip is already completed.' : 'Trip has not started.', 'invalid_transition', 409);
            }

            $actualDistance = $this->measuredDistance($trip) ?: $trip->rideRequest->distance_m;
            $actualDuration = (int) $trip->started_at->diffInSeconds(now());

            // Waiting charge beyond free minutes between arrival and start.
            $waitMin = $trip->arrived_at ? max(0, ($trip->arrived_at->diffInSeconds($trip->started_at) / 60) - config('femalerider.trip.free_waiting_minutes')) : 0;
            $waiting = Money::round($waitMin * $trip->vehicleCategory->waiting_per_minute, 5);

            $final = round($trip->agreed_fare + $waiting, 2);
            $discount = $this->coupons->redeem($trip->rideRequest, $trip->id, $final);
            $commission = round($final * $trip->commission_rate / 100, 2);
            $earning = round($final - $commission, 2);

            $trip = $this->transition($trip, TripStatus::Completed, 'driver', $driver->id, [
                'completed_at' => now(),
                'final_fare' => $final,
                'waiting_charge' => $waiting,
                'discount_amount' => $discount,
                'amount_due' => round($final - $discount, 2),
                'commission_amount' => $commission,
                'driver_earning' => $earning,
                'actual_distance_m' => $actualDistance,
                'actual_duration_s' => $actualDuration,
                'fare_breakdown' => [
                    'agreed_fare' => $trip->agreed_fare,
                    'waiting_minutes' => round($waitMin, 1),
                    'waiting_charge' => $waiting,
                    'total_fare' => $final,
                    'discount' => $discount,
                    'amount_due' => round($final - $discount, 2),
                    'commission_rate' => $trip->commission_rate,
                    'commission' => $commission,
                    'driver_earning' => $earning,
                    'currency' => $trip->currency,
                ],
                'share_expires_at' => now(),
            ], [], false);

            $this->payments->createForTrip($trip);
            $trip->driver->driverProfile()->increment('completed_trips');

            return $trip;
        });

        $this->presence->markAvailable($driver->driverProfile->fresh());
        $this->notifications->send($trip->customer, 'trip.completed', 'Trip completed',
            'Amount due: '.$trip->currency.' '.number_format($trip->amount_due).'. Please rate your driver.', ['trip_id' => $trip->uuid, 'screen' => 'trip_receipt']);

        return $trip;
    }

    public function cancel(User|AdminUser $actor, Trip $trip, ?string $reason = null): Trip
    {
        $role = $actor instanceof AdminUser ? 'admin' : $trip->roleOf($actor);
        if (! $role) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        $target = match ($role) {
            'customer' => TripStatus::CancelledByCustomer,
            'driver' => TripStatus::CancelledByDriver,
            default => TripStatus::CancelledByAdmin,
        };

        $trip = DB::transaction(function () use ($trip, $target, $role, $actor, $reason) {
            $trip = Trip::query()->with('vehicleCategory', 'rideRequest')->lockForUpdate()->findOrFail($trip->id);
            if (! $trip->status->canTransitionTo($target)) {
                throw new BusinessRuleException('This trip can no longer be cancelled. Contact support or use SOS if you feel unsafe.', 'invalid_transition', 409);
            }

            // Cancellation policy: customer pays a fee if cancelling after driver arrival or after the free window.
            $fee = 0.0;
            if ($role === 'customer' && ($trip->status === TripStatus::Arrived || $trip->assigned_at->diffInSeconds(now()) > config('femalerider.trip.customer_free_cancel_seconds'))) {
                $fee = (float) $trip->vehicleCategory->cancellation_fee;
            }

            $trip = $this->transition($trip, $target, $role, $actor->id, [
                'cancelled_at' => now(), 'cancelled_by' => $role, 'cancel_reason' => $reason, 'cancellation_fee' => $fee,
                'payment_status' => $fee > 0 ? 'pending' : 'not_applicable',
            ], ['reason' => $reason, 'fee' => $fee], false);

            $this->coupons->release($trip->rideRequest);
            if ($role === 'driver') {
                $trip->driver->driverProfile()->increment('cancelled_trips');
            }
            if ($fee > 0) {
                $this->payments->createCancellationFee($trip, $fee);
            }

            return $trip;
        });

        $this->presence->markAvailable($trip->driver->driverProfile);
        $other = $role === 'customer' ? $trip->driver : ($role === 'driver' ? $trip->customer : null);
        foreach (array_filter([$other, $role === 'admin' ? $trip->driver : null, $role === 'admin' ? $trip->customer : null]) as $u) {
            $this->notifications->send($u, 'trip.cancelled', 'Trip cancelled', 'Your trip was cancelled'.($reason ? ": {$reason}" : '.'), ['trip_id' => $trip->uuid, 'screen' => 'home']);
        }

        return $trip;
    }

    public function recordLocation(User $user, Trip $trip, array $ping): void
    {
        $role = $trip->roleOf($user);
        if (! $role || ! $trip->status->isActive()) {
            return;
        }
        $last = TripLocation::query()->where('trip_id', $trip->id)->where('user_id', $user->id)->latest('id')->first();
        if ($last && isset($ping['sequence']) && $ping['sequence'] <= $last->sequence) {
            return; // out of order
        }

        TripLocation::query()->create([
            'trip_id' => $trip->id, 'user_id' => $user->id,
            'lat' => $ping['lat'], 'lng' => $ping['lng'],
            'accuracy' => $ping['accuracy'] ?? null, 'heading' => $ping['heading'] ?? null, 'speed' => $ping['speed'] ?? null,
            'sequence' => $ping['sequence'] ?? 0,
            'recorded_at' => $ping['recorded_at'] ?? now(),
        ]);

        if ($role === 'driver') {
            $payload = ['trip_id' => $trip->uuid, 'lat' => $ping['lat'], 'lng' => $ping['lng'], 'heading' => $ping['heading'] ?? null, 'recorded_at' => now()->toIso8601String()];
            if (in_array($trip->status, [TripStatus::DriverAssigned, TripStatus::DriverEnRoute], true)) {
                $ride = $trip->rideRequest;
                $d = Geo::distanceM($ping['lat'], $ping['lng'], $ride->pickup_lat, $ride->pickup_lng);
                $payload['pickup_eta_s'] = Geo::etaSeconds($d);
                $payload['pickup_distance_m'] = $d;
            }
            RealtimeEvent::send(["trip.{$trip->uuid}"], 'trip.location', $payload);
        }
    }

    /** Create (or reuse) an expiring, revocable share link with minimal exposed data. */
    public function share(User $user, Trip $trip): array
    {
        if ($trip->customer_id !== $user->id && $trip->driver_id !== $user->id) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        if (! $trip->status->isActive()) {
            throw new BusinessRuleException('Only active trips can be shared.', 'trip_not_active', 409);
        }
        if (! $trip->share_token || $trip->share_expires_at?->isPast()) {
            $trip->forceFill([
                'share_token' => Str::random(40),
                'share_expires_at' => now()->addHours((int) config('femalerider.trip.share_link_ttl_hours')),
            ])->save();
        }

        return ['url' => route('website.trip.share', $trip->share_token), 'expires_at' => $trip->share_expires_at->toIso8601String()];
    }

    public function revokeShare(User $user, Trip $trip): void
    {
        if (! $trip->involves($user)) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        $trip->forceFill(['share_token' => null, 'share_expires_at' => null])->save();
    }

    public function transition(Trip $trip, TripStatus $to, string $actorType, ?int $actorId, array $attributes = [], array $meta = [], bool $withLock = true): Trip
    {
        $apply = function () use ($trip, $to, $actorType, $actorId, $attributes, $meta) {
            $trip = Trip::query()->lockForUpdate()->findOrFail($trip->id);
            $from = $trip->status;
            if (! $from->canTransitionTo($to)) {
                throw new BusinessRuleException("Cannot change trip from {$from->label()} to {$to->label()}.", 'invalid_transition', 409);
            }
            $trip->forceFill(array_merge($attributes, ['status' => $to]))->save();
            TripEvent::query()->create([
                'trip_id' => $trip->id, 'ride_request_id' => $trip->ride_request_id,
                'event' => 'trip.'.$to->value, 'from_status' => $from->value, 'to_status' => $to->value,
                'actor_type' => $actorType, 'actor_id' => $actorId, 'meta' => $meta ?: null,
            ]);

            DB::afterCommit(fn () => RealtimeEvent::send(
                ["trip.{$trip->uuid}", "user.{$trip->customer_id}", "driver.{$trip->driver_id}", 'admin.operations'],
                'trip.updated',
                ['trip_id' => $trip->uuid, 'status' => $to->value, 'from_status' => $from->value],
            ));

            return $trip;
        };

        return $withLock ? DB::transaction($apply) : $apply();
    }

    /**
     * Authorized post-trip fare adjustment (dispute resolution). Posts a balanced ledger
     * adjustment for the delta and re-computes commission. Requires a reason; audited.
     */
    public function adjustFare(AdminUser $admin, Trip $trip, float $newFare, string $reason): Trip
    {
        return DB::transaction(function () use ($admin, $trip, $newFare, $reason) {
            $trip = Trip::query()->lockForUpdate()->findOrFail($trip->id);
            if (! in_array($trip->status, [TripStatus::Completed, TripStatus::Disputed], true)) {
                throw new BusinessRuleException('Only completed or disputed trips can be adjusted.', 'invalid_state', 409);
            }
            $newFare = round($newFare, 2);
            if ($newFare < 0 || $newFare > $trip->final_fare * 3) {
                throw new BusinessRuleException('Adjusted fare is outside the permitted range.', 'fare_out_of_bounds');
            }
            $delta = round($newFare - $trip->final_fare, 2);
            if ($delta == 0.0) {
                return $trip;
            }
            $newCommission = round($newFare * $trip->commission_rate / 100, 2);
            $commissionDelta = round($newCommission - $trip->commission_amount, 2);
            $before = $trip->only(['final_fare', 'amount_due', 'commission_amount', 'driver_earning']);

            $ledger = app(LedgerService::class);
            $abs = abs($delta);
            $ledger->post($delta > 0 ? [
                ['account' => 'customer_receivable', 'direction' => 'debit', 'amount' => $abs, 'user_id' => $trip->customer_id],
                ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => $abs, 'user_id' => $trip->driver_id],
            ] : [
                ['account' => 'driver_payable', 'direction' => 'debit', 'amount' => $abs, 'user_id' => $trip->driver_id],
                ['account' => 'customer_receivable', 'direction' => 'credit', 'amount' => $abs, 'user_id' => $trip->customer_id],
            ], 'adjustment', "Fare adjustment on trip {$trip->uuid}: {$reason}", ['trip_id' => $trip->id], $trip->currency, 'admin', $admin->id);
            $cAbs = abs($commissionDelta);
            $ledger->post($commissionDelta > 0 ? [
                ['account' => 'driver_payable', 'direction' => 'debit', 'amount' => $cAbs, 'user_id' => $trip->driver_id],
                ['account' => 'platform_revenue', 'direction' => 'credit', 'amount' => $cAbs],
            ] : [
                ['account' => 'platform_revenue', 'direction' => 'debit', 'amount' => $cAbs],
                ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => $cAbs, 'user_id' => $trip->driver_id],
            ], 'adjustment', "Commission adjustment on trip {$trip->uuid}", ['trip_id' => $trip->id], $trip->currency, 'admin', $admin->id);

            $breakdown = $trip->fare_breakdown ?? [];
            $breakdown['adjustments'][] = ['from' => $trip->final_fare, 'to' => $newFare, 'reason' => $reason, 'by' => $admin->name, 'at' => now()->toIso8601String()];
            $trip->forceFill([
                'final_fare' => $newFare,
                'amount_due' => max(0, round($newFare - $trip->discount_amount, 2)),
                'commission_amount' => $newCommission,
                'driver_earning' => round($newFare - $newCommission, 2),
                'fare_breakdown' => $breakdown,
            ])->save();
            Commission::query()->where('trip_id', $trip->id)->update(['gross_fare' => $newFare, 'amount' => $newCommission]);
            TripEvent::query()->create(['trip_id' => $trip->id, 'event' => 'trip.fare_adjusted', 'actor_type' => 'admin', 'actor_id' => $admin->id, 'meta' => ['from' => $before['final_fare'], 'to' => $newFare, 'reason' => $reason]]);
            AuditLog::record('trip.fare_adjusted', $trip, $before, $trip->only(['final_fare', 'amount_due', 'commission_amount', 'driver_earning']), ['reason' => $reason]);

            return $trip;
        });
    }

    private function measuredDistance(Trip $trip): int
    {
        $points = TripLocation::query()->where('trip_id', $trip->id)->where('user_id', $trip->driver_id)
            ->where('recorded_at', '>=', $trip->started_at)->orderBy('recorded_at')->get(['lat', 'lng']);
        $total = 0;
        for ($i = 1; $i < $points->count(); $i++) {
            $total += Geo::distanceM($points[$i - 1]->lat, $points[$i - 1]->lng, $points[$i]->lat, $points[$i]->lng);
        }

        return $total;
    }

    private function assertDriver(User $driver, Trip $trip): void
    {
        if ($trip->driver_id !== $driver->id) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
    }
}
