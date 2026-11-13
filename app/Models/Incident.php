<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Incident extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['restricted_notes'];

    protected function casts(): array
    {
        return [
            'restricted_notes' => 'encrypted',
            'lat' => 'float',
            'lng' => 'float',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            $i->uuid ??= (string) Str::uuid();
            $i->reference ??= 'INC-'.strtoupper(Str::random(8));
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function againstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class);
    }
}
