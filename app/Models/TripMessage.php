<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TripMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Wire format shared by the REST API and the realtime event. */
    public function toPayload(): array
    {
        return [
            'id' => $this->uuid,
            'seq' => $this->id,
            'trip_id' => $this->trip?->uuid,
            'sender_role' => $this->sender_role,
            'body' => $this->body,
            'client_id' => $this->client_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
