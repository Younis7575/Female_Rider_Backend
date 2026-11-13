<?php

namespace Tests\Feature\Website;

use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Services\Dispatch\DriverPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripShareTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_PHONE = '+923001112233';

    private const DRIVER_PHONE = '+923339998877';

    private const EMERGENCY_PHONE = '+923215556677';

    private function makeTrip(array $overrides = []): Trip
    {
        $category = VehicleCategory::query()->create([
            'name' => 'FemaleRider Mini', 'slug' => 'mini-'.Str::random(5), 'icon' => 'car', 'capacity' => 4,
            'base_fare' => 140, 'per_km' => 40, 'per_minute' => 4, 'minimum_fare' => 260, 'commission_percent' => 15,
        ]);

        $customer = (new User)->forceFill([
            'phone' => self::CUSTOMER_PHONE, 'country_code' => '+92', 'name' => 'Zainab Customer-Secret',
            'emergency_contact_name' => 'Mother', 'emergency_contact_phone' => self::EMERGENCY_PHONE,
        ]);
        $customer->save();

        $driver = (new User)->forceFill([
            'phone' => self::DRIVER_PHONE, 'country_code' => '+92', 'name' => 'Sana Driverlastname', 'active_mode' => 'driver',
        ]);
        $driver->save();

        $profile = (new DriverProfile)->forceFill([
            'user_id' => $driver->id, 'status' => 'approved', 'full_name' => 'Sana Driverlastname',
        ]);
        $profile->save();

        $vehicle = (new Vehicle)->forceFill([
            'driver_profile_id' => $profile->id, 'vehicle_category_id' => $category->id,
            'make' => 'Suzuki', 'model' => 'Alto', 'color' => 'White', 'year' => 2022,
            'plate_number' => 'KHI-1234', 'capacity' => 4, 'status' => 'approved',
        ]);
        $vehicle->save();

        $ride = (new RideRequest)->forceFill([
            'customer_id' => $customer->id, 'vehicle_category_id' => $category->id,
            'pickup_lat' => 24.8607123, 'pickup_lng' => 67.0011456, 'pickup_address' => 'Clifton Block 5, Karachi',
            'dropoff_lat' => 24.9056789, 'dropoff_lng' => 67.0822123, 'dropoff_address' => 'Gulshan-e-Iqbal, Karachi',
            'distance_m' => 9500, 'duration_s' => 1500, 'suggested_fare' => 450, 'min_fare' => 320, 'max_fare' => 1100,
            'proposed_fare' => 450, 'initial_proposed_fare' => 450, 'status' => 'accepted', 'driver_id' => $driver->id,
            'expires_at' => now()->addMinutes(3),
        ]);
        $ride->save();

        $trip = (new Trip)->forceFill(array_merge([
            'ride_request_id' => $ride->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id, 'vehicle_category_id' => $category->id,
            'status' => TripStatus::DriverEnRoute, 'agreed_fare' => 450, 'pickup_pin' => '4827',
            'assigned_at' => now()->subMinutes(5),
            'share_token' => Str::random(40), 'share_expires_at' => now()->addHours(6),
        ], $overrides));
        $trip->save();

        return $trip->fresh();
    }

    private function fakePresence(?array $location): void
    {
        $this->mock(DriverPresenceService::class, function ($mock) use ($location) {
            $mock->shouldReceive('get')->andReturn($location);
        });
    }

    public function test_share_page_renders_for_active_trip_with_valid_token(): void
    {
        $this->fakePresence(['lat' => 24.87, 'lng' => 67.01, 'heading' => 90.0, 'ts' => now()->subSeconds(5)->timestamp]);
        $trip = $this->makeTrip();

        $response = $this->get(route('website.trip.share', $trip->share_token));

        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('leaflet@1.9.4', false)
            ->assertSee('id="trip-map"', false)
            ->assertSee('On the way to pickup')
            ->assertSee('Sana')
            ->assertSee('White Suzuki Alto')
            ->assertSee('KHI-1234')
            ->assertSee('Clifton Block 5, Karachi')
            ->assertSee('Gulshan-e-Iqbal, Karachi')
            ->assertSee(route('website.trip.status', $trip->share_token), false)
            ->assertDontSee('Driverlastname')
            ->assertDontSee('Zainab')
            ->assertDontSee('Customer-Secret')
            ->assertDontSee(self::CUSTOMER_PHONE)
            ->assertDontSee(self::DRIVER_PHONE)
            ->assertDontSee(self::EMERGENCY_PHONE)
            ->assertDontSee('3001112233')
            ->assertDontSee('3339998877');
    }

    public function test_status_json_shape_and_minimal_exposure(): void
    {
        $this->fakePresence(['lat' => 24.8712345, 'lng' => 67.0154321, 'heading' => 45.0, 'ts' => now()->subSeconds(8)->timestamp]);
        $trip = $this->makeTrip(['status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(2)]);

        $response = $this->getJson(route('website.trip.status', $trip->share_token));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure([
                'active', 'status', 'status_label', 'status_message',
                'driver' => ['first_name'],
                'vehicle' => ['label', 'plate_number', 'category'],
                'pickup' => ['address', 'lat', 'lng'],
                'dropoff' => ['address', 'lat', 'lng'],
                'driver_location' => ['lat', 'lng', 'heading', 'updated_at', 'stale'],
                'started_at', 'share_expires_at', 'server_time',
            ])
            ->assertJson([
                'active' => true,
                'status' => 'in_progress',
                'driver' => ['first_name' => 'Sana'],
                'vehicle' => ['label' => 'White Suzuki Alto', 'plate_number' => 'KHI-1234'],
                'pickup' => ['address' => 'Clifton Block 5, Karachi', 'lat' => 24.861, 'lng' => 67.001],
                'driver_location' => ['lat' => 24.87123, 'lng' => 67.01543, 'stale' => false],
            ]);

        $json = $response->json();
        $flat = json_encode($json);

        // No phone numbers, customer identity, PIN, fares or internal ids anywhere in the payload.
        foreach ([self::CUSTOMER_PHONE, self::DRIVER_PHONE, self::EMERGENCY_PHONE, '3001112233', '3339998877', '3215556677', 'Zainab', 'Driverlastname', '4827', 'Mother'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat, "Share payload leaked: {$secret}");
        }
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(phone|customer|pin|fare|email|uuid|_id)[^"]*"\s*:/i', $flat);
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('id', $json['driver']);
    }

    public function test_status_json_handles_missing_driver_location(): void
    {
        $this->fakePresence(null);
        $trip = $this->makeTrip(['status' => TripStatus::DriverAssigned]);

        $this->getJson(route('website.trip.status', $trip->share_token))
            ->assertOk()
            ->assertJson(['status' => 'driver_assigned', 'driver_location' => null]);

        $this->get(route('website.trip.share', $trip->share_token))->assertOk()->assertSee('No location yet');
    }

    public function test_share_page_works_with_real_presence_service_fallback(): void
    {
        $trip = $this->makeTrip(['status' => TripStatus::Arrived]);

        $this->get(route('website.trip.share', $trip->share_token))->assertOk()->assertSee('Driver at pickup');
        $this->getJson(route('website.trip.status', $trip->share_token))->assertOk()->assertJsonPath('status', 'arrived');
    }

    public function test_unknown_token_shows_expired_page(): void
    {
        $this->fakePresence(null);

        $this->get('/trip/'.Str::random(40))
            ->assertStatus(410)
            ->assertSee('This trip link has expired')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);

        $this->getJson('/trip/'.Str::random(40).'/status')
            ->assertStatus(410)
            ->assertJson(['active' => false, 'expired' => true]);

        // Short / malformed tokens get the same friendly response rather than a 404.
        $this->get('/trip/abc')->assertStatus(410)->assertSee('This trip link has expired');
    }

    public function test_expired_share_link_is_rejected(): void
    {
        $this->fakePresence(['lat' => 24.87, 'lng' => 67.01, 'heading' => null, 'ts' => now()->timestamp]);
        $trip = $this->makeTrip(['share_expires_at' => now()->subMinute()]);

        $this->get(route('website.trip.share', $trip->share_token))
            ->assertStatus(410)
            ->assertSee('This trip link has expired')
            ->assertDontSee('KHI-1234');

        $this->getJson(route('website.trip.status', $trip->share_token))
            ->assertStatus(410)
            ->assertJsonMissingPath('driver_location');
    }

    public function test_link_for_finished_trip_is_rejected(): void
    {
        $this->fakePresence(['lat' => 24.87, 'lng' => 67.01, 'heading' => null, 'ts' => now()->timestamp]);
        $trip = $this->makeTrip(['status' => TripStatus::Completed, 'completed_at' => now()]);

        foreach ([TripStatus::Completed, TripStatus::CancelledByCustomer] as $status) {
            $trip->forceFill(['status' => $status])->save();

            $this->get(route('website.trip.share', $trip->share_token))->assertStatus(410);
            $this->getJson(route('website.trip.status', $trip->share_token))
                ->assertStatus(410)
                ->assertJson(['expired' => true]);
        }
    }

    public function test_share_route_name_and_url(): void
    {
        $this->assertSame(url('/trip/abc123'), route('website.trip.share', 'abc123'));
        $this->assertSame(url('/trip/abc123/status'), route('website.trip.status', 'abc123'));
    }
}
