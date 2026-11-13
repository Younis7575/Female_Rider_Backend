<?php

namespace Tests\Feature\Api;

use App\Enums\DriverStatus;
use App\Enums\EligibilityStatus;
use App\Models\EligibilityVerification;
use App\Services\Driver\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class EligibilityAndRolesTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
        Storage::fake('local');
    }

    public function test_unverified_user_cannot_request_a_ride(): void
    {
        $user = $this->makeUser(EligibilityStatus::Draft);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rides', $this->ridePayload())
            ->assertStatus(403)->assertJsonPath('error.code', 'customer_not_eligible');
    }

    public function test_eligibility_submission_review_and_approval_grants_customer_access(): void
    {
        $user = $this->makeUser(EligibilityStatus::Draft);
        $this->actingAs($user, 'sanctum')->post('/api/v1/verification', [
            'document_type' => 'cnic',
            'document_front' => UploadedFile::fake()->image('front.jpg'),
            'declaration' => '1', 'consent' => '1',
        ], ['Accept' => 'application/json'])->assertCreated();

        $v = EligibilityVerification::query()->where('user_id', $user->id)->firstOrFail();
        Storage::disk('local')->assertExists($v->document_path);
        $this->assertEquals(EligibilityStatus::Submitted, $user->fresh()->eligibility_status);

        // Cannot resubmit while in review
        $this->actingAs($user, 'sanctum')->post('/api/v1/verification', ['document_type' => 'cnic', 'document_front' => UploadedFile::fake()->image('f.jpg'), 'declaration' => '1', 'consent' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(409);

        app(EligibilityService::class)->approve($this->makeAdmin('driver_verification_officer'), $v);
        $this->assertTrue($user->fresh()->canUseCustomerMode());
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/me')->assertJsonPath('data.user.capabilities.customer_mode', true);
    }

    public function test_rejection_allows_appeal_and_reason_is_visible_but_internal_notes_are_not(): void
    {
        $user = $this->makeUser(EligibilityStatus::Submitted);
        $v = EligibilityVerification::query()->create(['user_id' => $user->id, 'status' => 'submitted', 'document_type' => 'cnic', 'document_path' => 'x.png']);
        app(EligibilityService::class)->reject($this->makeAdmin(), $v, 'Document unreadable', 'internal: blurry');

        $res = $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/verification')->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reason', 'Document unreadable')->assertJsonPath('data.can_appeal', true);
        $this->assertStringNotContainsString('blurry', $res->getContent());

        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/verification/appeal', ['reason' => 'I have uploaded a clearer copy now.'])->assertOk();
        $this->assertEquals(EligibilityStatus::Appealed, $user->fresh()->eligibility_status);
    }

    public function test_customer_cannot_switch_to_driver_without_approval(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'driver'])
            ->assertStatus(403)->assertJsonPath('error.code', 'driver_not_approved');

        $pending = $this->makeDriver('mini', DriverStatus::Pending);
        $this->actingAs($pending, 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'driver'])->assertStatus(403);
    }

    public function test_driver_must_go_offline_before_switching_to_customer(): void
    {
        $driver = $this->makeDriver();
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'customer'])
            ->assertStatus(409)->assertJsonPath('error.code', 'driver_online');

        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/offline')->assertOk();
        $this->actingAs($driver->fresh(), 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'customer'])->assertOk()->assertJsonPath('data.active_mode', 'customer');
    }

    public function test_cannot_switch_mode_during_active_trip(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $this->assignedTrip($customer, $driver);
        $this->actingAs($customer->fresh(), 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'driver'])->assertStatus(409)->assertJsonPath('error.code', 'active_trip_exists');
    }
}
