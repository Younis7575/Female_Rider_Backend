<?php

namespace App\Support;

final class Geo
{
    /** Great-circle distance in metres. */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** Rough ETA in seconds for urban traffic when no routing provider is available. */
    public static function etaSeconds(int $distanceM, float $avgKmh = 22): int
    {
        return (int) max(60, round(($distanceM / 1000) / $avgKmh * 3600));
    }
}
