<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('driver.driverProfile', 'vehicle');
        $driver = $this->driver;

        return [
            'id' => $this->id,
            'ride_id' => $this->rideRequest?->uuid,
            'amount' => $this->amount,
            'proposed_by' => $this->proposed_by,
            'status' => $this->status->value,
            'sequence' => $this->sequence,
            'parent_offer_id' => $this->parent_offer_id,
            'eta_s' => $this->eta_s,
            'distance_to_pickup_m' => $this->distance_to_pickup_m,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'driver' => $driver ? [
                'id' => $driver->uuid,
                'first_name' => $driver->firstName(),
                'photo_url' => $driver->photoUrl(),
                'rating' => (float) ($driver->driverProfile?->rating_avg ?? $driver->rating_avg),
                'completed_trips' => (int) $driver->driverProfile?->completed_trips,
            ] : null,
            'vehicle' => $this->vehicle ? [
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'color' => $this->vehicle->color,
                'plate_number' => $this->vehicle->plate_number,
            ] : null,
        ];
    }
}
