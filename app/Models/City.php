<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'center_lat' => 'float', 'center_lng' => 'float'];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(ServiceZone::class);
    }
}
