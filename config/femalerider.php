<?php

/*
|--------------------------------------------------------------------------
| FemaleRider business configuration
|--------------------------------------------------------------------------
| Defaults for business rules. Most of these can be overridden at runtime by
| admins through System Settings (system_settings table); see SettingsService.
*/

return [
    'currency' => env('APP_CURRENCY', 'PKR'),
    'default_country_code' => env('DEFAULT_COUNTRY_CODE', '+92'),

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 300,
        'resend_cooldown_seconds' => 60,
        'max_attempts' => 5,
        'max_requests_per_hour' => 5,
        // Only ever honoured when APP_ENV=local|testing. Lets developers log in without SMS.
        'expose_in_response' => env('OTP_EXPOSE_IN_RESPONSE', false),
        // Store-review / QA numbers (E.164, comma separated) that always use OTP_TEST_CODE and
        // never send an SMS. Works in every environment; keep the list short and private.
        'test_numbers' => array_values(array_filter(array_map('trim', explode(',', (string) env('OTP_TEST_NUMBERS', ''))))),
        'test_code' => env('OTP_TEST_CODE'),
    ],

    'eligibility' => [
        'policy_version' => env('ELIGIBILITY_POLICY_VERSION', '1.0'),
        // Accepted evidence types; final list must be approved by legal counsel.
        'accepted_documents' => ['cnic', 'passport', 'nicop', 'other_government_id'],
        'require_selfie' => false,
    ],

    'consent_versions' => [
        'terms' => '1.0',
        'privacy' => '1.0',
        'driver_agreement' => '1.0',
    ],

    'dispatch' => [
        'initial_radius_km' => 3,
        'radius_step_km' => 2,
        'max_radius_km' => 10,
        'max_drivers_per_round' => 50,
        'round_interval_seconds' => 20,
        'request_ttl_seconds' => 300,
        'offer_ttl_seconds' => 90,
        'driver_presence_ttl_seconds' => 90,
        'location_stale_seconds' => 60,
        'max_counter_rounds' => 3,
        'max_pending_offers_per_ride' => 20,
        // "redis" (GEO index, recommended) or "sql" (shared hosting without Redis).
        'presence_driver' => env('PRESENCE_DRIVER', 'redis'),
    ],

    /*
    | Shared hosting (no daemons): the scheduler — driven by a single cron entry —
    | also runs a short-lived queue worker every minute.
    */
    'shared_hosting' => (bool) env('SHARED_HOSTING', false),

    'trip' => [
        'pin_length' => 4,
        'max_pin_attempts' => 5,
        'share_link_ttl_hours' => 6,
        'arrival_radius_m' => 300,
        'free_waiting_minutes' => 3,
        'customer_free_cancel_seconds' => 120,
    ],

    'location' => [
        'online_interval_seconds' => 10,
        'trip_interval_seconds' => 4,
        'idle_interval_seconds' => 30,
        'max_accuracy_m' => 100,
        'max_speed_kmh' => 160,
    ],

    'finance' => [
        'min_payout_amount' => 500,
        // Refunds or payouts above this amount require a second approver (maker-checker).
        'maker_checker_threshold' => 5000,
        'commission_percent_default' => 5,
    ],

    'support' => [
        'hours' => env('SUPPORT_HOURS', 'Daily 08:00–23:00 PKT'),
        'phone' => env('SUPPORT_PHONE', '+92-000-0000000'),
        'email' => env('SUPPORT_EMAIL', 'support@femalerider.example'),
    ],

    'admin' => [
        // Require TOTP MFA for super admin, finance, safety and verification roles.
        'mfa_required' => env('ADMIN_MFA_REQUIRED', env('APP_ENV') === 'production'),
    ],

    'cancel_reasons' => [
        'customer' => ['Driver is too far', 'Changed my plans', 'Booked by mistake', 'Driver asked me to cancel', 'Safety concern', 'Other'],
        'driver' => ['Customer not at pickup', 'Customer asked me to cancel', 'Vehicle problem', 'Safety concern', 'Other'],
    ],

    'rating_tags' => [
        'customer' => ['Safe driving', 'Clean vehicle', 'Friendly', 'On time', 'Good navigation', 'Felt unsafe', 'Rude', 'Late'],
        'driver' => ['Polite', 'On time at pickup', 'Respectful', 'Clear directions', 'Rude', 'Late', 'Made me wait'],
    ],
];
