<?php

namespace Tests\Feature\Api;

use App\Enums\OfferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\RideDispatch;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Services\Rides\NegotiationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class RideNegotiationTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_fare_estimate_returns_server_computed_bounds_for_each_category(): void
    {
        $res = $this->actingAs($this->makeUser(), 'sanctum')->postJson('/api/v1/fare/estimate', [
            'pickup_lat' => $this->pickup['lat'], 'pickup_lng' => $this->pickup['lng'], 'dropoff_lat' => $this->dropoff['lat'], 'dropoff_lng' => $this->dropoff['lng'],
        ])->assertOk();
        $this->assertCount(4, $res->json('data.options'));
        foreach ($res->json('data.options') as $o) {
            $this->assertTrue($o['min_fare'] <= $o['suggested_fare'] && $o['suggested_fare'] <= $o['max_fare']);
        }
    }

    public function test_ride_creation_rejects_out_of_bounds_fare_and_outside_service_area(): void
    {
        $c = $this->makeUser();
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/rides', $this->ridePayload(['proposed_fare' => 5]))
            ->assertStatus(422)->assertJsonPath('error.code', 'fare_out_of_bounds');
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/rides', $this->ridePayload(['pickup_lat' => 40.7, 'pickup_lng' => -74.0]))
            ->assertStatus(422)->assertJsonPath('error.code', 'outside_service_area');
    }

    public function test_ride_creation_is_idempotent_and_only_one_open_request_allowed(): void
    {
        $c = $this->makeUser();
        $a = $this->actingAs($c, 'sanctum')->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/rides', $this->ridePayload())->assertCreated();
        $b = $this->actingAs($c, 'sanctum')->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/rides', $this->ridePayload())->assertCreated();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, RideRequest::query()->count());

        $this->actingAs($c, 'sanctum')->withHeader('Idempotency-Key', 'key-2')->postJson('/api/v1/rides', $this->ridePayload())
            ->assertStatus(409)->assertJsonPath('error.code', 'open_request_exists');
    }

    public function test_dispatch_targets_only_eligible_nearby_drivers(): void
    {
        $near = $this->makeDriver('mini');
        $wrongClass = $this->makeDriver('bike');
        $offline = $this->makeDriver('mini', online: false);
        $far = $this->makeDriver('mini', at: ['lat' => 24.98, 'lng' => 67.20]);

        $ride = $this->requestRide($this->makeUser());
        $notified = RideDispatch::query()->where('ride_request_id', $ride->id)->pluck('driver_id')->all();

        $this->assertContains($near->id, $notified);
        $this->assertNotContains($wrongClass->id, $notified);
        $this->assertNotContains($offline->id, $notified);
        $this->assertNotContains($far->id, $notified);

        $this->actingAs($near, 'sanctum')->getJson('/api/v1/driver/requests')->assertOk()
            ->assertJsonPath('data.0.id', $ride->uuid)
            ->assertJsonMissingPath('data.0.dropoff.lat'); // exact drop-off hidden before acceptance
    }

    public function test_driver_offer_customer_counter_driver_accept_creates_trip(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer);

        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 700])->assertCreated()->json('data.id');
        $this->assertEquals('offers_available', $ride->fresh()->status->value);

        $counterId = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/counter", ['amount' => 640])->assertCreated()->json('data.id');
        $this->assertEquals(OfferStatus::Countered, RideOffer::find($offerId)->status);

        $trip = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/offers/{$counterId}/respond", ['action' => 'accept'])->assertOk()->json('data.trip');
        $this->assertEquals('driver_assigned', $trip['status']);
        $this->assertEquals(640, $trip['agreed_fare']);
        $this->assertNull($trip['pickup_pin'], 'Driver must never see the pickup PIN');
        $this->assertEquals('accepted', $ride->fresh()->status->value);

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$trip['id']}")->assertOk()->assertJsonPath('data.agreed_fare', 640)
            ->assertJson(fn ($json) => $json->where('data.pickup_pin', fn ($pin) => strlen($pin) === 4)->etc());
    }

    public function test_offer_amount_must_be_within_bounds_and_driver_must_match_category(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 99999])->assertStatus(422)->assertJsonPath('error.code', 'fare_out_of_bounds');

        $bikeDriver = $this->makeDriver('bike'); // wrong vehicle class is never dispatched
        $this->actingAs($bikeDriver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->assertStatus(403)->assertJsonPath('error.code', 'not_dispatched');
    }

    public function test_driver_can_bid_customer_price_or_higher_but_never_lower(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer, ['proposed_fare' => 600]);

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 550])
            ->assertStatus(422)->assertJsonPath('error.code', 'offer_below_customer_fare')->assertJsonPath('error.details.min_offer', 600);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->assertCreated()->assertJsonPath('data.amount', 600); // same price
    }

    public function test_drivers_who_come_online_later_still_see_all_nearby_requests(): void
    {
        $customer = $this->makeUser();
        $ride1 = $this->requestRide($customer);
        $ride2 = $this->requestRide($this->makeUser(), ['pickup_lat' => $this->pickup['lat'] + 0.01]);

        $late = $this->makeDriver(); // online after both requests were broadcast
        $ids = collect($this->actingAs($late, 'sanctum')->getJson('/api/v1/driver/requests')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ride1->uuid) && $ids->contains($ride2->uuid));
        $this->actingAs($late, 'sanctum')->postJson("/api/v1/driver/requests/{$ride1->uuid}/offer", ['amount' => 650])->assertCreated();
    }

    public function test_customer_sees_every_driver_bid_and_can_rebid_one_driver_lower(): void
    {
        $customer = $this->makeUser();
        $d1 = $this->makeDriver();
        $d2 = $this->makeDriver();
        $ride = $this->requestRide($customer, ['proposed_fare' => 600]);
        $o1 = $this->actingAs($d1, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 800])->json('data.id');
        $this->actingAs($d2, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 650])->assertCreated();

        $this->assertCount(2, $this->actingAs($customer, 'sanctum')->getJson("/api/v1/rides/{$ride->uuid}/offers")->json('data'));

        // Re-bid must be lower than that driver's offer.
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$o1}/counter", ['amount' => 800])->assertStatus(422)->assertJsonPath('error.code', 'counter_not_lower');
        $counterId = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$o1}/counter", ['amount' => 700])->assertCreated()->json('data.id');

        // That driver sees the customer's fare and may counter only at or above it, reject, or accept.
        $this->actingAs($d1, 'sanctum')->postJson("/api/v1/offers/{$counterId}/respond", ['action' => 'counter', 'amount' => 690])->assertStatus(422)->assertJsonPath('error.code', 'offer_below_customer_fare');
        $mine = collect($this->actingAs($d1, 'sanctum')->getJson('/api/v1/driver/requests')->json('data'))->firstWhere('id', $ride->uuid)['my_offer'];
        $this->assertSame('customer', $mine['proposed_by']);
        $this->assertEquals(700, $mine['amount']);
        $this->actingAs($d1, 'sanctum')->postJson("/api/v1/offers/{$counterId}/respond", ['action' => 'accept'])->assertOk()->assertJsonPath('data.trip.agreed_fare', 700);
    }

    public function test_customer_cannot_act_on_another_customers_offer(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->json('data.id');

        $intruder = $this->makeUser();
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->assertStatus(404);
        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/rides/{$ride->uuid}")->assertStatus(404);
    }

    public function test_concurrent_acceptance_assigns_exactly_one_driver(): void
    {
        $customer = $this->makeUser();
        $d1 = $this->makeDriver();
        $d2 = $this->makeDriver();
        $ride = $this->requestRide($customer);
        $o1 = $this->actingAs($d1, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->json('data.id');
        $o2 = $this->actingAs($d2, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 620])->json('data.id');

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$o1}/accept")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$o2}/accept")->assertStatus(409);

        $this->assertSame(1, Trip::query()->where('ride_request_id', $ride->id)->count());
        $this->assertEquals(OfferStatus::Closed, RideOffer::find($o2)->status);

        // Database-level guarantee: a second trip row for the same ride is impossible.
        $this->expectException(QueryException::class);
        Trip::query()->create(array_merge(Trip::query()->first()->only(['ride_request_id', 'customer_id', 'vehicle_category_id', 'agreed_fare', 'assigned_at']), ['driver_id' => $d2->id, 'pickup_pin' => '1111', 'status' => 'driver_assigned']));
    }

    public function test_driver_cannot_hold_two_active_trips(): void
    {
        $driver = $this->makeDriver();
        $this->assignedTrip($this->makeUser(), $driver);

        $ride2 = $this->requestRide($this->makeUser(), ['pickup_lat' => $this->pickup['lat'] + 0.001]);
        $this->assertFalse(RideDispatch::query()->where('ride_request_id', $ride2->id)->where('driver_id', $driver->id)->exists(), 'Busy driver must not be dispatched');

        try {
            app(NegotiationService::class)->driverOffer($driver->fresh(), $ride2);
            $this->fail('Busy driver should not be able to offer');
        } catch (BusinessRuleException $e) {
            $this->assertContains($e->errorCode, ['not_dispatched', 'driver_busy']);
        }
    }

    public function test_customer_can_cancel_open_request_and_offers_close(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->json('data.id');

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/rides/{$ride->uuid}/cancel", ['reason' => 'Changed my plans'])->assertOk()->assertJsonPath('data.status', 'cancelled_by_customer');
        $this->assertEquals(OfferStatus::Closed, RideOffer::find($offerId)->status);
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->assertStatus(409);
    }

    public function test_request_expires_to_no_driver_found_when_nobody_offers(): void
    {
        $customer = $this->makeUser();
        $ride = $this->requestRide($customer);
        $this->travel(6)->minutes();
        $this->artisan('fr:dispatch-tick')->assertSuccessful();
        $this->assertEquals('no_driver_found', $ride->fresh()->status->value);
    }

    public function test_offers_expire(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer")->json('data.id');
        $this->travel(100)->seconds();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->assertStatus(409)->assertJsonPath('error.code', 'offer_not_pending');
    }
}
