<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceZone extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'is_active' => 'boolean',
            'surge_enabled' => 'boolean',
            'surge_multiplier' => 'float',
            'surge_cap' => 'float',
            'center_lat' => 'float',
            'center_lng' => 'float',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Ray-casting point-in-polygon test. Polygon is [[lat,lng], ...]. */
    public function contains(float $lat, float $lng): bool
    {
        $poly = $this->polygon ?? [];
        $n = count($poly);
        if ($n < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = [(float) $poly[$i][0], (float) $poly[$i][1]];
            [$yj, $xj] = [(float) $poly[$j][0], (float) $poly[$j][1]];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    public function effectiveSurge(): float
    {
        if (! $this->surge_enabled || ! SystemSetting::get('surge.enabled', true)) {
            return 1.0;
        }

        return round(min(max(1.0, $this->surge_multiplier), $this->surge_cap), 2);
    }

    public static function locate(float $lat, float $lng): ?self
    {
        return static::query()->where('is_active', true)
            ->whereHas('city', fn ($q) => $q->where('is_active', true))
            ->get()
            ->first(fn (self $z) => $z->contains($lat, $lng));
    }
}
