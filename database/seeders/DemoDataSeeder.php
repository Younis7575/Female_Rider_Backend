<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Enums\EligibilityStatus;
use App\Models\AdminUser;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\EligibilityVerification;
use App\Models\ServiceZone;
use App\Models\User;
use App\Models\UserRole;
use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * DEVELOPMENT / DEMO ONLY. Refuses to run in production.
 * Admin password comes from DEMO_ADMIN_PASSWORD (see backend/.env.example).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder must never run in production.');
        }

        $password = env('DEMO_ADMIN_PASSWORD', 'FemaleRider@Dev2026');
        $admins = [
            ['Super Admin', 'admin@femalerider.test', 'super_admin'],
            ['Ops Manager', 'ops@femalerider.test', 'operations_manager'],
            ['Verification Officer', 'verify@femalerider.test', 'driver_verification_officer'],
            ['Support Agent', 'support@femalerider.test', 'customer_support'],
            ['Safety Officer', 'safety@femalerider.test', 'safety_officer'],
            ['Finance Manager', 'finance@femalerider.test', 'finance_manager'],
            ['Finance Checker', 'finance2@femalerider.test', 'finance_manager'],
            ['Analyst', 'analyst@femalerider.test', 'analyst'],
        ];
        foreach ($admins as [$name, $email, $role]) {
            $a = AdminUser::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'is_active' => true]);
            $a->syncRoles([$role]);
        }
        $superAdmin = AdminUser::query()->where('email', 'admin@femalerider.test')->first();

        $zone = ServiceZone::query()->where('name', 'Karachi Metro')->first();
        $mini = VehicleCategory::query()->where('slug', 'mini')->first();
        $bike = VehicleCategory::query()->where('slug', 'bike')->first();

        // Approved customer
        $customer = $this->user('+923001234567', 'Ayesha Khan', EligibilityStatus::Approved, $superAdmin);

        // Approved driver (also approved customer — single identity, two roles)
        $driver = $this->user('+923007654321', 'Sana Malik', EligibilityStatus::Approved, $superAdmin);
        $this->driver($driver, $mini, $zone, 'KHI-2026', 'Toyota', 'Yaris', 'White', DriverStatus::Approved, $superAdmin, [24.8615, 67.0099]);

        // Second approved driver on a bike
        $driver2 = $this->user('+923005556667', 'Hira Ahmed', EligibilityStatus::Approved, $superAdmin);
        $this->driver($driver2, $bike, $zone, 'KHI-BK-77', 'Honda', 'CG 125', 'Red', DriverStatus::Approved, $superAdmin, [24.8590, 67.0150]);

        // Driver application pending review
        $pending = $this->user('+923001112223', 'Maryam Siddiqui', EligibilityStatus::Approved, $superAdmin);
        $this->driver($pending, $mini, $zone, 'LEA-3344', 'Suzuki', 'Cultus', 'Silver', DriverStatus::Pending, null, null);

        // Customer awaiting eligibility review
        $this->user('+923009998887', 'Zainab Raza', EligibilityStatus::Submitted, null);
    }

    private function user(string $phone, string $name, EligibilityStatus $status, ?AdminUser $reviewer): User
    {
        $user = User::query()->firstOrNew(['phone' => $phone]);
        $user->fill(['country_code' => '+92', 'name' => $name, 'locale' => 'en', 'emergency_contact_name' => 'Family Contact', 'emergency_contact_phone' => '+923000000000']);
        $user->forceFill(['eligibility_status' => $status, 'phone_verified_at' => now(), 'profile_completed_at' => now(), 'account_status' => 'active'])->save();

        $doc = $this->placeholder("eligibility/{$user->uuid}/cnic-front.png");
        EligibilityVerification::query()->firstOrCreate(['user_id' => $user->id], [
            'status' => $status, 'method' => 'document_review', 'document_type' => 'cnic', 'document_path' => $doc,
            'consent_version' => '1.0', 'consented_at' => now(), 'submitted_at' => now()->subDay(),
            'reviewer_id' => $status === EligibilityStatus::Approved ? $reviewer?->id : null,
            'reviewed_at' => $status === EligibilityStatus::Approved ? now() : null,
        ]);
        UserRole::query()->updateOrCreate(['user_id' => $user->id, 'role' => 'customer'], [
            'status' => $status === EligibilityStatus::Approved ? 'active' : 'pending',
            'approved_at' => $status === EligibilityStatus::Approved ? now() : null,
            'approved_by' => $status === EligibilityStatus::Approved ? $reviewer?->id : null,
        ]);

        return $user;
    }

    private function driver(User $user, VehicleCategory $cat, ServiceZone $zone, string $plate, string $make, string $model, string $color, DriverStatus $status, ?AdminUser $reviewer, ?array $location): void
    {
        $profile = DriverProfile::query()->firstOrNew(['user_id' => $user->id]);
        $profile->fill([
            'full_name' => $user->name, 'cnic_number' => '42101-'.rand(1000000, 9999999).'-'.rand(1, 9), 'date_of_birth' => now()->subYears(29)->toDateString(),
            'license_number' => 'LIC-'.rand(100000, 999999), 'license_expiry' => now()->addYears(3)->toDateString(), 'address' => 'Karachi',
            'emergency_contact_name' => 'Family Contact', 'emergency_contact_phone' => '+923000000000', 'service_zone_id' => $zone->id,
            'payout_method' => 'jazzcash', 'payout_account_title' => $user->name, 'payout_account_number' => '03'.rand(100000000, 999999999),
        ]);
        $profile->forceFill([
            'status' => $status, 'submitted_at' => now()->subDays(2), 'agreement_version' => '1.0', 'agreement_accepted_at' => now()->subDays(2),
            'reviewer_id' => $reviewer?->id, 'reviewed_at' => $reviewer ? now() : null, 'approved_at' => $status === DriverStatus::Approved ? now() : null,
            'rating_avg' => 4.9, 'is_online' => false,
        ])->save();

        $vehicle = $profile->vehicles()->firstOrCreate(['plate_number' => $plate], [
            'vehicle_category_id' => $cat->id, 'make' => $make, 'model' => $model, 'color' => $color, 'year' => 2022,
            'capacity' => $cat->capacity, 'status' => $status === DriverStatus::Approved ? 'approved' : 'pending', 'is_primary' => true,
        ]);

        foreach (DriverProfile::REQUIRED_DOCUMENTS as $type) {
            $profile->documents()->firstOrCreate(['type' => $type], [
                'vehicle_id' => str_starts_with($type, 'vehicle') ? $vehicle->id : null,
                'file_path' => $this->placeholder("driver-documents/{$user->uuid}/{$type}.png"),
                'mime' => 'image/png', 'size' => 100,
                'expiry_date' => in_array($type, ['license_front', 'vehicle_registration'], true) ? now()->addYears(2)->toDateString() : null,
                'status' => $status === DriverStatus::Approved ? 'approved' : 'pending',
                'reviewer_id' => $reviewer?->id, 'reviewed_at' => $reviewer ? now() : null,
            ]);
        }

        UserRole::query()->updateOrCreate(['user_id' => $user->id, 'role' => 'driver'], [
            'status' => $status === DriverStatus::Approved ? 'active' : 'pending',
            'approved_at' => $status === DriverStatus::Approved ? now() : null, 'approved_by' => $reviewer?->id,
        ]);

        if ($location) {
            DriverLocation::query()->updateOrCreate(['driver_profile_id' => $profile->id], ['lat' => $location[0], 'lng' => $location[1], 'recorded_at' => now()->subHour()]);
        }
    }

    /** 1×1 PNG placeholder in private storage (demo documents only). */
    private function placeholder(string $path): string
    {
        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        }

        return $path;
    }
}
