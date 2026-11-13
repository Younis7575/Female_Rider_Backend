<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label(),
            'status' => $this->status,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => (bool) $this->expiry_date?->isPast(),
            'rejection_reason' => $this->rejection_reason,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
