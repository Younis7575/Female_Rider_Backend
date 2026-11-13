<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedPlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'name' => $this->name, 'address' => $this->address, 'lat' => $this->lat, 'lng' => $this->lng];
    }
}
