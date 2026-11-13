<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverOnlineSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['online_at' => 'datetime', 'offline_at' => 'datetime'];
    }
}
