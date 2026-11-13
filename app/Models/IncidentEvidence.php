<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvidence extends Model
{
    protected $table = 'incident_evidence';

    protected $guarded = ['id'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
