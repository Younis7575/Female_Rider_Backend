<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'femalerider-a2c0f'),
        // Absolute path or path relative to the project root. NEVER commit this file.
        'credentials' => env('FIREBASE_CREDENTIALS', 'storage/app/private/firebase/service-account.json'),
        'enabled' => env('FCM_ENABLED', true),
    ],

    'google_maps' => [
        // Server key (restricted by IP) used for Directions / Places / Geocoding.
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'region' => env('GOOGLE_MAPS_REGION', 'pk'),
    ],

    // Free / OpenStreetMap-based providers (no card required). Used when no Google key is set.
    'osm' => [
        'geoapify_key' => env('GEOAPIFY_API_KEY'),          // geocoding & place search (geoapify.com, free tier)
        'openrouteservice_key' => env('OPENROUTESERVICE_API_KEY'), // driving routes (openrouteservice.org, free tier)
        // Keyless public endpoints for development / low volume only (respect their usage policies).
        'allow_public' => env('MAPS_ALLOW_PUBLIC_OSM', env('APP_ENV') !== 'production'),
        'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'osrm_url' => env('OSRM_URL', 'https://router.project-osrm.org'),
        'user_agent' => env('MAPS_USER_AGENT', 'FemaleRider/1.0 (ride-hailing platform)'),
        'country_codes' => env('MAPS_COUNTRY_CODES', 'pk'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | twilio
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    'payments' => [
        'default_gateway' => env('PAYMENT_GATEWAY', 'cash'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    ],

];
