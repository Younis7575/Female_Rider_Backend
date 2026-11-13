<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\DriverProfile;
use App\Models\Faq;
use App\Models\SystemSetting;
use App\Models\VehicleCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public app bootstrap configuration and CMS content. */
class ContentController extends Controller
{
    public function config(): JsonResponse
    {
        return ApiResponse::ok([
            'app' => [
                'name' => 'FemaleRider',
                'min_supported_version' => SystemSetting::get('app.min_version', '1.0.0'),
                'latest_version' => SystemSetting::get('app.latest_version', '1.0.0'),
                'maintenance_mode' => (bool) SystemSetting::get('platform.maintenance_mode', false),
                'maintenance_message' => SystemSetting::get('platform.maintenance_message'),
            ],
            'currency' => config('femalerider.currency'),
            'default_country_code' => config('femalerider.default_country_code'),
            'countries' => [
                ['code' => '+92', 'iso' => 'PK', 'name' => 'Pakistan', 'flag' => '🇵🇰'],
                ['code' => '+971', 'iso' => 'AE', 'name' => 'United Arab Emirates', 'flag' => '🇦🇪'],
                ['code' => '+966', 'iso' => 'SA', 'name' => 'Saudi Arabia', 'flag' => '🇸🇦'],
                ['code' => '+44', 'iso' => 'GB', 'name' => 'United Kingdom', 'flag' => '🇬🇧'],
                ['code' => '+1', 'iso' => 'US', 'name' => 'United States', 'flag' => '🇺🇸'],
            ],
            'otp' => ['length' => config('femalerider.otp.length'), 'resend_seconds' => config('femalerider.otp.resend_cooldown_seconds')],
            'vehicle_categories' => VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug', 'icon', 'capacity', 'description']),
            'cancel_reasons' => config('femalerider.cancel_reasons'),
            'rating_tags' => config('femalerider.rating_tags'),
            'location' => config('femalerider.location'),
            'trip' => ['pin_length' => config('femalerider.trip.pin_length'), 'customer_free_cancel_seconds' => (int) config('femalerider.trip.customer_free_cancel_seconds')],
            'dispatch' => ['request_ttl_seconds' => config('femalerider.dispatch.request_ttl_seconds'), 'offer_ttl_seconds' => config('femalerider.dispatch.offer_ttl_seconds')],
            'support' => config('femalerider.support'),
            'realtime' => $this->realtimeConfig(),
            'documents' => [
                'required' => DriverProfile::REQUIRED_DOCUMENTS,
                'optional' => DriverProfile::OPTIONAL_DOCUMENTS,
            ],
            'legal' => [
                'terms_url' => url('/terms'),
                'privacy_url' => url('/privacy'),
                'eligibility_policy_url' => url('/eligibility-policy'),
            ],
        ]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $audience = $request->query('audience');

        return ApiResponse::ok(Faq::query()->where('is_published', true)
            ->when($audience, fn ($q) => $q->whereIn('audience', ['all', $audience]))
            ->orderBy('category')->orderBy('sort_order')->get(['id', 'category', 'audience', 'question', 'answer']));
    }

    public function page(string $slug): JsonResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->where('is_published', true)->first();

        return $page ? ApiResponse::ok(['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body, 'version' => $page->version, 'updated_at' => $page->updated_at?->toIso8601String()])
            : ApiResponse::error('Page not found.', 'not_found', 404);
    }

    /**
     * Pusher-protocol socket settings for the app. Reverb (self-hosted) or Pusher Channels
     * (hosted, for shared hosting). An empty key disables sockets and the app falls back
     * to REST polling.
     */
    private function realtimeConfig(): array
    {
        $driver = config('broadcasting.default');
        if ($driver === 'pusher') {
            $cluster = config('broadcasting.connections.pusher.options.cluster', 'mt1');

            return [
                'driver' => 'pusher',
                'key' => (string) config('broadcasting.connections.pusher.key'),
                'host' => "ws-{$cluster}.pusher.com",
                'port' => 443,
                'scheme' => 'https',
                'auth_endpoint' => '/api/v1/broadcasting/auth',
            ];
        }
        if ($driver !== 'reverb') {
            return ['driver' => 'polling', 'key' => '', 'host' => '', 'port' => 0, 'scheme' => 'https', 'auth_endpoint' => '/api/v1/broadcasting/auth'];
        }

        return [
            'driver' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => env('REVERB_PUBLIC_HOST', config('broadcasting.connections.reverb.options.host')),
            'port' => (int) env('REVERB_PUBLIC_PORT', config('broadcasting.connections.reverb.options.port')),
            'scheme' => env('REVERB_PUBLIC_SCHEME', config('broadcasting.connections.reverb.options.scheme')),
            'auth_endpoint' => '/api/v1/broadcasting/auth',
        ];
    }

    /** Deployment health: database reachable + deployed revision (used by the CI/CD smoke test). */
    public function health(): JsonResponse
    {
        $revisionFile = base_path('REVISION');
        $db = true;
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = false;
        }

        return response()->json([
            'status' => $db ? 'ok' : 'degraded',
            'revision' => is_file($revisionFile) ? trim((string) file_get_contents($revisionFile)) : null,
            'database' => $db,
            'time' => now()->toIso8601String(),
        ], $db ? 200 : 503);
    }
}
