<?php

namespace App\Services\Dispatch;

use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Support\Geo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Live driver presence & location.
 *
 * Redis (primary, ephemeral):
 *   fr:drivers:geo            GEO set of online drivers (member = driver user id)
 *   fr:driver:{userId}        HASH last ping (lat, lng, heading, category, zone, seq, ts) with TTL heartbeat
 * SQL (durable snapshot, throttled): driver_locations — used for admin map and as fallback
 * when Redis is unavailable.
 */
class DriverPresenceService
{
    private const GEO_KEY = 'fr:drivers:geo';

    private const SQL_SNAPSHOT_SECONDS = 15;

    private function usesRedis(): bool
    {
        return config('femalerider.dispatch.presence_driver', 'redis') !== 'sql';
    }

    public function ttl(): int
    {
        return (int) config('femalerider.dispatch.driver_presence_ttl_seconds');
    }

    /**
     * Store a location ping. Returns false if the ping is stale/out-of-order and was ignored.
     */
    public function update(DriverProfile $driver, float $lat, float $lng, array $meta = []): bool
    {
        $seq = (int) ($meta['sequence'] ?? 0);
        $recordedAt = isset($meta['recorded_at']) ? Carbon::parse($meta['recorded_at']) : now();

        // A stationary phone may resend an older (but still valid) fix, so only reject fixes that are
        // very old or from the future. Presence freshness is measured by when the server receives the
        // heartbeat (ts), not by the age of the GPS fix (fix_at).
        if ($recordedAt->lt(now()->subMinutes(15)) || $recordedAt->gt(now()->addMinute())) {
            return false;
        }

        $vehicle = $driver->primaryVehicle;
        $hashKey = "fr:driver:{$driver->user_id}";

        $snapshot = DriverLocation::query()->firstOrNew(['driver_profile_id' => $driver->id]);

        if (! $this->usesRedis()) {
            // SQL mode: the snapshot *is* the live presence, so write every accepted ping.
            if ($seq > 0 && $snapshot->exists && (int) $snapshot->sequence > 0 && $seq <= (int) $snapshot->sequence
                && $snapshot->recorded_at?->gt(now()->subSeconds($this->ttl()))) {
                return false; // out-of-order
            }
            $snapshot->fill([
                'lat' => $lat, 'lng' => $lng,
                'heading' => $meta['heading'] ?? null, 'speed' => $meta['speed'] ?? null,
                'accuracy' => $meta['accuracy'] ?? null, 'sequence' => $seq, 'recorded_at' => now(),
            ])->save();

            return true;
        }

        try {
            $prevSeq = (int) (Redis::hget($hashKey, 'seq') ?? 0);
            if ($seq > 0 && $prevSeq > 0 && $seq <= $prevSeq) {
                return false; // out-of-order
            }
            Redis::hmset($hashKey, [
                'lat' => $lat, 'lng' => $lng,
                'heading' => $meta['heading'] ?? '', 'speed' => $meta['speed'] ?? '',
                'accuracy' => $meta['accuracy'] ?? '',
                'category' => $vehicle?->vehicle_category_id ?? '',
                'zone' => $driver->service_zone_id ?? '',
                'seq' => $seq, 'ts' => now()->timestamp, 'fix_at' => $recordedAt->timestamp,
            ]);
            Redis::expire($hashKey, $this->ttl());
            if ($driver->is_online) {
                Redis::geoadd(self::GEO_KEY, $lng, $lat, (string) $driver->user_id);
            }
        } catch (\Throwable $e) {
            Log::warning('Redis presence write failed; SQL snapshot only', ['error' => $e->getMessage()]);
        }

        if (! $snapshot->exists || $snapshot->recorded_at?->lt(now()->subSeconds(self::SQL_SNAPSHOT_SECONDS)) || ($meta['force_snapshot'] ?? false)) {
            $snapshot->fill([
                'lat' => $lat, 'lng' => $lng,
                'heading' => $meta['heading'] ?? null, 'speed' => $meta['speed'] ?? null,
                'accuracy' => $meta['accuracy'] ?? null, 'sequence' => $seq, 'recorded_at' => now(),
            ])->save();
        }

        return true;
    }

    public function goOffline(DriverProfile $driver): void
    {
        if (! $this->usesRedis()) {
            return; // SQL presence is filtered by is_online
        }
        try {
            Redis::zrem(self::GEO_KEY, (string) $driver->user_id);
            Redis::del("fr:driver:{$driver->user_id}");
        } catch (\Throwable $e) {
            Log::warning('Redis presence remove failed', ['error' => $e->getMessage()]);
        }
    }

    /** Temporarily remove from the dispatch pool (e.g. while on a trip) without going offline. */
    public function markBusy(int $driverUserId): void
    {
        if (! $this->usesRedis()) {
            return; // busy drivers are excluded by DispatchService::filterEligible
        }
        try {
            Redis::zrem(self::GEO_KEY, (string) $driverUserId);
        } catch (\Throwable) {
        }
    }

    public function markAvailable(DriverProfile $driver): void
    {
        if (! $this->usesRedis()) {
            return;
        }
        $loc = $this->get($driver->user_id) ?? ($driver->location ? ['lat' => $driver->location->lat, 'lng' => $driver->location->lng] : null);
        if ($loc && $driver->is_online) {
            try {
                Redis::geoadd(self::GEO_KEY, $loc['lng'], $loc['lat'], (string) $driver->user_id);
            } catch (\Throwable) {
            }
        }
    }

    /** @return array{lat:float,lng:float,heading:?float,ts:int}|null */
    public function get(int $driverUserId): ?array
    {
        if ($this->usesRedis()) {
            try {
                $h = Redis::hgetall("fr:driver:{$driverUserId}");
                if (! empty($h['lat'])) {
                    return [
                        'lat' => (float) $h['lat'], 'lng' => (float) $h['lng'],
                        'heading' => $h['heading'] !== '' ? (float) $h['heading'] : null,
                        'ts' => (int) $h['ts'],
                        'recorded_at' => Carbon::createFromTimestamp((int) $h['ts'])->toIso8601String(),
                    ];
                }
            } catch (\Throwable) {
            }
        }

        $loc = DriverLocation::query()->whereHas('driverProfile', fn ($q) => $q->where('user_id', $driverUserId))->first();

        return $loc ? ['lat' => $loc->lat, 'lng' => $loc->lng, 'heading' => $loc->heading, 'ts' => $loc->recorded_at->timestamp, 'recorded_at' => $loc->recorded_at->toIso8601String()] : null;
    }

    /**
     * Find candidate online drivers within a radius, nearest first.
     *
     * @return array<int, array{driver_id:int, distance_m:int}>
     */
    public function nearby(float $lat, float $lng, float $radiusKm, int $limit = 50): array
    {
        if (! $this->usesRedis()) {
            return $this->nearbyFromSql($lat, $lng, $radiusKm, $limit);
        }
        try {
            $raw = Redis::georadius(self::GEO_KEY, $lng, $lat, $radiusKm, 'km', ['withdist' => true, 'sort' => 'asc', 'count' => $limit * 2]);
            $staleBefore = now()->subSeconds($this->ttl())->timestamp;
            $out = [];
            foreach ($raw as $row) {
                [$member, $distKm] = is_array($row) ? [$row[0], (float) $row[1]] : [$row, 0.0];
                $ts = (int) (Redis::hget("fr:driver:{$member}", 'ts') ?? 0);
                if ($ts < $staleBefore) {
                    Redis::zrem(self::GEO_KEY, (string) $member); // stale presence cleanup

                    continue;
                }
                $out[] = ['driver_id' => (int) $member, 'distance_m' => (int) round($distKm * 1000)];
            }

            return array_slice($out, 0, $limit);
        } catch (\Throwable $e) {
            Log::warning('Redis GEO search failed; falling back to SQL', ['error' => $e->getMessage()]);
        }

        return $this->nearbyFromSql($lat, $lng, $radiusKm, $limit);
    }

    private function nearbyFromSql(float $lat, float $lng, float $radiusKm, int $limit): array
    {
        $delta = $radiusKm / 111;

        return DB::table('driver_locations')
            ->join('driver_profiles', 'driver_profiles.id', '=', 'driver_locations.driver_profile_id')
            ->where('driver_profiles.is_online', true)
            ->where('driver_locations.recorded_at', '>=', now()->subSeconds($this->ttl()))
            ->whereBetween('driver_locations.lat', [$lat - $delta, $lat + $delta])
            ->whereBetween('driver_locations.lng', [$lng - $delta * 1.2, $lng + $delta * 1.2])
            ->get(['driver_profiles.user_id', 'driver_locations.lat', 'driver_locations.lng'])
            ->map(fn ($r) => ['driver_id' => (int) $r->user_id, 'distance_m' => Geo::distanceM($lat, $lng, (float) $r->lat, (float) $r->lng)])
            ->filter(fn ($r) => $r['distance_m'] <= $radiusKm * 1000)
            ->sortBy('distance_m')->take($limit)->values()->all();
    }

    /** @return array<int, array{driver_id:int, lat:float, lng:float}> */
    public function allOnline(): array
    {
        if (! $this->usesRedis()) {
            return DB::table('driver_locations')
                ->join('driver_profiles', 'driver_profiles.id', '=', 'driver_locations.driver_profile_id')
                ->where('driver_profiles.is_online', true)
                ->where('driver_locations.recorded_at', '>=', now()->subSeconds($this->ttl()))
                ->get(['driver_profiles.user_id', 'driver_locations.lat', 'driver_locations.lng'])
                ->map(fn ($r) => ['driver_id' => (int) $r->user_id, 'lat' => (float) $r->lat, 'lng' => (float) $r->lng])
                ->all();
        }
        try {
            $members = Redis::zrange(self::GEO_KEY, 0, -1);
            if (! $members) {
                return [];
            }
            $positions = Redis::geopos(self::GEO_KEY, ...$members);

            return collect($members)->map(fn ($m, $i) => isset($positions[$i][0])
                ? ['driver_id' => (int) $m, 'lng' => (float) $positions[$i][0], 'lat' => (float) $positions[$i][1]]
                : null)->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
