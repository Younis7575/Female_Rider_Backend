<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DriverDeposit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'approved_amount' => 'float', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->uuid,
            'amount' => $this->amount,
            'approved_amount' => $this->approved_amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'transaction_ref' => $this->transaction_ref,
            'sender_number' => $this->sender_number,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'note' => $this->driver_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
