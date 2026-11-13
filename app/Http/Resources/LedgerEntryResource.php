<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'type' => $this->entry_type, 'direction' => $this->direction, 'amount' => $this->amount, 'currency' => $this->currency, 'description' => $this->description, 'trip_id' => $this->trip?->uuid, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
