<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideDispatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
