<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Customer's own ride request view. */
class RideRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'pickup' => ['lat' => $this->pickup_lat, 'lng' => $this->pickup_lng, 'address' => $this->pickup_address],
            'dropoff' => ['lat' => $this->dropoff_lat, 'lng' => $this->dropoff_lng, 'address' => $this->dropoff_address],
            'pickup_note' => $this->pickup_note,
            'distance_m' => $this->distance_m,
            'duration_s' => $this->duration_s,
            'polyline' => $this->route_polyline,
            'suggested_fare' => $this->suggested_fare,
            'min_fare' => $this->min_fare,
            'max_fare' => $this->max_fare,
            'proposed_fare' => $this->proposed_fare,
            'discount_amount' => $this->discount_amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'vehicle_category' => $this->whenLoaded('vehicleCategory', fn () => ['id' => $this->vehicleCategory->id, 'name' => $this->vehicleCategory->name, 'slug' => $this->vehicleCategory->slug, 'icon' => $this->vehicleCategory->icon]),
            'drivers_notified' => $this->whenCounted('dispatches'),
            'search_radius_km' => $this->search_radius_km,
            'offers' => RideOfferResource::collection($this->whenLoaded('offers')),
            'trip_id' => $this->trip?->uuid,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
