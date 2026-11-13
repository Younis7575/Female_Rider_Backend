<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'trip_id' => $this->trip?->uuid, 'amount' => $this->amount, 'reason' => $this->reason, 'status' => $this->status, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
