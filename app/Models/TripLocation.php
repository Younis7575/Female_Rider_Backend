<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripLocation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'recorded_at' => 'datetime'];
    }
}
