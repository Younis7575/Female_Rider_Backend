<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Files are stored on the private "local" disk and are never publicly addressable. */
class DriverDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'reviewed_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
        ];
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_id');
    }

    public const LABELS = [
        'cnic_front' => 'CNIC (front)', 'cnic_back' => 'CNIC (back)',
        'license_front' => 'Driving licence (front)', 'license_back' => 'Driving licence (back)',
        'vehicle_registration' => 'Vehicle registration', 'profile_photo' => 'Profile photo',
        'vehicle_photo_front' => 'Vehicle photo (front)', 'vehicle_photo_back' => 'Vehicle photo (back)',
        'vehicle_photo_interior' => 'Vehicle photo (interior)', 'insurance' => 'Insurance certificate',
        'fitness_certificate' => 'Fitness certificate', 'police_clearance' => 'Police clearance certificate',
    ];

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucwords(str_replace('_', ' ', $this->type));
    }
}
