<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\DriverDeposit;
use App\Models\SystemSetting;
use App\Services\Finance\LedgerService;
use App\Services\Finance\ManualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
        Storage::fake('local');
        SystemSetting::set('payments.manual.jazzcash_number', '03001234567');
    }

    private function submit($driver, array $over = [])
    {
        return $this->actingAs($driver, 'sanctum')->post('/api/v1/driver/deposits', array_merge([
            'amount' => 500, 'transaction_ref' => 'TID123456', 'sender_number' => '03007654321',
            'screenshot' => UploadedFile::fake()->image('proof.jpg'),
        ], $over), ['Accept' => 'application/json']);
    }

    public function test_driver_sees_account_details_and_submits_proof(): void
    {
        $driver = $this->makeDriver();
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/payment-info')
            ->assertOk()->assertJsonPath('data.account.account_number', '03001234567')->assertJsonPath('data.account.enabled', true);

        $this->submit($driver)->assertCreated()->assertJsonPath('data.status', 'pending');
        $d = DriverDeposit::query()->first();
        Storage::disk('local')->assertExists($d->screenshot_path);
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/deposits')->assertOk()->assertJsonPath('data.0.transaction_ref', 'TID123456');

        $this->submit($this->makeDriver())->assertStatus(409); // duplicate TID
        $this->submit($driver, ['transaction_ref' => 'X1'])->assertStatus(422);
        $this->submit($driver, ['transaction_ref' => 'TID999999', 'screenshot' => null])->assertStatus(422);
        $this->submit($driver, ['transaction_ref' => 'TID999998', 'amount' => 5])->assertStatus(422);
    }

    public function test_customer_cannot_submit_and_disabled_without_number(): void
    {
        $this->submit($this->makeUser())->assertStatus(403);
        SystemSetting::set('payments.manual.jazzcash_number', '');
        $this->submit($this->makeDriver())->assertStatus(409);
    }

    public function test_admin_approval_credits_driver_balance_once(): void
    {
        $driver = $this->makeDriver();
        $this->submit($driver)->assertCreated();
        $d = DriverDeposit::query()->first();
        $ledger = app(LedgerService::class);
        $before = $ledger->driverBalance($driver->id);

        app(ManualPaymentService::class)->approve($this->makeAdmin(), $d, 450);
        $this->assertEqualsWithDelta($before + 450, $ledger->driverBalance($driver->id), 0.001);
        $this->assertTrue($ledger->isBalanced());
        $this->assertSame('approved', $d->fresh()->status);

        $this->expectException(BusinessRuleException::class);
        app(ManualPaymentService::class)->approve($this->makeAdmin(), $d->fresh(), 450);
    }

    public function test_rejection_leaves_balance_and_proof_is_admin_only(): void
    {
        $driver = $this->makeDriver();
        $this->submit($driver)->assertCreated();
        $d = DriverDeposit::query()->first();
        app(ManualPaymentService::class)->reject($this->makeAdmin(), $d, 'TID not found');
        $this->assertSame('rejected', $d->fresh()->status);
        $this->assertEqualsWithDelta(0, app(LedgerService::class)->driverBalance($driver->id), 0.001);

        $this->get("/admin/secure/files/payment-proof/{$d->id}")->assertStatus(302);
        $this->actingAs($this->makeAdmin(), 'admin')->get("/admin/secure/files/payment-proof/{$d->id}")->assertOk();
    }
}
