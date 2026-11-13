<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\CmsPage;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\FareRule;
use App\Models\SystemSetting;
use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;

/** Operational configuration safe for every environment (no credentials, no people). */
class PlatformConfigSeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['Karachi', 24.8607, 67.0011, [[25.10, 66.85], [25.10, 67.35], [24.75, 67.35], [24.75, 66.85]]],
            ['Lahore', 31.5204, 74.3587, [[31.70, 74.15], [31.70, 74.55], [31.35, 74.55], [31.35, 74.15]]],
            ['Islamabad', 33.6844, 73.0479, [[33.80, 72.80], [33.80, 73.25], [33.45, 73.25], [33.45, 72.80]]],
        ];
        foreach ($cities as [$name, $lat, $lng, $poly]) {
            $city = City::query()->updateOrCreate(['name' => $name], ['center_lat' => $lat, 'center_lng' => $lng, 'country_code' => 'PK', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'emergency_number' => '15', 'is_active' => true]);
            $city->zones()->updateOrCreate(['name' => "{$name} Metro"], ['polygon' => $poly, 'center_lat' => $lat, 'center_lng' => $lng, 'is_active' => true, 'surge_enabled' => false, 'surge_multiplier' => 1.0, 'surge_cap' => 1.8]);
        }

        $categories = [
            ['FemaleRider Bike', 'bike', 'bike', 'Quick, affordable solo rides', 1, 60, 18, 2.5, 120, 0, 2, 50, 5],
            ['FemaleRider Rickshaw', 'rickshaw', 'rickshaw', 'Comfortable 3-seat auto rickshaw', 3, 90, 28, 3, 180, 0, 3, 80, 5],
            ['FemaleRider Mini', 'mini', 'car', 'Compact AC car for up to 4', 4, 140, 40, 4, 260, 10, 4, 120, 5],
            ['FemaleRider Comfort', 'comfort', 'car_premium', 'Spacious sedans, top-rated drivers', 4, 200, 55, 5, 380, 15, 5, 150, 5],
        ];
        foreach ($categories as $i => [$name, $slug, $icon, $desc, $cap, $base, $km, $min, $minFare, $book, $wait, $cancel, $comm]) {
            VehicleCategory::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'icon' => $icon, 'description' => $desc, 'capacity' => $cap,
                'base_fare' => $base, 'per_km' => $km, 'per_minute' => $min, 'minimum_fare' => $minFare,
                'booking_fee' => $book, 'waiting_per_minute' => $wait, 'cancellation_fee' => $cancel,
                'min_offer_ratio' => 0.70, 'max_offer_ratio' => 2.50, 'commission_percent' => $comm,
                'is_active' => true, 'sort_order' => $i,
            ]);
        }

        // Late-night rule (22:00–05:00) +15% on all categories.
        foreach (VehicleCategory::all() as $c) {
            FareRule::query()->updateOrCreate(['vehicle_category_id' => $c->id, 'name' => 'Late night'], [
                'time_multiplier' => 1.15, 'applies_from' => '22:00:00', 'applies_to' => '05:00:00', 'is_active' => true,
            ]);
        }

        $settings = [
            ['platform.maintenance_mode', false, 'platform', 'boolean', 'Block new bookings with a maintenance message'],
            ['platform.maintenance_message', 'We will be back shortly.', 'platform', 'string', 'Maintenance banner text'],
            ['app.min_version', '1.0.0', 'app', 'string', 'Minimum supported mobile app version'],
            ['app.latest_version', '1.0.0', 'app', 'string', 'Latest mobile app version'],
            ['surge.enabled', true, 'pricing', 'boolean', 'Global surge kill-switch'],
            ['dispatch.request_ttl_seconds', 300, 'dispatch', 'integer', 'Ride request lifetime (seconds)'],
            ['driver.min_age', 18, 'driver', 'integer', 'Minimum driver age'],
            ['trip.enforce_arrival_radius', false, 'trip', 'boolean', 'Require driver to be near pickup to mark arrival'],
        ];
        foreach ($settings as [$key, $value, $group, $type, $desc]) {
            SystemSetting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'group' => $group, 'type' => $type, 'description' => $desc]);
        }

        $faqs = [
            ['general', 'all', 'Who can use FemaleRider?', 'FemaleRider is a women-only community. Every rider and driver completes phone verification and our eligibility review before using the service.'],
            ['general', 'all', 'How does fare negotiation work?', 'You see a suggested fare and can propose your own price within a fair range. Nearby drivers can accept it or send a counter-offer, and you choose the offer you prefer.'],
            ['safety', 'all', 'What is the trip PIN?', 'When a driver is assigned you receive a 4-digit PIN. Share it only after confirming the driver and vehicle match the app. The trip cannot start without it.'],
            ['safety', 'all', 'What does the SOS button do?', 'SOS immediately alerts the FemaleRider safety team with your trip and location, and notifies your emergency contact by SMS if you have added one. In immediate danger always call 15.'],
            ['payments', 'customers', 'How do I pay?', 'Cash is currently supported. Pay the amount shown in the app at the end of your trip. Digital payments are coming soon.'],
            ['drivers', 'drivers', 'What documents do I need to drive?', 'CNIC (front & back), driving licence (front & back), vehicle registration, a profile photo and vehicle photos. Insurance and fitness certificates are recommended.'],
            ['drivers', 'drivers', 'How much commission does FemaleRider charge?', 'FemaleRider charges a flat 5% commission on every completed trip. It is shown in your earnings breakdown for each trip.'],
            ['drivers', 'drivers', 'When can I withdraw my earnings?', 'Request a payout from the Earnings screen once your available balance exceeds PKR 500. Payouts are reviewed and sent to your bank, JazzCash or Easypaisa account.'],
        ];
        foreach ($faqs as $i => [$cat, $aud, $q, $a]) {
            Faq::query()->updateOrCreate(['question' => $q], ['category' => $cat, 'audience' => $aud, 'answer' => $a, 'sort_order' => $i, 'is_published' => true]);
        }

        $pages = [
            'terms' => ['Terms of Service', '<p>These draft terms govern use of the FemaleRider platform. <strong>They must be reviewed and approved by legal counsel before launch.</strong></p><h3>1. Eligibility</h3><p>FemaleRider is available to women who complete our verification process.</p><h3>2. Fares</h3><p>Fares are agreed between rider and driver within limits set by FemaleRider. The final fare follows our published rules and is shown on your receipt.</p><h3>3. Safety</h3><p>FemaleRider provides safety tools but cannot guarantee absolute safety. In an emergency contact local emergency services.</p>'],
            'delete-account' => ['Delete your FemaleRider account', '<p>You can delete your FemaleRider account and associated data at any time.</p><h3>In the app</h3><ol><li>Open the menu and go to <strong>Settings</strong>.</li><li>Tap <strong>Delete account</strong> and confirm.</li></ol><h3>Without the app</h3><p>Send a request from the <a href="/contact">contact page</a> with the phone number registered on your account. We verify the request and confirm by SMS.</p><h3>What is deleted</h3><p>Your profile, saved places, verification documents, device tokens and chat messages are deleted within 30 days. Trip, payment and safety-incident records are kept for up to 5 years where the law or fraud prevention requires it, then deleted.</p>'],
            'privacy' => ['Privacy Policy', '<p>This draft privacy notice explains what we collect and why. <strong>It must be reviewed by legal counsel before launch.</strong></p><h3>What we collect</h3><ul><li>Phone number and profile details</li><li>Verification documents (stored privately, accessed only by authorised reviewers)</li><li>Location during app use and trips</li><li>Trip, payment and support records</li></ul><h3>Your rights</h3><p>You can request access, correction or deletion of your data from the app settings.</p>'],
            'eligibility-policy' => ['Women-only Eligibility Policy', '<p>FemaleRider is a women-only platform. To keep our community safe, every account is reviewed by a trained team member. We do <strong>not</strong> use automated gender detection or facial analysis.</p><h3>Evidence we accept</h3><p>A government-issued identity document (CNIC, NICOP or passport). Ambiguous cases are reviewed by a senior reviewer.</p><h3>Retention</h3><p>Verification documents are retained only as long as legally required and then deleted.</p><h3>Appeals</h3><p>If your verification is rejected you can appeal from the app. A different reviewer will consider your appeal.</p><p><em>Draft policy — the exact standard must be approved by the business owner and local legal counsel.</em></p>'],
            'driver-agreement' => ['Driver Agreement', '<p>By driving with FemaleRider you agree to maintain valid documents, follow safety policies, and accept the commission disclosed for your vehicle category. <em>Draft — requires legal review.</em></p>'],
        ];
        foreach ($pages as $slug => [$title, $body]) {
            CmsPage::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => $body, 'version' => '1.0', 'is_published' => true]);
        }

        Coupon::query()->firstOrCreate(['code' => 'WELCOME50'], ['description' => '50% off your first ride (up to PKR 150)', 'type' => 'percent', 'value' => 50, 'max_discount' => 150, 'min_fare' => 150, 'per_user_limit' => 1, 'first_ride_only' => true, 'budget' => 100000, 'is_active' => true, 'ends_at' => now()->addMonths(6)]);
        Coupon::query()->firstOrCreate(['code' => 'SAFE100'], ['description' => 'PKR 100 off rides above PKR 500', 'type' => 'fixed', 'value' => 100, 'min_fare' => 500, 'per_user_limit' => 3, 'usage_limit' => 1000, 'is_active' => true, 'ends_at' => now()->addMonths(3)]);
    }
}
