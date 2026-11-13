<?php

namespace Tests\Feature\Admin;

use App\Enums\DriverStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\EligibilityReviews\EligibilityReviewResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Rides\RideResource;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\VehicleCategories\VehicleCategoryResource;
use App\Models\AuditLog;
use App\Models\EligibilityVerification;
use App\Models\Incident;
use App\Models\SupportTicket;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_guests_are_redirected_and_mobile_users_cannot_access_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($this->makeUser(), 'web')->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_super_admin_can_open_every_admin_page(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [$ride, $tripId] = $this->assignedTrip($customer, $driver);
        $trip = Trip::query()->where('uuid', $tripId)->first();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/sos', ['trip_id' => $tripId]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/support/tickets', ['category' => 'general', 'subject' => 'Hi', 'message' => 'Hello there']);
        EligibilityVerification::query()->create(['user_id' => $customer->id, 'status' => 'submitted', 'document_type' => 'cnic', 'document_path' => 'x.png']);

        $admin = $this->makeAdmin('super_admin');
        $this->actingAs($admin, 'admin');

        $pages = ['/admin', '/admin/live-operations', '/admin/reports'];
        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            $class = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            $pages[] = $class::getUrl('index');
            if (array_key_exists('create', $class::getPages())) {
                $pages[] = $class::getUrl('create');
            }
        }
        $pages[] = TripResource::getUrl('view', ['record' => $trip]);
        $pages[] = RideResource::getUrl('view', ['record' => $ride]);
        $pages[] = DriverResource::getUrl('view', ['record' => $driver->driverProfile]);
        $pages[] = CustomerResource::getUrl('view', ['record' => $customer]);
        $pages[] = IncidentResource::getUrl('view', ['record' => Incident::query()->first()]);
        $pages[] = SupportTicketResource::getUrl('view', ['record' => SupportTicket::query()->first()]);
        $pages[] = EligibilityReviewResource::getUrl('view', ['record' => EligibilityVerification::query()->first()]);
        $pages[] = VehicleCategoryResource::getUrl('edit', ['record' => $this->category()]);

        $this->withoutExceptionHandling();
        $failures = [];
        foreach (array_unique($pages) as $url) {
            try {
                $status = $this->get($url)->getStatusCode();
                if ($status !== 200) {
                    $failures[] = "{$url} → HTTP {$status}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$url} → ".class_basename($e).': '.substr($e->getMessage(), 0, 300);
            }
        }
        $this->assertSame([], $failures, "Admin pages failed:\n".implode("\n", $failures));
    }

    public function test_roles_are_least_privilege(): void
    {
        $support = $this->makeAdmin('customer_support');
        $this->actingAs($support, 'admin');
        $this->get('/admin/support-tickets')->assertOk();
        $this->get('/admin/drivers')->assertOk();                 // can view drivers
        $this->get('/admin/eligibility-reviews')->assertForbidden(); // cannot review identity
        $this->get('/admin/payouts')->assertForbidden();
        $this->get('/admin/refunds')->assertForbidden();
        $this->get('/admin/admin-users')->assertForbidden();

        $this->flushSession();
        $finance = $this->makeAdmin('finance_manager');
        $this->actingAs($finance, 'admin');
        $this->get('/admin/payouts')->assertOk();
        $this->get('/admin/incidents')->assertForbidden();
        $this->get('/admin/system-settings')->assertForbidden();

        $this->flushSession();
        $analyst = $this->makeAdmin('analyst');
        $this->actingAs($analyst, 'admin');
        $this->get('/admin/reports')->assertOk();
        $this->get('/admin/vehicle-categories/create')->assertForbidden();
    }

    public function test_sensitive_document_access_requires_permission_and_is_audited(): void
    {
        Storage::fake('local');
        $driver = $this->makeDriver('mini', DriverStatus::Pending);
        $doc = $driver->driverProfile->documents()->first();
        Storage::disk('local')->put($doc->file_path, 'img');
        $url = route('admin.secure.file', ['type' => 'driver-document', 'id' => $doc->id]);

        $this->actingAs($this->makeAdmin('customer_support'), 'admin')->get($url)->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'sensitive_file.viewed')->count());

        $this->flushSession();
        $this->actingAs($this->makeAdmin('driver_verification_officer'), 'admin')->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, AuditLog::query()->where('action', 'sensitive_file.viewed')->where('auditable_id', $doc->id)->count());
    }

    public function test_admin_mfa_contract_is_implemented(): void
    {
        $admin = $this->makeAdmin('finance_manager', withMfa: false);
        $this->assertTrue($admin->requiresMfa());
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertTrue($admin->fresh()->hasMfa());
        $this->assertNotEquals('JBSWY3DPEHPK3PXP', \DB::table('admin_users')->where('id', $admin->id)->value('app_authentication_secret'), 'MFA secret must be encrypted at rest');
    }

    public function test_privileged_admin_without_two_factor_is_forced_to_set_it_up(): void
    {
        $admin = $this->makeAdmin('super_admin', withMfa: false);

        $res = $this->actingAs($admin, 'admin')->get('/admin');
        $res->assertRedirect();
        $this->assertStringContainsString('multi-factor', $res->headers->get('Location'));

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin->fresh(), 'admin')->get('/admin')->assertOk();
    }
}
