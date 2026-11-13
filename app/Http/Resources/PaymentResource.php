<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'trip_id' => $this->trip?->uuid,
            'purpose' => $this->purpose,
            'amount' => $this->amount,
            'refunded_amount' => $this->refunded_amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'route' => $this->trip ? ['from' => $this->trip->rideRequest?->pickup_address, 'to' => $this->trip->rideRequest?->dropoff_address] : null,
        ];
    }
}
