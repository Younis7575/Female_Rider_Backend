<?php

namespace App\Models;

use App\Enums\EligibilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityVerification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['reviewer_notes'];

    protected function casts(): array
    {
        return [
            'status' => EligibilityStatus::class,
            'consented_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'appealed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_id');
    }
}
