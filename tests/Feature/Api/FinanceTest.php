<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\DriverPayout;
use App\Models\LedgerEntry;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\ServiceZone;
use App\Models\SystemSetting;
use App\Models\Trip;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\RefundService;
use App\Services\Pricing\FareCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    private function completedPaidTrip($customer, $driver, float $fare = 650): Trip
    {
        $ride = $this->requestRide($customer, ['proposed_fare' => $fare]);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => $fare])->json('data.id');
        $tripId = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->json('data.id');
        $trip = Trip::query()->where('uuid', $tripId)->first();
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/arrive");
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/start", ['pin' => $trip->pickup_pin]);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/complete")->assertOk();
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cash-collected")->assertOk();

        return $trip->fresh();
    }

    public function test_fare_calculation_floors_at_minimum_and_applies_capped_surge(): void
    {
        $calc = app(FareCalculator::class);
        $mini = $this->category('mini');
        $short = $calc->estimate($mini, 300, 60);
        $this->assertEquals(260, $short['suggested_fare']); // minimum fare

        $zone = ServiceZone::query()->first();
        $zone->update(['surge_enabled' => true, 'surge_multiplier' => 3.0, 'surge_cap' => 1.5]);
        $normal = $calc->estimate($mini, 10000, 1500);
        $surged = $calc->estimate($mini, 10000, 1500, $zone->fresh());
        $this->assertEquals(1.5, $surged['surge_multiplier']);
        $this->assertGreaterThan($normal['suggested_fare'], $surged['suggested_fare']);

        SystemSetting::set('surge.enabled', false);
        $this->assertEquals(1.0, $calc->estimate($mini, 10000, 1500, $zone->fresh())['surge_multiplier'], 'Global kill-switch must disable surge');
    }

    public function test_refund_above_threshold_requires_two_different_approvers(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $trip = $this->completedPaidTrip($customer, $driver, 1200);
        config(['femalerider.finance.maker_checker_threshold' => 500]);

        $refundId = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/refunds', ['trip_id' => $trip->uuid, 'amount' => 800, 'reason' => 'Driver took a much longer route'])->assertCreated()->json('data.id');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/refunds', ['trip_id' => $trip->uuid, 'amount' => 900, 'reason' => 'Another attempt'])->assertStatus(422);

        $refund = Refund::query()->where('uuid', $refundId)->first();
        $maker = $this->makeAdmin('finance_manager');
        $checker = $this->makeAdmin('finance_manager');
        $svc = app(RefundService::class);

        $svc->approve($maker, $refund);
        $this->assertEquals('approved', $refund->fresh()->status);
        try {
            $svc->approve($maker, $refund->fresh());
            $this->fail('Maker cannot be checker');
        } catch (BusinessRuleException $e) {
            $this->assertSame('maker_checker', $e->errorCode);
        }
        $svc->approve($checker, $refund->fresh());
        $this->assertEquals('processed', $refund->fresh()->status);
        $this->assertEquals('partially_refunded', $trip->farePayment()->first()->status);
        $this->assertTrue(app(LedgerService::class)->isBalanced());
    }

    public function test_payout_requires_balance_and_maker_checker_and_settles_ledger(): void
    {
        $driver = $this->makeDriver();
        $payouts = app(PayoutService::class);

        // Cash trips leave the driver owing commission: no payout possible.
        $this->completedPaidTrip($this->makeUser(), $driver);
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/payouts', ['amount' => 600])->assertStatus(422)->assertJsonPath('error.code', 'insufficient_balance');

        // Simulate platform-collected earnings (e.g. digital payments) via a balanced adjustment.
        app(LedgerService::class)->post([
            ['account' => 'gateway_clearing', 'direction' => 'debit', 'amount' => 8000],
            ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => 8000, 'user_id' => $driver->id],
        ], 'adjustment', 'Test digital earnings');

        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/payouts', ['amount' => 100])->assertStatus(422)->assertJsonPath('error.code', 'payout_below_minimum');
        $payoutId = $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/payouts', ['amount' => 6000])->assertCreated()->json('data.id');
        $payout = DriverPayout::query()->where('uuid', $payoutId)->first();
        $this->assertEquals('•••••••4567', $payout->account_number_masked);

        $maker = $this->makeAdmin('finance_manager');
        $checker = $this->makeAdmin('finance_manager');
        $payouts->approve($maker, $payout);                 // 6000 > 5000 threshold → needs checker
        $this->assertEquals('approved', $payout->fresh()->status);
        try {
            $payouts->markPaid($maker, $payout->fresh(), 'REF1');
            $this->fail('Cannot pay before second approval');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_state', $e->errorCode);
        }
        $payouts->approve($checker, $payout->fresh());
        $payouts->markPaid($checker, $payout->fresh(), 'BANK-REF-1');
        $this->assertEquals('paid', $payout->fresh()->status);
        $this->assertEquals(round(8000 - 32.5 - 6000, 2), app(LedgerService::class)->driverBalance($driver->id));
        $this->assertTrue(app(LedgerService::class)->isBalanced());
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $this->completedPaidTrip($this->makeUser(), $this->makeDriver());
        $this->expectException(\LogicException::class);
        LedgerEntry::query()->first()->update(['amount' => 1]);
    }

    public function test_payment_webhook_requires_valid_signature_and_is_idempotent(): void
    {
        config(['services.payments.webhook_secret' => 'whsec_test']);
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => ['payment_id' => 'none']]);
        $ts = time();

        $this->call('POST', '/api/v1/webhooks/payments/testpsp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => 'bad'], $body)
            ->assertStatus(401);

        $sig = hash_hmac('sha256', $ts.'.'.$body, 'whsec_test');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => $sig];
        $this->call('POST', '/api/v1/webhooks/payments/testpsp', [], [], [], $server, $body)->assertOk();
        $this->call('POST', '/api/v1/webhooks/payments/testpsp', [], [], [], $server, $body)->assertOk();
        $this->assertSame(1, PaymentWebhookEvent::query()->count());

        $old = time() - 600;
        $this->call('POST', '/api/v1/webhooks/payments/testpsp', [], [], [], array_merge($server, ['HTTP_X_WEBHOOK_TIMESTAMP' => $old, 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $old.'.'.$body, 'whsec_test')]), $body)
            ->assertStatus(400);
    }
}
