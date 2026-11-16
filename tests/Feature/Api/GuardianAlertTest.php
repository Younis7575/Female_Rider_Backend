<?php

namespace Tests\Feature\Api;

use App\Jobs\SendGuardianAlert;
use App\Models\GuardianAlert;
use App\Models\Trip;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class GuardianAlertTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    private function withGuardian($driver): void
    {
        $driver->driverProfile->forceFill(['guardian_name' => 'Ayesha', 'guardian_relationship' => 'Mother', 'guardian_whatsapp' => '923007654321', 'guardian_alerts_enabled' => true, 'guardian_consent_at' => now()])->save();
    }

    public function test_number_normalisation(): void
    {
        $this->assertSame('923001234567', WhatsAppService::normalize('0300 1234567'));
        $this->assertSame('923001234567', WhatsAppService::normalize('+92 300-1234567'));
        $this->assertSame('923001234567', WhatsAppService::normalize('3001234567'));
        $this->assertSame('923001234567', WhatsAppService::normalize('00923001234567'));
        $this->assertNull(WhatsAppService::normalize('12345'));
    }

    public function test_guardian_gets_driver_location_on_pickup_and_on_completion(): void
    {
        Queue::fake([SendGuardianAlert::class]);
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $this->withGuardian($driver);
        [, $tripId] = $this->assignedTrip($customer, $driver);
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => 24.8611, 'lng' => 67.0015, 'sequence' => 50])->assertOk();

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk();
        $this->assertSame(0, GuardianAlert::query()->count(), 'nothing is sent before pickup');
        $pin = Trip::query()->where('uuid', $tripId)->first()->pickup_pin;
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertOk();

        $started = GuardianAlert::query()->where('event', 'trip_started')->first();
        $this->assertNotNull($started);
        $this->assertSame('live', $started->location_source);
        $this->assertEqualsWithDelta(24.8611, $started->lat, 0.0001);
        Queue::assertPushed(SendGuardianAlert::class, fn ($j) => $j->alertId === $started->id);

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertOk();
        $this->assertSame(1, GuardianAlert::query()->where('event', 'trip_completed')->count());
    }

    public function test_cloud_api_sends_the_approved_template_without_rider_details(): void
    {
        config(['services.whatsapp.driver' => 'cloud', 'services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '1234567890']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $this->withGuardian($driver);
        [, $tripId] = $this->assignedTrip($customer, $driver);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk();
        $pin = Trip::query()->where('uuid', $tripId)->first()->pickup_pin;
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertOk(); // sync queue in tests

        $alert = GuardianAlert::query()->where('event', 'trip_started')->first();
        $this->assertSame('sent', $alert->status);
        $this->assertSame('wamid.TEST', $alert->provider_message_id);
        Http::assertSent(function ($req) use ($customer) {
            $body = $req->data();
            $text = json_encode($body);

            return str_contains($req->url(), '/1234567890/messages')
                && $body['to'] === '923007654321'
                && $body['template']['name'] === 'guardian_trip_started'
                && str_contains($text, 'maps.google.com')
                && ! str_contains($text, (string) $customer->name)
                && ! str_contains($text, (string) $customer->phone);
        });
    }

    public function test_no_alert_without_guardian_or_when_disabled(): void
    {
        Queue::fake([SendGuardianAlert::class]);
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($this->makeUser(), $driver);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk();
        $pin = Trip::query()->where('uuid', $tripId)->first()->pickup_pin;
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertOk();
        $this->assertSame(0, GuardianAlert::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_approved_driver_can_change_guardian_and_send_a_test_message(): void
    {
        Queue::fake([SendGuardianAlert::class]);
        $driver = $this->makeDriver();
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/guardian/test')->assertStatus(422)->assertJsonPath('error.code', 'guardian_missing');
        $this->actingAs($driver, 'sanctum')->putJson('/api/v1/driver/guardian', ['guardian_name' => 'Ayesha', 'guardian_whatsapp' => '12'])->assertStatus(422);
        $this->actingAs($driver, 'sanctum')->putJson('/api/v1/driver/guardian', ['guardian_name' => 'Ayesha', 'guardian_relationship' => 'Sister', 'guardian_whatsapp' => '0333-1112223', 'guardian_consent' => true])
            ->assertOk()->assertJsonPath('data.guardian.relationship', 'Sister')->assertJsonPath('data.guardian.configured', true);
        $this->assertSame('923331112223', $driver->driverProfile->fresh()->guardian_whatsapp);
        Queue::assertPushed(SendGuardianAlert::class, 1); // "guardian added" message
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/guardian/test')->assertOk();
        $this->actingAs($driver, 'sanctum')->putJson('/api/v1/driver/guardian', ['guardian_name' => 'Ayesha', 'guardian_alerts_enabled' => false])->assertOk()->assertJsonPath('data.guardian.alerts_enabled', false);
    }
}
