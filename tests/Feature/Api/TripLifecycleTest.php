<?php

namespace Tests\Feature\Api;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Trip;
use App\Services\Finance\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class TripLifecycleTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_full_trip_with_pin_verification_completion_cash_and_ledger(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($customer, $driver);
        $trip = Trip::query()->where('uuid', $tripId)->first();
        $pin = $trip->pickup_pin;

        // Cannot start before arrival, cannot complete before start
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertStatus(409);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertStatus(409);
        // Customer cannot perform driver actions
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertStatus(404);

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/en-route")->assertOk();
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk()->assertJsonPath('data.status', 'arrived');

        $wrong = $pin === '0000' ? '1111' : '0000';
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid');
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => 24.85, 'lng' => 67.01, 'sequence' => 1])->assertOk()->assertJsonPath('data.accepted', true);

        $res = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertOk();
        $this->assertEquals(650, $res->json('data.final_fare'));
        $this->assertEquals(32.5, $res->json('data.commission_amount')); // flat 5%
        $this->assertEquals(617.5, $res->json('data.driver_earning'));

        // Cannot complete twice
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertStatus(409);

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cash-collected")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cash-collected")->assertOk(); // idempotent
        $this->assertSame(1, Payment::query()->where('trip_id', $trip->id)->count());

        $ledger = app(LedgerService::class);
        $this->assertTrue($ledger->isBalanced(), 'Ledger must balance');
        $this->assertEquals(-32.5, $ledger->driverBalance($driver->id), 'Cash trip: driver owes commission');
        $this->assertEquals(32.5, $ledger->accountBalance('platform_revenue'));
        $this->assertEquals(0.0, $ledger->accountBalance('customer_receivable'));

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}/receipt")->assertOk()->assertJsonPath('data.amount_paid', 650)->assertJsonPath('data.refundable_amount', 650);
        $stmt = $this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/statements')->assertOk();
        $this->assertSame(1, $stmt->json('data.summary.completed_trips'));
        $this->assertEquals(-32.5, $stmt->json('data.summary.closing_balance'));
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/rating", ['score' => 5, 'tags' => ['Safe driving']])->assertCreated();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/rating", ['score' => 4])->assertStatus(409);
    }

    public function test_pin_locks_after_max_attempts(): void
    {
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($this->makeUser(), $driver);
        $pin = Trip::query()->where('uuid', $tripId)->value('id') ? Trip::query()->where('uuid', $tripId)->first()->pickup_pin : null;
        $wrong = $pin === '0000' ? '1111' : '0000';
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $wrong]);
        }
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertStatus(423)->assertJsonPath('error.code', 'pin_locked');
    }

    public function test_customer_cancellation_after_arrival_charges_fee_and_frees_driver(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($customer, $driver);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive")->assertOk();
        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertJsonPath('data.cancellation_policy.fee_applies_now', true)->assertJsonPath('data.cancellation_policy.fee', 120);

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cancel", ['reason' => 'Changed my plans'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled_by_customer')->assertJsonPath('data.cancellation_fee', 120);
        $this->assertTrue(app(LedgerService::class)->isBalanced());
        $this->assertNull($driver->fresh()->activeTrip());
    }

    public function test_in_progress_trip_cannot_be_cancelled_by_customer(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($customer, $driver);
        $pin = Trip::query()->where('uuid', $tripId)->first()->pickup_pin;
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive");
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin])->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cancel", ['reason' => 'x'])->assertStatus(409);
    }

    public function test_other_users_cannot_view_trip_idor(): void
    {
        [, $tripId] = $this->assignedTrip($this->makeUser(), $this->makeDriver());
        $this->actingAs($this->makeUser(), 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertStatus(404);
        $this->actingAs($this->makeUser(), 'sanctum')->postJson("/api/v1/trips/{$tripId}/share")->assertStatus(404);
    }

    public function test_coupon_discount_is_applied_on_completion_and_funded_by_platform(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $ride = $this->requestRide($customer, ['coupon_code' => 'welcome50']);
        $this->assertEquals(150, $ride->discount_amount);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 600])->json('data.id');
        $tripId = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->json('data.id');
        $pin = Trip::query()->where('uuid', $tripId)->first()->pickup_pin;
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive");
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $pin]);
        $res = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertOk();
        $this->assertEquals(450, $res->json('data.amount_due'));
        $this->assertEquals(150, app(LedgerService::class)->accountBalance('promotions_expense') * -1);
        $this->assertTrue(app(LedgerService::class)->isBalanced());
        $this->assertGreaterThan(0, LedgerEntry::query()->where('entry_type', 'promotion')->count());
    }

    public function test_sos_creates_critical_incident_with_audit(): void
    {
        $customer = $this->makeUser();
        [, $tripId] = $this->assignedTrip($customer, $this->makeDriver());
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/sos', ['trip_id' => $tripId, 'lat' => 24.86, 'lng' => 67.0])
            ->assertCreated()->assertJsonPath('data.incident.severity', 'critical')->assertJsonPath('data.emergency_number', '15');
        $this->assertDatabaseHas('audit_logs', ['action' => 'safety.sos_raised']);
    }
}
