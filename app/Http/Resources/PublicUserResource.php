<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Minimal counterpart view (no phone numbers or personal data). */
class PublicUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'first_name' => $this->firstName(),
            'photo_url' => $this->photoUrl(),
            'rating' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
        ];
    }
}
