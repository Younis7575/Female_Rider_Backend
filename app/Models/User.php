<?php

namespace App\Models;

use App\Enums\DriverStatus;
use App\Enums\EligibilityStatus;
use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A FemaleRider mobile account. The same identity is used for Customer and Driver modes;
 * driver privileges live in DriverProfile + UserRole and require separate approval.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'country_code', 'phone', 'name', 'email', 'photo_path', 'locale',
        'emergency_contact_name', 'emergency_contact_phone',
        'notification_preferences', 'privacy_preferences',
    ];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'eligibility_status' => EligibilityStatus::class,
            'emergency_contact_name' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
            'notification_preferences' => 'array',
            'privacy_preferences' => 'array',
            'rating_avg' => 'decimal:2',
            'phone_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    // ---- Relations -------------------------------------------------------

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function eligibilityVerifications(): HasMany
    {
        return $this->hasMany(EligibilityVerification::class)->latest('id');
    }

    public function latestEligibility(): HasOne
    {
        return $this->hasOne(EligibilityVerification::class)->latestOfMany();
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function savedPlaces(): HasMany
    {
        return $this->hasMany(SavedPlace::class);
    }

    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class, 'customer_id');
    }

    public function customerTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'customer_id');
    }

    public function driverTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'ratee_id');
    }

    // ---- Domain helpers --------------------------------------------------

    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    public function isEligible(): bool
    {
        return $this->eligibility_status === EligibilityStatus::Approved;
    }

    public function hasActiveRole(string $role): bool
    {
        return $this->roles->where('role', $role)->where('status', 'active')->isNotEmpty();
    }

    public function canUseCustomerMode(): bool
    {
        return $this->isActive() && $this->isEligible() && $this->hasActiveRole('customer');
    }

    public function isApprovedDriver(): bool
    {
        return $this->isActive()
            && $this->isEligible()
            && $this->hasActiveRole('driver')
            && $this->driverProfile?->status === DriverStatus::Approved;
    }

    public function activeTrip(): ?Trip
    {
        return Trip::query()
            ->where(fn ($q) => $q->where('customer_id', $this->id)->orWhere('driver_id', $this->id))
            ->whereIn('status', TripStatus::activeValues())
            ->latest('id')
            ->first();
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function maskedPhone(): string
    {
        return substr($this->phone, 0, 4).str_repeat('•', max(0, strlen($this->phone) - 7)).substr($this->phone, -3);
    }

    public function displayName(): string
    {
        return $this->name ?: 'FemaleRider member';
    }

    public function firstName(): string
    {
        return Str::before($this->displayName(), ' ');
    }
}
