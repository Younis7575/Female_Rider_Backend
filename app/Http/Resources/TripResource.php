<?php

namespace App\Http\Resources;

use App\Enums\TripStatus;
use App\Services\Dispatch\DriverPresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Role-aware trip view: the PIN is only ever shown to the customer. */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $t = $this->resource;
        $t->loadMissing('rideRequest', 'customer', 'driver.driverProfile', 'vehicle', 'vehicleCategory', 'farePayment', 'ratings');
        $viewer = $request->user();
        $role = $viewer ? $t->roleOf($viewer) : null;
        $ride = $t->rideRequest;
        $active = $t->status->isActive();

        $driverLocation = null;
        if ($active && $role === 'customer') {
            $driverLocation = app(DriverPresenceService::class)->get($t->driver_id);
        }

        return [
            'id' => $t->uuid,
            'ride_id' => $ride->uuid,
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'viewer_role' => $role,
            'pickup' => ['lat' => $ride->pickup_lat, 'lng' => $ride->pickup_lng, 'address' => $ride->pickup_address, 'note' => $ride->pickup_note],
            'dropoff' => ['lat' => $ride->dropoff_lat, 'lng' => $ride->dropoff_lng, 'address' => $ride->dropoff_address],
            'distance_m' => $ride->distance_m,
            'duration_s' => $ride->duration_s,
            'polyline' => $ride->route_polyline,
            'pickup_pin' => $role === 'customer' && in_array($t->status, [TripStatus::DriverAssigned, TripStatus::DriverEnRoute, TripStatus::Arrived], true) ? $t->pickup_pin : null,
            'agreed_fare' => $t->agreed_fare,
            'final_fare' => $t->final_fare,
            'amount_due' => $t->amount_due,
            'discount_amount' => $t->discount_amount,
            'cancellation_fee' => $t->cancellation_fee,
            'commission_amount' => $role === 'driver' ? $t->commission_amount : null,
            'driver_earning' => $role === 'driver' ? $t->driver_earning : null,
            'fare_breakdown' => $t->fare_breakdown,
            'currency' => $t->currency,
            'payment_method' => $t->payment_method,
            'payment_status' => $t->payment_status,
            'vehicle_category' => $t->vehicleCategory?->name,
            'vehicle_category_slug' => $t->vehicleCategory?->slug,
            'cancellation_policy' => $active ? [
                'fee' => (float) ($t->vehicleCategory?->cancellation_fee ?? 0),
                'free_window_seconds' => (int) config('femalerider.trip.customer_free_cancel_seconds'),
                'free_until' => $t->assigned_at?->copy()->addSeconds((int) config('femalerider.trip.customer_free_cancel_seconds'))->toIso8601String(),
                'fee_applies_now' => $role === 'customer' && ($t->status === TripStatus::Arrived || $t->assigned_at?->diffInSeconds(now()) > config('femalerider.trip.customer_free_cancel_seconds')),
            ] : null,
            'driver' => array_merge((new PublicUserResource($t->driver))->resolve(), [
                'rating' => (float) ($t->driver->driverProfile?->rating_avg ?? 5),
                'completed_trips' => (int) $t->driver->driverProfile?->completed_trips,
            ]),
            'customer' => (new PublicUserResource($t->customer))->resolve(),
            'vehicle' => $t->vehicle ? (new VehicleResource($t->vehicle))->resolve() : null,
            'driver_location' => $driverLocation,
            'chat' => $role ? [
                'enabled' => $active,
                'unread' => $t->messages()->where('sender_id', '!=', $viewer->id)->whereNull('read_at')->count(),
            ] : null,
            'my_rating' => optional($t->ratings->firstWhere('rater_id', $viewer?->id))->score,
            'timeline' => [
                'assigned_at' => $t->assigned_at?->toIso8601String(),
                'en_route_at' => $t->en_route_at?->toIso8601String(),
                'arrived_at' => $t->arrived_at?->toIso8601String(),
                'started_at' => $t->started_at?->toIso8601String(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                'cancelled_at' => $t->cancelled_at?->toIso8601String(),
            ],
            'cancelled_by' => $t->cancelled_by,
            'cancel_reason' => $t->cancel_reason,
            'share' => $t->share_token && $t->share_expires_at?->isFuture() ? ['url' => route('website.trip.share', $t->share_token), 'expires_at' => $t->share_expires_at->toIso8601String()] : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
