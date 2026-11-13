<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideOffer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'amount' => 'float',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_offer_id');
    }

    public function isLive(): bool
    {
        return $this->status === OfferStatus::Pending && $this->expires_at->isFuture();
    }
}
