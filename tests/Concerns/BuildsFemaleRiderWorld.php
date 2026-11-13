<?php

namespace Tests\Concerns;

use App\Enums\DriverStatus;
use App\Enums\EligibilityStatus;
use App\Models\AdminUser;
use App\Models\DriverProfile;
use App\Models\RideRequest;
use App\Models\ServiceZone;
use App\Models\User;
use App\Models\UserRole;
use App\Models\VehicleCategory;
use App\Services\Dispatch\DriverPresenceService;
use Database\Seeders\PlatformConfigSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/** Test fixtures for the FemaleRider domain (tests only). */
trait BuildsFemaleRiderWorld
{
    // Karachi coordinates inside the seeded service zone.
    protected array $pickup = ['lat' => 24.8607, 'lng' => 67.0011];

    protected array $dropoff = ['lat' => 24.8021, 'lng' => 67.0300];

    protected function bootWorld(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, PlatformConfigSeeder::class]);
        if (config('femalerider.dispatch.presence_driver') !== 'sql') {
            try {
                Redis::connection()->flushdb();
            } catch (\Throwable) {
                // No Redis (CI / shared hosting): presence falls back to SQL.
            }
        }
    }

    protected function category(string $slug = 'mini'): VehicleCategory
    {
        return VehicleCategory::query()->where('slug', $slug)->firstOrFail();
    }

    protected function makeUser(EligibilityStatus $status = EligibilityStatus::Approved, ?string $phone = null): User
    {
        $u = new User(['phone' => $phone ?? '+92300'.random_int(1000000, 9999999), 'country_code' => '+92', 'name' => 'Test '.Str::random(5)]);
        $u->forceFill(['eligibility_status' => $status, 'phone_verified_at' => now(), 'profile_completed_at' => now()])->save();
        UserRole::query()->create(['user_id' => $u->id, 'role' => 'customer', 'status' => $status === EligibilityStatus::Approved ? 'active' : 'pending']);

        return $u->fresh();
    }

    protected function makeDriver(string $categorySlug = 'mini', DriverStatus $status = DriverStatus::Approved, bool $online = true, ?array $at = null): User
    {
        $u = $this->makeUser();
        $profile = new DriverProfile([
            'full_name' => $u->name, 'cnic_number' => '42101-1234567-1', 'date_of_birth' => now()->subYears(30)->toDateString(),
            'license_number' => 'LIC123', 'license_expiry' => now()->addYear()->toDateString(), 'address' => 'Karachi',
            'emergency_contact_name' => 'Mum', 'emergency_contact_phone' => '+923000000000',
            'payout_method' => 'jazzcash', 'payout_account_title' => $u->name, 'payout_account_number' => '03001234567',
        ]);
        $profile->user_id = $u->id;
        $profile->forceFill(['status' => $status, 'submitted_at' => now(), 'approved_at' => $status === DriverStatus::Approved ? now() : null])->save();
        $vehicle = $profile->vehicles()->create([
            'vehicle_category_id' => $this->category($categorySlug)->id, 'make' => 'Toyota', 'model' => 'Yaris', 'color' => 'White',
            'year' => 2022, 'plate_number' => 'T-'.Str::upper(Str::random(6)), 'capacity' => 4,
            'status' => $status === DriverStatus::Approved ? 'approved' : 'pending', 'is_primary' => true,
        ]);
        foreach (DriverProfile::REQUIRED_DOCUMENTS as $type) {
            $profile->documents()->create(['type' => $type, 'file_path' => "test/{$type}.png", 'status' => $status === DriverStatus::Approved ? 'approved' : 'pending', 'vehicle_id' => str_starts_with($type, 'vehicle') ? $vehicle->id : null]);
        }
        UserRole::query()->create(['user_id' => $u->id, 'role' => 'driver', 'status' => $status === DriverStatus::Approved ? 'active' : 'pending']);

        if ($status === DriverStatus::Approved) {
            $u->forceFill(['active_mode' => 'driver'])->save();
            if ($online) {
                $profile->forceFill(['is_online' => true, 'service_zone_id' => ServiceZone::query()->first()->id])->save();
                $loc = $at ?? ['lat' => $this->pickup['lat'] + 0.004, 'lng' => $this->pickup['lng'] + 0.004];
                app(DriverPresenceService::class)->update($profile->fresh('primaryVehicle'), $loc['lat'], $loc['lng'], ['force_snapshot' => true]);
            }
        }

        return $u->fresh(['driverProfile', 'roles']);
    }

    protected function makeAdmin(string $role = 'super_admin', bool $withMfa = true): AdminUser
    {
        $a = AdminUser::query()->create(['name' => ucfirst($role), 'email' => $role.Str::random(4).'@test.local', 'password' => 'Password!123456', 'is_active' => true]);
        $a->assignRole($role);
        if ($withMfa) {
            $a->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP'); // MFA is mandatory for privileged admins
        }

        return $a;
    }

    protected function ridePayload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_category_id' => $this->category('mini')->id,
            'pickup_lat' => $this->pickup['lat'], 'pickup_lng' => $this->pickup['lng'], 'pickup_address' => 'Saddar, Karachi',
            'dropoff_lat' => $this->dropoff['lat'], 'dropoff_lng' => $this->dropoff['lng'], 'dropoff_address' => 'Clifton, Karachi',
            'proposed_fare' => 600,
        ], $overrides);
    }

    protected function requestRide(User $customer, array $overrides = []): RideRequest
    {
        $res = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/rides', $this->ridePayload($overrides))->assertCreated();

        return RideRequest::query()->where('uuid', $res->json('data.id'))->firstOrFail();
    }

    /** Full negotiation to an assigned trip; returns [ride, trip uuid]. */
    protected function assignedTrip(User $customer, User $driver): array
    {
        $ride = $this->requestRide($customer);
        $offerId = $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/requests/{$ride->uuid}/offer", ['amount' => 650])->assertCreated()->json('data.id');
        $tripId = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/offers/{$offerId}/accept")->assertOk()->json('data.id');

        return [$ride->fresh(), $tripId];
    }
}
