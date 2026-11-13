<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'color' => $this->color,
            'year' => $this->year,
            'plate_number' => $this->plate_number,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'registration_expiry' => $this->registration_expiry?->toDateString(),
            'insurance_expiry' => $this->insurance_expiry?->toDateString(),
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]),
            'vehicle_category_id' => $this->vehicle_category_id,
        ];
    }
}
