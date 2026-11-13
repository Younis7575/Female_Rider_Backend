<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DriverProfile extends Model
{
    /** Documents that must be approved and unexpired before a driver can go online. */
    public const REQUIRED_DOCUMENTS = [
        'cnic_front', 'cnic_back', 'license_front', 'license_back',
        'vehicle_registration', 'profile_photo', 'vehicle_photo_front', 'vehicle_photo_back',
    ];

    public const OPTIONAL_DOCUMENTS = ['insurance', 'fitness_certificate', 'vehicle_photo_interior', 'police_clearance'];

    protected $fillable = [
        'full_name', 'cnic_number', 'date_of_birth', 'license_number', 'license_expiry', 'address',
        'emergency_contact_name', 'emergency_contact_phone', 'service_zone_id',
        'payout_method', 'payout_account_title', 'payout_account_number',
    ];

    protected $hidden = ['review_notes', 'cnic_number', 'license_number', 'payout_account_number', 'emergency_contact_phone'];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'cnic_number' => 'encrypted',
            'license_number' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
            'payout_account_number' => 'encrypted',
            'date_of_birth' => 'date',
            'license_expiry' => 'date',
            'agreement_accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_online_at' => 'datetime',
            'is_online' => 'boolean',
            'rating_avg' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function primaryVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->where('is_primary', true)->latestOfMany();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function location(): HasOne
    {
        return $this->hasOne(DriverLocation::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_id');
    }

    public function onlineSessions(): HasMany
    {
        return $this->hasMany(DriverOnlineSession::class);
    }

    /** Latest document per type. */
    public function latestDocuments()
    {
        return $this->documents()->get()->sortByDesc('id')->unique('type')->values();
    }

    /**
     * Returns the list of reasons (empty = compliant) blocking this driver from going online.
     *
     * @return array<int, string>
     */
    public function complianceIssues(): array
    {
        $issues = [];
        if ($this->status !== DriverStatus::Approved) {
            $issues[] = 'Driver application is '.$this->status->label().'.';
        }
        if ($this->license_expiry && $this->license_expiry->isPast()) {
            $issues[] = 'Driving licence has expired.';
        }
        $docs = $this->latestDocuments()->keyBy('type');
        foreach (self::REQUIRED_DOCUMENTS as $type) {
            $doc = $docs->get($type);
            if (! $doc || $doc->status !== 'approved') {
                $issues[] = 'Document '.str_replace('_', ' ', $type).' is not approved.';
            } elseif ($doc->expiry_date && $doc->expiry_date->isPast()) {
                $issues[] = 'Document '.str_replace('_', ' ', $type).' has expired.';
            }
        }
        $vehicle = $this->primaryVehicle;
        if (! $vehicle || $vehicle->status !== 'approved') {
            $issues[] = 'Vehicle is not approved.';
        }

        return $issues;
    }
}
