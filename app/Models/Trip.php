<?php

namespace App\Models;

use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Trip extends Model
{
    protected $guarded = ['id', 'active_driver_lock', 'active_customer_lock'];

    protected $hidden = ['pickup_pin', 'active_driver_lock', 'active_customer_lock'];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'pickup_pin' => 'encrypted',
            'fare_breakdown' => 'array',
            'agreed_fare' => 'float',
            'final_fare' => 'float',
            'discount_amount' => 'float',
            'waiting_charge' => 'float',
            'cancellation_fee' => 'float',
            'amount_due' => 'float',
            'commission_rate' => 'float',
            'commission_amount' => 'float',
            'driver_earning' => 'float',
            'assigned_at' => 'datetime',
            'en_route_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'share_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(RideOffer::class, 'ride_offer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class)->orderBy('id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function farePayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('purpose', 'fare');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TripMessage::class);
    }

    public function involves(User $user): bool
    {
        return $user->id === $this->customer_id || $user->id === $this->driver_id;
    }

    public function roleOf(User $user): ?string
    {
        return match ($user->id) {
            $this->customer_id => 'customer',
            $this->driver_id => 'driver',
            default => null,
        };
    }
}
