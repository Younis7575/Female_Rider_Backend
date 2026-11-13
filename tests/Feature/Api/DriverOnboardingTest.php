<?php

namespace Tests\Feature\Api;

use App\Enums\DriverStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Services\Driver\DriverOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class DriverOnboardingTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
        Storage::fake('local');
    }

    public function test_full_driver_application_flow_and_approval_gate(): void
    {
        $user = $this->makeUser();
        $api = $this->actingAs($user, 'sanctum');

        $api->postJson('/api/v1/driver/submit', ['accept_agreement' => true])->assertStatus(422)->assertJsonPath('error.code', 'application_incomplete');

        $api->putJson('/api/v1/driver/profile', [
            'full_name' => 'Sara Test', 'cnic_number' => '42101-1234567-1', 'date_of_birth' => '1995-01-01',
            'license_number' => 'KHI-998877', 'license_expiry' => now()->addYears(2)->toDateString(), 'address' => 'Karachi',
            'emergency_contact_name' => 'Mum', 'emergency_contact_phone' => '+923001234567',
        ])->assertOk();
        $this->assertStringEndsWith('67-1', $api->getJson('/api/v1/driver/profile')->json('data.cnic_number_masked'));

        $api->putJson('/api/v1/driver/vehicle', ['vehicle_category_id' => $this->category('mini')->id, 'make' => 'Suzuki', 'model' => 'Alto', 'color' => 'Red', 'year' => 2021, 'plate_number' => 'abc 123'])
            ->assertOk()->assertJsonPath('data.plate_number', 'ABC-123');

        foreach (DriverProfile::REQUIRED_DOCUMENTS as $type) {
            $api->post('/api/v1/driver/documents', ['type' => $type, 'file' => UploadedFile::fake()->image("$type.jpg")], ['Accept' => 'application/json'])->assertCreated();
        }
        $api->postJson('/api/v1/driver/submit', ['accept_agreement' => true])->assertStatus(422)->assertJsonPath('error.details.missing.0', 'payout account');
        $api->putJson('/api/v1/driver/payout-account', ['payout_method' => 'jazzcash', 'payout_account_title' => 'Sara Test', 'payout_account_number' => '03001234567'])->assertOk();
        // Re-editing without re-sending masked encrypted fields keeps the stored values.
        $api->putJson('/api/v1/driver/profile', ['full_name' => 'Sara T.', 'date_of_birth' => '1995-01-01', 'license_expiry' => now()->addYears(2)->toDateString(), 'address' => 'Karachi', 'emergency_contact_name' => 'Mum'])->assertOk();
        $this->assertSame('42101-1234567-1', $user->driverProfile()->first()->cnic_number);
        $api->postJson('/api/v1/driver/submit', ['accept_agreement' => true])->assertOk()->assertJsonPath('data.status', 'pending');

        // Pending driver cannot go online or switch mode
        $api->postJson('/api/v1/driver/online', ['lat' => 24.86, 'lng' => 67.0])->assertStatus(403)->assertJsonPath('error.code', 'driver_not_approved');

        $profile = $user->driverProfile()->first();
        $admin = $this->makeAdmin('driver_verification_officer');
        $svc = app(DriverOnboardingService::class);

        // Approval is blocked until documents and vehicle are approved
        try {
            $svc->setStatus($admin, $profile, DriverStatus::Approved);
            $this->fail('Approval should be blocked while documents are pending.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('documents_pending', $e->errorCode);
        }

        $profile->documents->each(fn (DriverDocument $d) => $svc->reviewDocument($admin, $d, true));
        $svc->reviewVehicle($admin, $profile->primaryVehicle, true);
        $svc->setStatus($admin, $profile->fresh(), DriverStatus::Approved);
        $this->assertTrue($user->fresh()->isApprovedDriver());

        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/me/mode', ['mode' => 'driver'])->assertOk();
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/driver/online', ['lat' => 24.86, 'lng' => 67.0])->assertOk();
        $this->assertTrue($profile->fresh()->is_online);
    }

    public function test_internal_review_notes_are_never_exposed_to_driver(): void
    {
        $driver = $this->makeDriver('mini', DriverStatus::Pending);
        $driver->driverProfile->forceFill(['review_notes' => 'SECRET-NOTE', 'user_facing_reason' => 'Upload clearer licence'])->save();
        $res = $this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/profile')->assertOk()->assertJsonPath('data.reason', 'Upload clearer licence');
        $this->assertStringNotContainsString('SECRET-NOTE', $res->getContent());
        $this->assertStringNotContainsString('42101-1234567-1', $res->getContent(), 'CNIC must be masked');
    }

    public function test_suspension_forces_driver_offline(): void
    {
        $driver = $this->makeDriver();
        app(DriverOnboardingService::class)->setStatus($this->makeAdmin(), $driver->driverProfile, DriverStatus::Suspended, 'Investigation');
        $this->assertFalse($driver->driverProfile->fresh()->is_online);
        $this->actingAs($driver->fresh(), 'sanctum')->postJson('/api/v1/driver/online', ['lat' => 24.86, 'lng' => 67.0])->assertStatus(403);
    }

    public function test_expired_document_blocks_going_online(): void
    {
        $driver = $this->makeDriver(online: false);
        $driver->driverProfile->documents()->where('type', 'license_front')->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/online', ['lat' => 24.86, 'lng' => 67.0])
            ->assertStatus(403)->assertJsonPath('error.code', 'driver_not_compliant');
    }

    public function test_driver_outside_service_area_cannot_go_online(): void
    {
        $driver = $this->makeDriver(online: false);
        $this->actingAs($driver, 'sanctum')->postJson('/api/v1/driver/online', ['lat' => 40.71, 'lng' => -74.0])
            ->assertStatus(422)->assertJsonPath('error.code', 'outside_service_area');
    }
}
