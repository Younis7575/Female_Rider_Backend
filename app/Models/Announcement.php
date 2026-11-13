<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['send_push' => 'boolean', 'sent_at' => 'datetime'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
