<?php

namespace Tests\Feature\Api;

use App\Models\RideDispatch;
use App\Services\Dispatch\DriverPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

/** Production on shared hosting: no Redis, no Reverb — SQL presence + REST polling. */
class SharedHostingModeTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['femalerider.dispatch.presence_driver' => 'sql']);
        $this->bootWorld();
    }

    public function test_dispatch_and_offers_work_without_redis(): void
    {
        Redis::shouldReceive()->never();
        $driver = $this->makeDriver();
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => $this->pickup['lat'] + 0.002, 'lng' => $this->pickup['lng'], 'sequence' => 7])->assertOk()->assertJsonPath('data.accepted', true);
        // Out-of-order ping is ignored
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => 1, 'lng' => 1, 'sequence' => 3])->assertOk()->assertJsonPath('data.accepted', false);

        $presence = app(DriverPresenceService::class);
        $this->assertSame($driver->id, $presence->nearby($this->pickup['lat'], $this->pickup['lng'], 3)[0]['driver_id'] ?? null);
        $this->assertCount(1, $presence->allOnline());

        $customer = $this->makeUser();
        $rideId = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/rides', $this->ridePayload())->assertCreated()->json('data.id');
        $this->assertTrue(RideDispatch::query()->where('driver_id', $driver->id)->exists());
        $ids = collect($this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/requests')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($rideId));
    }

    public function test_app_config_switches_to_polling_or_hosted_pusher(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.realtime.driver', 'polling')->assertJsonPath('data.realtime.key', '');

        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'pk_test', 'broadcasting.connections.pusher.options.cluster' => 'ap2']);
        $this->getJson('/api/v1/config')->assertOk()
            ->assertJsonPath('data.realtime.driver', 'pusher')
            ->assertJsonPath('data.realtime.host', 'ws-ap2.pusher.com')
            ->assertJsonPath('data.realtime.port', 443);
    }

    public function test_health_endpoint_reports_database_and_revision(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('database', true)->assertJsonStructure(['revision', 'time']);
    }
}
