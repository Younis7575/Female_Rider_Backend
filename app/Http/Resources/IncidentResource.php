<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'reference' => $this->reference, 'type' => $this->type, 'severity' => $this->severity, 'status' => $this->status, 'description' => $this->description, 'trip_id' => $this->trip?->uuid, 'resolution' => $this->resolution, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
