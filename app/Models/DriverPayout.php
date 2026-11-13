<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DriverPayout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'maker_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'checker_id');
    }
}
