<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'base_fare' => 'float',
            'per_km' => 'float',
            'per_minute' => 'float',
            'minimum_fare' => 'float',
            'booking_fee' => 'float',
            'waiting_per_minute' => 'float',
            'cancellation_fee' => 'float',
            'min_offer_ratio' => 'float',
            'max_offer_ratio' => 'float',
            'commission_percent' => 'float',
        ];
    }

    public function fareRules(): HasMany
    {
        return $this->hasMany(FareRule::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
