<?php

namespace App\Models;

use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class RideRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RideStatus::class,
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'suggested_fare' => 'float',
            'min_fare' => 'float',
            'max_fare' => 'float',
            'proposed_fare' => 'float',
            'initial_proposed_fare' => 'float',
            'discount_amount' => 'float',
            'surge_multiplier' => 'float',
            'search_radius_km' => 'float',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(RideDispatch::class);
    }

    public function trip(): HasOne
    {
        return $this->hasOne(Trip::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen() && $this->expires_at->isFuture();
    }

    /** Approximate area shown to drivers before acceptance (privacy). */
    public static function areaName(string $address): string
    {
        $parts = array_map('trim', explode(',', $address));

        return count($parts) > 2 ? implode(', ', array_slice($parts, -3, 2)) : ($parts[count($parts) - 1] ?? $address);
    }
}
