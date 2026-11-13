<?php

namespace App\Services\Maps;

use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Maps abstraction with a provider chain so the platform works without a card-backed Google account:
 *
 *   Routing:   Google Directions → OpenRouteService (key) → OSRM (self-hosted or public) → offline estimate
 *   Search:    Google Places     → Geoapify (key)         → Nominatim (public/self-hosted) → local landmarks
 *   Reverse:   Google Geocoding  → Geoapify (key)         → Nominatim → nearest landmark
 *
 * Every external call has a short timeout, is cached, and falls through to the next provider on failure,
 * so bookings never block on a third-party outage.
 */
class MapsService
{
    private const ROAD_FACTOR = 1.3;

    public function provider(): string
    {
        return match (true) {
            (bool) $this->googleKey() => 'google',
            (bool) config('services.osm.geoapify_key') || (bool) config('services.osm.openrouteservice_key') => 'openstreetmap',
            (bool) config('services.osm.allow_public') => 'openstreetmap-public',
            default => 'offline',
        };
    }

    private function googleKey(): ?string
    {
        return config('services.google_maps.server_key') ?: null;
    }

    private function osm(string $key): mixed
    {
        return config("services.osm.$key");
    }

    private function http()
    {
        // Force IPv4 and classic TLS key-exchange curves: some networks silently drop IPv6 routes and the
        // large post-quantum TLS ClientHello (X25519MLKEM768), which shows up as connection timeouts.
        return Http::timeout(8)->connectTimeout(4)
            ->withOptions(['force_ip_resolve' => 'v4', 'curl' => [CURLOPT_SSL_EC_CURVES => 'X25519:P-256:P-384']])
            ->withHeaders(['User-Agent' => $this->osm('user_agent'), 'Accept-Language' => 'en']);
    }

    // ---- Routing --------------------------------------------------------

    /** @return array{distance_m:int, duration_s:int, polyline:?string, provider:string} */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $cacheKey = 'route:'.md5(implode(',', array_map(fn ($v) => round($v, 4), [$fromLat, $fromLng, $toLat, $toLng])));
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        foreach (['googleRoute', 'orsRoute', 'osrmRoute'] as $method) {
            try {
                if ($result = $this->{$method}($fromLat, $fromLng, $toLat, $toLng)) {
                    Cache::put($cacheKey, $result, 600);

                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning("Maps {$method} failed", ['error' => $e->getMessage()]);
            }
        }

        $distance = (int) round(Geo::distanceM($fromLat, $fromLng, $toLat, $toLng) * self::ROAD_FACTOR);

        return ['distance_m' => max($distance, 100), 'duration_s' => Geo::etaSeconds($distance), 'polyline' => null, 'provider' => 'offline'];
    }

    private function googleRoute(float $fLat, float $fLng, float $tLat, float $tLng): ?array
    {
        if (! $this->googleKey()) {
            return null;
        }
        $res = $this->http()->get('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => "$fLat,$fLng", 'destination' => "$tLat,$tLng", 'mode' => 'driving', 'departure_time' => 'now',
            'region' => config('services.google_maps.region'), 'key' => $this->googleKey(),
        ]);
        $leg = $res->json('routes.0.legs.0');

        return $res->successful() && $leg ? [
            'distance_m' => (int) $leg['distance']['value'],
            'duration_s' => (int) ($leg['duration_in_traffic']['value'] ?? $leg['duration']['value']),
            'polyline' => $res->json('routes.0.overview_polyline.points'),
            'provider' => 'google',
        ] : null;
    }

    private function orsRoute(float $fLat, float $fLng, float $tLat, float $tLng): ?array
    {
        if (! ($key = $this->osm('openrouteservice_key'))) {
            return null;
        }
        $res = $this->http()->withHeaders(['Authorization' => $key])
            ->post('https://api.openrouteservice.org/v2/directions/driving-car/json', ['coordinates' => [[$fLng, $fLat], [$tLng, $tLat]]]);
        $route = $res->json('routes.0');

        return $res->successful() && $route ? [
            'distance_m' => (int) round($route['summary']['distance'] ?? 0),
            'duration_s' => (int) round($route['summary']['duration'] ?? 0),
            'polyline' => $route['geometry'] ?? null, // encoded polyline, precision 5 (Google-compatible)
            'provider' => 'openrouteservice',
        ] : null;
    }

    private function osrmRoute(float $fLat, float $fLng, float $tLat, float $tLng): ?array
    {
        $base = rtrim((string) $this->osm('osrm_url'), '/');
        $isPublic = str_contains($base, 'project-osrm.org');
        if (! $base || ($isPublic && ! $this->osm('allow_public'))) {
            return null;
        }
        $res = $this->http()->get("{$base}/route/v1/driving/{$fLng},{$fLat};{$tLng},{$tLat}", ['overview' => 'simplified', 'geometries' => 'polyline']);
        $route = $res->json('routes.0');

        return $res->successful() && $res->json('code') === 'Ok' && $route ? [
            'distance_m' => (int) round($route['distance']),
            // OSRM durations ignore traffic; pad 25% for urban Pakistan conditions.
            'duration_s' => (int) round($route['duration'] * 1.25),
            'polyline' => $route['geometry'] ?? null,
            'provider' => 'osrm',
        ] : null;
    }

    // ---- Search ---------------------------------------------------------

    /** @return array<int, array{name:string, address:string, lat:float, lng:float, place_id:?string}> */
    public function search(string $query, ?float $nearLat = null, ?float $nearLng = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $cacheKey = 'places:'.md5(mb_strtolower($query).'|'.round((float) $nearLat, 2).'|'.round((float) $nearLng, 2));
        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $local = $this->landmarkSearch($query, $nearLat, $nearLng);
        foreach (['googleSearch', 'geoapifySearch', 'nominatimSearch'] as $method) {
            try {
                $results = $this->{$method}($query, $nearLat, $nearLng);
                if ($results !== null) {
                    // Local curated landmarks first, then provider results (deduplicated by name).
                    $merged = collect($local)->concat($results)->unique(fn ($p) => mb_strtolower($p['name']))->take(10)->values()->all();
                    Cache::put($cacheKey, $merged, 86400);

                    return $merged;
                }
            } catch (\Throwable $e) {
                Log::warning("Maps {$method} failed", ['error' => $e->getMessage()]);
            }
        }

        return $local;
    }

    private function googleSearch(string $q, ?float $lat, ?float $lng): ?array
    {
        if (! $this->googleKey()) {
            return null;
        }
        $params = ['query' => $q, 'region' => config('services.google_maps.region'), 'key' => $this->googleKey()];
        if ($lat && $lng) {
            $params += ['location' => "$lat,$lng", 'radius' => 30000];
        }
        $res = $this->http()->get('https://maps.googleapis.com/maps/api/place/textsearch/json', $params);
        if (! $res->successful()) {
            return null;
        }

        return collect($res->json('results', []))->take(8)->map(fn ($p) => [
            'name' => $p['name'], 'address' => $p['formatted_address'] ?? $p['name'],
            'lat' => (float) $p['geometry']['location']['lat'], 'lng' => (float) $p['geometry']['location']['lng'],
            'place_id' => $p['place_id'] ?? null,
        ])->values()->all();
    }

    private function geoapifySearch(string $q, ?float $lat, ?float $lng): ?array
    {
        if (! ($key = $this->osm('geoapify_key'))) {
            return null;
        }
        $params = ['text' => $q, 'filter' => 'countrycode:'.$this->osm('country_codes'), 'limit' => 8, 'format' => 'json', 'apiKey' => $key];
        if ($lat && $lng) {
            $params['bias'] = "proximity:{$lng},{$lat}";
        }
        $res = $this->http()->get('https://api.geoapify.com/v1/geocode/search', $params);
        if (! $res->successful()) {
            return null;
        }

        return collect($res->json('results', []))->map(fn ($p) => [
            'name' => $p['name'] ?? $p['address_line1'] ?? $p['formatted'],
            'address' => $p['formatted'] ?? ($p['address_line2'] ?? ''),
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lon'],
            'place_id' => $p['place_id'] ?? null,
        ])->values()->all();
    }

    private function nominatimSearch(string $q, ?float $lat, ?float $lng): ?array
    {
        if (! $this->nominatimAllowed()) {
            return null;
        }
        $params = ['q' => $q, 'format' => 'jsonv2', 'limit' => 8, 'countrycodes' => $this->osm('country_codes'), 'addressdetails' => 0];
        if ($lat && $lng) {
            $params['viewbox'] = implode(',', [$lng - 0.4, $lat + 0.4, $lng + 0.4, $lat - 0.4]);
        }
        $res = $this->http()->get(rtrim($this->osm('nominatim_url'), '/').'/search', $params);
        if (! $res->successful()) {
            return null;
        }

        return collect($res->json() ?? [])->map(fn ($p) => [
            'name' => $p['name'] ?: explode(',', $p['display_name'])[0],
            'address' => $p['display_name'],
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lon'],
            'place_id' => isset($p['osm_id']) ? ($p['osm_type'] ?? 'osm').':'.$p['osm_id'] : null,
        ])->values()->all();
    }

    /** Public Nominatim allows max 1 request/second; self-hosted instances are unrestricted. */
    private function nominatimAllowed(): bool
    {
        $url = (string) $this->osm('nominatim_url');
        if (! str_contains($url, 'nominatim.openstreetmap.org')) {
            return (bool) $url;
        }
        if (! $this->osm('allow_public')) {
            return false;
        }
        if (RateLimiter::tooManyAttempts('nominatim-public', 1)) {
            return false;
        }
        RateLimiter::hit('nominatim-public', 1);

        return true;
    }

    private function landmarkSearch(string $q, ?float $lat, ?float $lng): array
    {
        $q = mb_strtolower($q);

        return collect(config('landmarks', []))
            ->filter(fn ($p) => str_contains(mb_strtolower($p['name'].' '.$p['address']), $q))
            ->sortBy(fn ($p) => $lat ? Geo::distanceM($lat, $lng, $p['lat'], $p['lng']) : 0)
            ->take(5)->map(fn ($p) => $p + ['place_id' => null])->values()->all();
    }

    // ---- Reverse geocoding --------------------------------------------

    public function reverseGeocode(float $lat, float $lng): string
    {
        $cacheKey = 'revgeo:'.round($lat, 4).','.round($lng, 4);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $address = null;
        try {
            if ($this->googleKey()) {
                $address = $this->http()->get('https://maps.googleapis.com/maps/api/geocode/json', ['latlng' => "$lat,$lng", 'key' => $this->googleKey()])->json('results.0.formatted_address');
            } elseif ($key = $this->osm('geoapify_key')) {
                $address = $this->http()->get('https://api.geoapify.com/v1/geocode/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'json', 'apiKey' => $key])->json('results.0.formatted');
            } elseif ($this->nominatimAllowed()) {
                $address = $this->http()->get(rtrim($this->osm('nominatim_url'), '/').'/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 17])->json('display_name');
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocoding failed', ['error' => $e->getMessage()]);
        }

        if (! $address) {
            $nearest = collect(config('landmarks', []))->map(fn ($p) => $p + ['d' => Geo::distanceM($lat, $lng, $p['lat'], $p['lng'])])->sortBy('d')->first();
            $address = $nearest && $nearest['d'] < 3000 ? 'Near '.$nearest['name'].', '.$nearest['address'] : sprintf('%.5f, %.5f', $lat, $lng);
        } else {
            Cache::put($cacheKey, $address, 86400 * 7);
        }

        return $address;
    }
}
