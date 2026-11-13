<?php

namespace Tests\Feature\Api;

use App\Services\Maps\MapsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.google_maps.server_key' => null, 'services.osm.allow_public' => true,
            'services.osm.geoapify_key' => null, 'services.osm.openrouteservice_key' => null]);
    }

    public function test_osrm_route_is_used_and_duration_padded(): void
    {
        Http::fake(['router.project-osrm.org/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 9348.4, 'duration' => 713.6, 'geometry' => 'abc']]])]);
        $r = app(MapsService::class)->route(24.86, 67.0, 24.80, 67.03);
        $this->assertSame('osrm', $r['provider']);
        $this->assertSame(9348, $r['distance_m']);
        $this->assertSame(892, $r['duration_s']);
        $this->assertSame('abc', $r['polyline']);
    }

    public function test_openrouteservice_preferred_when_key_set(): void
    {
        config(['services.osm.openrouteservice_key' => 'ors-test']);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 5000, 'duration' => 600], 'geometry' => 'xyz']]])]);
        $r = app(MapsService::class)->route(24.86, 67.0, 24.80, 67.03);
        $this->assertSame('openrouteservice', $r['provider']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'ors-test'));
    }

    public function test_falls_back_to_offline_estimate_when_providers_fail(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $r = app(MapsService::class)->route(24.86, 67.0, 24.80, 67.03);
        $this->assertSame('offline', $r['provider']);
        $this->assertGreaterThan(6000, $r['distance_m']);
    }

    public function test_search_merges_local_landmarks_with_nominatim_and_sends_user_agent(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['name' => 'Imtiaz Supermarket', 'display_name' => 'Imtiaz, PECHS, Karachi', 'lat' => '24.87', 'lon' => '67.06', 'osm_type' => 'node', 'osm_id' => 1]])]);
        $res = app(MapsService::class)->search('Imtiaz', 24.86, 67.0);
        $this->assertSame('Imtiaz Supermarket', $res[0]['name']);
        Http::assertSent(fn ($req) => str_contains($req->header('User-Agent')[0] ?? '', 'FemaleRider'));
    }

    public function test_geoapify_used_when_key_set_and_public_disabled(): void
    {
        config(['services.osm.geoapify_key' => 'geo-test', 'services.osm.allow_public' => false]);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [['name' => 'Dolmen Mall Tariq Road', 'formatted' => 'Tariq Road, Karachi', 'lat' => 24.87, 'lon' => 67.06]]])]);
        $res = app(MapsService::class)->search('Tariq Road Dolmen', 24.86, 67.0);
        $this->assertSame('Dolmen Mall Tariq Road', collect($res)->last()['name']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'nominatim'));
    }

    public function test_public_osm_disabled_in_production_style_config(): void
    {
        config(['services.osm.allow_public' => false]);
        Http::fake();
        $this->assertSame('offline', app(MapsService::class)->route(24.86, 67.0, 24.80, 67.03)['provider']);
        Http::assertNothingSent();
    }
}
